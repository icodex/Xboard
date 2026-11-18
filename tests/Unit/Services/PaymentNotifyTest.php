<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * payment gateways treat a non-2xx/`fail` body as "keep retrying" and a truthy
 * return as "done". handle() must therefore return a real bool: an unknown
 * trade_no or a mismatched paid amount may never report success.
 */
class PaymentNotifyTest extends TestCase
{
    use RefreshDatabase;

    private function controller(): PaymentController
    {
        return new class extends PaymentController {
            public function callHandle(string $tradeNo, string $callbackNo, ?int $amount = null): bool
            {
                return $this->handle($tradeNo, $callbackNo, $amount);
            }
        };
    }

    public function test_unknown_trade_no_returns_false(): void
    {
        $this->assertFalse($this->controller()->callHandle('missing-trade-no', 'cb'));
    }

    public function test_amount_mismatch_keeps_order_pending(): void
    {
        $order = $this->makeOrder($this->makeUser(), $this->makePlan(), [
            'total_amount' => 100,
            'handling_amount' => null,
        ]);

        $this->assertFalse($this->controller()->callHandle($order->trade_no, 'cb', 50));
        $this->assertSame(Order::STATUS_PENDING, $order->refresh()->status);
    }

    public function test_matching_amount_marks_paid(): void
    {
        $order = $this->makeOrder($this->makeUser(), $this->makePlan(), [
            'total_amount' => 100,
            'handling_amount' => null,
        ]);

        $this->assertTrue($this->controller()->callHandle($order->trade_no, 'cb', 100));
        $this->assertSame(Order::STATUS_COMPLETED, $order->refresh()->status);
    }

    public function test_cancelled_order_callback_is_acknowledged_and_recorded_once(): void
    {
        $order = $this->makeOrder($this->makeUser(), $this->makePlan(), [
            'status' => Order::STATUS_PENDING,
            'total_amount' => 100,
            'balance_amount' => 25,
        ]);
        $stale = $order->fresh();
        $this->assertTrue((new \App\Services\OrderService($order))->cancel());
        // The gateway already captured the money; acknowledge so it stops retrying.
        $this->assertTrue((new \App\Services\OrderService($stale))->paid('gateway-1', 100, true));
        $this->assertTrue($this->controller()->callHandle($order->trade_no, 'gateway-2', 100));
        $order->refresh();
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertSame('gateway-1', $order->callback_no);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(25, (int) $order->user->balance);
    }

    public function test_paid_order_cannot_be_cancelled_from_stale_pending_copy(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $order = $this->makeOrder($this->makeUser(), $this->makePlan(), [
            'status' => Order::STATUS_PENDING, 'total_amount' => 100, 'balance_amount' => 25,
        ]);
        $stale = $order->fresh();
        $this->assertTrue($this->controller()->callHandle($order->trade_no, 'gateway', 100));
        $this->assertFalse((new \App\Services\OrderService($stale))->cancel());
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
        $this->assertSame(0, (int) $order->user->balance);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'payment-notify@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000009',
            'token' => '{{SECRET_9wq2pl4m}}',
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
            'name' => 'Payment Notify Plan',
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
            'trade_no' => uniqid('notify_', true),
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
