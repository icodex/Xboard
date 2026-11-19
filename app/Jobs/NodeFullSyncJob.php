<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class NodeFullSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 60;

    /**
     * 即使版本号没变，也至少每这么久重推一次整份用户名单。
     * Redis pubsub 是发后不管：消息丢了面板无从得知，只能靠这个上限收敛。
     */
    private const USERS_RESYNC_INTERVAL = 900;

    public function __construct(private readonly int $nodeId)
    {
        $this->onQueue('node_sync');
    }

    public function handle(): void
    {
        if (!NodeSyncService::isNodeOnline($this->nodeId) || !($node = Server::find($this->nodeId))) {
            return;
        }

        // Periodic reconciliation must also recover a lost sync.config push;
        // otherwise a config change dropped by Redis stays stale until reconnect.
        // config 很小，每轮都推，不作为跳过对象。
        NodeSyncService::push($this->nodeId, 'sync.config', [
            'config' => ServerService::buildNodeConfig($node),
        ]);

        $version = (string) (Cache::get(CacheKey::get('SERVER_USERS_VERSION')) ?? '0');
        $markerKey = CacheKey::get('NODE_USERS_SYNCED', $this->nodeId);
        $marker = (array) Cache::get($markerKey, []);

        $sameVersion = ($marker['version'] ?? null) === $version;
        $recentlySynced = (time() - (int) ($marker['at'] ?? 0)) < self::USERS_RESYNC_INTERVAL;

        // 名单没变且刚同步过：跳过这一轮的全量序列化与广播
        if ($sameVersion && $recentlySynced) {
            return;
        }

        if (!NodeSyncService::push($this->nodeId, 'sync.users', [
            'users' => ServerService::getAvailableUsers($node)->toArray(),
        ])) {
            // 没送达（ws-server 不订阅/异常）：不写标记，下一轮重试
            return;
        }

        Cache::put($markerKey, ['version' => $version, 'at' => time()], 86400);
    }
}
