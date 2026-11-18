<?php

namespace Tests\Unit\Services\Auth;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\Auth\MailLinkService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 未知邮箱也要占限流位：否则「第二次请求会不会 429」直接暴露该邮箱是否注册。
 */
class MailLinkThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        admin_setting(['login_with_mail_link_enable' => 1]);
    }

    public function test_unknown_email_is_throttled_the_same_way(): void
    {
        $service = app(MailLinkService::class);

        [$first] = $service->handleMailLink('nobody@example.com');
        [$secondSuccess, $result] = $service->handleMailLink('nobody@example.com');

        $this->assertTrue($first);
        $this->assertFalse($secondSuccess);
        $this->assertSame(429, $result[0]);
    }

    public function test_known_email_sends_a_link_once_per_minute(): void
    {
        User::create([
            'email' => 'known@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000d1',
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $service = app(MailLinkService::class);

        [$first] = $service->handleMailLink('known@example.com');
        [$secondSuccess, $result] = $service->handleMailLink('known@example.com');

        $this->assertTrue($first);
        $this->assertFalse($secondSuccess);
        $this->assertSame(429, $result[0]);
    }

    public function test_mail_login_uses_configured_site_instead_of_request_host(): void
    {
        User::create([
            'email' => 'url-test@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000d2',
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        admin_setting(['app_url' => '']);
        config(['app.url' => 'https://panel.example']);
        app()->instance('request', \Illuminate\Http\Request::create('https://attacker.example/'));

        [$success] = app(MailLinkService::class)->handleMailLink('url-test@example.com');

        $this->assertTrue($success);
        Queue::assertPushed(SendEmailJob::class, function ($job) {
            $params = (new \ReflectionProperty(SendEmailJob::class, 'params'))->getValue($job);
            return str_starts_with($params['template_value']['link'], 'https://panel.example/#/login?verify=');
        });
    }

    public function test_mail_login_refuses_to_send_without_a_valid_site_url(): void
    {
        admin_setting(['app_url' => '']);
        config(['app.url' => '']);

        [$success] = app(MailLinkService::class)->handleMailLink('url-test@example.com');

        $this->assertFalse($success);
        Queue::assertNothingPushed();
    }
}
