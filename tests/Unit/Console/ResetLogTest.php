<?php

namespace Tests\Unit\Console;

use App\Models\MailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * reset:log 只负责统计表 + 发信日志；审计日志归 cleanup:audit-log 管。
 * 两条规则同时删一张表时，谁先跑谁生效，配置等于摆设。
 */
class ResetLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prunes_old_mail_logs_and_keeps_recent_ones(): void
    {
        $old = time() - 40 * 86400;
        $fresh = time() - 3600;

        $this->insertMailLog('old@example.com', $old);
        $this->insertMailLog('fresh@example.com', $fresh);

        $this->artisan('reset:log')->assertExitCode(0);

        $this->assertSame(0, MailLog::where('email', 'old@example.com')->count());
        $this->assertSame(1, MailLog::where('email', 'fresh@example.com')->count());
    }

    public function test_it_does_not_touch_the_audit_log(): void
    {
        DB::table('v2_admin_audit_log')->insert([
            'admin_id' => 1,
            'action' => 'user.update',
            'method' => 'POST',
            'uri' => '/api/v2/xxx/user/update',
            'request_data' => '{}',
            'ip' => '127.0.0.1',
            'created_at' => time() - 200 * 86400,
            'updated_at' => time() - 200 * 86400,
        ]);

        $this->artisan('reset:log')->assertExitCode(0);

        // 审计日志由 cleanup:audit-log 独占，reset:log 一行都不能删
        $this->assertSame(1, DB::table('v2_admin_audit_log')->count());
    }

    private function insertMailLog(string $email, int $createdAt): void
    {
        DB::table('v2_mail_log')->insert([
            'email' => $email,
            'subject' => 's',
            'template_name' => 'notify',
            'error' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
