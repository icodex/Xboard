<?php

namespace Tests\Feature\Protocols;

use App\Models\SubscribeTemplate;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\QuantumultX;
use App\Protocols\Shadowsocks;
use App\Protocols\SingBox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Subscription output snapshot tests: fixed user + node fixtures must produce
 * parseable, complete client configs. These guard the most user-visible surface
 * (protocol field changes) that previously had no failing test.
 */
class ProtocolOutputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SubscribeTemplate::setContent(
            'clashmeta',
            File::get(base_path('resources/rules/default.clash.yaml'))
        );
        SubscribeTemplate::setContent(
            'singbox',
            File::get(base_path('resources/rules/default.sing-box.json'))
        );
    }

    public function test_clashmeta_emits_ss_and_vmess_proxies(): void
    {
        $protocol = new ClashMeta($this->user(), $this->servers(), 'meta', '1.19.10', 'clash-verge/2.0.0 meta');
        $response = $protocol->handle();

        $config = Yaml::parse($response->getContent());
        $proxies = collect($config['proxies'])->keyBy('type');

        $ss = $proxies['ss'];
        $this->assertSame('HK-SS', $ss['name']);
        $this->assertSame('hk.example.com', $ss['server']);
        $this->assertSame(8388, $ss['port']);
        $this->assertSame('aes-256-gcm', $ss['cipher']);
        $this->assertSame('server-psk:user-psk', $ss['password']);
        $this->assertTrue($ss['udp']);

        $vmess = $proxies['vmess'];
        $this->assertSame('HK-VMess', $vmess['name']);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $vmess['uuid']);
        $this->assertSame('hk.example.com', $vmess['server']);
        $this->assertSame(443, $vmess['port']);
        $this->assertTrue($vmess['tls']);
        $this->assertSame('hk.example.com', $vmess['servername']);
        $this->assertSame('ws', $vmess['network']);
        $this->assertSame('/ws', $vmess['ws-opts']['path']);

        // every node name must land in the proxy groups so clients can select them
        $grouped = collect($config['proxy-groups'])->flatMap(fn ($group) => $group['proxies']);
        $this->assertContains('HK-SS', $grouped);
        $this->assertContains('HK-VMess', $grouped);
        $this->assertNotEmpty($config['rules']);

        $this->assertStringContainsString(
            'total=107374182400',
            $response->headers->get('subscription-userinfo')
        );
    }

    public function test_singbox_emits_ss_and_vmess_outbounds(): void
    {
        $protocol = new SingBox($this->user(), $this->servers(), 'sfa', '1.10.0', 'SFA/1.10.0');
        $response = $protocol->handle();

        $config = json_decode($response->getContent(), true);
        $this->assertIsArray($config, 'sing-box output must be valid JSON');

        $byType = collect($config['outbounds'])->keyBy('type');

        $ss = $byType['shadowsocks'];
        $this->assertSame('HK-SS', $ss['tag']);
        $this->assertSame('hk.example.com', $ss['server']);
        $this->assertSame(8388, $ss['server_port']);
        $this->assertSame('aes-256-gcm', $ss['method']);
        $this->assertSame('server-psk:user-psk', $ss['password']);

        $vmess = $byType['vmess'];
        $this->assertSame('HK-VMess', $vmess['tag']);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $vmess['uuid']);
        $this->assertSame(443, $vmess['server_port']);
        $this->assertTrue($vmess['tls']['enabled']);
        $this->assertSame('hk.example.com', $vmess['tls']['server_name']);
        $this->assertSame('ws', $vmess['transport']['type']);
    }

    public function test_general_emits_ss_and_vmess_uri_lines(): void
    {
        $protocol = new General($this->user(), $this->servers(), 'v2rayng', '1.9.0', 'v2rayng/1.9.0');
        $response = $protocol->handle();

        $body = base64_decode($response->getContent(), true);
        $this->assertNotFalse($body, 'general output must be base64');

        $lines = explode("\r\n", trim($body));
        $this->assertCount(2, $lines);

        $ssLine = $lines[0];
        $this->assertStringStartsWith('ss://', $ssLine);
        $this->assertStringContainsString('@hk.example.com:8388', $ssLine);
        $this->assertStringContainsString('#HK-SS', $ssLine);
        $payload = base64_decode(strtr(substr($ssLine, 5, strpos($ssLine, '@') - 5), '-_', '+/'), true);
        $this->assertSame('aes-256-gcm:server-psk:user-psk', $payload);

        $vmessLine = $lines[1];
        $this->assertStringStartsWith('vmess://', $vmessLine);
        $vmessConfig = json_decode(base64_decode(substr($vmessLine, 8)), true);
        $this->assertSame('HK-VMess', $vmessConfig['ps']);
        $this->assertSame('hk.example.com', $vmessConfig['add']);
        $this->assertSame('443', $vmessConfig['port']);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $vmessConfig['id']);
        $this->assertSame('tls', $vmessConfig['tls']);
        $this->assertSame('ws', $vmessConfig['net']);
    }

    public function test_singbox_emits_http_transport(): void
    {
        // DB rows 52/72/88/174/204/227 store network=http; the builder had no
        // 'http' arm, so those outbounds shipped without any transport while
        // h2 rows shipped one (clients then dialed plaintext HTTP/1.1).
        $server = [
            'id' => 9,
            'type' => 'vmess',
            'name' => 'HK-HTTP',
            'host' => 'http.example.com',
            'port' => 80,
            'password' => '11111111-2222-3333-4444-555555555555',
            'protocol_settings' => [
                'tls' => 0,
                'network' => 'http',
                'network_settings' => [
                    'path' => '/matrix',
                    'host' => 'example.com',
                ],
                'reality_settings' => null,
                'multiplex' => null,
                'utls' => null,
            ],
        ];

        $protocol = new SingBox($this->user(), [$server], 'sfa', '1.12.0', 'SFA/1.12.0');
        $config = json_decode($protocol->handle()->getContent(), true);

        $vmess = collect($config['outbounds'])->firstWhere('tag', 'HK-HTTP');
        $this->assertIsArray($vmess, 'http vmess outbound must be emitted');
        $this->assertSame('http', $vmess['transport']['type']);
        $this->assertSame('/matrix', $vmess['transport']['path']);
        $this->assertSame('example.com', $vmess['transport']['host']);
    }

    public function test_singbox_hysteria_v1_obfs_honors_open_flag(): void
    {
        $make = function (bool $open, string $name) {
            return [
                'id' => 37,
                'type' => 'hysteria',
                'name' => $name,
                'host' => 'hy.example.com',
                'port' => 8443,
                'password' => 'hy-pass',
                'protocol_settings' => [
                    'version' => 1,
                    'obfs' => ['open' => $open, 'type' => 'salamander', 'password' => 'obfspass'],
                    'tls' => ['server_name' => 'hy.example.com'],
                    'bandwidth' => ['up' => 100, 'down' => 100],
                ],
            ];
        };

        $off = new SingBox($this->user(), [$make(false, 'HY-OFF')], 'sfa', '1.12.0', 'SFA/1.12.0');
        $offOutbound = collect(json_decode($off->handle()->getContent(), true)['outbounds'])->firstWhere('tag', 'HY-OFF');
        $this->assertSame('hysteria', $offOutbound['type']);
        $this->assertArrayNotHasKey('obfs', $offOutbound, 'obfs.open=false must not emit obfs');

        $on = new SingBox($this->user(), [$make(true, 'HY-ON')], 'sfa', '1.12.0', 'SFA/1.12.0');
        $onOutbound = collect(json_decode($on->handle()->getContent(), true)['outbounds'])->firstWhere('tag', 'HY-ON');
        $this->assertSame('obfspass', $onOutbound['obfs']);
    }

    public function test_quantumultx_emits_servers_and_matching_policy_tags(): void
    {
        $protocol = new QuantumultX($this->user(), $this->servers(), 'quantumultx', '1.5.0', 'Quantumult%20X/1.5.0');
        $content = $protocol->handle()->getContent();

        // Without the server lines Quantumult X loads an empty [server_local] and the
        // policy lines below point at nothing.
        $this->assertStringContainsString('shadowsocks=hk.example.com:8388', $content);
        $this->assertStringContainsString('vmess=hk.example.com:443', $content);
        $this->assertStringNotContainsString('$proxies', $content);

        // every emitted node must also be selectable in the policy groups
        $this->assertStringContainsString('HK-SS, HK-VMess', $content);
    }

    public function test_shadowsocks_node_with_xchacha_cipher_is_not_dropped(): void
    {
        // ServerSave accepts xchacha20-ietf-poly1305; the builders used to filter it
        // out, so the node simply vanished from those clients.
        $server = [
            'id' => 9,
            'type' => 'shadowsocks',
            'name' => 'XC-SS',
            'host' => 'xc.example.com',
            'port' => 8388,
            'password' => 'psk',
            'protocol_settings' => [
                'cipher' => 'xchacha20-ietf-poly1305',
                'obfs' => null,
                'obfs_settings' => null,
                'plugin' => null,
                'plugin_opts' => null,
            ],
        ];

        $sip008 = new Shadowsocks($this->user(), [$server], 'shadowsocks', '1.0', 'shadowsocks');
        $config = json_decode($sip008->handle()->getContent(), true);

        $this->assertSame('xc.example.com', $config['servers'][0]['server'] ?? null);
        $this->assertSame('xchacha20-ietf-poly1305', $config['servers'][0]['method'] ?? null);
    }

    public function test_multi_valued_obfs_settings_render_identically_every_time(): void
    {
        // 旧的 Arr::random / array_rand 每次轮询会挑不同的 path/host，
        // 订阅正文随之变化，ClientController 的 md5 ETag 永远命中不了。
        $server = [
            'id' => 11,
            'type' => 'vmess',
            'name' => 'STABLE-VMESS',
            'host' => 'st.example.com',
            'port' => 443,
            'password' => '11111111-2222-3333-4444-555555555555',
            '_fp_seed' => 'seed-value',
            'protocol_settings' => [
                'tls' => 0,
                'network' => 'tcp',
                'network_settings' => [
                    'header' => [
                        'type' => 'http',
                        'request' => [
                            'path' => ['/a', '/b', '/c'],
                            'headers' => ['Host' => ['h1.example.com', 'h2.example.com']],
                        ],
                    ],
                ],
                'tls_settings' => null,
                'reality_settings' => null,
                'multiplex' => null,
                'utls' => null,
            ],
        ];

        $first = General::buildVmess($server['password'], $server);
        $second = General::buildVmess($server['password'], $server);

        $this->assertSame($first, $second, 'identical body is what lets the md5 etag match');

        $payload = json_decode(base64_decode(trim(substr($first, strlen('vmess://')))), true);
        $this->assertContains($payload['path'], ['/a', '/b', '/c']);
        $this->assertContains($payload['host'], ['h1.example.com', 'h2.example.com']);
    }

    private function user(): array
    {
        return [
            'id' => 1,
            'uuid' => '11111111-2222-3333-4444-555555555555',
            'token' => 'test-subscribe-token',
            'u' => 1024,
            'd' => 2048,
            'transfer_enable' => 107374182400,
            'expired_at' => 4102444800,
            'speed_limit' => null,
            'device_limit' => null,
        ];
    }

    /**
     * Node arrays shaped like ServerService::getAvailableServers output
     * (protocol_settings already cast by the Server model).
     */
    private function servers(): array
    {
        return [
            [
                'id' => 7,
                'type' => 'shadowsocks',
                'name' => 'HK-SS',
                'host' => 'hk.example.com',
                'port' => 8388,
                'password' => 'server-psk:user-psk',
                'protocol_settings' => [
                    'cipher' => 'aes-256-gcm',
                    'obfs' => null,
                    'obfs_settings' => null,
                    'plugin' => null,
                    'plugin_opts' => null,
                ],
            ],
            [
                'id' => 8,
                'type' => 'vmess',
                'name' => 'HK-VMess',
                'host' => 'hk.example.com',
                'port' => 443,
                'password' => '11111111-2222-3333-4444-555555555555',
                'protocol_settings' => [
                    'tls' => 1,
                    'network' => 'ws',
                    'network_settings' => [
                        'path' => '/ws',
                        'headers' => ['Host' => 'hk.example.com'],
                    ],
                    'tls_settings' => [
                        'server_name' => 'hk.example.com',
                        'allow_insecure' => false,
                        'ech' => null,
                    ],
                    'reality_settings' => null,
                    'multiplex' => null,
                    'utls' => null,
                ],
            ],
        ];
    }
}
