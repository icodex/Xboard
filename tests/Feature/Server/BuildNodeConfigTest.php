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

    public function test_hysteria_policy_fields_sent_when_set(): void
    {
        $server = Server::create([
            'name' => 'hy2-policy-node',
            'type' => Server::TYPE_HYSTERIA,
            'host' => '127.0.0.1',
            'port' => 8443,
            'server_port' => 8443,
            'rate' => '1',
            'group_id' => [1],
            'enabled' => true,
            'protocol_settings' => [
                'version' => 2,
                'sniff' => ['enable' => true, 'timeout' => 5, 'rewriteDomain' => true],
                'masquerade' => ['type' => 'string', 'string' => ['content' => 'hello', 'statusCode' => 200]],
                'congestion' => ['type' => 'bbr', 'bbrProfile' => 'high'],
                'udpIdleTimeout' => 120,
                'ignoreClientBandwidth' => true,
                'disableUDP' => true,
                'bandwidth' => ['up' => 100, 'down' => 100, 'disableLossCompensation' => true],
            ],
        ]);

        $config = ServerService::buildNodeConfig($server);

        $this->assertSame(['enable' => true, 'timeout' => 5, 'rewriteDomain' => true, 'tcpPorts' => null, 'udpPorts' => null], $config['sniff']);
        $this->assertSame('string', $config['masquerade']['type']);
        $this->assertSame(['type' => 'bbr', 'bbrProfile' => 'high'], $config['congestion']);
        $this->assertSame(120, $config['udp_idle_timeout']);
        $this->assertTrue($config['ignore_client_bandwidth']);
        $this->assertTrue($config['disable_udp']);
        $this->assertTrue($config['disable_loss_compensation']);
        // 未设置的策略组不下发
        $this->assertArrayNotHasKey('quic', $config);
        $this->assertArrayNotHasKey('resolver', $config);
    }

    public function test_hysteria_policy_fields_omitted_for_plain_nodes(): void
    {
        $server = Server::create([
            'name' => 'hy2-plain-node',
            'type' => Server::TYPE_HYSTERIA,
            'host' => '127.0.0.1',
            'port' => 8443,
            'server_port' => 8443,
            'rate' => '1',
            'group_id' => [1],
            'enabled' => true,
            'protocol_settings' => [
                'version' => 2,
            ],
        ]);

        $config = ServerService::buildNodeConfig($server);

        foreach (['sniff', 'masquerade', 'quic', 'congestion', 'resolver', 'disable_udp', 'udp_idle_timeout', 'ignore_client_bandwidth', 'disable_loss_compensation'] as $key) {
            $this->assertArrayNotHasKey($key, $config, "{$key} should be omitted");
        }
    }
}
