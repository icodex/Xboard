<?php

namespace Tests\Unit\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P0SilentBugfixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_pending_order_is_cancelled_by_check_job(): void
    {
        $order = $this->makeOrder($this->makeUser(), $this->makePlan(), [
            'status' => Order::STATUS_PENDING,
            'created_at' => time() - 3600 * 3,
        ]);

        (new OrderHandleJob($order->trade_no))->handle();

        $this->assertSame(Order::STATUS_CANCELLED, Order::findOrFail($order->id)->status);
    }

    public function test_fresh_pending_order_is_not_cancelled_by_check_job(): void
    {
        $order = $this->makeOrder($this->makeUser(), $this->makePlan(), [
            'status' => Order::STATUS_PENDING,
            'created_at' => time(),
        ]);

        (new OrderHandleJob($order->trade_no))->handle();

        $this->assertSame(Order::STATUS_PENDING, Order::findOrFail($order->id)->status);
    }

    public function test_expired_at_zero_user_is_available_and_listed_for_nodes(): void
    {
        $user = $this->makeUser(['expired_at' => 0, 'transfer_enable' => 1073741824]);
        $this->assertTrue((new UserService())->isAvailable($user));
        $this->assertTrue((new UserService())->getAvailableUsers()->contains('id', $user->id));
    }

    public function test_paid_is_idempotent_for_repeat_callbacks(): void
    {
        $order = $this->makeOrder($this->makeUser(), $this->makePlan(), [
            'status' => Order::STATUS_PENDING,
        ]);

        $orderService = new OrderService($order);
        $this->assertTrue($orderService->paid('callback-1'));
        // gateway retry must not re-process the same order
        $this->assertTrue($orderService->paid('callback-2'));

        // dispatchSync runs open() inline, so the order completes once
        $order->refresh();
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertSame('callback-1', $order->callback_no);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'p0-test@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000002',
            'token' => '11111111111111111111111111111111',
            'balance' => 0,
            'commission_balance' => 1,
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

    private function makePlan(): Plan
    {
        return Plan::create([
            'group_id' => null,
            'transfer_enable' => 1111,
            'name' => 'P0 Test Plan',
            'show' => 1,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 4.10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'sell' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function makeOrder(User $user, Plan $plan, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => uniqid('p0_', true),
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
