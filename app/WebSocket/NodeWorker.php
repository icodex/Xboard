<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class NodeWorker
{
    private const AUTH_TIMEOUT = 10;
    private const PING_INTERVAL = 55;
    // 连续 3 个 ping 周期无 pong 即判死链（NAT 超时/节点进程僵死但 TCP 未断）
    private const PONG_TIMEOUT = 180;

    public const HEARTBEAT_CACHE_KEY = 'ws_server:heartbeat';
    private const HEARTBEAT_INTERVAL = 10;
    private const HEARTBEAT_TTL = 30;

    private Worker $worker;

    private array $handlers = [
        'pong' => [NodeEventHandlers::class, 'handlePong'],
        'node.status' => [NodeEventHandlers::class, 'handleNodeStatus'],
        'report.devices' => [NodeEventHandlers::class, 'handleDeviceReport'],
        'request.devices' => [NodeEventHandlers::class, 'handleDeviceRequest'],
    ];

    public function __construct(string $host, int $port)
    {
        $this->worker = new Worker("websocket://{$host}:{$port}");
        $this->worker->count = 1;
        $this->worker->name = 'xboard-ws-server';
    }

    public function run(): void
    {
        $this->setupLogging();
        $this->setupCallbacks();
        Worker::runAll();
    }

    private function setupLogging(): void
    {
        $logPath = storage_path('logs');
        if (!is_dir($logPath)) {
            mkdir($logPath, 0777, true);
        }
        Worker::$logFile = $logPath . '/xboard-ws-server.log';
        Worker::$pidFile = $logPath . '/xboard-ws-server.pid';
    }

    private function setupCallbacks(): void
    {
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
    }

    public function onWorkerStart(Worker $worker): void
    {
        Log::info("[WS] Worker started, pid={$worker->id}");
        $this->subscribeRedis();
        $this->setupTimers();
    }

    private function setupTimers(): void
    {
        Cache::put(self::HEARTBEAT_CACHE_KEY, time(), self::HEARTBEAT_TTL);
        Timer::add(self::HEARTBEAT_INTERVAL, function () {
            $this->safeCall('heartbeat', function () {
                Cache::put(self::HEARTBEAT_CACHE_KEY, time(), self::HEARTBEAT_TTL);
            });
        });

        // 常驻进程里的 Laravel DB/Redis 连接死后不会自动重建（mysql/pg 空闲会被
        // wait_timeout 掐断），定时重连保底；连接健康时该操作是廉价的
        Timer::add(60, function () {
            $this->safeCall('db.reconnect', function () {
                DB::reconnect();
            });
        });

        Timer::add(self::PING_INTERVAL, function () {
            $this->safeCall('ping', function () {
                $seen = [];

                $pingOrKick = function (TcpConnection $conn) use ($seen): void {
                    $oid = spl_object_id($conn);
                    if (isset($seen[$oid])) {
                        return;
                    }
                    $seen[$oid] = true;

                    // Pong 超时踢除：只发不查会让半开连接（NAT 超时/节点僵死）
                    // 无限期占着 registry，面板对着死连接推 sync.* 无人接收。
                    // lastPongAt 在认证成功时初始化，未认证连接交给 AUTH_TIMEOUT。
                    if (!empty($conn->lastPongAt) && time() - $conn->lastPongAt > self::PONG_TIMEOUT) {
                        Log::warning('[WS] Dropping silent connection, no pong for ' . (time() - $conn->lastPongAt) . 's', [
                            'node_id' => $conn->nodeId ?? null,
                            'machine_id' => $conn->machineId ?? null,
                        ]);
                        $conn->close();
                        return;
                    }

                    $conn->send(json_encode(['event' => 'ping']));
                };

                foreach (NodeRegistry::getConnectedNodeIds() as $nodeId) {
                    $conn = NodeRegistry::get($nodeId);
                    if ($conn) {
                        $pingOrKick($conn);
                    }
                }

                foreach (NodeRegistry::getConnectedMachineIds() as $machineId) {
                    $conn = NodeRegistry::getMachine($machineId);
                    if ($conn) {
                        $pingOrKick($conn);
                    }
                }
            });
        });

        Timer::add(10, function () {
            $this->safeCall('device.push', function () {
                $pendingNodeIds = Redis::spop(CacheKey::get('DEVICE_PUSH_PENDING_NODES'), 100);
                if (empty($pendingNodeIds)) {
                    return;
                }

                $service = app(DeviceStateService::class);
                foreach ($pendingNodeIds as $nodeId) {
                    $nodeId = (int) $nodeId;
                    if (NodeRegistry::get($nodeId) !== null) {
                        NodeEventHandlers::pushDeviceStateToNode($nodeId, $service);
                    }
                }
            });
        });
    }

    /**
     * 在 Workerman 常驻进程里隔离回调异常：Laravel 的 DB/Redis 连接死后会抛
     * 未捕获异常，直接把 worker 子进程带崩（历史日志里 exit status 64000 即此），
     * 所有节点连接同时 EOF。这里捕获、重连、留 error 日志，不让抖动放大。
     */
    private function safeCall(string $context, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            try {
                DB::reconnect();
            } catch (\Throwable) {
                // 重连失败留给下个周期再试
            }
            Log::error("[WS] {$context} failed: {$e->getMessage()}", [
                'exception' => $e,
            ]);
        }
    }

    public function onConnect(TcpConnection $conn): void
    {
        $conn->authTimer = Timer::add(self::AUTH_TIMEOUT, function () use ($conn) {
            if (empty($conn->nodeId) && empty($conn->machineNodeIds)) {
                $conn->close(json_encode([
                    'event' => 'error',
                    'data' => ['message' => 'auth timeout'],
                ]));
            }
        }, [], false);
    }

    public function onWebSocketConnect(TcpConnection $conn, $httpMessage): void
    {
        $queryString = '';
        if (is_string($httpMessage)) {
            $queryString = parse_url($httpMessage, PHP_URL_QUERY) ?? '';
        } elseif ($httpMessage instanceof \Workerman\Protocols\Http\Request) {
            $queryString = $httpMessage->queryString();
        }

        parse_str($queryString, $params);

        if (isset($conn->authTimer)) {
            Timer::del($conn->authTimer);
        }

        // 判断认证模式
        $this->safeCall('auth', function () use ($conn, $params) {
            if (!empty($params['machine_id'])) {
                $this->authenticateMachine($conn, $params);
            } else {
                $this->authenticateNode($conn, $params);
            }
        });
    }

    /**
     * 旧模式：单节点认证
     */
    private function authenticateNode(TcpConnection $conn, array $params): void
    {
        $token = $params['token'] ?? '';
        $nodeId = (int) ($params['node_id'] ?? 0);

        $serverToken = admin_setting('server_token', '');
        if ($token === '' || $serverToken === '' || !hash_equals($serverToken, $token)) {
            Log::warning('[WS] Node auth failed: invalid token', [
                'remote' => $conn->getRemoteIp(),
                'node_id' => $nodeId,
            ]);
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid token'],
            ]));
            return;
        }

        $node = ServerService::getServer($nodeId, null);
        if (!$node) {
            Log::warning('[WS] Node auth failed: node not found', [
                'remote' => $conn->getRemoteIp(),
                'node_id' => $nodeId,
            ]);
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'node not found'],
            ]));
            return;
        }

        $conn->nodeId = $nodeId;
        $conn->lastPongAt = time();
        NodeRegistry::add($nodeId, $conn);
        Cache::put(CacheKey::get("NODE_WS_ALIVE_{$nodeId}"), true, 86400);

        app(DeviceStateService::class)->clearAllNodeDevices($nodeId);

        Log::info("[WS] Node#{$nodeId} connected", [
            'remote' => $conn->getRemoteIp(),
            'total' => NodeRegistry::count(),
        ]);

        $conn->send(json_encode([
            'event' => 'auth.success',
            'data' => ['node_id' => $nodeId],
        ]));

        NodeEventHandlers::pushFullSync($conn, $node);
    }

    /**
     * 新模式：机器认证，自动注册该机器下所有已启用节点
     */
    private function authenticateMachine(TcpConnection $conn, array $params): void
    {
        $machineId = (int) ($params['machine_id'] ?? 0);
        $token = $params['token'] ?? '';

        $machine = ServerMachine::where('id', $machineId)
            ->where('token', $token)
            ->first();

        if (!$machine || !$machine->is_active) {
            Log::warning('[WS] Machine auth failed: invalid credentials', [
                'remote' => $conn->getRemoteIp(),
                'machine_id' => $machineId,
            ]);
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid machine credentials'],
            ]));
            return;
        }

        $nodes = ServerService::getMachineNodes($machine);

        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();
        NodeRegistry::addMachine($machineId, $conn);

        // 把同一个连接注册到该机器下所有节点
        $nodeIds = [];
        $deviceService = app(DeviceStateService::class);
        foreach ($nodes as $node) {
            NodeRegistry::add($node->id, $conn);
            Cache::put(CacheKey::get("NODE_WS_ALIVE_{$node->id}"), true, 86400);
            $deviceService->clearAllNodeDevices($node->id);
            $nodeIds[] = $node->id;
        }

        // 连接上记录所属机器和节点列表
        $conn->machineId = $machineId;
        $conn->machineNodeIds = $nodeIds;
        $conn->lastPongAt = time();

        Log::info("[WS] Machine#{$machineId} connected, nodes: " . implode(',', $nodeIds), [
            'remote' => $conn->getRemoteIp(),
            'total' => NodeRegistry::count(),
            'machines' => NodeRegistry::machineCount(),
        ]);

        $conn->send(json_encode([
            'event' => 'auth.success',
            'data' => [
                'machine_id' => $machineId,
                'node_ids' => $nodeIds,
            ],
        ]));

        // 为每个节点推送完整同步
        foreach ($nodes as $node) {
            NodeEventHandlers::pushFullSync($conn, $node);
        }
    }

    public function onMessage(TcpConnection $conn, $data): void
    {
        $msg = json_decode($data, true);
        if (!is_array($msg)) {
            return;
        }

        $event = $msg['event'] ?? '';

        // 任何 pong 都刷新连接活性（pong 超时踢除依赖此时间戳）
        if ($event === 'pong') {
            $conn->lastPongAt = time();
        }

        $this->safeCall("message.{$event}", function () use ($conn, $msg, $event) {
            // 机器连接：从消息中读取 node_id 来分派到具体节点
            if (!empty($conn->machineNodeIds)) {
                if ($event === 'pong') {
                    foreach ($conn->machineNodeIds as $nid) {
                        Cache::put(CacheKey::get("NODE_WS_ALIVE_{$nid}"), true, 86400);
                    }
                    return;
                }

                $nodeId = (int) ($msg['data']['node_id'] ?? 0);
                if ($nodeId <= 0 || !in_array($nodeId, $conn->machineNodeIds, true)) {
                    return;
                }
                if (isset($this->handlers[$event])) {
                    $handler = $this->handlers[$event];
                    $handler($conn, $nodeId, $msg['data'] ?? []);
                }
                return;
            }

            // 旧模式：单节点
            $nodeId = $conn->nodeId ?? null;
            if (isset($this->handlers[$event]) && $nodeId) {
                $handler = $this->handlers[$event];
                $handler($conn, $nodeId, $msg['data'] ?? []);
            }
        });
    }

    public function onClose(TcpConnection $conn): void
    {
        $service = app(DeviceStateService::class);

        // 机器模式：清理所有关联节点
        if (!empty($conn->machineNodeIds)) {
            $machineId = $conn->machineId ?? 'unknown';
            foreach ($conn->machineNodeIds as $nodeId) {
                NodeRegistry::remove($nodeId, $conn);
                Cache::forget(CacheKey::get("NODE_WS_ALIVE_{$nodeId}"));

                $affectedUserIds = $service->clearAllNodeDevices($nodeId);
                foreach ($affectedUserIds as $userId) {
                    $service->notifyUpdate($userId);
                }
            }

            if (!empty($conn->machineId)) {
                NodeRegistry::removeMachine((int) $conn->machineId, $conn);
            }

            Log::info("[WS] Machine#{$machineId} disconnected", [
                'nodes' => $conn->machineNodeIds,
                'total' => NodeRegistry::count(),
                'machines' => NodeRegistry::machineCount(),
            ]);
            return;
        }

        // 旧模式：单节点
        if (!empty($conn->nodeId)) {
            $nodeId = $conn->nodeId;
            NodeRegistry::remove($nodeId, $conn);
            Cache::forget(CacheKey::get("NODE_WS_ALIVE_{$nodeId}"));

            $affectedUserIds = $service->clearAllNodeDevices($nodeId);
            foreach ($affectedUserIds as $userId) {
                $service->notifyUpdate($userId);
            }

            Log::info("[WS] Node#{$nodeId} disconnected", [
                'total' => NodeRegistry::count(),
                'affected_users' => count($affectedUserIds),
            ]);
        }
    }

    private function subscribeRedis(): void
    {
        $host = config('database.redis.default.host', '127.0.0.1');
        $port = config('database.redis.default.port', 6379);

        if (str_starts_with($host, '/')) {
            $redisUri = "unix://{$host}";
        } else {
            $redisUri = "redis://{$host}:{$port}";
        }

        $redis = new \Workerman\Redis\Client($redisUri);

        $password = config('database.redis.default.password');
        if ($password) {
            $redis->auth($password);
        }

        $prefix = config('database.redis.options.prefix', '');
        $channel = $prefix . 'node:push';

        $redis->subscribe([$channel], function ($chan, $message) {
            $payload = json_decode($message, true);
            if (!is_array($payload)) {
                return;
            }

            $event = $payload['event'] ?? '';
            $data = $payload['data'] ?? [];

            // Machine-level events (e.g., sync.nodes)
            $machineId = $payload['machine_id'] ?? null;
            if ($machineId && $event) {
                // Update server-side registry when node membership changes
                if ($event === 'sync.nodes') {
                    $nodeIds = array_map('intval', array_column($data['nodes'] ?? [], 'id'));
                    NodeRegistry::refreshMachineNodes((int) $machineId, $nodeIds);
                }

                $sent = NodeRegistry::sendMachine((int) $machineId, $event, $data);
                if ($sent) {
                    Log::debug("[WS] Pushed {$event} to machine#{$machineId}");
                }
                return;
            }

            // Per-node events
            $nodeId = $payload['node_id'] ?? null;
            if (!$nodeId || !$event) {
                return;
            }

            $sent = NodeRegistry::send((int) $nodeId, $event, $data);
            if ($sent) {
                Log::debug("[WS] Pushed {$event} to node#{$nodeId}");
            }
        });

        Log::info("[WS] Subscribed to Redis channel: {$channel}");
    }
}
