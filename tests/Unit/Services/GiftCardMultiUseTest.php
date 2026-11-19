<?php

namespace Tests\Unit\Services;

use App\Models\GiftCardCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 多次可用的分享卡：兑换一次之后仍然可用，状态不能立刻变成"已使用"，
 * 否则后台显示错误、cleanupExpired() 也不再处理它。
 */
class GiftCardMultiUseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_multi_use_code_stays_available_until_it_is_used_up(): void
    {
        $user = $this->makeUser('multi@example.com');
        $code = $this->makeCode(maxUsage: 2);

        $this->assertTrue($code->isAvailable());

        $this->assertTrue($code->markAsUsed($user));
        $code->refresh();
        $this->assertSame(GiftCardCode::STATUS_UNUSED, (int) $code->status);
        $this->assertSame(1, (int) $code->usage_count);
        $this->assertSame($user->id, (int) $code->user_id);
        $this->assertTrue($code->isAvailable());

        $this->assertTrue($code->markAsUsed($user));
        $code->refresh();
        $this->assertSame(GiftCardCode::STATUS_USED, (int) $code->status);
        $this->assertSame(2, (int) $code->usage_count);
        $this->assertFalse($code->isAvailable());
    }

    public function test_the_first_redeemer_is_not_overwritten(): void
    {
        $first = $this->makeUser('first@example.com');
        $second = $this->makeUser('second@example.com');
        $code = $this->makeCode(maxUsage: 3);

        $code->markAsUsed($first);
        $code->markAsUsed($second);

        $code->refresh();
        $this->assertSame($first->id, (int) $code->user_id);
        $this->assertSame(2, (int) $code->usage_count);
    }

    public function test_cleanup_expires_a_partially_used_code_but_not_a_used_up_one(): void
    {
        $user = $this->makeUser('cleanup@example.com');

        $partial = $this->makeCode(maxUsage: 3, expiresAt: time() - 60);
        $partial->markAsUsed($user);

        $usedUp = $this->makeCode(maxUsage: 1, expiresAt: time() - 60, code: 'USEDUP01');
        $usedUp->markAsUsed($user);

        $this->assertSame(1, GiftCardCode::cleanupExpired());

        $this->assertSame(GiftCardCode::STATUS_EXPIRED, (int) $partial->fresh()->status);
        $this->assertSame(GiftCardCode::STATUS_USED, (int) $usedUp->fresh()->status);
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'password' => 'password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => substr(md5($email . uniqid('', true)), 0, 32),
            'transfer_enable' => 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
        ]);
    }

    private function makeCode(int $maxUsage, ?int $expiresAt = null, string $code = 'MULTI001'): GiftCardCode
    {
        return GiftCardCode::create([
            'template_id' => 1,
            'code' => $code,
            'status' => GiftCardCode::STATUS_UNUSED,
            'usage_count' => 0,
            'max_usage' => $maxUsage,
            'expires_at' => $expiresAt,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}