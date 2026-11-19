<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 订阅下发前必须剥掉节点端明确拒绝的字段（xboard-node 的 validate.go 会拒绝
 * shadowsocks + SIP003 plugin 以及 socks + TLS）。否则客户端按插件/over-tls 连接，
 * 节点却是明文服务，用户看到的是"能导入、连不上"。
 */
class SubscribeSettingsNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_shadowsocks_plugin_fields_are_stripped(): void
    {
        $user = $this->makeUser();
        $this->makeServer(Server::TYPE_SHADOWSOCKS, [
            'cipher' => 'aes-256-gcm',
            'plugin' => 'v2ray-plugin',
            'plugin_opts' => 'tls;host=example.com',
            'obfs' => 'http',
            'obfs_settings' => ['host' => 'example.com'],
        ]);

        $server = $this->onlyServer($user);

        // protocol_settings 的 accessor 会按 schema 把这些键补回来（值为 null），
        // 所以契约是"值被清空、builder 因此不输出"，而不是"键消失"。
        $this->assertEmpty($server['protocol_settings']['plugin'] ?? null);
        $this->assertEmpty($server['protocol_settings']['plugin_opts'] ?? null);
        $this->assertEmpty($server['protocol_settings']['obfs'] ?? null);
        $this->assertEmpty($server['protocol_settings']['obfs_settings'] ?? null);
        $this->assertSame('aes-256-gcm', $server['protocol_settings']['cipher']);
    }

    public function test_legacy_socks_tls_is_forced_off(): void
    {
        $user = $this->makeUser();
        $this->makeServer(Server::TYPE_SOCKS, ['tls' => 1]);

        $server = $this->onlyServer($user);

        $this->assertSame(0, $server['protocol_settings']['tls']);
    }

    private function onlyServer(User $user): array
    {
        $servers = ServerService::getAvailableServers($user);

        $this->assertCount(1, $servers, 'fixture server must be in the user groups');

        return $servers[0];
    }

    private function makeUser(): User
    {
        return User::create([
            'email' => 'subscribe-normalize@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000h1',
            'token' => md5('subscribe-normalize@example.com'),
            'group_id' => 1,
            'transfer_enable' => 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
        ]);
    }

    private function makeServer(string $type, array $protocolSettings): Server
    {
        return Server::create([
            'name' => 'normalize-' . $type,
            'type' => $type,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'show' => true,
            'protocol_settings' => $protocolSettings,
        ]);
    }
}