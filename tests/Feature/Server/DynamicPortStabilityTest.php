<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 端口区间节点的端口必须按「用户 + 节点」固定：随机端口会让订阅正文每次都变，
 * ClientController 的 md5 ETag 永远命中不了。
 */
class DynamicPortStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_port_is_stable_across_polls_and_stays_in_range(): void
    {
        $user = $this->makeUser('stable-port@example.com', 'port-stable-token');
        $this->makeServer('20000-20010');

        $first = ServerService::getAvailableServers($user);
        $second = ServerService::getAvailableServers($user);

        $this->assertCount(1, $first);
        $this->assertSame($first[0]['port'], $second[0]['port'], 'the same user must get the same port');
        $this->assertGreaterThanOrEqual(20000, $first[0]['port']);
        $this->assertLessThanOrEqual(20010, $first[0]['port']);
        // the range itself is still advertised to the client
        $this->assertSame('20000-20010', $first[0]['ports']);
    }

    public function test_different_users_are_spread_over_the_range(): void
    {
        $this->makeServer('30000-30099');

        $ports = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $suffix) {
            $user = $this->makeUser("spread-{$suffix}@example.com", "spread-token-{$suffix}");
            $ports[] = ServerService::getAvailableServers($user)[0]['port'];
        }

        $this->assertGreaterThan(1, count(array_unique($ports)), 'seeded ports must not collapse to one value');
        foreach ($ports as $port) {
            $this->assertGreaterThanOrEqual(30000, $port);
            $this->assertLessThanOrEqual(30099, $port);
        }
    }

    private function makeUser(string $email, string $token): User
    {
        return User::create([
            'email' => $email,
            'password' => 'password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => $token,
            'group_id' => 1,
            'transfer_enable' => 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
        ]);
    }

    private function makeServer(string $portRange): Server
    {
        return Server::create([
            'name' => 'dynamic-port-node',
            'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1',
            'port' => $portRange,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'show' => true,
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
        ]);
    }
}