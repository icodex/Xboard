<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildNodeConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_kernel_type_route_rules_and_proxy_protocol_are_sent(): void
    {
        $routeRules = [
            [
                'name' => 'route-warp',
                'match' => ['ip_cidrs' => ['1.1.1.0/24']],
                'action' => ['type' => 'route', 'target' => 'warp-out'],
            ],
        ];

        $server = Server::create([
            'name' => 'vless-xray-node',
            'type' => Server::TYPE_VLESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_id' => [1],
            'enabled' => true,
            'kernel_type' => 'xray',
            'custom_route_rules' => $routeRules,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'tcp',
                'tls_settings' => [
                    'server_name' => 'a.example.com',
                    'alpn' => ['h2', 'http/1.1'],
                ],
                'accept_proxy_protocol' => true,
            ],
        ]);

        $config = ServerService::buildNodeConfig($server);

        $this->assertSame('xray', $config['kernel_type']);
        $this->assertTrue($config['accept_proxy_protocol']);
        $this->assertSame($routeRules, $config['custom_route_rules']);
        $this->assertSame(['h2', 'http/1.1'], $config['tls_settings']['alpn']);
    }

    public function test_new_fields_omitted_for_legacy_nodes(): void
    {
        $server = Server::create([
            'name' => 'legacy-node',
            'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_id' => [1],
            'enabled' => true,
            'protocol_settings' => [
                'tls' => 0,
                'network' => 'ws',
                'network_settings' => ['path' => '/ws'],
            ],
        ]);

        $config = ServerService::buildNodeConfig($server);

        $this->assertArrayNotHasKey('kernel_type', $config);
        $this->assertArrayNotHasKey('accept_proxy_protocol', $config);
        $this->assertArrayNotHasKey('custom_route_rules', $config);
        $this->assertArrayNotHasKey('kernel_log_level', $config);
    }

    public function test_hysteria_tls_object_alpn_passes_through(): void
    {
        $server = Server::create([
            'name' => 'hy2-node',
            'type' => Server::TYPE_HYSTERIA,
            'host' => '127.0.0.1',
            'port' => 8443,
            'server_port' => 8443,
            'rate' => '1',
            'group_id' => [1],
            'enabled' => true,
            'protocol_settings' => [
                'version' => 2,
                'bandwidth' => ['up' => 100, 'down' => 100],
                'tls' => [
                    'server_name' => 'hy.example.com',
                    'alpn' => ['h3'],
                ],
            ],
        ]);

        $config = ServerService::buildNodeConfig($server);

        // hysteria 的 tls 对象整体下发为 tls_settings，节点侧读取 alpn
        $this->assertSame(['h3'], $config['tls_settings']['alpn']);
        $this->assertSame('hy.example.com', $config['server_name']);
    }
}
