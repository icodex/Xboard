<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;

class NodeEventHandlers
{
    /** 同一节点的设备状态推送最小间隔（秒）：节点侧整表替换，只能压频率 */
    private const DEVICE_PUSH_MIN_INTERVAL = 30;

    /** 节点 capability 位：自报后才会收到 sync.devices.delta */
    public const CAPABILITY_DEVICE_DELTA = 'device_delta';

    /** 一次增量推送最多携带的用户数 */
    private const DEVICE_DELTA_BATCH = 1000;

    /**
     * Handle pong heartbeat
     */
    public static function handlePong(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        Cache::put(\App\Utils\CacheKey::get("NODE_WS_ALIVE_{$nodeId}"), true, 86400);
    }

    /**
     * Handle node status update
     */
    public static function handleNodeStatus(TcpConnection $conn, int $nodeId, array $data): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $nodeType = strtoupper($node->type);
        Cache::put(\App\Utils\CacheKey::get('SERVER_' . $nodeType . '_LAST_CHECK_AT', $nodeId), time(), 3600);
        ServerService::updateMetrics($node, $data);

        Log::debug("[WS] Node#{$nodeId} status updated");
    }

    /**
     * Handle device report from node
     * 
     * 数据格式: {"event": "report.devices", "data": {userId: [ip1, ip2, ...], ...}}
     */
    public static function handleDeviceReport(TcpConnection $conn, int $nodeId, array $data): void
    {
        $service = app(DeviceStateService::class);

        if (isset($data['devices']) && is_array($data['devices'])) {
            $data = $data['devices'];
        }

        // Get old data
        $oldUserIds = $service->getNodeUserIds($nodeId);

        // Calculate diff
        $newDevices = [];

        foreach ($data as $userId => $ips) {
            if (is_numeric($userId) && is_array($ips)) {
                $newDevices[(int) $userId] = $ips;
            }
        }

        $removedUsers = array_diff($oldUserIds, array_keys($newDevices));

        // Handle removed users
        foreach ($removedUsers as $userId) {
            $service->removeNodeDevices($nodeId, $userId);
            $service->notifyUpdate($userId);
        }

        // Handle new/updated users
        foreach ($newDevices as $userId => $ips) {
            $service->setDevices($userId, $nodeId, $ips);
        }

        // Mark for push: 记录"哪些用户变了"，支持增量的节点只收这些用户的新状态
        $changedUserIds = array_values(array_unique(array_map(
            'intval',
            array_merge(array_keys($newDevices), $removedUsers)
        )));
        if ($changedUserIds !== []) {
            Redis::sadd(
                \App\Utils\CacheKey::get('DEVICE_PUSH_PENDING_USERS', $nodeId),
                ...$changedUserIds
            );
        }
        Redis::sadd(\App\Utils\CacheKey::get('DEVICE_PUSH_PENDING_NODES'), $nodeId);

        Log::debug("[WS] Node#{$nodeId} synced " . count($newDevices) . " users, removed " . count($removedUsers));
    }

    /**
     * Handle device state request from node
     */
    public static function handleDeviceRequest(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();

        $service = app(DeviceStateService::class);
        $devices = $service->getUsersDevices($userIds);

        NodeRegistry::send($nodeId, 'sync.devices', [
            'users' => $devices,
        ]);

        Log::info("[WS] Node#{$nodeId} requested devices, sent " . count($devices) . " users");
    }

    /**
     * Push device state to node
     */
    public static function pushDeviceStateToNode(int $nodeId, DeviceStateService $service): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        // 支持 device_delta 的节点只收"变化了的用户"：节点侧 mailbox 会合并而不是整表替换。
        // 旧节点没有这个能力位，继续收全量快照（保持原行为）。
        if (self::supportsDeviceDelta($nodeId)) {
            self::pushDeviceDeltaToNode($nodeId, $service);

            return;
        }

        // 节点侧的 sync.devices 是整表替换（internal/controlplane/mailbox.go 里
        // `m.deviceUsers = cloneDeviceUsers(...)`），所以只能推全量；想"只推变化用户"
        // 必须先给节点加 device delta 事件。这里压的是频率：同一节点 30s 内最多推一次，
        // 把 10s 一轮的突发上报合并掉，避免每次都对全部用户做一遍查询 + HGETALL。
        if (!Cache::add(CacheKey::get('DEVICE_PUSH_LAST', $nodeId), 1, self::DEVICE_PUSH_MIN_INTERVAL)) {
            // 本轮被节流：放回待推集合，窗口过期后的下一轮再推
            Redis::sadd(CacheKey::get('DEVICE_PUSH_PENDING_NODES'), $nodeId);

            return;
        }

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();
        $devices = $service->getUsersDevices($userIds);

        NodeRegistry::send($nodeId, 'sync.devices', [
            'users' => $devices
        ]);

        Log::debug("[WS] Pushed device state to node#{$nodeId}: " . count($devices) . " users");
    }

    /**
     * 节点是否在 report.status 里自报了 device_delta 能力。
     */
    public static function supportsDeviceDelta(int $nodeId): bool
    {
        $capabilities = Cache::get(CacheKey::get('NODE_CAPABILITIES', $nodeId));

        return is_array($capabilities) && in_array(self::CAPABILITY_DEVICE_DELTA, $capabilities, true);
    }

    /**
     * 只推送本轮变化用户的新状态：有设备 → set，没设备（已被删除/到期）→ remove。
     */
    private static function pushDeviceDeltaToNode(int $nodeId, DeviceStateService $service): void
    {
        $pendingKey = CacheKey::get('DEVICE_PUSH_PENDING_USERS', $nodeId);
        $pending = Redis::spop($pendingKey, self::DEVICE_DELTA_BATCH);
        $userIds = array_values(array_unique(array_map('intval', (array) $pending)));

        if ($userIds === []) {
            return;
        }

        $devices = $service->getUsersDevices($userIds);
        $removed = array_values(array_diff($userIds, array_keys($devices)));

        $delivered = NodeRegistry::send($nodeId, 'sync.devices.delta', [
            'set' => $devices,
            'remove' => $removed,
            'node_id' => $nodeId,
        ]);

        if (!$delivered) {
            // 没送达：把这批用户放回待推集合，下一轮重试（节点重连会清标记拿全量）
            Redis::sadd($pendingKey, ...$userIds);
            Redis::sadd(CacheKey::get('DEVICE_PUSH_PENDING_NODES'), $nodeId);

            return;
        }

        // 还有剩余变化用户：把节点放回待推集合，下一个 tick 继续
        if ((int) Redis::scard($pendingKey) > 0) {
            Redis::sadd(CacheKey::get('DEVICE_PUSH_PENDING_NODES'), $nodeId);
        }

        Log::debug("[WS] Pushed device delta to node#{$nodeId}: set " . count($devices) . ", remove " . count($removed));
    }

    /**
     * Push full config + users to newly connected node
     */
    public static function pushFullSync(TcpConnection $conn, Server $node): void
    {
        $nodeId = (int) $node->id;

        // Push config
        $config = ServerService::buildNodeConfig($node);
        NodeRegistry::send($nodeId, 'sync.config', [
            'config' => $config,
        ]);

        // Push users
        $users = ServerService::getAvailableUsers($node)->toArray();
        NodeRegistry::send($nodeId, 'sync.users', [
            'users' => $users,
        ]);

        // 设备基线：支持 device_delta 的节点必须先收到一份完整快照，
        // 之后的 sync.devices.delta 才有可合并的基准（否则节点会要求重建）。
        $service = app(DeviceStateService::class);
        NodeRegistry::send($nodeId, 'sync.devices', [
            'users' => $service->getUsersDevices(array_column($users, 'id')),
        ]);

        Log::info("[WS] Full sync pushed to node#{$nodeId}", [
            'users' => count($users),
        ]);
    }
}
