<?php

namespace App\Observers;

use App\Jobs\NodeUserSyncJob;
use App\Models\User;
use App\Services\TrafficResetService;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserObserver
{
  public function __construct(
    private readonly TrafficResetService $trafficResetService
  ) {
  }

  public function updated(User $user): void
  {
    $syncFields = ['group_id', 'uuid', 'speed_limit', 'device_limit', 'banned', 'expired_at', 'transfer_enable', 'u', 'd', 'plan_id'];
    $needsSync = $user->wasChanged($syncFields);
    $oldGroupId = $user->wasChanged('group_id') ? $user->getOriginal('group_id') : null;
    $recalculate = $user->wasChanged(['plan_id', 'expired_at']);
    $userId = $user->id;

    DB::afterCommit(function () use ($userId, $oldGroupId, $needsSync, $recalculate) {
      if ($recalculate && ($current = User::find($userId))) {
        $this->recalculateNextResetAt($current);
      }
      if ($needsSync) {
        $this->bumpNodeUserCacheVersion();
        NodeUserSyncJob::dispatch($userId, 'updated', $oldGroupId);
      }
    });
  }

  public function created(User $user): void
  {
    $userId = $user->id;
    $hasPlan = (bool) $user->plan_id;
    DB::afterCommit(function () use ($userId, $hasPlan) {
      $this->bumpNodeUserCacheVersion();
      if ($hasPlan && ($current = User::find($userId))) {
        $this->recalculateNextResetAt($current);
      }
      NodeUserSyncJob::dispatch($userId, 'created');
    });
  }

  public function deleted(User $user): void
  {
    $userId = $user->id;
    $groupId = $user->group_id;
    DB::afterCommit(function () use ($userId, $groupId) {
      $this->bumpNodeUserCacheVersion();
      if ($groupId) {
        NodeUserSyncJob::dispatch($userId, 'deleted', $groupId);
      }
    });
  }

  /**
   * 节点用户名单缓存按版本号分代，任何影响名单的变化都要递增版本号
   */
  private function bumpNodeUserCacheVersion(): void
  {
    $key = CacheKey::get('SERVER_USERS_VERSION');
    if (Cache::increment($key) === false) {
      Cache::forever($key, 1);
    }
  }

  /**
   * 根据当前用户状态重新计算 next_reset_at
   */
  private function recalculateNextResetAt(User $user): void
  {
    $user->refresh();
    User::withoutEvents(function () use ($user) {
      $nextResetTime = $this->trafficResetService->calculateNextResetTime($user);
      $nextResetAt = $nextResetTime?->timestamp;
      $current = $user->getRawOriginal('next_reset_at');
      // 值没变就不写：批量建号/批量改字段时每条都多一次 UPDATE
      if (($current === null ? null : (int) $current) === $nextResetAt) {
        return;
      }
      $user->next_reset_at = $nextResetAt;
      $user->save();
    });
  }
}