<?php

namespace App\Services;

use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class DeviceStateService
{
    private const PREFIX = 'user_devices:';
    private const NODE_USERS_PREFIX = 'node_users:';
    private const TTL = 300;                     // device state ttl
    private const DB_THROTTLE = 60;              // min seconds between DB refreshes per user

    /**
     * 每个节点持有一份「该节点上出现过设备的用户」集合。
     * 没有这份集合时，算差集只能 SCAN 整个 user_devices:* 键空间（O(全部用户)）。
     */
    private function nodeUsersKey(int $nodeId): string
    {
        return self::NODE_USERS_PREFIX . $nodeId;
    }

    /**
     * 该节点当前有设备数据的用户 ID
     */
    public function getNodeUserIds(int $nodeId): array
    {
        return array_map('intval', Redis::smembers($this->nodeUsersKey($nodeId)) ?: []);
    }

    /**
     * 批量设置设备
     * 用于 HTTP /alive 和 WebSocket report.devices
     */
    public function setDevices(int $userId, int $nodeId, array $ips): void
    {
        $key = self::PREFIX . $userId;
        $timestamp = time();

        $this->removeNodeDevices($nodeId, $userId);

        // Normalize: strip port suffix and deduplicate
        $ips = array_values(array_unique(array_map([self::class, 'normalizeIP'], $ips)));

        if (!empty($ips)) {
            $fields = [];
            foreach ($ips as $ip) {
                $fields["{$nodeId}:{$ip}"] = $timestamp;
            }
            Redis::hMset($key, $fields);
            Redis::expire($key, self::TTL);
            Redis::sadd($this->nodeUsersKey($nodeId), $userId);
            Redis::expire($this->nodeUsersKey($nodeId), self::TTL);
        }

        $this->notifyUpdate($userId);
    }

    /**
     * 删除某节点某用户的设备
     */
    public function removeNodeDevices(int $nodeId, int $userId): void
    {
        $this->deleteNodeFields($nodeId, $userId);
        Redis::srem($this->nodeUsersKey($nodeId), $userId);
    }

    /**
     * 清除节点所有设备数据（用于节点断开连接）
     */
    public function clearAllNodeDevices(int $nodeId): array
    {
        $userIds = $this->getNodeUserIds($nodeId);

        foreach ($userIds as $userId) {
            $this->deleteNodeFields($nodeId, $userId);
            $this->notifyUpdate($userId);
        }

        Redis::del($this->nodeUsersKey($nodeId));

        return $userIds;
    }

    /**
     * 删掉某用户哈希里属于该节点的字段（一次 hdel，不是每个字段一次）
     */
    private function deleteNodeFields(int $nodeId, int $userId): void
    {
        $key = self::PREFIX . $userId;
        $prefix = "{$nodeId}:";
        $fields = [];

        foreach (Redis::hkeys($key) ?: [] as $field) {
            if (str_starts_with($field, $prefix)) {
                $fields[] = $field;
            }
        }

        if ($fields !== []) {
            Redis::hdel($key, ...$fields);
        }
    }

    /**
     * get user device count (deduplicated by IP, filter expired data)
     */
    public function getDeviceCount(int $userId): int
    {
        return count($this->activeIps(Redis::hgetall(self::PREFIX . $userId)));
    }

    /**
     * get user device count (for alivelist interface)
     */
    public function getAliveList(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        // one pipeline round trip for all users instead of one hgetall each
        $users = $users->values();
        $responses = Redis::pipeline(function ($pipe) use ($users) {
            foreach ($users as $user) {
                $pipe->hgetall(self::PREFIX . $user->id);
            }
        });

        $result = [];
        foreach ($users as $index => $user) {
            $count = count($this->activeIps($responses[$index] ?? []));
            if ($count > 0) {
                $result[$user->id] = $count;
            }
        }

        return $result;
    }

    /**
     * get devices of multiple users (for sync.devices, filter expired data)
     */
    public function getUsersDevices(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $responses = Redis::pipeline(function ($pipe) use ($userIds) {
            foreach ($userIds as $userId) {
                $pipe->hgetall(self::PREFIX . $userId);
            }
        });

        $result = [];
        foreach ($userIds as $index => $userId) {
            $ips = $this->activeIps($responses[$index] ?? []);
            if (!empty($ips)) {
                $result[$userId] = $ips;
            }
        }

        return $result;
    }

    /**
     * extract the still-valid, deduplicated IPs from a user_devices hash
     */
    private function activeIps(array $data): array
    {
        $now = time();
        $ips = [];

        foreach ($data as $field => $timestamp) {
            if ($now - (int) $timestamp <= self::TTL) {
                $ips[] = substr($field, strpos($field, ':') + 1);
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Strip port from IP address: "1.2.3.4:12345" → "1.2.3.4", "[::1]:443" → "::1"
     */
    private static function normalizeIP(string $ip): string
    {
        // [IPv6]:port
        if (preg_match('/^\[(.+)\]:\d+$/', $ip, $m)) {
            return $m[1];
        }
        // IPv4:port
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $m)) {
            return $m[1];
        }
        return $ip;
    }

    /**
     * notify update (throttle control)
     */
    public function notifyUpdate(int $userId): void
    {
        // one DB refresh per user per TTL window; the Redis hash stays the live source
        if (Cache::add(CacheKey::get('DEVICE_DB_THROTTLE', $userId), 1, self::DB_THROTTLE)) {
            User::query()
                ->whereKey($userId)
                ->update([
                    'online_count' => $this->getDeviceCount($userId),
                    'last_online_at' => now(),
                ]);
        }
    }
}
