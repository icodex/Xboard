<?php

namespace Tests\Unit\Console;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * check:order 每分钟重派一次未完成订单。卡在 PROCESSING 的订单如果无限重派，
 * 一个永久失败的单（套餐被删、hook 一直抛）会每分钟塞一个必然失败的 job。
 */
class CheckOrderWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_order_inside_window_is_retried(): void
    {
        Bus::fake();

        $order = $this->makeOrder(['status' => Order::STATUS_PROCESSING, 'paid_at' => time() - 60]);

        $this->artisan('check:order')->assertExitCode(0);

        Bus::assertDispatched(OrderHandleJob::class);
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
    }

    public function test_processing_order_past_window_is_not_retried_and_is_reported(): void
    {
        Bus::fake();

        $stale = $this->makeOrder([
            'status' => Order::STATUS_PROCESSING,
            'paid_at' => time() - 25 * 3600,
        ]);

        $this->artisan('check:order')
            ->expectsOutputToContain('停留在开通中')
            ->assertExitCode(0);

        Bus::assertNotDispatched(OrderHandleJob::class);
        // 已付款订单不能自动取消，保持原状态等人工处理
        $this->assertSame(Order::STATUS_PROCESSING, $stale->fresh()->status);
    }

    public function test_fresh_pending_order_is_not_dispatched(): void
    {
        Bus::fake();

        $this->makeOrder(['status' => Order::STATUS_PENDING, 'created_at' => time()]);

        $this->artisan('check:order')->assertExitCode(0);

        Bus::assertNotDispatched(OrderHandleJob::class);
    }

    public function test_old_pending_order_is_dispatched(): void
    {
        Bus::fake();

        $this->makeOrder(['status' => Order::STATUS_PENDING, 'created_at' => time() - 3 * 3600]);

        $this->artisan('check:order')->assertExitCode(0);

        Bus::assertDispatched(OrderHandleJob::class);
    }

    private function makeOrder(array $overrides = []): Order
    {
        $user = User::create([
            'email' => 'order-window@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000c1',
            'token' => '{{SECRET_8dtx2mvb}}',
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
        ]);

        $plan = Plan::create([
            'group_id' => null,
            'transfer_enable' => 1111,
            'name' => 'Order Window Plan',
            'show' => 1,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 4.10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'sell' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return Order::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => uniqid('window_', true),
            'total_amount' => 100,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }
}
