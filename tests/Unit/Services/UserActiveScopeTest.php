<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * expired_at = 0 means "never expires" (v2board legacy). Every "is this user
 * still valid" query must agree with User::isActive(), otherwise plan capacity
 * and node payloads disagree about who is active.
 */
class UserActiveScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_scope_includes_expired_at_zero(): void
    {
        $user = $this->makeUser(['expired_at' => 0]);

        $this->assertTrue(User::query()->active()->whereKey($user->id)->exists());
    }

    public function test_active_scope_excludes_past_expiry(): void
    {
        $user = $this->makeUser(['expired_at' => time() - 60]);

        $this->assertFalse(User::query()->active()->whereKey($user->id)->exists());
    }

    public function test_capacity_counts_expired_at_zero_users(): void
    {
        $plan = $this->makePlan(1);
        $this->makeUser(['expired_at' => 0, 'plan_id' => $plan->id]);

        $this->assertFalse((new PlanService($plan))->hasCapacity($plan));
    }

    public function test_get_available_plans_skips_full_plan(): void
    {
        $full = $this->makePlan(1);
        $this->makeUser(['expired_at' => 0, 'plan_id' => $full->id]);
        $open = $this->makePlan(5);

        $ids = (new PlanService($full))->getAvailablePlans()->pluck('id')->all();

        $this->assertNotContains($full->id, $ids);
        $this->assertContains($open->id, $ids);
    }

    private function makeUser(array $overrides = []): User
    {
        static $seq = 0;
        $seq++;

        return User::create(array_merge([
            'email' => "active-scope-{$seq}@example.com",
            'password' => 'password',
            'uuid' => sprintf('00000000-0000-0000-0000-0000000000%02d', $seq),
            'token' => md5('active-scope-' . $seq),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makePlan(?int $capacityLimit): Plan
    {
        return Plan::create([
            'group_id' => null,
            'transfer_enable' => 1111,
            'name' => 'Active Scope Plan ' . ($capacityLimit ?? 'unlimited'),
            'show' => 1,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 4.10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'sell' => 1,
            'capacity_limit' => $capacityLimit,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
