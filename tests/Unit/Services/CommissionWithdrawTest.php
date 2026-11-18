<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\CommissionWithdraw;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CommissionWithdrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionWithdrawTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting([
            'withdraw_close_enable' => 0,
            'commission_withdraw_limit' => 10,
        ]);
    }

    public function test_request_freezes_full_balance_and_creates_ledger_and_ticket(): void
    {
        $user = $this->user(10000);

        $withdraw = app(CommissionWithdrawService::class)->request($user, 'alipay', 'acct');

        $this->assertSame(0, (int) $user->fresh()->commission_balance);
        $this->assertSame(10000, $withdraw->amount);
        $this->assertSame(CommissionWithdraw::STATUS_PENDING, $withdraw->status);
        $this->assertNotNull($withdraw->ticket_id);
        $this->assertSame(1, Ticket::where('user_id', $user->id)->count());
    }

    public function test_reject_refunds_once(): void
    {
        $user = $this->user(10000);
        $service = app(CommissionWithdrawService::class);
        $withdraw = $service->request($user, 'alipay', 'acct', 30);

        $service->reject($withdraw, 1, 'invalid account');
        $service->reject($withdraw, 1, 'duplicate');

        $this->assertSame(10000, (int) $user->fresh()->commission_balance);
        $this->assertSame(CommissionWithdraw::STATUS_REJECTED, $withdraw->fresh()->status);
    }

    public function test_approve_marks_paid_without_refund(): void
    {
        $user = $this->user(10000);
        $service = app(CommissionWithdrawService::class);
        $withdraw = $service->request($user, 'alipay', 'acct', 50);

        $service->approve($withdraw, 1, 'paid');

        $this->assertSame(5000, (int) $user->fresh()->commission_balance);
        $this->assertSame(CommissionWithdraw::STATUS_PAID, $withdraw->fresh()->status);
    }

    public function test_second_pending_request_is_rejected(): void
    {
        $user = $this->user(10000);
        $service = app(CommissionWithdrawService::class);
        $service->request($user, 'alipay', 'acct', 10);

        $this->expectException(ApiException::class);
        $service->request($user, 'alipay', 'acct', 10);
    }

    private function user(int $commission): User
    {
        return User::create([
            'email' => 'withdraw@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000f4',
            'token' => md5('withdraw@example.com'),
            'commission_balance' => $commission,
            'balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
