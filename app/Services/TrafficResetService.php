<?php

namespace App\Services;

use App\Models\User;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

/**
 * Service for handling traffic reset.
 */
class TrafficResetService
{
  /**
   * Check if a user's traffic should be reset and perform the reset.
   */
  public function checkAndReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_AUTO): bool
  {
    if (!$user->shouldResetTraffic()) {
      return false;
    }

    return $this->performReset($user, $triggerSource);
  }

  /** Resume an interrupted policy change; each claim fences older sweepers. */
  public function recalculatePendingSystemPolicy(): void
  {
    $token = DB::transaction(function () {
      $pending = DB::table('v2_settings')->where('name', 'reset_traffic_policy_pending')->lockForUpdate()->value('value');
      if (!$pending) {
        return null;
      }
      $token = bin2hex(random_bytes(16));
      DB::table('v2_settings')->where('name', 'reset_traffic_policy_pending')->update(['value' => $token]);
      return $token;
    });
    if (!$token) {
      return;
    }

    // The marker lock serializes each update against policy changes and cron resets.
    User::whereHas('plan', fn ($query) => $query->whereNull('reset_traffic_method'))
      ->chunkById(500, function ($users) use ($token) {
        foreach ($users as $user) {
          $current = DB::transaction(function () use ($user, $token) {
            $pending = DB::table('v2_settings')->where('name', 'reset_traffic_policy_pending')->lockForUpdate()->value('value');
            if ($pending !== $token) {
              return false;
            }
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            if ($locked && $locked->plan && $locked->plan->reset_traffic_method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
              $method = (int) (DB::table('v2_settings')->where('name', 'reset_traffic_method')->value('value')
                ?? config('v2board.reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY));
              $next = $this->calculateNextResetTime($locked, $method);
              $locked->update(['next_reset_at' => $next?->timestamp]);
            }
            return true;
          });
          if (!$current) {
            return false;
          }
        }
      });

    DB::table('v2_settings')->where('name', 'reset_traffic_policy_pending')
      ->where('value', $token)->update(['value' => '']);
  }

  /** Perform the traffic reset for a user. */
  public function performReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_MANUAL, array $metadata = []): bool
  {
    try {
      if ($triggerSource === TrafficResetLog::SOURCE_CRON) {
        DB::table('v2_settings')->insertOrIgnore([
          'name' => 'reset_traffic_policy_pending', 'value' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
      }
      return DB::transaction(function () use ($user, $triggerSource, $metadata) {
        if ($triggerSource === TrafficResetLog::SOURCE_CRON && DB::table('v2_settings')
          ->where('name', 'reset_traffic_policy_pending')->lockForUpdate()->value('value')) {
          return false;
        }
        $locked = User::whereKey($user->id)->lockForUpdate()->first();
        if (!$locked) {
          return false;
        }
        if ($triggerSource === TrafficResetLog::SOURCE_CRON) {
          $systemMethod = (int) (\App\Models\Setting::where('name', 'reset_traffic_method')->value('value')
            ?? config('v2board.reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY));
          if (!$locked->shouldResetTraffic() || !$locked->plan_id || !$this->calculateNextResetTime($locked, $systemMethod)) {
            return false;
          }
        }
        $user = $locked;

        $oldUpload = $user->u ?? 0;
        $oldDownload = $user->d ?? 0;
        $oldTotal = $oldUpload + $oldDownload;

        $nextResetTime = $this->calculateNextResetTime($user, $systemMethod ?? null);

        $user->update([
          'u' => 0,
          'd' => 0,
          'last_reset_at' => time(),
          'reset_count' => $user->reset_count + 1,
          'next_reset_at' => $nextResetTime ? $nextResetTime->timestamp : null,
        ]);

        $this->recordResetLog($user, [
          'reset_type' => $this->getResetTypeFromPlan($user->plan),
          'trigger_source' => $triggerSource,
          'old_upload' => $oldUpload,
          'old_download' => $oldDownload,
          'old_total' => $oldTotal,
          'new_upload' => 0,
          'new_download' => 0,
          'new_total' => 0,
          'metadata' => $metadata ?: null,
        ]);

        HookManager::call('traffic.reset.after', $user);
        return true;
      });
    } catch (\Exception $e) {
      Log::error(__('traffic_reset.reset_failed'), [
        'user_id' => $user->id,
        'email' => $user->email,
        'error' => $e->getMessage(),
        'trigger_source' => $triggerSource,
      ]);

      return false;
    }
  }

  /**
   * Calculate the next traffic reset time for a user.
   */
  public function calculateNextResetTime(User $user, ?int $systemResetMethod = null): ?Carbon
  {
    if (
      !$user->plan
      || $user->plan->reset_traffic_method === Plan::RESET_TRAFFIC_NEVER
      || ($user->plan->reset_traffic_method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM
        && ($systemResetMethod ?? (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY)) === Plan::RESET_TRAFFIC_NEVER)
    ) {
      return null;
    }

    $resetMethod = $user->plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = $systemResetMethod ?? (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    $now = Carbon::now(config('app.timezone'));

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => $this->getNextMonthFirstDay($now),
      Plan::RESET_TRAFFIC_MONTHLY => $this->getNextMonthlyReset($user, $now),
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => $this->getNextYearFirstDay($now),
      Plan::RESET_TRAFFIC_YEARLY => $this->getNextYearlyReset($user, $now),
      default => null,
    };
  }

  /**
   * Get the first day of the next month.
   */
  private function getNextMonthFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->startOfMonth()->addMonth();
  }

  /**
   * Get the next monthly reset time based on the user's expiration date.
   *
   * Logic:
   * 1. If the user has no expiration date, reset on the 1st of each month.
   * 2. If the user has an expiration date, use the day of that date as the monthly reset day.
   * 3. Prioritize the reset day in the current month if it has not passed yet.
   * 4. Handle cases where the day does not exist in a month (e.g., 31st in February).
   */
  private function getNextMonthlyReset(User $user, Carbon $from): Carbon
  {
    $expiredAt = $user->expired_at
      ? Carbon::createFromTimestamp((int) $user->expired_at, config('app.timezone'))
      : null;
    $resetDay = $expiredAt ? $expiredAt->day : 1;
    $resetTime = $expiredAt
      ? [$expiredAt->hour, $expiredAt->minute, $expiredAt->second]
      : [0, 0, 0];

    $month = $from->copy()->startOfMonth();
    $target = $month->copy()->day(min($resetDay, $month->daysInMonth))->setTime(...$resetTime);
    if ($target->greaterThan($from)) {
      return $target;
    }

    $month->addMonth();
    return $month->day(min($resetDay, $month->daysInMonth))->setTime(...$resetTime);
  }

  /**
   * Get the first day of the next year.
   */
  private function getNextYearFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->startOfYear()->addYear();
  }

  /**
   * Get the next yearly reset time based on the user's expiration date.
   *
   * Logic:
   * 1. If the user has no expiration date, reset on January 1st of each year.
   * 2. If the user has an expiration date, use the month and day of that date as the yearly reset date.
   * 3. Prioritize the reset date in the current year if it has not passed yet.
   * 4. Handle the case of February 29th in a leap year.
   */
  private function getNextYearlyReset(User $user, Carbon $from): Carbon
  {
    $expiredAt = $user->expired_at
      ? Carbon::createFromTimestamp((int) $user->expired_at, config('app.timezone'))
      : null;
    $resetMonth = $expiredAt ? $expiredAt->month : 1;
    $resetDay = $expiredAt ? $expiredAt->day : 1;
    $resetTime = $expiredAt
      ? [$expiredAt->hour, $expiredAt->minute, $expiredAt->second]
      : [0, 0, 0];

    $month = $from->copy()->startOfYear()->month($resetMonth);
    $target = $month->copy()->day(min($resetDay, $month->daysInMonth))->setTime(...$resetTime);
    if ($target->greaterThan($from)) {
      return $target;
    }

    $month->addYearNoOverflow();
    return $month->day(min($resetDay, $month->daysInMonth))->setTime(...$resetTime);
  }


  /**
   * Record the traffic reset log.
   */
  private function recordResetLog(User $user, array $data): void
  {
    TrafficResetLog::create([
      'user_id' => $user->id,
      'reset_type' => $data['reset_type'],
      'reset_time' => now(),
      'old_upload' => $data['old_upload'],
      'old_download' => $data['old_download'],
      'old_total' => $data['old_total'],
      'new_upload' => $data['new_upload'],
      'new_download' => $data['new_download'],
      'new_total' => $data['new_total'],
      'trigger_source' => $data['trigger_source'],
      'metadata' => $data['metadata'] ?? null,
    ]);
  }

  /**
   * Get the reset type from the user's plan.
   */
  private function getResetTypeFromPlan(?Plan $plan): string
  {
    if (!$plan) {
      return TrafficResetLog::TYPE_MANUAL;
    }

    $resetMethod = $plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => TrafficResetLog::TYPE_FIRST_DAY_MONTH,
      Plan::RESET_TRAFFIC_MONTHLY => TrafficResetLog::TYPE_MONTHLY,
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => TrafficResetLog::TYPE_FIRST_DAY_YEAR,
      Plan::RESET_TRAFFIC_YEARLY => TrafficResetLog::TYPE_YEARLY,
      Plan::RESET_TRAFFIC_NEVER => TrafficResetLog::TYPE_MANUAL,
      default => TrafficResetLog::TYPE_MANUAL,
    };
  }

  /**
   * Get the user's traffic reset history.
   */
  public function getUserResetHistory(User $user, int $limit = 10): \Illuminate\Database\Eloquent\Collection
  {
    return $user->trafficResetLogs()
      ->orderBy('reset_time', 'desc')
      ->limit($limit)
      ->get();
  }

  /**
   * Check if the user is eligible for traffic reset.
   */
  public function canReset(User $user): bool
  {
    return $user->isActive() && $user->plan !== null;
  }

  /**
   * Manually reset a user's traffic (Admin function).
   */
  public function manualReset(User $user, array $metadata = []): bool
  {
    if (!$this->canReset($user)) {
      return false;
    }

    return $this->performReset($user, TrafficResetLog::SOURCE_MANUAL, $metadata);
  }
}