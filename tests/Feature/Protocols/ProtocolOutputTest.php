<?php

namespace Tests\Feature\Protocols;

use App\Models\SubscribeTemplate;
use App\Protocols\Clash;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\Loon;
use App\Protocols\QuantumultX;
use App\Protocols\Shadowrocket;
use App\Protocols\Shadowsocks;
use App\Protocols\SingBox;
use App\Protocols\Stash;
use App\Protocols\Surge;
use App\Protocols\Surfboard;
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

    public function test_legacy_clients_drop_reality_nodes_instead_of_emitting_broken_tls(): void
    {
        SubscribeTemplate::setContent('clash', File::get(base_path('resources/rules/default.clash.yaml')));
        SubscribeTemplate::setContent('surge', File::get(base_path('resources/rules/default.surge.conf')));

        $make = function (int $tls, string $name) {
            return [
                'id' => 21 + $tls,
                'type' => 'vmess',
                'name' => $name,
                'host' => 'reality.example.com',
                'port' => 443,
                'password' => '11111111-2222-3333-4444-555555555555',
                'protocol_settings' => [
                    'tls' => $tls,
                    'network' => 'tcp',
                    'tls_settings' => ['server_name' => 'reality.example.com', 'allow_insecure' => false],
                    'reality_settings' => ['public_key' => 'pbk', 'short_id' => 'sid', 'server_name' => 'reality.example.com'],
                    'multiplex' => null,
                    'utls' => null,
                ],
            ];
        };

        // tls=2 (Reality) is not representable in either client: it must be dropped,
        // not emitted as a plain TLS proxy the client can never connect to.
        $servers = [$make(2, 'REALITY-NODE'), $make(1, 'PLAIN-TLS-NODE')];

        $clash = (new Clash($this->user(), $servers, 'clash', '1.0.0', 'clash'))->handle()->getContent();
        $this->assertStringNotContainsString('REALITY-NODE', $clash);
        $this->assertStringContainsString('PLAIN-TLS-NODE', $clash);

        $surge = (new Surge($this->user(), $servers, 'surge', '5.0.0', 'surge'))->handle()->getContent();
        $this->assertStringNotContainsString('REALITY-NODE', $surge);
        $this->assertStringContainsString('PLAIN-TLS-NODE', $surge);
    }

    public function test_every_shadowsocks_builder_emits_the_configured_cipher(): void
    {
        // These five formats had no output test at all: a cipher added to ServerSave
        // (xchacha20-ietf-poly1305) was silently dropped from their whitelists.
        $server = [
            'id' => 31,
            'type' => 'shadowsocks',
            'name' => 'FORMAT-SS',
            'host' => 'fmt.example.com',
            'port' => 8388,
            'password' => 'psk',
            '_fp_seed' => 'seed',
            'protocol_settings' => [
                'cipher' => 'xchacha20-ietf-poly1305',
                'obfs' => null,
                'obfs_settings' => null,
                'plugin' => null,
                'plugin_opts' => null,
            ],
        ];

        $clash = Clash::buildShadowsocks('psk', $server);
        $this->assertSame('ss', $clash['type']);
        $this->assertSame('xchacha20-ietf-poly1305', $clash['cipher']);

        $stash = Stash::buildShadowsocks('psk', $server);
        $this->assertSame('xchacha20-ietf-poly1305', $stash['cipher']);

        $surfboard = Surfboard::buildShadowsocks('psk', $server);
        $this->assertStringContainsString('xchacha20-ietf-poly1305', $surfboard);

        // ss:// URI 把 method:password 放在 base64 里
        $shadowrocket = trim((string) Shadowrocket::buildShadowsocks('psk', $server));
        $payload = str_starts_with($shadowrocket, 'ss://') ? substr($shadowrocket, 5) : $shadowrocket;
        $userInfo = base64_decode(explode('@', $payload)[0] ?? '', true);
        $this->assertStringContainsString('xchacha20-ietf-poly1305', (string) $userInfo);

        $loon = Loon::buildShadowsocks('psk', $server);
        $this->assertStringContainsString('xchacha20-ietf-poly1305', implode(',', (array) $loon));
    }

    // 节点侧只下发 tls.alpn（buildNodeConfig 取 tls 对象），管理端旧绑定写的是顶层
    // alpn。订阅侧若继续读顶层，用户在面板选的 ALPN 永远不会到服务端，客户端与
    // 服务端 ALPN 不一致会直接握不上手。
    public function test_tuic_alpn_follows_tls_settings_not_legacy_top_level_key(): void
    {
        $server = [
            'id' => 16,
            'type' => 'tuic',
            'name' => 'TU-ALPN',
            'host' => 'tu.example.com',
            'port' => 9443,
            'protocol_settings' => [
                'version' => 5,
                'congestion_control' => 'bbr',
                'udp_relay_mode' => 'native',
                'alpn' => ['h2'],
                'tls' => ['server_name' => 'tu.example.com', 'alpn' => ['h3']],
            ],
        ];

        $singbox = json_decode((new SingBox($this->user(), [$server], 'sfa', '1.12.0', 'SFA/1.12.0'))->handle()->getContent(), true);
        $outbound = collect($singbox['outbounds'])->firstWhere('tag', 'TU-ALPN');
        $this->assertSame(['h3'], $outbound['tls']['alpn'], 'sing-box TUIC must use tls.alpn');

        $stash = Stash::buildTuic('11111111-2222-3333-4444-555555555555', $server);
        $this->assertSame(['h3'], $stash['alpn'], 'stash TUIC must use tls.alpn');

        $clash = ClashMeta::buildTuic('11111111-2222-3333-4444-555555555555', $server);
        $this->assertSame(['h3'], $clash['alpn'], 'clashmeta TUIC must use tls.alpn');
    }

    public function test_anytls_alpn_follows_tls_settings(): void
    {
        $server = [
            'id' => 17,
            'type' => 'anytls',
            'name' => 'ANY-ALPN',
            'host' => 'any.example.com',
            'port' => 443,
            'protocol_settings' => [
                'alpn' => ['h2'],
                'tls' => ['server_name' => 'any.example.com', 'alpn' => ['h3']],
            ],
        ];

        $singbox = json_decode((new SingBox($this->user(), [$server], 'sfa', '1.12.0', 'SFA/1.12.0'))->handle()->getContent(), true);
        $outbound = collect($singbox['outbounds'])->firstWhere('tag', 'ANY-ALPN');
        $this->assertSame(['h3'], $outbound['tls']['alpn'], 'sing-box anytls must use tls.alpn');
    }

    // splithttp 是 xray 的 xhttp 别名（节点侧同一分支）；订阅侧统一归一成 xhttp，
    // 支持 xhttp 的格式正常输出，sing-box 这类不支持的格式按版本门槛整条过滤，
    // 不能退化成明文 tcp 出站。
    public function test_splithttp_node_is_rendered_as_xhttp_transport(): void
    {
        $server = [
            'id' => 52,
            'type' => 'vless',
            'name' => 'SPLIT-XHTTP',
            'host' => 'split.example.com',
            'port' => 2052,
            'password' => '11111111-2222-3333-4444-555555555555',
            'protocol_settings' => [
                'tls' => 0,
                'network' => 'splithttp',
                'network_settings' => ['path' => '/split', 'host' => 'example.com', 'mode' => 'auto'],
                'reality_settings' => null,
                'multiplex' => null,
                'utls' => null,
            ],
        ];

        $clash = Yaml::parse((new ClashMeta($this->user(), [$server], 'meta', '1.19.10', 'clash-verge/2.0.0'))->handle()->getContent());
        $proxy = collect($clash['proxies'])->firstWhere('name', 'SPLIT-XHTTP');
        $this->assertIsArray($proxy, 'clashmeta supports xhttp and must keep the node');
        $this->assertSame('xhttp', $proxy['network']);
        $this->assertSame('/split', $proxy['xhttp-opts']['path']);

        $singbox = json_decode((new SingBox($this->user(), [$server], 'sfa', '1.12.0', 'SFA/1.12.0'))->handle()->getContent(), true);
        $outbound = collect($singbox['outbounds'])->firstWhere('tag', 'SPLIT-XHTTP');
        $this->assertNull($outbound, 'sing-box has no xhttp transport; the node must be filtered, not downgraded to tcp');
    }

    // 管理端 hysteria 表单：v1 ALPN 在顶层 alpn，端口跳跃范围在 port_range；
    // 订阅侧必须把它们翻成各家客户端的字段，否则"改了没效果"。
    public function test_hysteria_legacy_alpn_and_port_range_reach_clients(): void
    {
        $server = [
            'id' => 15,
            'type' => 'hysteria',
            'name' => 'HY-V1',
            'host' => 'hy.example.com',
            'port' => 8443,
            'protocol_settings' => [
                'version' => 1,
                'alpn' => 'hysteria',
                'port_range' => '10000-20000',
                'tls' => ['server_name' => 'hy.example.com'],
                'bandwidth' => ['up' => 100, 'down' => 100],
                'obfs' => ['open' => false],
            ],
        ];

        $clash = ClashMeta::buildHysteria('11111111-2222-3333-4444-555555555555', $server, $this->user());
        $this->assertSame(['hysteria'], $clash['alpn']);
        $this->assertSame('10000-20000', $clash['ports']);

        // server_ports 受 sing-box>=1.11 门禁，clientName 必须是 'sing-box'
        $singbox = json_decode((new SingBox($this->user(), [$server], 'sing-box', '1.12.0', 'SFA/1.12.0'))->handle()->getContent(), true);
        $outbound = collect($singbox['outbounds'])->firstWhere('tag', 'HY-V1');
        $this->assertSame(['hysteria'], $outbound['tls']['alpn']);
        $this->assertSame(['10000:20000'], $outbound['server_ports']);
    }

    // xhttp/splithttp 没有对应的 sing-box transport：VMess/Trojan 必须整条过滤，
    // 不能输出缺少 transport 段、客户端按明文 tcp 连接的假节点。
    public function test_singbox_filters_vmess_and_trojan_xhttp(): void
    {
        $make = function (string $type, string $name) {
            return [
                'id' => 900, 'type' => $type, 'name' => $name,
                'host' => 'x.example.com', 'port' => 2099,
                'password' => '11111111-2222-3333-4444-555555555555',
                'protocol_settings' => [
                    'tls' => 0, 'network' => 'xhttp',
                    'network_settings' => ['path' => '/x', 'host' => 'x.example.com'],
                ],
            ];
        };
        $servers = [$make('vmess', 'X-VMESS'), $make('trojan', 'X-TROJAN')];
        $config = json_decode((new SingBox($this->user(), $servers, 'sing-box', '', ''))->handle()->getContent(), true);
        $tags = array_column($config['outbounds'], 'tag');
        $this->assertNotContains('X-VMESS', $tags);
        $this->assertNotContains('X-TROJAN', $tags);

        $split = $make('vless', 'X-VLESS');
        $config = json_decode((new SingBox($this->user(), [$split], 'sing-box', '', ''))->handle()->getContent(), true);
        $this->assertNotContains('X-VLESS', array_column($config['outbounds'], 'tag'));
    }

    // hysteria v1 服务端只协商 h3：缺少 ALPN 的客户端会被拒握手。
    public function test_singbox_hysteria_v1_defaults_alpn_h3(): void
    {
        $server = [
            'id' => 901, 'type' => 'hysteria', 'name' => 'HY1',
            'host' => 'hy.example.com', 'port' => 443,
            'password' => '11111111-2222-3333-4444-555555555555',
            'protocol_settings' => ['version' => 1, 'tls' => ['server_name' => 'hy.example.com']],
        ];
        $config = json_decode((new SingBox($this->user(), [$server], 'sing-box', '1.12.0', 'SFA/1.12.0'))->handle()->getContent(), true);
        $outbound = collect($config['outbounds'])->firstWhere('tag', 'HY1');
        $this->assertSame(['h3'], $outbound['tls']['alpn']);
    }

    // sing-box 把 security=auto 解析成 zero；xray 入站不接受，VMess+TLS 会全挂。
    public function test_singbox_vmess_tls_uses_concrete_security(): void
    {
        $make = function (int $tls, string $name) {
            return [
                'id' => 902, 'type' => 'vmess', 'name' => $name,
                'host' => 'v.example.com', 'port' => 443,
                'password' => '11111111-2222-3333-4444-555555555555',
                'protocol_settings' => [
                    'tls' => $tls,
                    'tls_settings' => ['server_name' => 'v.example.com'],
                    'network' => 'tcp',
                ],
            ];
        };
        $config = json_decode((new SingBox($this->user(), [$make(1, 'V-TLS')], 'sing-box', '1.12.0', 'SFA/1.12.0'))->handle()->getContent(), true);
        $this->assertSame('aes-128-gcm', collect($config['outbounds'])->firstWhere('tag', 'V-TLS')['security']);

        $config = json_decode((new SingBox($this->user(), [$make(0, 'V-PLAIN')], 'sing-box', '1.12.0', 'SFA/1.12.0'))->handle()->getContent(), true);
        $this->assertSame('auto', collect($config['outbounds'])->firstWhere('tag', 'V-PLAIN')['security']);
    }

    // 管理端把 ws Host 存在 network_settings.host；只读 headers.Host 会丢 Host 头。
    public function test_singbox_ws_uses_flat_network_settings_host(): void
    {
        $server = [
            'id' => 903, 'type' => 'vmess', 'name' => 'WS-HOST',
            'host' => 'v.example.com', 'port' => 443,
            'password' => '11111111-2222-3333-4444-555555555555',
            'protocol_settings' => [
                'tls' => 1, 'network' => 'ws',
                'tls_settings' => ['server_name' => 'ws.example.com'],
                'network_settings' => ['path' => '/ws', 'host' => 'ws.example.com'],
            ],
        ];
        $config = json_decode((new SingBox($this->user(), [$server], 'sing-box', '1.12.0', 'SFA/1.12.0'))->handle()->getContent(), true);
        $outbound = collect($config['outbounds'])->firstWhere('tag', 'WS-HOST');
        $this->assertSame(['Host' => 'ws.example.com'], $outbound['transport']['headers']);
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
