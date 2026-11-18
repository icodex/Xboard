<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\TrafficResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * reset:traffic runs every minute. A second pass in the same window must not
 * double-count users or double-log resets, while manual/order-triggered resets
 * must always clear traffic (buying a plan has to reset usage).
 */
class TrafficResetIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_cron_reset_with_advanced_time_is_noop(): void
    {
        $user = $this->makeUser();
        $service = app(TrafficResetService::class);

        $this->assertTrue($service->performReset($user->fresh(), TrafficResetLog::SOURCE_CRON));
        $this->assertFalse($service->performReset($user->fresh(), TrafficResetLog::SOURCE_CRON));

        $this->assertSame(1, (int) $user->fresh()->reset_count);
        $this->assertSame(1, TrafficResetLog::where('user_id', $user->id)->count());
    }

    public function test_cron_entrypoint_resets_a_due_user(): void
    {
        // checkAndReset 是 reset:traffic 的真实入口：它先过 shouldResetTraffic()，
        // 那里曾把 int 的 next_reset_at 当 Carbon 用（->timestamp），运行时报错
        $user = $this->makeUser();

        $this->assertTrue(app(TrafficResetService::class)->checkAndReset($user->fresh(), TrafficResetLog::SOURCE_CRON));

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->u);
        $this->assertSame(0, (int) $fresh->d);
        $this->assertSame(1, (int) $fresh->reset_count);
    }

    public function test_cron_entrypoint_skips_a_user_who_is_not_due(): void
    {
        $user = $this->makeUser();
        $user->update(['next_reset_at' => time() + 3600]);

        $this->assertFalse(app(TrafficResetService::class)->checkAndReset($user->fresh(), TrafficResetLog::SOURCE_CRON));
        $this->assertSame(1024, (int) $user->fresh()->u);
        $this->assertSame(0, TrafficResetLog::where('user_id', $user->id)->count());
    }

    public function test_manual_reset_ignores_advanced_time(): void
    {
        $user = $this->makeUser();
        $service = app(TrafficResetService::class);

        $this->assertTrue($service->performReset($user->fresh(), TrafficResetLog::SOURCE_ORDER));

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->u);
        $this->assertSame(0, (int) $fresh->d);
        $this->assertSame(1, TrafficResetLog::where('user_id', $user->id)->count());
    }

    public function test_cron_rechecks_cleared_reset_date_under_lock(): void
    {
        $stale = $this->makeUser()->fresh();
        User::whereKey($stale->id)->update(['next_reset_at' => null]);

        $this->assertFalse(app(TrafficResetService::class)->performReset($stale, TrafficResetLog::SOURCE_CRON));
        $this->assertSame(1024, (int) $stale->fresh()->u);
    }

    public function test_manual_reset_persists_metadata(): void
    {
        $user = $this->makeUser();
        $this->assertTrue(app(TrafficResetService::class)->manualReset($user, ['reason' => 'support', 'admin_id' => 42]));
        $this->assertSame(['reason' => 'support', 'admin_id' => 42], TrafficResetLog::where('user_id', $user->id)->firstOrFail()->metadata);
    }

    public function test_calendar_resets_clamp_dates_and_keep_configured_timezone(): void
    {
        config(['app.timezone' => 'Asia/Shanghai']);
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2025-02-01 10:00:00', 'Asia/Shanghai'));
        try {
            $user = $this->makeUser();
            $user->expired_at = \Carbon\Carbon::parse('2025-03-31 18:30:00', 'Asia/Shanghai')->timestamp;
            $user->plan->reset_traffic_method = Plan::RESET_TRAFFIC_MONTHLY;
            $service = app(TrafficResetService::class);
            $this->assertSame('2025-02-28 18:30:00', $service->calculateNextResetTime($user)->format('Y-m-d H:i:s'));

            $user->expired_at = \Carbon\Carbon::parse('2028-02-29 18:30:00', 'Asia/Shanghai')->timestamp;
            $user->plan->reset_traffic_method = Plan::RESET_TRAFFIC_YEARLY;
            $this->assertSame('2025-02-28 18:30:00', $service->calculateNextResetTime($user)->format('Y-m-d H:i:s'));

            \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2025-01-31 18:00:00', 'Asia/Shanghai'));
            $user->plan->reset_traffic_method = Plan::RESET_TRAFFIC_FIRST_DAY_MONTH;
            $this->assertSame('2025-02-01 00:00:00', $service->calculateNextResetTime($user)->format('Y-m-d H:i:s'));
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }

    public function test_global_policy_transitions_recalculate_inherited_users_only(): void
    {
        admin_setting(['reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
        $user = $this->makeUser();
        $plan = $user->plan;
        $plan->update(['reset_traffic_method' => null]);
        $request = \Mockery::mock(\App\Http\Requests\Admin\ConfigSave::class);
        $request->shouldReceive('validated')->andReturn(['reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER]);
        (new \App\Http\Controllers\V2\Admin\ConfigController())->save($request);
        app(TrafficResetService::class)->recalculatePendingSystemPolicy();
        $this->assertNull($user->fresh()->next_reset_at);

        $request = \Mockery::mock(\App\Http\Requests\Admin\ConfigSave::class);
        $request->shouldReceive('validated')->andReturn(['reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
        (new \App\Http\Controllers\V2\Admin\ConfigController())->save($request);
        app(TrafficResetService::class)->recalculatePendingSystemPolicy();
        $this->assertNotNull($user->fresh()->next_reset_at);

        $user->update(['next_reset_at' => 0]);
        (new \App\Http\Controllers\V2\Admin\ConfigController())->save($request);
        $this->assertSame(0, $user->fresh()->next_reset_at);
    }

    public function test_cron_recovers_an_interrupted_policy_sweep_before_processing_users(): void
    {
        $user = $this->makeUser();
        $user->plan->update(['reset_traffic_method' => null]);
        admin_setting(['reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER]);
        \App\Models\Setting::createOrUpdate('reset_traffic_policy_pending', 'interrupted');
        User::whereKey($user->id)->update(['next_reset_at' => time() - 60]);

        $this->assertFalse(app(TrafficResetService::class)->performReset($user, TrafficResetLog::SOURCE_CRON));
        $this->artisan('reset:traffic')->assertSuccessful();
        $this->assertNull($user->fresh()->next_reset_at);
        $this->assertSame(1024, (int) $user->fresh()->u);
        $this->assertSame('', \Illuminate\Support\Facades\DB::table('v2_settings')->where('name', 'reset_traffic_policy_pending')->value('value'));
    }

    public function test_force_clears_reset_date_for_never_policy(): void
    {
        $user = $this->makeUser();
        $user->plan->update(['reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER]);
        User::whereKey($user->id)->update(['next_reset_at' => time() + 3600]);

        $this->artisan('reset:traffic', ['--force' => true])->assertSuccessful();
        $this->assertNull($user->fresh()->next_reset_at);
    }

    public function test_no_expiry_monthly_plan_schedules_first_of_next_month(): void
    {
        $user = $this->makeUser();
        $user->update(['expired_at' => null]);

        $next = app(TrafficResetService::class)->calculateNextResetTime($user->fresh());

        $this->assertNotNull($next);
        $this->assertSame(1, $next->day);
        $this->assertSame(0, $next->hour);
    }

    public function test_no_expiry_user_is_reset_by_the_cron_entrypoint(): void
    {
        $user = $this->makeUser();
        $user->update(['expired_at' => null]);
        User::whereKey($user->id)->update(['next_reset_at' => time() - 60]);

        $this->assertTrue(app(TrafficResetService::class)->checkAndReset($user->fresh(), TrafficResetLog::SOURCE_CRON));

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->u);
        $next = \Carbon\Carbon::createFromTimestamp((int) $fresh->getRawOriginal('next_reset_at'), config('app.timezone'));
        $this->assertSame(1, $next->day);
    }

    private function makeUser(): User
    {
        $plan = Plan::create([
            'group_id' => null,
            'transfer_enable' => 107374182400,
            'name' => 'Reset Idempotency Plan',
            'show' => 1,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 4.10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'sell' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $user = User::create([
            'email' => 'reset-idempotency@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-00000000000a',
            'token' => '{{SECRET_4mv8kd2q}}',
            'plan_id' => $plan->id,
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 107374182400,
            'u' => 1024,
            'd' => 2048,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => time() + 30 * 86400,
            'next_reset_at' => time() - 60,
            'reset_count' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        // The observer updates a separate model instance, so bypass stale dirty tracking.
        User::whereKey($user->id)->update(['next_reset_at' => time() - 60]);

        return $user;
    }
}
