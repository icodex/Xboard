<?php

namespace App\Observers;

use App\Models\Plan;
use App\Models\User;
use App\Services\TrafficResetService;

class PlanObserver
{
    public bool $afterCommit = true;

    /**
     * reset user  next_reset_at
     */
    public function updated(Plan $plan): void
    {
        if (!$plan->wasChanged('reset_traffic_method')) {
            return;
        }
        $trafficResetService = app(TrafficResetService::class);
        User::where('plan_id', $plan->id)
            ->chunkById(500, function ($users) use ($trafficResetService, $plan) {
                foreach ($users as $user) {
                    \Illuminate\Support\Facades\DB::transaction(function () use ($user, $plan, $trafficResetService) {
                        $locked = User::whereKey($user->id)->lockForUpdate()->first();
                        if (!$locked || $locked->plan_id !== $plan->id) {
                            return;
                        }
                        $nextResetTime = $trafficResetService->calculateNextResetTime($locked);
                        $locked->update(['next_reset_at' => $nextResetTime?->timestamp]);
                    });
                }
            });
    }
}

