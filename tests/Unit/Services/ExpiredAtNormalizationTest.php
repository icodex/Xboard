<?php

namespace Tests\Unit\Services;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * expired_at 的「永不过期」只保留 NULL：0 会让 OrderService 的"续费重置流量"
 * 判断失效（它只认 NULL），于是新周期带着上个周期的用量开始。
 */
class ExpiredAtNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_expiry_is_stored_as_null(): void
    {
        $user = $this->makeUser(['expired_at' => 0]);

        $this->assertNull($user->fresh()->expired_at);
        $this->assertSame(0, User::where('expired_at', 0)->count());
    }

    public function test_an_explicit_null_expiry_stays_valid(): void
    {
        $user = $this->makeUser(['expired_at' => null]);

        $this->assertNull($user->fresh()->expired_at);
        $this->assertTrue($user->fresh()->isActive());
        $this->assertTrue(User::active()->whereKey($user->id)->exists());
    }

    public function test_updating_to_zero_also_normalizes(): void
    {
        $user = $this->makeUser(['expired_at' => time() + 86400]);

        $user->expired_at = 0;
        $user->save();

        $this->assertNull($user->fresh()->expired_at);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'expiry-' . uniqid() . '@example.com',
            'password' => 'password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => substr(md5(uniqid('', true)), 0, 32),
            'transfer_enable' => 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
        ], $overrides));
    }
}