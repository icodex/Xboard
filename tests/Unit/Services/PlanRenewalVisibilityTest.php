<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanRenewalVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sold_out_plan_stays_visible_to_its_active_subscriber(): void
    {
        $plan = $this->plan(['capacity_limit' => 1]);
        $subscriber = $this->user('subscriber@example.com', $plan->id, time() + 86400);
        $other = $this->user('other@example.com', null, null);

        $ids = (new PlanService($plan))->getAvailablePlans($subscriber)->pluck('id')->all();

        $this->assertContains($plan->id, $ids);
        $this->assertNotContains($plan->id, (new PlanService($plan))->getAvailablePlans($other)->pluck('id')->all());
    }

    public function test_hidden_plan_stays_visible_to_its_active_subscriber(): void
    {
        $plan = $this->plan(['show' => 0, 'sell' => 0, 'capacity_limit' => null]);
        $subscriber = $this->user('hidden-subscriber@example.com', $plan->id, null);

        $this->assertContains(
            $plan->id,
            (new PlanService($plan))->getAvailablePlans($subscriber)->pluck('id')->all()
        );
        $this->assertSame([], (new PlanService($plan))->getAvailablePlans()->pluck('id')->all());
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'group_id' => null,
            'transfer_enable' => 1000,
            'name' => 'renewal-visible-plan',
            'show' => 1,
            'renew' => 1,
            'sell' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 1],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function user(string $email, ?int $planId, ?int $expiredAt): User
    {
        return User::create([
            'email' => $email,
            'password' => 'password',
            'uuid' => md5($email),
            'token' => md5($email . '-token'),
            'plan_id' => $planId,
            'expired_at' => $expiredAt,
            'banned' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
