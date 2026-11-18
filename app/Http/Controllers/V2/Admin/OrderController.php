<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderAssign;
use App\Http\Requests\Admin\OrderUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{

    public function detail(Request $request)
    {
        $order = Order::with(['user', 'plan', 'commission_log', 'invite_user'])->find($request->input('id'));
        if (!$order)
            return $this->fail([400202, '订单不存在']);
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        $order['period'] = PlanService::getLegacyPeriod((string) $order->period);
        return $this->success($order);
    }

    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $this->perPage($request);
        $orderModel = Order::with('plan:id,name');

        if ($request->boolean('is_commission')) {
            $orderModel->whereNotNull('invite_user_id')
                ->whereNotIn('status', [0, 2])
                ->where('commission_balance', '>', 0);
        }

        $this->applyFiltersAndSorts($request, $orderModel);

        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginatedResults */
        $paginatedResults = $orderModel
            ->latest('created_at')
            ->paginate(
                perPage: $pageSize,
                page: $current
            );

        $paginatedResults->getCollection()->transform(function ($order) {
            $orderArray = $order->toArray();
            $orderArray['period'] = PlanService::getLegacyPeriod((string) $order->period);
            return $orderArray;
        });

        return $this->paginate($paginatedResults);
    }

    /**
     * filter[].id / sort[].id 会拼进 SQL 列位（值才是绑定的），请求原件不可直接入列，
     * 否则可用 `... ` 或子查询做盲注。白名单外的字段直接忽略。
     */
    private const FILTERABLE_COLUMNS = [
        'id', 'invite_user_id', 'user_id', 'plan_id', 'coupon_id', 'payment_id',
        'type', 'period', 'trade_no', 'callback_no', 'total_amount', 'handling_amount',
        'discount_amount', 'surplus_amount', 'surplus_credit', 'balance_amount',
        'surplus_order_ids', 'status', 'commission_status', 'commission_balance',
        'actual_commission_balance', 'paid_at', 'refunded_at', 'created_at', 'updated_at',
    ];

    private function applyFiltersAndSorts(Request $request, Builder $builder): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder);
    }

    private function applyFilters(Request $request, Builder $builder): void
    {
        if (!$request->has('filter')) {
            return;
        }

        $filters = $request->input('filter');
        if (!is_array($filters)) {
            return;
        }

        collect($filters)->each(function ($filter) use ($builder) {
            if (!is_array($filter)) {
                return;
            }
            $field = $filter['id'] ?? null;
            if (!in_array($field, self::FILTERABLE_COLUMNS, true)) {
                return;
            }
            $value = $filter['value'];

            $builder->where(function ($query) use ($field, $value) {
                $this->buildFilterQuery($query, $field, $value);
            });
        });
    }

    private function buildFilterQuery(Builder $query, string $field, mixed $value): void
    {
        // Handle array values for 'in' operations
        if (is_array($value)) {
            $query->whereIn($field, $value);
            return;
        }

        // Handle operator-based filtering
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($field, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);
        $operator = strtolower($operator);

        // 空值判定没有右侧操作数，必须先处理：闭包不能当 where() 的操作符传进去
        if ($operator === 'null') {
            $query->whereNull($field);
            return;
        }
        if ($operator === 'notnull') {
            $query->whereNotNull($field);
            return;
        }

        // Convert numeric strings to appropriate type
        if (is_numeric($filterValue)) {
            $filterValue = strpos($filterValue, '.') !== false
                ? (float) $filterValue
                : (int) $filterValue;
        }

        // Apply operator
        $query->where(
            $field,
            match ($operator) {
                'eq' => '=',
                'gt' => '>',
                'gte' => '>=',
                'lt' => '<',
                'lte' => '<=',
                'notlike' => 'not like',
                default => 'like',
            },
            in_array($operator, ['like', 'notlike'], true) ? "%{$filterValue}%" : $filterValue
        );
    }

    private function applySorting(Request $request, Builder $builder): void
    {
        if (!$request->has('sort')) {
            return;
        }

        $sorts = $request->input('sort');
        if (!is_array($sorts)) {
            return;
        }

        collect($sorts)->each(function ($sort) use ($builder) {
            if (!is_array($sort)) {
                return;
            }
            $field = $sort['id'] ?? null;
            if (!in_array($field, self::FILTERABLE_COLUMNS, true)) {
                return;
            }
            $direction = !empty($sort['desc']) ? 'DESC' : 'ASC';
            $builder->orderBy($field, $direction);
        });
    }

    public function paid(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }
        if ($order->status !== 0)
            return $this->fail([400, '只能对待支付的订单进行操作']);

        $orderService = new OrderService($order);
        if (!$orderService->paid('manual_operation')) {
            return $this->fail([500, '更新失败']);
        }
        return $this->success(true);
    }

    public function cancel(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }
        if ($order->status !== 0)
            return $this->fail([400, '只能对待支付的订单进行操作']);

        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return $this->fail([400, '更新失败']);
        }
        return $this->success(true);
    }

    /**
     * Reconcile a payment captured after the order was cancelled.
     * complete => retry provisioning; refund => record an out-of-band refund.
     */
    public function reconcile(Request $request)
    {
        $data = $request->validate([
            'trade_no' => 'required|string',
            'action' => 'required|in:complete,refund',
        ]);

        try {
            $order = DB::transaction(function () use ($data) {
                $order = Order::where('trade_no', $data['trade_no'])->lockForUpdate()->first();
                if (!$order) {
                    throw new \RuntimeException('订单不存在');
                }

                if ($data['action'] === 'refund') {
                    if ($order->refunded_at !== null) {
                        return $order;
                    }
                    if ($order->paid_at === null) {
                        throw new \RuntimeException('该订单没有迟到付款记录');
                    }
                    $order->refunded_at = time();
                    $order->saveOrFail();
                    return $order;
                }

                if ($order->refunded_at !== null) {
                    throw new \RuntimeException('该订单已标记退款，不能补单');
                }
                if ((int) $order->status === Order::STATUS_COMPLETED) {
                    return $order;
                }
                if ((int) $order->status === Order::STATUS_PROCESSING) {
                    return $order;
                }
                if ((int) $order->status !== Order::STATUS_CANCELLED || $order->paid_at === null) {
                    throw new \RuntimeException('只有已取消且已付款的订单可以补单');
                }

                $order->status = Order::STATUS_PROCESSING;
                $order->saveOrFail();
                DB::afterCommit(fn () => \App\Jobs\OrderHandleJob::dispatch($order->trade_no));
                return $order;
            });
        } catch (\RuntimeException $e) {
            return $this->fail([422, $e->getMessage()]);
        }

        return $this->success([
            'trade_no' => $order->trade_no,
            'status' => $order->status,
            'refunded_at' => $order->refunded_at,
        ]);
    }

    public function update(OrderUpdate $request)
    {
        $params = $request->only([
            'commission_status'
        ]);

        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }

        try {
            $order->update($params);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '更新失败']);
        }

        return $this->success(true);
    }

    public function assign(OrderAssign $request)
    {
        $plan = Plan::find($request->input('plan_id'));
        $user = User::byEmail($request->input('email'))->first();

        if (!$user) {
            return $this->fail([400202, '该用户不存在']);
        }

        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            return $this->fail([400, '该用户还有待支付的订单，无法分配']);
        }

        try {
            DB::beginTransaction();
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $period = $request->input('period');
            $order->period = PlanService::getPeriodKey((string) $period);
            $order->trade_no = Helper::guid();
            $order->total_amount = $request->input('total_amount');

            if (PlanService::getPeriodKey((string) $order->period) === Plan::PERIOD_RESET_TRAFFIC) {
                $order->type = Order::TYPE_RESET_TRAFFIC;
            } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id) {
                $order->type = Order::TYPE_UPGRADE;
            } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) {
                $order->type = Order::TYPE_RENEWAL;
            } else {
                $order->type = Order::TYPE_NEW_PURCHASE;
            }

            $orderService->setInvite($user);

            if (!$order->save()) {
                DB::rollBack();
                return $this->fail([500, '订单创建失败']);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->success($order->trade_no);
    }
}
