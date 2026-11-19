<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CheckCommission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:commission';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '返佣服务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->autoCheck();
        $this->autoPayCommission();
    }

    public function autoCheck()
    {
        if ((int)admin_setting('commission_auto_check_enable', 1)) {
            Order::where('commission_status', 0)
                ->where('invite_user_id', '!=', NULL)
                ->where('status', 3)
                ->where('updated_at', '<=', strtotime('-3 day', time()))
                ->update([
                    'commission_status' => 1
                ]);
        }
    }

    public function autoPayCommission()
    {
        // 分块处理：一次性 get() 会在订单量大时把整批订单模型读进内存
        Order::where('commission_status', 1)
            ->where('invite_user_id', '!=', NULL)
            ->chunkById(500, function ($orders) {
                foreach ($orders as $order) {
                    // Never pay the same order twice. An admin can flip
                    // commission_status back to 1 from the order screen, which used to
                    // run payHandle() again and double the payout.
                    if ((int) $order->actual_commission_balance > 0) {
                        $order->commission_status = 2;
                        $order->save();
                        continue;
                    }

                    try {
                        DB::beginTransaction();
                        if (!$this->payHandle($order->invite_user_id, $order)) {
                            DB::rollBack();
                            continue;
                        }
                        $order->commission_status = 2;
                        if (!$order->save()) {
                            DB::rollBack();
                            continue;
                        }
                        DB::commit();
                    } catch (\Exception $e) {
                        DB::rollBack();
                        throw $e;
                    }
                }
            });
    }

    public function payHandle($inviteUserId, Order $order)
    {
        // A paid order must never be paid again by any caller.
        if ((int) $order->actual_commission_balance > 0) {
            return false;
        }

        $level = 3;
        if ((int)admin_setting('commission_distribution_enable', 0)) {
            $commissionShareLevels = [
                0 => (int)admin_setting('commission_distribution_l1'),
                1 => (int)admin_setting('commission_distribution_l2'),
                2 => (int)admin_setting('commission_distribution_l3')
            ];
        } else {
            $commissionShareLevels = [
                0 => 100
            ];
        }
        for ($l = 0; $l < $level; $l++) {
            $inviter = User::find($inviteUserId);
            // 邀请链断了就没必要继续往上找：下面每层用的还是同一个 id
            if (!$inviter) break;

            // 先推进到上一级，再判断本层发不发钱：份额为 0（未配置 / 取整为 0）
            // 只表示“这一层不发”，不能让下一层的钱继续发给同一级邀请人。
            $inviteUserId = $inviter->invite_user_id;

            if (!isset($commissionShareLevels[$l])) continue;
            $commissionBalance = (int) round($order->commission_balance * ($commissionShareLevels[$l] / 100));
            if (!$commissionBalance) continue;
            if ((int)admin_setting('withdraw_close_enable', 0)) {
                $inviter->increment('balance', $commissionBalance);
            } else {
                $inviter->increment('commission_balance', $commissionBalance);
            }
            CommissionLog::create([
                // $inviteUserId 已推进到上一级，收款人是刚取出的 $inviter
                'invite_user_id' => $inviter->id,
                'user_id' => $order->user_id,
                'trade_no' => $order->trade_no,
                'order_amount' => $order->total_amount,
                'get_amount' => $commissionBalance
            ]);
            // update order actual commission balance
            $order->actual_commission_balance = $order->actual_commission_balance + $commissionBalance;
        }
        return true;
    }

}
