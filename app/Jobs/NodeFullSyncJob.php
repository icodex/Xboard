<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NodeFullSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 60;

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
        NodeSyncService::push($this->nodeId, 'sync.config', [
            'config' => ServerService::buildNodeConfig($node),
        ]);

        NodeSyncService::push($this->nodeId, 'sync.users', [
            'users' => ServerService::getAvailableUsers($node)->toArray(),
        ]);
    }
}
