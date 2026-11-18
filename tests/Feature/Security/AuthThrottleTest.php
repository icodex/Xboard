<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 公开的登录/注册/发信接口必须有路由级限流：验证码默认关闭、
 * 注册 IP 限制默认关闭，否则一个脚本就能刷爆写信队列或试密码。
 */
class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited_per_ip(): void
    {
        $status = 0;

        // 每次用不同邮箱，避免触发按邮箱的错误计数，只考察路由限流
        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/v1/passport/auth/login', [
                'email' => "user{$i}@example.com",
                'password' => 'wrong-password',
            ]);
            $status = $response->getStatusCode();
        }

        $this->assertSame(429, $status);
    }

    public function test_email_verify_send_is_rate_limited_per_ip(): void
    {
        admin_setting(['captcha_enable' => 0, 'email_whitelist_enable' => 0]);

        $status = 0;
        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/v1/passport/comm/sendEmailVerify', [
                'email' => "target{$i}@example.com",
            ]);
            $status = $response->getStatusCode();
        }

        $this->assertSame(429, $status);
    }

    public function test_login_limit_is_shared_across_api_versions(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $version = $i % 2 === 0 ? 1 : 2;
            $response = $this->postJson("/api/v{$version}/passport/auth/login", [
                'email' => "mixed{$i}@example.com",
                'password' => 'wrong-password',
            ]);
        }

        $this->assertSame(429, $response->getStatusCode());
    }

    public function test_v2_email_verify_send_is_rate_limited_per_ip(): void
    {
        admin_setting(['captcha_enable' => 0, 'email_whitelist_enable' => 0]);

        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/v2/passport/comm/sendEmailVerify', [
                'email' => "v2target{$i}@example.com",
            ]);
        }

        $this->assertSame(429, $response->getStatusCode());
    }
}
