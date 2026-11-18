<?php

namespace App\Console\Commands;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:order';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '订单检查任务';

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
        // 卡在 PROCESSING 的订单（套餐被删、插件 hook 一直抛）如果无限重试，
        // 每分钟都会给队列塞一个必然失败的 job。超过重试窗口只报警不回滚：
        // 已付款订单不能自动置为取消，需要人工处理退款/补单。
        $retryWindow = 3600 * 24;
        $stuckQuery = Order::where('status', Order::STATUS_PROCESSING)
            ->where(function ($query) use ($retryWindow) {
                $query->whereNull('paid_at')->orWhere('paid_at', '<', time() - $retryWindow);
            });

        $stuckCount = $stuckQuery->count();
        if ($stuckCount > 0) {
            Log::error('订单长时间停留在开通中，需人工处理', [
                'count' => $stuckCount,
                'trade_nos' => $stuckQuery->limit(50)->pluck('trade_no')->all(),
            ]);
            $this->error("{$stuckCount} 个订单停留在开通中超过 24 小时，已停止自动重试，请人工处理");
        }

        Order::whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->where(function ($query) use ($retryWindow) {
                $query->where(function ($q) use ($retryWindow) {
                    $q->where('status', Order::STATUS_PROCESSING)
                        ->where('paid_at', '>', time() - $retryWindow);
                })->orWhere(function ($q) {
                    $q->where('status', Order::STATUS_PENDING)
                        ->where('created_at', '<=', time() - 3600 * 2);
                });
            })
            ->orderBy('created_at', 'ASC')
            ->lazyById(200)
            ->each(function ($order) {
                OrderHandleJob::dispatch($order->trade_no);
            });
    }
}
