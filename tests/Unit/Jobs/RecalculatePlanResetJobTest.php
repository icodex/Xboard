<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RecalculatePlanResetJob;
use App\Models\Plan;
use App\Models\User;
use App\Services\TrafficResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 改套餐的重置策略后，只重算该套餐用户的 next_reset_at（原实现同步跑在
 * 管理端请求里，几千用户会把请求拖到超时并留下"一半新一半旧"）。
 */
class RecalculatePlanResetJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recalculates_only_the_users_of_the_plan(): void
    {
        $plan = $this->makePlan('target-plan');
        $otherPlan = $this->makePlan('other-plan');

        $mine = $this->makeUser('mine@example.com', $plan);
        $theirs = $this->makeUser('theirs@example.com', $otherPlan);

        // users already get a next_reset_at at creation; record it to prove it is untouched
        $untouched = $theirs->fresh()->next_reset_at;

        (new RecalculatePlanResetJob($plan->id))->handle();

        $this->assertNotNull($mine->fresh()->next_reset_at);
        $this->assertSame(
            $untouched,
            $theirs->fresh()->next_reset_at,
            'another plan must be untouched'
        );

        $expected = app(TrafficResetService::class)->calculateNextResetTime($mine->fresh());
        $this->assertSame($expected?->timestamp, (int) $mine->fresh()->next_reset_at);
    }

    private function makePlan(string $name): Plan
    {
        return Plan::create([
            'group_id' => null,
            'transfer_enable' => 1000,
            'name' => $name,
            'show' => 1,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 1],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'sell' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function makeUser(string $email, Plan $plan): User
    {
        return User::create([
            'email' => $email,
            'password' => 'password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => substr(md5($email . uniqid('', true)), 0, 32),
            'plan_id' => $plan->id,
            'group_id' => 1,
            'transfer_enable' => 1000,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => time() + 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}