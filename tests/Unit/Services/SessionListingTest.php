<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 每次登录都新建一个有效期一年的 token；过期行必须从会话列表里隐藏，
 * 否则用户看到的是一年登录记录。
 */
class SessionListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_sessions_are_not_listed(): void
    {
        $user = $this->makeUser();
        $service = new AuthService($user);

        $live = $user->createToken('live', ['*'], now()->addDay());
        $dead = $user->createToken('dead', ['*'], now()->subDay());

        $ids = array_column($service->getSessions(), 'id');

        $this->assertContains($live->accessToken->id, $ids);
        $this->assertNotContains($dead->accessToken->id, $ids);
    }

    public function test_sessions_without_expiry_are_listed(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('forever', ['*'], null);

        $ids = array_column((new AuthService($user))->getSessions(), 'id');

        $this->assertContains($token->accessToken->id, $ids);
    }

    private function makeUser(): User
    {
        return User::create([
            'email' => 'sessions@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000f1',
            'token' => md5('sessions@example.com'),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
