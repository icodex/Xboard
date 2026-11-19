<?php

namespace App\Jobs;

use App\Models\Plan;
use App\Models\User;
use App\Services\TrafficResetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * 套餐的流量重置策略变更后，重算该套餐下用户的 next_reset_at。
 *
 * 原来是同步跑在管理员那次 HTTP 请求里的：一个套餐挂几千用户就是几千个事务，
 * 请求超时中断后一半用户按新策略、一半按旧策略，后台只看到一个 504。
 * 这里每次只处理一批并把自己重新入队，单次运行时间受控、可中断可续跑。
 */
class RecalculatePlanResetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 受 Horizon business supervisor 的 30s timeout 限制，留出余量 */
    public int $timeout = 25;

    public int $tries = 3;

    private const BATCH = 200;

    public function __construct(private int $planId, private int $afterUserId = 0)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $plan = Plan::find($this->planId);
        if (!$plan) {
            return;
        }

        $users = User::where('plan_id', $plan->id)
            ->where('id', '>', $this->afterUserId)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get(['id']);

        if ($users->isEmpty()) {
            return;
        }

        $service = app(TrafficResetService::class);

        foreach ($users as $user) {
            // 每个用户单独加锁重读：期间下单/续费会改同一行，只重算仍然属于该套餐的人
            DB::transaction(function () use ($user, $plan, $service) {
                $locked = User::whereKey($user->id)->lockForUpdate()->first();
                if (!$locked || (int) $locked->plan_id !== (int) $plan->id) {
                    return;
                }

                $nextResetTime = $service->calculateNextResetTime($locked);
                $locked->update(['next_reset_at' => $nextResetTime?->timestamp]);
            });
        }

        if ($users->count() === self::BATCH) {
            self::dispatch($this->planId, (int) $users->last()->id);
        }
    }
}
