<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Order;
use App\Models\User;
use App\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;

class PlanService
{
    public Plan $plan;

    public function __construct(Plan $plan)
    {
        $this->plan = $plan;
    }

    /**
     * 获取所有可销售的订阅计划列表
     * 条件：show 和 sell 为 true，且容量充足
     *
     * @return Collection
     */
    public function getAvailablePlans(?User $user = null): Collection
    {
        $query = Plan::query();

        if ($user && $user->plan_id) {
            // A hidden or sold-out plan may still be renewed by its current active
            // subscriber; validatePurchase() already allows that. Keep the list in
            // sync instead of hiding the only renewal entry.
            $query->where(function ($q) use ($user) {
                $q->where(function ($q) {
                    $q->where('show', true)->where('sell', true);
                })->orWhere(function ($q) use ($user) {
                    $q->where('id', $user->plan_id)->where('renew', true);
                });
            });
        } else {
            $query->where('show', true)->where('sell', true);
        }

        $plans = $query->orderBy('sort')->get();

        $activeCounts = User::whereIn('plan_id', $plans->pluck('id'))
            ->active()
            ->groupBy('plan_id')
            ->selectRaw('plan_id, count(*) as aggregate')
            ->pluck('aggregate', 'plan_id');

        // Pending and processing orders reserve one slot per user unless their active subscription already occupies it.
        $reservedCounts = Order::whereIn('plan_id', $plans->pluck('id'))
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('v2_user')
                    ->whereColumn('v2_user.id', 'v2_order.user_id')
                    ->whereColumn('v2_user.plan_id', 'v2_order.plan_id')
                    ->where(function ($q) {
                        $q->whereNull('v2_user.expired_at')
                            ->orWhere('v2_user.expired_at', '>', time());
                    });
            })
            ->groupBy('plan_id')
            ->selectRaw('plan_id, count(distinct user_id) as aggregate')
            ->pluck('aggregate', 'plan_id');

        return $plans->filter(function (Plan $plan) use ($user, $activeCounts, $reservedCounts) {
            if ($user
                && (int) $user->plan_id === (int) $plan->id
                && $plan->renew
                && $user->isActive()) {
                return true;
            }

            if (!$plan->show || !$plan->sell) {
                return false;
            }

            if ($plan->capacity_limit === null) {
                return true;
            }
            return ($plan->capacity_limit - (int) ($activeCounts[$plan->id] ?? 0) - (int) ($reservedCounts[$plan->id] ?? 0)) > 0;
        });
    }

    /**
     * 获取指定订阅计划的可用状态
     * 条件：renew 和 sell 为 true
     *
     * @param int $planId
     * @return Plan|null
     */
    public function getAvailablePlan(int $planId): ?Plan
    {
        return Plan::where('id', $planId)
            ->where('sell', true)
            ->where('renew', true)
            ->first();
    }

    /**
     * 检查指定计划是否可用于指定用户
     * 
     * @param Plan $plan
     * @param User $user
     * @return bool
     */
    public function isPlanAvailableForUser(Plan $plan, User $user): bool
    {
        // 如果是续费
        if ($user->plan_id === $plan->id) {
            return $plan->renew;
        }

        // 如果是新购
        return $plan->show && $plan->sell && $this->hasCapacity($plan);
    }

    public function validatePurchase(User $user, string $period): void
    {
        if (!$this->plan) {
            throw new ApiException(__('Subscription plan does not exist'));
        }

        // 转换周期格式为新版格式
        $periodKey = self::getPeriodKey($period);
        $price = $this->plan->prices[$periodKey] ?? null;

        if ($price === null) {
            throw new ApiException(__('This payment period cannot be purchased, please choose another period'));
        }

        if ($periodKey === Plan::PERIOD_RESET_TRAFFIC) {
            $this->validateResetTrafficPurchase($user);
            return;
        }

        $isActiveRenewal = $user->plan_id === $this->plan->id
            && ($user->expired_at === null || $user->expired_at > time());
        if (!$isActiveRenewal && !$this->hasCapacity($this->plan)) {
            throw new ApiException(__('Current product is sold out'));
        }

        $this->validatePlanAvailability($user);
    }

    /**
     * 智能转换周期格式为新版格式
     * 如果是新版格式直接返回，如果是旧版格式则转换为新版格式
     *
     * @param string $period
     * @return string
     */
    public static function getPeriodKey(string $period): string
    {
        // 如果是新版格式直接返回
        if (in_array($period, self::getNewPeriods())) {
            return $period;
        }

        // 如果是旧版格式则转换为新版格式
        return Plan::LEGACY_PERIOD_MAPPING[$period] ?? $period;
    }
    /**
     * 只能转换周期格式为旧版本
     */
    public static function convertToLegacyPeriod(string $period): string
    {
        $flippedMapping = array_flip(Plan::LEGACY_PERIOD_MAPPING);
        return $flippedMapping[$period] ?? $period;
    }

    /**
     * 获取所有支持的新版周期格式
     *
     * @return array
     */
    public static function getNewPeriods(): array
    {
        return array_values(Plan::LEGACY_PERIOD_MAPPING);
    }

    /**
     * 获取旧版周期格式
     *
     * @param string $period
     * @return string
     */
    public static function getLegacyPeriod(string $period): string
    {
        $flipped = array_flip(Plan::LEGACY_PERIOD_MAPPING);
        return $flipped[$period] ?? $period;
    }

    protected function validateResetTrafficPurchase(User $user): void
    {
        if (!app(UserService::class)->isAvailable($user) || $this->plan->id !== $user->plan_id) {
            throw new ApiException(__('Subscription has expired or no active subscription, unable to purchase Data Reset Package'));
        }
    }

    protected function validatePlanAvailability(User $user): void
    {
        $isActiveRenewal = $user->plan_id === $this->plan->id
            && ($user->expired_at === null || $user->expired_at > time());
        if (!$this->plan->sell && !$isActiveRenewal) {
            throw new ApiException(__('This subscription has been sold out, please choose another subscription'));
        }

        if ((!$this->plan->show && !$this->plan->renew) || (!$this->plan->show && $user->plan_id !== $this->plan->id)) {
            throw new ApiException(__('This subscription has been sold out, please choose another subscription'));
        }

        if (!$this->plan->renew && $isActiveRenewal) {
            throw new ApiException(__('This subscription cannot be renewed, please change to another subscription'));
        }

        if (!$this->plan->show && $this->plan->renew && !app(UserService::class)->isAvailable($user)) {
            throw new ApiException(__('This subscription has expired, please change to another subscription'));
        }
    }

    public function hasCapacity(Plan $plan): bool
    {
        if ($plan->capacity_limit === null) {
            return true;
        }

        $activeUserCount = User::where('plan_id', $plan->id)
            ->active()
            ->count();

        // Cancelled orders release their slot; completed orders are counted through their active users.
        $reservedCount = Order::where('plan_id', $plan->id)
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('v2_user')
                    ->whereColumn('v2_user.id', 'v2_order.user_id')
                    ->whereColumn('v2_user.plan_id', 'v2_order.plan_id')
                    ->where(function ($q) {
                        $q->whereNull('v2_user.expired_at')
                            ->orWhere('v2_user.expired_at', '>', time());
                    });
            })->distinct()->count('user_id');

        return ($plan->capacity_limit - $activeUserCount - $reservedCount) > 0;
    }

    public function getAvailablePeriods(Plan $plan): array
    {
        return array_filter(
            $plan->getActivePeriods(),
            fn($period) => isset($plan->prices[$period]) && $plan->prices[$period] > 0
        );
    }

    public function canResetTraffic(Plan $plan): bool
    {
        return $plan->reset_traffic_method !== Plan::RESET_TRAFFIC_NEVER
            && $plan->getResetTrafficPrice() > 0;
    }
}
