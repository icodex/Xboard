<?php

namespace Tests\Feature\Client;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ?types= 必须按节点类型过滤，且历史别名（v2ray/hysteria2）要归一。
 * 之前的实现里，别名导致交集为空，而空交集在过滤阶段等于"不过滤"，
 * 于是客户端拿到了一堆自己不认识的节点。
 */
class SubscribeTypeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_alias_only_returns_the_normalised_type(): void
    {
        $user = $this->makeUser();
        $this->makeServer('vmess-node', Server::TYPE_VMESS);
        $this->makeServer('tuic-node', Server::TYPE_TUIC);

        $body = $this->subscribe($user, 'v2ray');

        // vmess:// present (alias resolved), tuic:// gone
        $this->assertStringContainsString('vmess://', $body);
        $this->assertStringNotContainsString('tuic://', $body);
    }

    public function test_an_unrecognised_type_returns_no_nodes(): void
    {
        $user = $this->makeUser();
        $this->makeServer('vmess-node', Server::TYPE_VMESS);

        $body = $this->subscribe($user, 'nonsense');

        $this->assertStringNotContainsString('vmess://', $body);
    }

    public function test_no_type_parameter_returns_every_node(): void
    {
        $user = $this->makeUser();
        $this->makeServer('vmess-node', Server::TYPE_VMESS);
        $this->makeServer('tuic-node', Server::TYPE_TUIC);

        $body = $this->subscribe($user, null);

        $this->assertStringContainsString('vmess://', $body);
        $this->assertStringContainsString('tuic://', $body);
    }

    private function subscribe(User $user, ?string $types): string
    {
        $query = '?token=' . rawurlencode($user->token);
        if ($types !== null) {
            $query .= '&types=' . rawurlencode($types);
        }

        $response = $this->get('/api/v1/client/subscribe' . $query);
        $response->assertOk();

        // the general format wraps the whole URI list in base64
        $body = (string) $response->getContent();
        $decoded = base64_decode(trim($body), true);

        return $decoded !== false ? $decoded : $body;
    }

    private function makeUser(): User
    {
        return User::create([
            'email' => 'types@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000h2',
            'token' => md5('types@example.com'),
            'group_id' => 1,
            'transfer_enable' => 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
        ]);
    }

    private function makeServer(string $name, string $type): Server
    {
        return Server::create([
            'name' => $name,
            'type' => $type,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'show' => true,
            'protocol_settings' => $type === Server::TYPE_TUIC
                ? ['version' => 5, 'congestion_control' => 'bbr']
                : ['tls' => 0, 'network' => 'tcp'],
        ]);
    }
}