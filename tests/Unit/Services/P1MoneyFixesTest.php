<?php

namespace Tests\Unit\Services;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P1MoneyFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_4_10_yields_410_cents_not_409(): void
    {
        $order = OrderService::createFromRequest(
            User::findOrFail($this->makeUser()->id),
            Plan::findOrFail($this->makePlan(['prices' => [Plan::PERIOD_MONTHLY => 4.10]])->id),
            Plan::PERIOD_MONTHLY,
        );

        $this->assertSame(410, (int) $order->total_amount);
    }

    public function test_cancel_refunds_coupon_limit_use(): void
    {
        $user = $this->makeUser();
        $coupon = Coupon::create([
            'name' => 'p1-test-coupon',
            'code' => 'P1TESTCODE1',
            'type' => 1,
            'value' => 100,
            'limit_use' => 0, // already burned by the pending order
            'started_at' => time() - 3600,
            'ended_at' => time() + 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order = $this->makeOrder($user, $this->makePlan(), [
            'status' => Order::STATUS_PENDING,
            'coupon_id' => $coupon->id,
        ]);

        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertSame(1, Coupon::findOrFail($coupon->id)->limit_use);
    }

    public function test_stacked_discounts_cannot_exceed_price(): void
    {
        $user = $this->makeUser(['discount' => 50]);
        Coupon::create([
            'name' => 'stacked', 'code' => 'STACKED', 'type' => 1, 'value' => 350, 'show' => true,
            'limit_use' => 1, 'started_at' => time() - 3600, 'ended_at' => time() + 3600,
        ]);
        $order = OrderService::createFromRequest($user, $this->makePlan(), Plan::PERIOD_MONTHLY, 'STACKED');
        $this->assertSame(0, (int) $order->total_amount);
        $this->assertSame(410, (int) $order->discount_amount);
    }

    public function test_unpaid_order_reserves_capacity_until_cancelled(): void
    {
        $plan = $this->makePlan(['capacity_limit' => 1]);
        $first = $this->makeUser();
        $second = $this->makeUser(['email' => 'second@example.com', 'uuid' => '00000000-0000-0000-0000-000000000004', 'token' => 'second-token']);
        $order = OrderService::createFromRequest($first, $plan, Plan::PERIOD_MONTHLY);
        $this->assertFalse((new \App\Services\PlanService($plan))->hasCapacity($plan));
        try {
            OrderService::createFromRequest($second, $plan, Plan::PERIOD_MONTHLY);
            $this->fail('A reserved capacity slot cannot be sold again');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertTrue((new OrderService($order))->cancel());
        }
        $this->assertNotNull(OrderService::createFromRequest($second, $plan, Plan::PERIOD_MONTHLY)->id);
    }

    public function test_unsold_plan_rejects_new_purchase_but_allows_active_renewal(): void
    {
        $plan = $this->makePlan(['sell' => 0, 'capacity_limit' => 1]);
        $user = $this->makeUser();
        try {
            OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);
            $this->fail('An unsold plan cannot be purchased');
        } catch (\App\Exceptions\ApiException $e) {
            $user->update(['plan_id' => $plan->id, 'expired_at' => 0]);
        }
        $order = OrderService::createFromRequest($user->fresh(), $plan, Plan::PERIOD_MONTHLY);
        $this->assertSame(Order::TYPE_RENEWAL, $order->type);
        $this->assertFalse((new \App\Services\PlanService($plan))->hasCapacity($plan));
    }

    public function test_zero_expiry_switch_is_an_upgrade(): void
    {
        $oldPlan = $this->makePlan();
        $user = $this->makeUser(['plan_id' => $oldPlan->id, 'expired_at' => 0]);
        $order = OrderService::createFromRequest($user, $this->makePlan(), Plan::PERIOD_MONTHLY);
        $this->assertSame(Order::TYPE_UPGRADE, $order->type);
    }

    public function test_failed_reset_rolls_back_fulfillment_and_can_retry(): void
    {
        $user = $this->makeUser(['u' => 100]);
        $order = $this->makeOrder($user, $this->makePlan(), ['status' => Order::STATUS_PROCESSING]);
        $reset = \Mockery::mock(\App\Services\TrafficResetService::class);
        $reset->shouldReceive('performReset')->once()->andReturn(false);
        $this->app->instance(\App\Services\TrafficResetService::class, $reset);
        try {
            (new OrderService($order))->open();
            $this->fail('Reset failure must abort fulfillment');
        } catch (\RuntimeException $e) {
            $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
            $this->assertSame(100, (int) $user->fresh()->u);
        }
        $this->app->forgetInstance(\App\Services\TrafficResetService::class);
        (new OrderService($order))->open();
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame(0, (int) $user->fresh()->u);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'p1-test@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000003',
            'token' => '22222222222222222222222222222222',
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

    private function makePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'group_id' => null,
            'transfer_enable' => 1111,
            'name' => 'P1 Test Plan',
            'show' => 1,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 4.10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makeOrder(User $user, Plan $plan, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => uniqid('p1_', true),
            'total_amount' => 0,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }
}
