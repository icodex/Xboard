<?php

namespace App\Services;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TrafficResetService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Hash;

class UserService
{
    /**
     * Get the remaining days until the next traffic reset for a user.
     * This method reuses the TrafficResetService logic for consistency.
     */
    public function getResetDay(User $user): ?int
    {
        // Use TrafficResetService to calculate the next reset time
        $trafficResetService = app(TrafficResetService::class);
        $nextResetTime = $trafficResetService->calculateNextResetTime($user);

        if (!$nextResetTime) {
            return null;
        }

        // Calculate the remaining days from now to the next reset time
        $now = time();
        $resetTimestamp = $nextResetTime->timestamp;

        if ($resetTimestamp <= $now) {
            return 0; // Reset time has passed or is now
        }

        // Calculate the difference in days (rounded up)
        $daysDifference = ceil(($resetTimestamp - $now) / 86400);

        return (int) $daysDifference;
    }

    public function isAvailable(User $user)
    {
        if (!$user->banned && $user->transfer_enable && ($user->expired_at > time() || $user->expired_at === NULL || $user->expired_at === 0)) {
            return true;
        }
        return false;
    }

    public function getUsersByIds($ids)
    {
        return User::whereIn('id', $ids)->get();
    }

    public function addBalance(int $userId, int $balance): bool
    {
        $user = User::lockForUpdate()->find($userId);
        if (!$user) {
            return false;
        }
        $user->balance = $user->balance + $balance;
        if ($user->balance < 0) {
            return false;
        }
        if (!$user->save()) {
            return false;
        }
        return true;
    }

    public function isNotCompleteOrderByUserId(int $userId): bool
    {
        $order = Order::whereIn('status', [0, 1])
            ->where('user_id', $userId)
            ->first();
        if (!$order) {
            return false;
        }
        return true;
    }

    public function trafficFetch(Server $server, string $protocol, array $data)
    {
        // A node may only report traffic for users it is allowed to serve. Every node
        // shares one token, so without this filter any single compromised node could
        // inflate the usage of an arbitrary user id and get them cut off by
        // check:traffic-exceeded.
        $data = $this->onlyUsersOfNode($server, $data);
        if (empty($data)) {
            return;
        }

        $server->rate = $server->getCurrentRate();
        $server = $server->toArray();

        list($server, $protocol, $data) = HookManager::filter('traffic.process.before', [$server, $protocol, $data]);
        // Compatible with legacy hook
        list($server, $protocol, $data) = HookManager::filter('traffic.before_process', [$server, $protocol, $data]);

        $timestamp = strtotime(date('Y-m-d'));
        collect($data)->chunk(1000)->each(function ($chunk) use ($timestamp, $server, $protocol) {
            TrafficFetchJob::dispatch($server, $chunk->toArray(), $protocol, $timestamp);
            StatUserJob::dispatch($server, $chunk->toArray(), $protocol);
            StatServerJob::dispatch($server, $chunk->toArray(), $protocol);
        });
    }

    /**
     * Keep only the reported entries whose user belongs to one of this node's groups.
     * A node with no groups serves nobody (getAvailableUsers returns an empty set),
     * so nothing is accepted for it either.
     *
     * @param array<int|string, array{0:int|float,1:int|float}> $data
     * @return array<int|string, array{0:int|float,1:int|float}>
     */
    private function onlyUsersOfNode(Server $server, array $data): array
    {
        $groupIds = array_values(array_filter(array_map('intval', (array) ($server->group_ids ?? []))));
        if (empty($groupIds) || empty($data)) {
            return [];
        }

        $allowed = [];
        foreach (array_chunk(array_map('intval', array_keys($data)), 500) as $ids) {
            foreach (User::query()->whereIn('id', $ids)->whereIn('group_id', $groupIds)->pluck('id') as $id) {
                $allowed[(int) $id] = true;
            }
        }

        return array_intersect_key($data, $allowed);
    }

    /**
     * 创建用户
     */
    public function createUser(array $data): User
    {
        $user = new User();

        // 基本信息
        $user->email = $data['email'];
        $user->password = Hash::make($data['password'] ?? $user->email);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();

        // 默认设置
        $user->remind_expire = admin_setting('default_remind_expire', 1);
        $user->remind_traffic = admin_setting('default_remind_traffic', 1);
        $user->expired_at = null;

        // 可选字段
        $this->setOptionalFields($user, $data);

        // 处理计划
        if (isset($data['plan_id'])) {
            $this->setPlanForUser($user, $data['plan_id'], $data['expired_at'] ?? null);
        } else {
            $this->setTryOutPlan(user: $user);
        }

        return $user;
    }

    /**
     * 设置可选字段
     */
    private function setOptionalFields(User $user, array $data): void
    {
        $optionalFields = [
            'invite_user_id',
            'telegram_id',
            'group_id',
            'speed_limit',
            'expired_at',
            'transfer_enable'
        ];

        foreach ($optionalFields as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field];
            }
        }
    }

    /**
     * 为用户设置计划
     */
    private function setPlanForUser(User $user, int $planId, ?int $expiredAt = null): void
    {
        $plan = Plan::find($planId);
        if (!$plan)
            return;

        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->transfer_enable = $plan->transfer_enable * 1073741824;
        $user->speed_limit = $plan->speed_limit;

        if ($expiredAt) {
            $user->expired_at = $expiredAt;
        }
    }

    /**
     * 为用户分配一个新套餐或续费现有套餐
     *
     * @param User $user 用户模型
     * @param Plan $plan 套餐模型
     * @param int $validityDays 购买天数
     * @return User 更新后的用户模型
     */
    public function assignPlan(User $user, Plan $plan, int $validityDays): User
    {
        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->transfer_enable = $plan->transfer_enable * 1073741824;
        $user->speed_limit = $plan->speed_limit;
        $user->device_limit = $plan->device_limit;

        if ($validityDays > 0) {
            $user = $this->extendSubscription($user, $validityDays);
        }

        $user->save();
        return $user;
    }

    /**
     * 延长用户的订阅有效期
     *
     * @param User $user 用户模型
     * @param int $days 延长天数
     * @return User 更新后的用户模型
     */
    public function extendSubscription(User $user, int $days): User
    {
        $currentExpired = $user->expired_at ?? time();
        $user->expired_at = max($currentExpired, time()) + ($days * 86400);

        return $user;
    }

    /**
     * 设置试用计划
     */
    private function setTryOutPlan(User $user): void
    {
        if (!(int) admin_setting('try_out_plan_id', 0))
            return;

        $plan = Plan::find(admin_setting('try_out_plan_id'));
        if (!$plan)
            return;

        $user->transfer_enable = $plan->transfer_enable * 1073741824;
        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->expired_at = time() + (admin_setting('try_out_hour', 1) * 3600);
        $user->speed_limit = $plan->speed_limit;
    }
}
