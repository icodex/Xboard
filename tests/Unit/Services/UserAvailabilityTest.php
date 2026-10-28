<?php

namespace Tests\Unit\Services;

use App\Models\User;
use Tests\TestCase;

class UserAvailabilityTest extends TestCase
{
    /**
     * User::isActive()/isAvailable() 是节点增量同步（sync.user.delta add/remove）
     * 的判定口径，必须与 ServerService::getAvailableUsers()（全量下发）一致：
     * 无 plan、expired_at=0 都算可用，否则全量/增量互相打架。
     */
    private function makeUser(array $attrs = []): User
    {
        $user = new User();
        $user->fill(array_merge([
            'banned' => 0,
            'plan_id' => null,
            'expired_at' => time() + 86400,
            'transfer_enable' => 107374182400,
            'u' => 0,
            'd' => 0,
            'group_id' => 1,
        ], $attrs));

        return $user;
    }

    public function test_no_plan_user_with_quota_is_available(): void
    {
        $user = $this->makeUser();

        $this->assertTrue($user->isActive());
        $this->assertTrue($user->isAvailable());
    }

    public function test_expired_at_zero_legacy_user_is_available(): void
    {
        $user = $this->makeUser(['expired_at' => 0]);

        $this->assertTrue($user->isActive());
        $this->assertTrue($user->isAvailable());
    }

    public function test_null_expiry_user_is_available(): void
    {
        $user = $this->makeUser(['expired_at' => null]);

        $this->assertTrue($user->isActive());
        $this->assertTrue($user->isAvailable());
    }

    public function test_banned_user_is_unavailable(): void
    {
        $user = $this->makeUser(['banned' => 1]);

        $this->assertFalse($user->isAvailable());
    }

    public function test_expired_user_is_unavailable(): void
    {
        $user = $this->makeUser(['expired_at' => time() - 3600]);

        $this->assertFalse($user->isActive());
        $this->assertFalse($user->isAvailable());
    }

    public function test_exhausted_traffic_user_is_unavailable(): void
    {
        $user = $this->makeUser(['transfer_enable' => 100, 'u' => 60, 'd' => 60]);

        $this->assertTrue($user->isActive());
        $this->assertFalse($user->isAvailable());
    }
}
