<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\CommissionWithdraw;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CommissionWithdrawService
{
    /**
     * Freeze the requested amount and create the ledger row + ticket in one transaction.
     * The frontend has no amount field, so omitting it withdraws the full balance.
     */
    public function request(User $user, string $method, string $account, ?float $amount = null): CommissionWithdraw
    {
        $minimumCents = (int) round(((float) admin_setting('commission_withdraw_limit', 100)) * 100);

        return DB::transaction(function () use ($user, $method, $account, $amount, $minimumCents) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $pending = CommissionWithdraw::where('user_id', $locked->id)
                ->where('status', CommissionWithdraw::STATUS_PENDING)
                ->lockForUpdate()
                ->exists();
            if ($pending) {
                throw new ApiException('存在待处理的提现申请');
            }

            $balance = (int) ($locked->commission_balance ?? 0);
            $amountCents = $amount === null ? $balance : (int) round($amount * 100);

            if ($amountCents <= 0) {
                throw new ApiException('提现金额必须大于 0');
            }
            if ($amountCents > $balance) {
                throw new ApiException('佣金余额不足');
            }
            if ($amountCents < $minimumCents) {
                throw new ApiException(__('The current required minimum withdrawal commission is :limit', [
                    'limit' => admin_setting('commission_withdraw_limit', 100),
                ]));
            }

            $locked->commission_balance = $balance - $amountCents;
            $locked->saveOrFail();

            $withdraw = CommissionWithdraw::create([
                'user_id' => $locked->id,
                'amount' => $amountCents,
                'method' => $method,
                'account' => $account,
                'status' => CommissionWithdraw::STATUS_PENDING,
                'created_at' => time(),
                'updated_at' => time(),
            ]);

            $ticket = app(TicketService::class)->createTicket(
                $locked->id,
                __('[Commission Withdrawal Request] This ticket is opened by the system'),
                2,
                sprintf(
                    "%s：%s\r\n%s：%s\r\n%s：%.2f",
                    __('Withdrawal method'),
                    $method,
                    __('Withdrawal account'),
                    $account,
                    __('Amount'),
                    $amountCents / 100
                )
            );

            $withdraw->ticket_id = $ticket->id;
            $withdraw->saveOrFail();

            return $withdraw;
        });
    }

    public function approve(CommissionWithdraw $withdraw, int $adminId, ?string $reason = null): CommissionWithdraw
    {
        return DB::transaction(function () use ($withdraw, $adminId, $reason) {
            $locked = CommissionWithdraw::whereKey($withdraw->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === CommissionWithdraw::STATUS_PAID) {
                return $locked;
            }
            if ($locked->status !== CommissionWithdraw::STATUS_PENDING) {
                throw new ApiException('该提现申请已拒绝，不能标记为已支付');
            }

            $locked->status = CommissionWithdraw::STATUS_PAID;
            $locked->admin_id = $adminId;
            $locked->reason = $reason;
            $locked->saveOrFail();
            return $locked;
        });
    }

    public function reject(CommissionWithdraw $withdraw, int $adminId, ?string $reason = null): CommissionWithdraw
    {
        return DB::transaction(function () use ($withdraw, $adminId, $reason) {
            $locked = CommissionWithdraw::whereKey($withdraw->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === CommissionWithdraw::STATUS_REJECTED) {
                return $locked;
            }
            if ($locked->status !== CommissionWithdraw::STATUS_PENDING) {
                throw new ApiException('该提现申请已支付，不能拒绝并退款');
            }

            $user = User::whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $user->commission_balance = (int) ($user->commission_balance ?? 0) + (int) $locked->amount;
            $user->saveOrFail();

            $locked->status = CommissionWithdraw::STATUS_REJECTED;
            $locked->admin_id = $adminId;
            $locked->reason = $reason;
            $locked->saveOrFail();
            return $locked;
        });
    }
}
