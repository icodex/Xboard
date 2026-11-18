<?php
namespace App\Services;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Server;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StatisticalService
{
    protected $startAt;
    protected $endAt;

    public function setStartAt($timestamp)
    {
        $this->startAt = $timestamp;
    }

    public function setEndAt($timestamp)
    {
        $this->endAt = $timestamp;
    }

    /**
     * 生成统计报表
     */
    public function generateStatData(): array
    {
        $startAt = $this->startAt;
        $endAt = $this->endAt;
        if (!$startAt || !$endAt) {
            $startAt = strtotime(date('Y-m-d'));
            $endAt = strtotime('+1 day', $startAt);
        }
        $data = [];
        $data['order_count'] = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();
        $data['order_total'] = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->sum('total_amount');
        $data['paid_count'] = Order::where('paid_at', '>=', $startAt)
            ->where('paid_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->count();
        $data['paid_total'] = Order::where('paid_at', '>=', $startAt)
            ->where('paid_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');
        $commissionLogBuilder = CommissionLog::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt);
        $data['commission_count'] = $commissionLogBuilder->count();
        $data['commission_total'] = $commissionLogBuilder->sum('get_amount');
        $data['register_count'] = User::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();
        $data['invite_count'] = User::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->whereNotNull('invite_user_id')
            ->count();
        $data['transfer_used_total'] = StatServer::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->select(DB::raw('SUM(u) + SUM(d) as total'))
            ->value('total') ?? 0;
        return $data;
    }

    public function getStatRecord($type)
    {
        switch ($type) {
            case "paid_total": {
                return Stat::select([
                    '*',
                    DB::raw('paid_total / 100 as paid_total')
                ])
                    ->where('record_at', '>=', $this->startAt)
                    ->where('record_at', '<', $this->endAt)
                    ->orderBy('record_at', 'ASC')
                    ->get();
            }
            case "commission_total": {
                return Stat::select([
                    '*',
                    DB::raw('commission_total / 100 as commission_total')
                ])
                    ->where('record_at', '>=', $this->startAt)
                    ->where('record_at', '<', $this->endAt)
                    ->orderBy('record_at', 'ASC')
                    ->get();
            }
            case "register_count": {
                return Stat::where('record_at', '>=', $this->startAt)
                    ->where('record_at', '<', $this->endAt)
                    ->orderBy('record_at', 'ASC')
                    ->get();
            }
            // 未知 type 返回空集合，避免接口给出 {"data":null} 让前端图表崩掉
            default: {
                return collect();
            }
        }
    }

    /**
     * 获取指定日期范围内的节点流量排行
     * @param mixed ...$times 可选值：'today', 'tomorrow', 'last_week'，或指定日期范围，格式：timestamp
     * @return array
     */

    public static function getServerRank(...$times)
    {
        $startAt = 0;
        $endAt = Carbon::tomorrow()->endOfDay()->timestamp;

        if (count($times) == 1) {
            switch ($times[0]) {
                case 'today':
                    $startAt = Carbon::today()->startOfDay()->timestamp;
                    $endAt = Carbon::today()->endOfDay()->timestamp;
                    break;
                case 'yesterday':
                    $startAt = Carbon::yesterday()->startOfDay()->timestamp;
                    $endAt = Carbon::yesterday()->endOfDay()->timestamp;
                    break;
                case 'last_week':
                    $startAt = Carbon::now()->subWeek()->startOfWeek()->timestamp;
                    $endAt = Carbon::now()->endOfDay()->timestamp;
                    break;
            }
        } else if (count($times) == 2) {
            $startAt = $times[0];
            $endAt = $times[1];
        }

        $statistics = Server::whereHas(
            'stats',
            function ($query) use ($startAt, $endAt) {
                $query->where('record_at', '>=', $startAt)
                    ->where('record_at', '<', $endAt)
                    ->where('record_type', 'd');
            }
        )
            // 求和必须和 whereHas 用同一个区间：无约束的 withSum 会把历史全量算进来，
            // 「今日排行」实际显示的是累计流量
            ->withSum(['stats as u' => fn($query) => $query->where('record_at', '>=', $startAt)->where('record_at', '<', $endAt)], 'u')
            ->withSum(['stats as d' => fn($query) => $query->where('record_at', '>=', $startAt)->where('record_at', '<', $endAt)], 'd')
            ->get()
            ->map(function ($item) {
                return [
                    'server_name' => optional($item->parent)->name ?? $item->name,
                    'server_id' => $item->id,
                    'server_type' => $item->type,
                    'u' => (int) $item->u,
                    'd' => (int) $item->d,
                    'total' => (int) $item->u + (int) $item->d,
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->toArray();
        return $statistics;
    }
}
