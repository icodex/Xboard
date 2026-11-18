<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\OrderController;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_retries_provisioning_for_cancelled_paid_order(): void
    {
        Queue::fake();
        $order = $this->cancelledPaidOrder();

        $response = (new OrderController())->reconcile(Request::create('/', 'POST', [
            'trade_no' => $order->trade_no,
            'action' => 'complete',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
        Queue::assertPushed(OrderHandleJob::class);
    }

    public function test_refund_marks_order_without_reopening_it(): void
    {
        $order = $this->cancelledPaidOrder();

        $response = (new OrderController())->reconcile(Request::create('/', 'POST', [
            'trade_no' => $order->trade_no,
            'action' => 'refund',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $order->refresh();
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertNotNull($order->refunded_at);
    }

    private function cancelledPaidOrder(): Order
    {
        $user = User::create([
            'email' => 'reconcile@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000f3',
            'token' => md5('reconcile@example.com'),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan = Plan::create([
            'group_id' => null,
            'transfer_enable' => 1000,
            'name' => 'reconcile-plan',
            'show' => 1,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 1],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'sell' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'reconcile-' . uniqid(),
            'total_amount' => 100,
            'balance_amount' => 0,
            'status' => Order::STATUS_CANCELLED,
            'paid_at' => time(),
            'callback_no' => 'gateway-late',
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
