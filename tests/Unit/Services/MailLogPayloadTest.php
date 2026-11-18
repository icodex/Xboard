<?php

namespace Tests\Unit\Services;

use App\Models\MailLog;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sendEmail() 的返回值会被管理端 testSendMail 原样回显给调用方。
 * 曾经这里附带 config('mail')（含 SMTP 用户名/密码）：库里写不进去（没有该列），
 * 但会随 HTTP 响应泄露给前端。
 */
class MailLogPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_returned_log_carries_no_credentials(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.password' => 'super-secret-smtp-password',
            'mail.mailers.smtp.username' => 'smtp-user@example.com',
        ]);

        $log = MailService::sendEmail([
            'email' => 'log-test@example.com',
            'subject' => 'log test',
            'template_name' => 'notify',
            'template_value' => ['name' => 'XBoard', 'content' => 'hi', 'url' => 'http://localhost'],
        ]);

        $this->assertArrayNotHasKey('config', $log);
        $payload = (string) json_encode($log);
        $this->assertStringNotContainsString('super-secret-smtp-password', $payload);
        $this->assertStringNotContainsString('smtp-user@example.com', $payload);
    }

    public function test_stored_row_has_no_credentials(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.password' => 'super-secret-smtp-password',
        ]);

        MailService::sendEmail([
            'email' => 'log-test@example.com',
            'subject' => 'log test',
            'template_name' => 'notify',
            'template_value' => ['name' => 'XBoard', 'content' => 'hi', 'url' => 'http://localhost'],
        ]);

        $row = (string) json_encode(MailLog::firstOrFail()->toArray());
        $this->assertStringNotContainsString('super-secret-smtp-password', $row);
    }
}
