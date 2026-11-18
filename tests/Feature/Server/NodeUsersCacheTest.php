<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The node user payload is served from cache keyed by a version number.
 * Same user set must yield the same body (ETag => 304), and any change that
 * affects the payload must bust the cache (new ETag => 200).
 */
class NodeUsersCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        admin_setting(['server_token' => 'server-token']);
    }

    public function test_user_payload_etag_changes_when_user_changes(): void
    {
        $server = $this->makeServer();
        $first = $this->makeUser('cache-user-1@example.com', '00000000-0000-0000-0000-0000000000b1');

        $initial = $this->get($this->nodeUrl($server));
        $initial->assertOk();
        $initialTag = $initial->headers->get('ETag');
        $this->assertNotEmpty($initialTag);
        $this->assertCount(1, $initial->json('users'));

        $repeat = $this->withHeader('If-None-Match', $initialTag)->get($this->nodeUrl($server));
        $this->assertSame(304, $repeat->getStatusCode());

        $this->makeUser('cache-user-2@example.com', '00000000-0000-0000-0000-0000000000b2');

        $updated = $this->get($this->nodeUrl($server));
        $updated->assertOk();
        $updatedTag = $updated->headers->get('ETag');
        $this->assertNotSame($initialTag, $updatedTag);
        $this->assertCount(2, $updated->json('users'));
    }

    private function nodeUrl(Server $server): string
    {
        return '/api/v1/server/UniProxy/user?token=server-token&node_id=' . $server->id;
    }

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'cache-node',
            'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'enabled' => true,
        ]);
    }

    private function makeUser(string $email, string $uuid): User
    {
        return User::create([
            'email' => $email,
            'password' => 'password',
            'uuid' => $uuid,
            'token' => md5($email),
            'group_id' => 1,
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 1073741824,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
