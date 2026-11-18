<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\NodeSyncService;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ServerObserver
{
    public function created(Server $server): void
    {
        $machineId = $server->machine_id;
        DB::afterCommit(fn () => $this->notifyMachineNodesChanged($machineId));
    }

    public function updated(Server $server): void
    {
        $fullSync = $server->wasChanged('group_ids');
        $configChanged = $server->wasChanged([
            'server_port', 'protocol_settings', 'type', 'route_ids', 'custom_outbounds',
            'custom_routes', 'custom_route_rules', 'cert_config', 'kernel_log_level',
            'kernel_type', 'host', 'parent_id',
        ]);
        $machineChanged = $server->wasChanged(['machine_id', 'enabled']);
        $nodeId = $server->id;
        $newMachineId = $server->machine_id;
        $oldMachineId = $server->getOriginal('machine_id');
        DB::afterCommit(function () use ($fullSync, $configChanged, $machineChanged, $nodeId, $newMachineId, $oldMachineId) {
            if ($fullSync) {
                $key = CacheKey::get('SERVER_USERS_VERSION');
                if (Cache::increment($key) === false) {
                    Cache::forever($key, 1);
                }
                NodeSyncService::notifyFullSync($nodeId);
            } elseif ($configChanged) {
                NodeSyncService::notifyConfigUpdated($nodeId);
            }
            if ($machineChanged) {
                $this->notifyMachineChange($newMachineId, $oldMachineId);
            }
        });
    }

    public function deleted(Server $server): void
    {
        $machineId = $server->machine_id;
        DB::afterCommit(fn () => $this->notifyMachineChange(null, $machineId));
    }

    private function notifyMachineChange(?int $newMachineId, ?int $oldMachineId): void
    {
        $notified = [];

        if ($newMachineId) {
            NodeSyncService::notifyMachineNodesChanged($newMachineId);
            $notified[] = $newMachineId;
        }

        if ($oldMachineId && !in_array($oldMachineId, $notified, true)) {
            NodeSyncService::notifyMachineNodesChanged($oldMachineId);
        }
    }

    private function notifyMachineNodesChanged(?int $machineId): void
    {
        if ($machineId) {
            NodeSyncService::notifyMachineNodesChanged($machineId);
        }
    }
}
