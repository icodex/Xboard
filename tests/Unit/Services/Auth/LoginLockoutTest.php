<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\LoginService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 密码错误计数按「IP + 邮箱」记：只按邮箱记时，任何知道邮箱的人发 5 个错密码
 * 就能把该用户锁在门外（账号锁定 DoS）。
 */
class LoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    private LoginService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        admin_setting([
            'password_limit_enable' => 1,
            'password_limit_count' => 5,
            'password_limit_expire' => 60,
        ]);
        $this->service = app(LoginService::class);
    }

    public function test_failures_from_one_ip_do_not_lock_the_account_for_another_ip(): void
    {
        $this->createUser('victim@example.com', 'correct-password');

        for ($i = 0; $i < 5; $i++) {
            [$ok] = $this->service->login('victim@example.com', 'wrong-password', '203.0.113.9');
            $this->assertFalse($ok);
        }

        // the attacker's own IP is throttled
        [$blocked, $result] = $this->service->login('victim@example.com', 'correct-password', '203.0.113.9');
        $this->assertFalse($blocked);
        $this->assertSame(429, $result[0]);

        // the real owner from another IP still gets in
        [$allowed] = $this->service->login('victim@example.com', 'correct-password', '198.51.100.7');
        $this->assertTrue($allowed);
    }

    public function test_counter_is_cleared_by_a_successful_login(): void
    {
        $this->createUser('user@example.com', 'correct-password');

        for ($i = 0; $i < 4; $i++) {
            $this->service->login('user@example.com', 'wrong-password', '198.51.100.7');
        }
        [$ok] = $this->service->login('user@example.com', 'correct-password', '198.51.100.7');
        $this->assertTrue($ok);

        $this->assertNull(Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', md5(User::normalizeEmail('user@example.com') . '|198.51.100.7'))));

        // four more typos after the success must not trip the limit: still 400, not 429
        for ($i = 0; $i < 4; $i++) {
            [$ok, $result] = $this->service->login('user@example.com', 'wrong-password', '198.51.100.7');
            $this->assertFalse($ok);
            $this->assertSame(400, $result[0]);
        }
        [$okAgain] = $this->service->login('user@example.com', 'correct-password', '198.51.100.7');
        $this->assertTrue($okAgain);
    }

    private function createUser(string $email, string $password): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
