<?php

namespace Tests\Feature\Admin;

use App\Http\Requests\Admin\ServerSave;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Tests\TestCase;

class ServerSaveValidationTest extends TestCase
{
    private function validate(array $protocolSettings, string $type, array $extra = []): Validator
    {
        $request = ServerSave::create('/', 'POST', array_merge([
            'type' => $type,
            'name' => 'test-node',
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'protocol_settings' => $protocolSettings,
        ], $extra));

        return ValidatorFactory::make($request->all(), $request->rules());
    }

    // Laravel 12 默认 excludeUnvalidatedArrayKeys 会让"有子规则的数组父键"整块从
    // validated() 消失：network_settings 只定义了 header.request.* 子规则，xhttp 值
    // 没有 header 时保存后会被 Server 模型补成 null（管理端编辑保存即清空）。
    public function test_unruled_nested_protocol_settings_survive_validation(): void
    {
        $settings = [
            'tls' => 1,
            'network' => 'xhttp',
            'network_settings' => ['path' => '/vlx', 'host' => 'test.local', 'mode' => 'auto'],
        ];

        $request = ServerSave::create('/', 'POST', [
            'type' => 'vless',
            'name' => 'test-node',
            'host' => '127.0.0.1',
            'port' => 10010,
            'server_port' => 10010,
            'rate' => 1,
            'protocol_settings' => $settings,
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        $request->validateResolved();

        $validated = $request->validated();

        $this->assertSame($settings['network_settings'], $validated['protocol_settings']['network_settings']);
    }

    // 显式钉 xray 内核时，面板必须只放行 xray fork 真的能起的协议/传输：
    // xboard-node 的 ValidateNodeSpec 会拒收，而拒收在节点侧只表现为一条日志
    // + 节点永远不启动（例如 hysteria2 + obfs 的节点）。
    public function test_pinned_xray_kernel_rejects_unsupported_protocols_and_transports(): void
    {
        $hy = fn (array $ps) => $this->validate($ps, 'hysteria', ['kernel_type' => 'xray']);

        $this->assertTrue(
            $hy(['version' => 2, 'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'x']])->fails(),
            'xray has no hysteria2 obfs'
        );
        $this->assertTrue(
            $hy(['version' => 1, 'obfs' => ['open' => false]])->fails(),
            'xray only implements hysteria v2'
        );
        $this->assertFalse(
            $hy(['version' => 2, 'obfs' => ['open' => false]])->fails(),
            'plain hysteria2 must stay saveable on xray'
        );

        $this->assertTrue(
            $this->validate(['tls' => 1], 'tuic', ['kernel_type' => 'xray'])->fails(),
            'xray has no tuic inbound'
        );
        $this->assertTrue(
            $this->validate(['tls' => 1], 'naive', ['kernel_type' => 'xray'])->fails(),
            'xray has no naive inbound'
        );
        $this->assertTrue(
            $this->validate(['tls' => 1, 'network' => 'h2'], 'vmess', ['kernel_type' => 'xray'])->fails(),
            'xray fork removed h2 transport'
        );
        $this->assertFalse(
            $this->validate(['tls' => 1, 'network' => 'ws'], 'vmess', ['kernel_type' => 'xray'])->fails(),
            'ws stays saveable on xray'
        );
        $this->assertFalse(
            $this->validate(['tls' => 1], 'tuic', ['kernel_type' => 'auto'])->fails(),
            'auto kernel resolution must not be constrained'
        );
    }

    // 节点契约（xboard-node ValidateNodeSpec）：tls=2 必须有 private_key 且
    // server_name 或 dest；面板放行会让节点反复启动失败，整个节点掉线。
    public function test_reality_required_fields_when_tls_is_2(): void
    {
        $settings = fn (array $reality) => ['tls' => 2, 'network' => 'tcp', 'reality_settings' => $reality];

        $this->assertTrue(
            $this->validate($settings(['server_name' => 'www.microsoft.com', 'dest' => 'www.microsoft.com:443']), 'vless')->fails(),
            'missing private_key must be rejected'
        );
        $this->assertTrue(
            $this->validate($settings(['private_key' => 'pk', 'public_key' => 'pub']), 'vless')->fails(),
            'missing server_name and dest must be rejected'
        );
        $this->assertFalse(
            $this->validate($settings(['private_key' => 'pk', 'server_name' => 'www.microsoft.com']), 'vless')->fails()
        );
        $this->assertFalse(
            $this->validate($settings(['private_key' => 'pk', 'dest' => 'www.microsoft.com:443']), 'vless')->fails()
        );
        // tls=1 不触发 Reality 契约
        $this->assertFalse(
            $this->validate(['tls' => 1, 'network' => 'tcp', 'tls_settings' => ['server_name' => 't.example.com']], 'vless')->fails()
        );
    }

    // 管理端 hysteria 表单字段：v1 ALPN（顶层）与端口跳跃范围
    public function test_hysteria_alpn_and_port_range(): void
    {
        $this->assertFalse($this->validate(['version' => 1, 'alpn' => 'hysteria'], 'hysteria')->fails());
        $this->assertTrue($this->validate(['version' => 1, 'alpn' => 'ftp'], 'hysteria')->fails());

        foreach (['10000-20000', '443', '443,8443', '10000-20000,30000-40000'] as $range) {
            $this->assertFalse(
                $this->validate(['version' => 2, 'port_range' => $range], 'hysteria')->fails(),
                "port_range={$range} should pass"
            );
        }
        foreach (['abc', '10000-', '1-2-3', '10000-20000;'] as $range) {
            $this->assertTrue(
                $this->validate(['version' => 2, 'port_range' => $range], 'hysteria')->fails(),
                "port_range={$range} should be rejected"
            );
        }
    }

    public function test_obfs_path_and_host_accept_string_or_string_list(): void
    {
        $settings = fn ($path, $host) => [
            'tls' => 0,
            'network' => 'tcp',
            'network_settings' => [
                'header' => [
                    'type' => 'http',
                    'request' => ['path' => $path, 'headers' => ['Host' => $host]],
                ],
            ],
        ];

        // both historical shapes stay saveable
        $this->assertFalse($this->validate($settings('/single', 'one.example.com'), 'vmess')->fails());
        $this->assertFalse(
            $this->validate($settings(['/a', '/b'], ['h1.example.com', 'h2.example.com']), 'vmess')->fails()
        );

        // shapes that would break the subscription bodies are rejected
        $this->assertTrue($this->validate($settings(123, 'one.example.com'), 'vmess')->fails());
        $this->assertTrue(
            $this->validate($settings(['/a', 5], 'one.example.com'), 'vmess')->fails()
        );
    }

    public function test_kernel_log_level_whitelist(): void
    {
        foreach (['trace', 'debug', 'info', 'warn', 'warning', 'error', 'fatal'] as $level) {
            $this->assertFalse(
                $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', ['kernel_log_level' => $level])->fails(),
                "kernel_log_level={$level} should pass"
            );
        }

        // Null and omission are allowed (empty = node derives from its own log.level).
        $this->assertFalse($this->validate(['tls' => 0, 'network' => 'tcp'], 'vless')->fails());
        $this->assertFalse($this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', ['kernel_log_level' => null])->fails());

        foreach (['verbose', 'quiet', 'none', 1] as $level) {
            $errors = $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', ['kernel_log_level' => $level])->errors();
            $this->assertTrue($errors->has('kernel_log_level'), "kernel_log_level={$level} should be rejected");
        }
    }

    public function test_network_whitelist_rejects_unsupported_transport(): void
    {
        $errors = $this->validate(['tls' => 0, 'network' => 'kcp'], 'vless')->errors();
        $this->assertTrue($errors->has('protocol_settings.network'));

        $errors = $this->validate(['tls' => 1, 'network' => 'raw'], 'vmess')->errors();
        $this->assertTrue($errors->has('protocol_settings.network'));
    }

    public function test_network_whitelist_accepts_supported_transports(): void
    {
        foreach (['tcp', 'ws', 'grpc', 'h2', 'http', 'httpupgrade', 'xhttp'] as $network) {
            $validator = $this->validate(['tls' => 0, 'network' => $network], 'vless');
            $this->assertFalse($validator->fails(), "network={$network} should pass");
        }
    }

    public function test_tls_level_must_be_0_1_or_2(): void
    {
        $errors = $this->validate(['tls' => 3, 'network' => 'tcp'], 'vless')->errors();
        $this->assertTrue($errors->has('protocol_settings.tls'));

        $errors = $this->validate(['tls' => 3, 'network' => 'tcp'], 'vmess')->errors();
        $this->assertTrue($errors->has('protocol_settings.tls'));

        // tls=2 需满足 Reality 契约（见 test_reality_required_fields_when_tls_is_2）
        $this->assertTrue($this->validate(['tls' => 2, 'network' => 'tcp'], 'vless')->fails());
        $this->assertFalse($this->validate(['tls' => 1, 'network' => 'tcp'], 'vless')->fails());
    }

    public function test_hysteria_version_must_be_1_or_2(): void
    {
        $errors = $this->validate(['version' => 3], 'hysteria')->errors();
        $this->assertTrue($errors->has('protocol_settings.version'));

        $this->assertFalse($this->validate(['version' => 2], 'hysteria')->fails());
    }

    public function test_shadowsocks_cipher_whitelist(): void
    {
        $errors = $this->validate(['cipher' => 'aes-128-cfb'], 'shadowsocks')->errors();
        $this->assertTrue($errors->has('protocol_settings.cipher'));

        // 2022-blake3-chacha20-poly1305 removed: no multi-user (iPSK) implementation
        // on either kernel, node refuses to start with it.
        $errors = $this->validate(['cipher' => '2022-blake3-chacha20-poly1305'], 'shadowsocks')->errors();
        $this->assertTrue($errors->has('protocol_settings.cipher'));

        foreach ([
            'aes-128-gcm', 'aes-192-gcm', 'aes-256-gcm',
            'chacha20-ietf-poly1305', 'xchacha20-ietf-poly1305',
            '2022-blake3-aes-128-gcm', '2022-blake3-aes-256-gcm',
        ] as $cipher) {
            $this->assertFalse($this->validate(['cipher' => $cipher], 'shadowsocks')->fails(), "cipher={$cipher} should pass");
        }
    }

    public function test_shadowsocks_xray_cipher_whitelist_is_narrower(): void
    {
        // xray fork cipherFromString has no aes-192-gcm (CipherType_UNKNOWN ->
        // kernel parse failure), so pinning kernel_type=xray must reject it.
        $errors = $this->validate(['cipher' => 'aes-192-gcm'], 'shadowsocks', ['kernel_type' => 'xray'])->errors();
        $this->assertTrue($errors->has('protocol_settings.cipher'), 'aes-192-gcm + xray should fail');

        // auto resolves Shadowsocks to sing-box, which does implement aes-192-gcm.
        foreach (['singbox', 'auto', null] as $kernel) {
            $validator = $this->validate(['cipher' => 'aes-192-gcm'], 'shadowsocks', ['kernel_type' => $kernel]);
            $this->assertFalse($validator->fails(), 'aes-192-gcm + kernel_type=' . var_export($kernel, true) . ' should pass');
        }

        $this->assertFalse(
            $this->validate(['cipher' => 'aes-256-gcm'], 'shadowsocks', ['kernel_type' => 'xray'])->fails(),
            'aes-256-gcm + xray should pass'
        );
    }

    public function test_socks_rejects_tls(): void
    {
        // Kernel socks inbound has no TLS option: tls=1 saves fine but serves
        // plaintext while the panel shows "encrypted" - must be rejected.
        foreach ([1, 2] as $tls) {
            $errors = $this->validate(['tls' => $tls], 'socks')->errors();
            $this->assertTrue($errors->has('protocol_settings.tls'), "socks tls={$tls} should fail");
        }

        $this->assertFalse($this->validate(['tls' => 0], 'socks')->fails());
        $this->assertFalse($this->validate([], 'socks')->fails());
    }

    public function test_vmess_accepts_reality_settings(): void
    {
        $validator = $this->validate([
            'tls' => 2,
            'network' => 'tcp',
            'reality_settings' => [
                'public_key' => 'pbk',
                'private_key' => 'priv',
                'short_id' => 'sid',
                'server_name' => 'example.com',
            ],
        ], 'vmess');

        $this->assertFalse($validator->fails());
    }

    public function test_kernel_type_whitelist(): void
    {
        foreach (['auto', 'singbox', 'xray'] as $kernelType) {
            $this->assertFalse(
                $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', ['kernel_type' => $kernelType])->fails(),
                "kernel_type={$kernelType} should pass"
            );
        }

        // Null and omission are allowed (empty = node auto-resolves).
        $this->assertFalse($this->validate(['tls' => 0, 'network' => 'tcp'], 'vless')->fails());
        $this->assertFalse($this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', ['kernel_type' => null])->fails());

        foreach (['sing-box', 'xray-core', 1] as $kernelType) {
            $errors = $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', ['kernel_type' => $kernelType])->errors();
            $this->assertTrue($errors->has('kernel_type'), "kernel_type={$kernelType} should be rejected");
        }
    }

    public function test_shadowsocks_plugin_prohibited(): void
    {
        // SIP003: neither kernel implements plugins; node rejects the whole config.
        $errors = $this->validate(['cipher' => 'aes-256-gcm', 'plugin' => 'obfs-local'], 'shadowsocks')->errors();
        $this->assertTrue($errors->has('protocol_settings.plugin'));

        $errors = $this->validate(['cipher' => 'aes-256-gcm', 'plugin_opts' => 'obfs=http'], 'shadowsocks')->errors();
        $this->assertTrue($errors->has('protocol_settings.plugin_opts'));

        // Omitted / null keep passing for legacy payloads.
        $this->assertFalse($this->validate(['cipher' => 'aes-256-gcm'], 'shadowsocks')->fails());
        $this->assertFalse($this->validate(['cipher' => 'aes-256-gcm', 'plugin' => null], 'shadowsocks')->fails());
    }

    public function test_shadowsocks_obfs_prohibited(): void
    {
        // ss simple-obfs: no kernel implements it server-side and the node is
        // never told — an obfs admin setting silently broke obfs clients.
        // Same prohibition contract as plugin above.
        $errors = $this->validate(['cipher' => 'aes-256-gcm', 'obfs' => 'http'], 'shadowsocks')->errors();
        $this->assertTrue($errors->has('protocol_settings.obfs'));

        $errors = $this->validate(['cipher' => 'aes-256-gcm', 'obfs_settings' => ['path' => '/obfs']], 'shadowsocks')->errors();
        $this->assertTrue($errors->has('protocol_settings.obfs_settings.path'));

        // Omitted / null keep passing for legacy payloads.
        $this->assertFalse($this->validate(['cipher' => 'aes-256-gcm'], 'shadowsocks')->fails());
        $this->assertFalse($this->validate(['cipher' => 'aes-256-gcm', 'obfs' => null], 'shadowsocks')->fails());
    }

    public function test_tuic_udp_relay_mode_enum(): void
    {
        foreach (['native', 'quic'] as $mode) {
            $this->assertFalse(
                $this->validate(['version' => 1, 'udp_relay_mode' => $mode], 'tuic')->fails(),
                "udp_relay_mode={$mode} should pass"
            );
        }

        foreach (['udp', 'native2', 1] as $mode) {
            $errors = $this->validate(['version' => 1, 'udp_relay_mode' => $mode], 'tuic')->errors();
            $this->assertTrue($errors->has('protocol_settings.udp_relay_mode'), "udp_relay_mode={$mode} should be rejected");
        }
    }

    public function test_multiplex_subkey_shapes(): void
    {
        $valid = [
            'tls' => 0,
            'network' => 'tcp',
            'multiplex' => [
                'enabled' => true,
                'protocol' => 'yamux',
                'max_connections' => 4,
                'min_streams' => 4,
                'max_streams' => 0,
                'padding' => false,
            ],
        ];
        $this->assertFalse($this->validate($valid, 'vmess')->fails());

        foreach ([['protocol' => 'quic'], ['max_connections' => -1], ['min_streams' => 'many'], ['max_streams' => -3]] as $bad) {
            $payload = $valid;
            $payload['multiplex'] = array_merge($payload['multiplex'], $bad);
            $errors = $this->validate($payload, 'vmess')->errors();
            $this->assertNotEmpty($errors, 'multiplex payload should be rejected: ' . json_encode($bad));
        }
    }

    public function test_custom_routes_accepts_three_shapes(): void
    {
        $base = ['tls' => 0, 'network' => 'tcp'];

        // structured match/action (the shape the node compiles per kernel)
        $this->assertFalse($this->validate($base, 'vless', [
            'custom_routes' => [[
                'match' => ['networks' => ['tcp', 'udp']],
                'action' => ['type' => 'route', 'target' => 'proxy'],
            ]],
        ])->fails());

        // Xray-native
        $this->assertFalse($this->validate($base, 'vless', [
            'custom_routes' => [['type' => 'field', 'network' => 'udp,tcp', 'outboundTag' => 'proxy']],
        ])->fails());

        // sing-box native
        $this->assertFalse($this->validate($base, 'vless', [
            'custom_routes' => [['outbound' => 'proxy', 'ip_cidr' => ['10.0.0.0/8']]],
        ])->fails());

        // unrecognized shape is rejected with the offending keys named
        $errors = $this->validate($base, 'vless', [
            'custom_routes' => [['foo' => 'bar', 'outboundTag2' => 'x']],
        ])->errors();
        $this->assertNotEmpty($errors, 'unrecognized raw route shape should be rejected');
    }

    public function test_cert_config_mode_whitelist(): void
    {
        // dns/http/file/content 各有 required_if 配套字段，走专属用例；此处只测无附加要求的模式
        foreach (['none', 'self'] as $mode) {
            $this->assertFalse(
                $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', ['cert_config' => ['cert_mode' => $mode]])->fails(),
                "cert_mode={$mode} should pass"
            );
            $this->assertFalse(
                $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', ['cert_config' => ['mode' => $mode]])->fails(),
                "mode={$mode} should pass"
            );
        }

        foreach (['acme', 'tls', 1] as $mode) {
            $errors = $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', ['cert_config' => ['cert_mode' => $mode]])->errors();
            $this->assertTrue($errors->has('cert_config.cert_mode'), "cert_mode={$mode} should be rejected");
        }
    }

    public function test_cert_config_dns_requires_provider_and_domain(): void
    {
        $errors = $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', [
            'cert_config' => ['cert_mode' => 'dns'],
        ])->errors();
        $this->assertTrue($errors->has('cert_config.dns_provider'));
        $this->assertTrue($errors->has('cert_config.domain'));

        // Provider whitelist matches xboard-node docs-dns-providers.md primary names.
        $errors = $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', [
            'cert_config' => ['cert_mode' => 'dns', 'domain' => 'a.example.com', 'dns_provider' => 'cf'],
        ])->errors();
        $this->assertTrue($errors->has('cert_config.dns_provider'));

        $this->assertFalse($this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', [
            'cert_config' => ['cert_mode' => 'dns', 'domain' => 'a.example.com', 'dns_provider' => 'cloudflare', 'dns_env' => ['CLOUDFLARE_DNS_API_TOKEN' => 'xxx']],
        ])->fails());
    }

    public function test_cert_config_file_and_content_required_fields(): void
    {
        $errors = $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', [
            'cert_config' => ['cert_mode' => 'file'],
        ])->errors();
        $this->assertTrue($errors->has('cert_config.cert_file'));
        $this->assertTrue($errors->has('cert_config.key_file'));

        $this->assertFalse($this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', [
            'cert_config' => ['cert_mode' => 'file', 'cert_file' => '/cert.pem', 'key_file' => '/key.pem'],
        ])->fails());

        $errors = $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', [
            'cert_config' => ['cert_mode' => 'content'],
        ])->errors();
        $this->assertTrue($errors->has('cert_config.cert_content'));
        $this->assertTrue($errors->has('cert_config.key_content'));
    }

    public function test_custom_route_rules_shape(): void
    {
        $valid = [
            [
                'name' => 'direct-example',
                'match' => ['domain_suffixes' => ['example.com']],
                'action' => ['type' => 'direct'],
            ],
            [
                'name' => 'route-warp',
                'disabled' => true,
                'match' => ['ip_cidrs' => ['1.1.1.0/24'], 'ports' => ['443', '8000-9000'], 'networks' => ['tcp']],
                'action' => ['type' => 'route', 'target' => 'warp-out'],
            ],
        ];

        $this->assertFalse($this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', ['custom_route_rules' => $valid])->fails());

        $errors = $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', [
            'custom_route_rules' => [
                ['match' => ['domains' => ['ads.example.com']], 'action' => ['type' => 'reject']],
            ],
        ])->errors();
        $this->assertTrue($errors->has('custom_route_rules.0.action.type'));

        // route action must carry a target outbound tag
        $errors = $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', [
            'custom_route_rules' => [
                ['match' => ['ip_cidrs' => ['1.1.1.0/24']], 'action' => ['type' => 'route']],
            ],
        ])->errors();
        $this->assertTrue($errors->has('custom_route_rules.0.action.target'));

        // networks only accepts tcp/udp
        $errors = $this->validate(['tls' => 0, 'network' => 'tcp'], 'vless', [
            'custom_route_rules' => [
                ['match' => ['networks' => ['sctp']], 'action' => ['type' => 'block']],
            ],
        ])->errors();
        $this->assertTrue($errors->has('custom_route_rules.0.match.networks.0'));
    }

    public function test_alpn_array_and_accept_proxy_protocol(): void
    {
        $this->assertFalse($this->validate([
            'tls' => 1,
            'network' => 'tcp',
            'tls_settings' => ['alpn' => ['h2', 'http/1.1']],
            'accept_proxy_protocol' => true,
        ], 'vless')->fails());

        $errors = $this->validate([
            'tls' => 1,
            'network' => 'tcp',
            'tls_settings' => ['alpn' => 'h2'],
        ], 'vless')->errors();
        $this->assertTrue($errors->has('protocol_settings.tls_settings.alpn'));

        $errors = $this->validate([
            'tls' => 0,
            'network' => 'tcp',
            'accept_proxy_protocol' => 'yes',
        ], 'vless')->errors();
        $this->assertTrue($errors->has('protocol_settings.accept_proxy_protocol'));

        // hysteria/tuic/anytls use the tls.* object form
        $this->assertFalse($this->validate([
            'version' => 2,
            'tls' => ['server_name' => 'a.example.com', 'alpn' => ['h3']],
        ], 'hysteria')->fails());
    }

    public function test_hysteria_policy_fields_whitelists_and_ranges(): void
    {
        // Full valid policy set mirrors hysteria server.yaml semantics.
        $this->assertFalse($this->validate([
            'version' => 2,
            'sniff' => ['enable' => true, 'timeout' => 5, 'rewriteDomain' => true, 'tcpPorts' => '80,443', 'udpPorts' => 'all'],
            'masquerade' => [
                'type' => 'proxy',
                'proxy' => ['url' => 'https://example.com', 'rewriteHost' => true, 'xForwarded' => false, 'insecure' => false],
            ],
            'quic' => [
                'initStreamReceiveWindow' => 8388608,
                'maxIdleTimeout' => 30,
                'maxIncomingStreams' => 1024,
                'disablePathMTUDiscovery' => true,
            ],
            'congestion' => ['type' => 'bbr', 'bbrProfile' => 'aggressive'],
            'resolver' => ['type' => 'https', 'https' => ['addr' => '1.1.1.1:443', 'timeout' => 10, 'sni' => 'cloudflare-dns.com', 'insecure' => false]],
            'disableUDP' => false,
            'udpIdleTimeout' => 60,
            'ignoreClientBandwidth' => true,
            'bandwidth' => ['up' => 100, 'down' => 100, 'disableLossCompensation' => true],
        ], 'hysteria')->fails());

        $errors = $this->validate(['version' => 2, 'masquerade' => ['type' => 'websocket']], 'hysteria')->errors();
        $this->assertTrue($errors->has('protocol_settings.masquerade.type'));

        // 233 is hysteria's reserved control status code
        $errors = $this->validate(['version' => 2, 'masquerade' => ['type' => 'string', 'string' => ['statusCode' => 233]]], 'hysteria')->errors();
        $this->assertTrue($errors->has('protocol_settings.masquerade.string.statusCode'));

        // QUIC window floor = 16384 (server.yaml Config.fill)
        $errors = $this->validate(['version' => 2, 'quic' => ['initStreamReceiveWindow' => 1024]], 'hysteria')->errors();
        $this->assertTrue($errors->has('protocol_settings.quic.initStreamReceiveWindow'));

        $errors = $this->validate(['version' => 2, 'congestion' => ['type' => 'cubic']], 'hysteria')->errors();
        $this->assertTrue($errors->has('protocol_settings.congestion.type'));

        // BBR profile enum matches sing-box fork and hysteria core parsers
        $errors = $this->validate(['version' => 2, 'congestion' => ['type' => 'bbr', 'bbrProfile' => 'high']], 'hysteria')->errors();
        $this->assertTrue($errors->has('protocol_settings.congestion.bbrProfile'));

        $errors = $this->validate(['version' => 2, 'udpIdleTimeout' => 601], 'hysteria')->errors();
        $this->assertTrue($errors->has('protocol_settings.udpIdleTimeout'));

        $errors = $this->validate(['version' => 2, 'resolver' => ['type' => 'dot']], 'hysteria')->errors();
        $this->assertTrue($errors->has('protocol_settings.resolver.type'));
    }

    public function test_sniff_udp_policy_available_on_all_protocols(): void
    {
        $sniff = ['enable' => true, 'timeout' => 3, 'rewriteDomain' => true];
        foreach (['vmess', 'vless', 'trojan', 'shadowsocks', 'tuic', 'anytls', 'socks', 'http', 'mieru'] as $type) {
            $settings = match ($type) {
                'vmess', 'vless' => ['tls' => 0, 'network' => 'tcp', 'sniff' => $sniff, 'udpIdleTimeout' => 60],
                'trojan' => ['network' => 'tcp', 'sniff' => $sniff, 'udpIdleTimeout' => 60],
                'shadowsocks' => ['cipher' => 'aes-256-gcm', 'sniff' => $sniff, 'udpIdleTimeout' => 60],
                'tuic' => ['sniff' => $sniff, 'udpIdleTimeout' => 60],
                'socks', 'anytls' => ['sniff' => $sniff, 'udpIdleTimeout' => 60],
                'http' => ['tls' => 1, 'sniff' => $sniff, 'udpIdleTimeout' => 60],
                'mieru' => ['transport' => 'TCP', 'traffic_pattern' => '', 'sniff' => $sniff, 'udpIdleTimeout' => 60],
                default => ['sniff' => $sniff],
            };
            $this->assertFalse($this->validate($settings, $type)->fails(), "sniff/udpIdleTimeout should validate for {$type}");
        }

        // range check applies everywhere
        $errors = $this->validate(['tls' => 0, 'network' => 'tcp', 'udpIdleTimeout' => 601], 'vless')->errors();
        $this->assertTrue($errors->has('protocol_settings.udpIdleTimeout'));
    }

    public function test_quic_rules_only_on_quic_protocols(): void
    {
        $quic = ['disablePathMTUDiscovery' => true];
        $this->assertFalse($this->validate(['tls' => 0, 'network' => 'tcp', 'quic' => $quic], 'vless')->fails()
            || $this->validate(['quic' => $quic], 'tuic')->fails()
            || $this->validate(['version' => 2, 'quic' => $quic], 'hysteria')->fails());

        $errors = $this->validate(['quic' => ['initStreamReceiveWindow' => 1024]], 'tuic')->errors();
        $this->assertTrue($errors->has('protocol_settings.quic.initStreamReceiveWindow'));
    }

    public function test_naive_http_reject_reality(): void
    {
        foreach (['naive', 'http'] as $type) {
            $errors = $this->validate(['tls' => 2], $type)->errors();
            $this->assertTrue($errors->has('protocol_settings.tls'), "{$type} tls=2 should fail");
            $this->assertFalse($this->validate(['tls' => 1], $type)->fails(), "{$type} tls=1 should pass");
        }
    }

    public function test_tuic_congestion_control_enum(): void
    {
        foreach (['cubic', 'new_reno', 'bbr'] as $cc) {
            $this->assertFalse($this->validate(['congestion_control' => $cc], 'tuic')->fails(), "congestion_control={$cc} should pass");
        }
        $errors = $this->validate(['congestion_control' => 'hybla'], 'tuic')->errors();
        $this->assertTrue($errors->has('protocol_settings.congestion_control'));
    }
}
