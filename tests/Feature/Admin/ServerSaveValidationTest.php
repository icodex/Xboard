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

        $this->assertFalse($this->validate(['tls' => 2, 'network' => 'tcp'], 'vless')->fails());
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
