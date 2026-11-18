<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CheckTrafficExceeded extends Command
{
    protected $signature = 'check:traffic-exceeded';
    protected $description = '检查流量超标用户并通知节点';

    public function handle()
    {
        $rawPending = Redis::smembers('traffic:pending_check');
        if (!is_array($rawPending) || $rawPending === []) {
            return;
        }

        $pendingUserIds = array_map('intval', $rawPending);

        $exceededUsers = User::toBase()
            ->whereIn('id', $pendingUserIds)
            ->whereRaw('u + d >= transfer_enable')
            ->where('transfer_enable', '>', 0)
            ->where('banned', 0)
            ->select(['id', 'group_id'])
            ->get();

        if ($exceededUsers->isEmpty()) {
            Redis::srem('traffic:pending_check', ...$pendingUserIds);
            return;
        }

        $groupedUsers = $exceededUsers->groupBy('group_id');
        $notifiedCount = 0;

        foreach ($groupedUsers as $groupId => $users) {
            if (!$groupId) {
                continue;
            }

            $userIdsInGroup = $users->pluck('id')->toArray();
            $servers = Server::where(fn ($query) => $query
                ->whereJsonContains('group_ids', (string) $groupId)
                ->orWhereJsonContains('group_ids', (int) $groupId))->get();

            foreach ($servers as $server) {
                if (!NodeSyncService::isNodeOnline($server->id)) {
                    continue;
                }

                NodeSyncService::push($server->id, 'sync.user.delta', [
                    'action' => 'remove',
                    'users' => array_map(fn($id) => ['id' => $id], $userIdsInGroup),
                ]);
                $notifiedCount++;
            }
        }

        // Only claim the IDs after processing; a crash must leave them for the next run.
        Redis::srem('traffic:pending_check', ...$pendingUserIds);

        $this->info("Checked " . count($pendingUserIds) . " users, notified {$notifiedCount} nodes for " . $exceededUsers->count() . " exceeded users.");
    }
}
