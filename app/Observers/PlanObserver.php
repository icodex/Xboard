<?php

namespace App\Observers;

use App\Jobs\RecalculatePlanResetJob;
use App\Models\Plan;

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

        // 一个套餐可能挂几千用户：同步重算会把管理端请求拖到超时，中断后一半用户
        // 按新策略、一半按旧策略。交给分块 job，可中断可续跑。
        RecalculatePlanResetJob::dispatch((int) $plan->id);
    }
}

