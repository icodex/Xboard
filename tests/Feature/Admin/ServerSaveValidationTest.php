<?php

namespace Tests\Feature\Admin;

use App\Http\Requests\Admin\ServerSave;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Tests\TestCase;

class ServerSaveValidationTest extends TestCase
{
    private function validate(array $protocolSettings, string $type): Validator
    {
        $request = ServerSave::create('/', 'POST', [
            'type' => $type,
            'name' => 'test-node',
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'protocol_settings' => $protocolSettings,
        ]);

        return ValidatorFactory::make($request->all(), $request->rules());
    }

    public function test_network_whitelist_rejects_unsupported_transport(): void
    {
        $errors = $this->validate(['tls' => 0, 'network' => 'kcp'], 'vless')->errors();
        $this->assertArrayHasKey('protocol_settings.network', $errors);

        $errors = $this->validate(['tls' => 1, 'network' => 'raw'], 'vmess')->errors();
        $this->assertArrayHasKey('protocol_settings.network', $errors);
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
        $this->assertArrayHasKey('protocol_settings.tls', $errors);

        $errors = $this->validate(['tls' => 3, 'network' => 'tcp'], 'vmess')->errors();
        $this->assertArrayHasKey('protocol_settings.tls', $errors);

        $this->assertFalse($this->validate(['tls' => 2, 'network' => 'tcp'], 'vless')->fails());
    }

    public function test_hysteria_version_must_be_1_or_2(): void
    {
        $errors = $this->validate(['version' => 3], 'hysteria')->errors();
        $this->assertArrayHasKey('protocol_settings.version', $errors);

        $this->assertFalse($this->validate(['version' => 2], 'hysteria')->fails());
    }

    public function test_shadowsocks_cipher_whitelist(): void
    {
        $errors = $this->validate(['cipher' => 'aes-128-cfb'], 'shadowsocks')->errors();
        $this->assertArrayHasKey('protocol_settings.cipher', $errors);

        foreach ([
            'aes-128-gcm', 'aes-192-gcm', 'aes-256-gcm',
            'chacha20-ietf-poly1305', 'xchacha20-ietf-poly1305',
            '2022-blake3-aes-128-gcm', '2022-blake3-aes-256-gcm', '2022-blake3-chacha20-poly1305',
        ] as $cipher) {
            $this->assertFalse($this->validate(['cipher' => $cipher], 'shadowsocks')->fails(), "cipher={$cipher} should pass");
        }
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
}
