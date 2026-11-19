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

    public function test_complete_retries_a_stuck_processing_order(): void
    {
        Queue::fake();
        $order = $this->cancelledPaidOrder(['status' => Order::STATUS_PROCESSING]);

        $response = (new OrderController())->reconcile(Request::create('/', 'POST', [
            'trade_no' => $order->trade_no,
            'action' => 'complete',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        // stuck in "processing" past the 24h retry window: the admin needs an exit
        Queue::assertPushed(OrderHandleJob::class);
    }

    private function cancelledPaidOrder(array $orderOverrides = [], int $userBalance = 0): Order
    {
        $user = User::create([
            'email' => 'reconcile@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000f3',
            'token' => md5('reconcile@example.com'),
            'balance' => $userBalance,
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

        return Order::create(array_merge([
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
        ], $orderOverrides));
    }

    public function test_complete_reclaims_the_balance_that_cancel_refunded(): void
    {
        Queue::fake();
        // cancel() gave back the 50 the user had paid from balance; the order itself
        // still says 50 was paid that way, so completing it must take it again.
        $order = $this->cancelledPaidOrder(['balance_amount' => 5000], 5000);

        $response = (new OrderController())->reconcile(Request::create('/', 'POST', [
            'trade_no' => $order->trade_no,
            'action' => 'complete',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
        $this->assertSame(0, (int) User::find($order->user_id)->balance);
    }

    public function test_complete_refuses_when_the_refunded_balance_is_gone(): void
    {
        Queue::fake();
        $order = $this->cancelledPaidOrder(['balance_amount' => 5000], 0);

        $response = (new OrderController())->reconcile(Request::create('/', 'POST', [
            'trade_no' => $order->trade_no,
            'action' => 'complete',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        Queue::assertNotPushed(OrderHandleJob::class);
    }
}
