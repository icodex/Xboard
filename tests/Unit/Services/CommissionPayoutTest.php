<?php

namespace Tests\Unit\Services;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 佣金发放是钱：分块重写后必须证明「发一次、发对数额、绝不重复发」。
 */
class CommissionPayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_commission_is_paid_once(): void
    {
        admin_setting([
            'commission_distribution_enable' => 0,
            'withdraw_close_enable' => 0,
        ]);

        $inviter = $this->makeUser('inviter@example.com', '00000000-0000-0000-0000-0000000000e1');
        $buyer = $this->makeUser('buyer@example.com', '00000000-0000-0000-0000-0000000000e2');
        $order = $this->makeOrder($buyer, $inviter, ['commission_balance' => 1000]);

        $this->artisan('check:commission')->assertExitCode(0);
        $this->artisan('check:commission')->assertExitCode(0);

        $this->assertSame(2, (int) $order->fresh()->commission_status);
        $this->assertSame(1000, (int) $inviter->fresh()->commission_balance);
        $this->assertSame(1, CommissionLog::where('trade_no', $order->trade_no)->count());
    }

    public function test_order_with_deleted_inviter_is_closed_without_credit(): void
    {
        admin_setting([
            'commission_distribution_enable' => 0,
            'withdraw_close_enable' => 0,
        ]);

        $buyer = $this->makeUser('no-inviter@example.com', '00000000-0000-0000-0000-0000000000e3');
        $order = $this->makeOrder($buyer, null, [
            'invite_user_id' => 999999,
            'commission_balance' => 500,
        ]);

        $this->artisan('check:commission')->assertExitCode(0);

        // 邀请人已被删除：钱发不出去，但订单不能一直卡在待发状态
        $this->assertSame(2, (int) $order->fresh()->commission_status);
        $this->assertSame(0, CommissionLog::where('trade_no', $order->trade_no)->count());
    }

    public function test_a_zero_share_level_skips_payout_without_paying_the_next_level_to_the_same_upline(): void
    {
        admin_setting([
            'commission_distribution_enable' => 1,
            // level 1 left unconfigured -> share 0
            'commission_distribution_l1' => 0,
            'commission_distribution_l2' => 20,
            'commission_distribution_l3' => 10,
            'withdraw_close_enable' => 0,
        ]);

        $l1 = $this->makeUser('skip-l1@example.com', '00000000-0000-0000-0000-0000000000e4');
        $l2 = $this->makeUser('skip-l2@example.com', '00000000-0000-0000-0000-0000000000e5');
        $l3 = $this->makeUser('skip-l3@example.com', '00000000-0000-0000-0000-0000000000e6');

        $l1->invite_user_id = $l2->id;
        $l1->save();
        $l2->invite_user_id = $l3->id;
        $l2->save();

        $buyer = $this->makeUser('skip-buyer@example.com', '00000000-0000-0000-0000-0000000000e7');
        $order = $this->makeOrder($buyer, $l1, ['commission_balance' => 100]);

        $this->artisan('check:commission')->assertExitCode(0);

        // share 0 means "this level pays nobody", not "keep paying the same upline"
        $this->assertSame(0, (int) $l1->fresh()->commission_balance);
        $this->assertSame(20, (int) $l2->fresh()->commission_balance);
        $this->assertSame(10, (int) $l3->fresh()->commission_balance);
    }

    private function makeUser(string $email, string $uuid): User
    {
        return User::create([
            'email' => $email,
            'password' => 'password',
            'uuid' => $uuid,
            'token' => md5($email),
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
    }

    private function makeOrder(User $buyer, ?User $inviter, array $overrides = []): Order
    {
        $plan = Plan::create([
            'group_id' => null,
            'transfer_enable' => 1111,
            'name' => 'Commission Plan',
            'show' => 1,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 4.10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'sell' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return Order::create(array_merge([
            'user_id' => $buyer->id,
            'plan_id' => $plan->id,
            'invite_user_id' => $inviter?->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => uniqid('commission_', true),
            'total_amount' => 4100,
            'balance_amount' => 0,
            'status' => Order::STATUS_COMPLETED,
            'commission_status' => 1,
            'commission_balance' => 0,
            'actual_commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }
}
