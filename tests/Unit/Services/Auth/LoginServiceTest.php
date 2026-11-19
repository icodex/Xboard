<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\LoginService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class LoginServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoginService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->service = app(LoginService::class);
    }

    public function test_reset_password_rejects_missing_cached_email_code(): void
    {
        $user = $this->createUser('victim@example.com', 'old-password');

        [$success, $result] = $this->service->resetPassword($user->email, '', 'new-password');

        $this->assertFalse($success);
        $this->assertSame(400, $result[0]);

        $user->refresh();
        $this->assertTrue(password_verify('old-password', $user->password));
    }

    public function test_reset_password_accepts_matching_cached_email_code(): void
    {
        $user = $this->createUser('user@example.com', 'old-password');
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $user->email), 123456, 300);

        [$success, $result] = $this->service->resetPassword($user->email, '123456', 'new-password');

        $this->assertTrue($success);
        $this->assertTrue($result);

        $user->refresh();
        $this->assertTrue(password_verify('new-password', $user->password));
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $user->email)));
    }

    public function test_reset_password_uses_canonical_email_for_verification_code(): void
    {
        $user = $this->createUser('José@example.com', 'old-password');
        $email = "JOSE\u{0301}@EXAMPLE.COM";
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', User::normalizeEmail($email)), 123456, 300);

        [$success, $result] = $this->service->resetPassword($email, '123456', 'new-password');

        $this->assertTrue($success);
        $this->assertTrue($result);
        $this->assertTrue(password_verify('new-password', $user->fresh()->password));
    }

    public function test_reset_password_revokes_existing_tokens(): void
    {
        $user = $this->createUser('revoke@example.com', 'old-password');
        $user->createToken('stale-session', ['*'], now()->addYear());
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $user->email), 123456, 300);

        [$success] = $this->service->resetPassword($user->email, '123456', 'new-password');

        $this->assertTrue($success);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_forget_password_validation_rejects_boolean_email_code(): void
    {
        $validator = Validator::make([
            'email' => 'victim@example.com',
            'password' => 'new-password',
            'email_code' => false,
        ], [
            'email' => 'required|email:strict',
            'password' => 'required|min:8',
            'email_code' => 'required|digits:6',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email_code', $validator->errors()->toArray());
    }

    public function test_quick_login_url_uses_configured_site_not_request_host(): void
    {
        $user = $this->createUser('quick-login@example.com', 'old-password');
        admin_setting(['app_url' => '']);
        config(['app.url' => 'https://panel.example']);
        app()->instance('request', \Illuminate\Http\Request::create('https://attacker.example/'));

        $url = $this->service->generateQuickLoginUrl($user);

        $this->assertIsString($url);
        $this->assertStringStartsWith('https://panel.example/#/login?verify=', $url);
        $this->assertStringNotContainsString('attacker.example', $url);
    }

    public function test_quick_login_url_requires_a_site_url(): void
    {
        $user = $this->createUser('quick-login-missing@example.com', 'old-password');
        admin_setting(['app_url' => '']);
        config(['app.url' => '']);

        $this->assertNull($this->service->generateQuickLoginUrl($user));
    }

    public function test_a_legacy_md5_password_is_rewritten_to_password_hash_on_login(): void
    {
        $user = User::query()->create([
            'email' => 'legacy-md5@example.com',
            'password' => md5('legacy-password'),
            'password_algo' => 'md5',
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        [$success] = $this->service->login('legacy-md5@example.com', 'legacy-password', '127.0.0.1');

        $this->assertTrue($success);

        $user->refresh();
        $this->assertNull($user->password_algo);
        $this->assertNull($user->password_salt);
        $this->assertTrue(password_verify('legacy-password', $user->password));
    }

    private function createUser(string $email, string $password): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}