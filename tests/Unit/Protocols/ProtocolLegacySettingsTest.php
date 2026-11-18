<?php

namespace Tests\Unit\Protocols;

use App\Protocols\Loon;
use App\Protocols\SingBox;
use App\Protocols\Surge;
use Tests\TestCase;

/**
 * 旧节点的 protocol_settings 可能缺少 version/tls/cipher。
 * 直接下标访问会触发 PHP 8 警告，Laravel 转成 ErrorException，整份订阅 500。
 */
class ProtocolLegacySettingsTest extends TestCase
{
    public function test_loon_hysteria_without_settings_does_not_throw(): void
    {
        $server = ['name' => 'n', 'host' => 'h', 'port' => 443, 'protocol_settings' => []];

        $this->assertNull(Loon::buildHysteria('pw', $server, null));
    }

    public function test_loon_hysteria_v2_without_tls_emits_null_sni(): void
    {
        $server = [
            'name' => 'n',
            'host' => 'h',
            'port' => 443,
            'protocol_settings' => ['version' => 2],
        ];

        $line = Loon::buildHysteria('pw', $server, null);

        $this->assertStringContainsString('Hysteria2', $line);
        $this->assertStringContainsString('(null)', $line);
    }

    public function test_surge_hysteria_without_settings_does_not_throw(): void
    {
        $server = ['name' => 'n', 'host' => 'h', 'port' => 443, 'protocol_settings' => []];

        $this->assertSame('', Surge::buildHysteria('pw', $server));
    }

    public function test_surge_shadowsocks_without_cipher_does_not_throw(): void
    {
        $server = ['name' => 'n', 'host' => 'h', 'port' => 8443, 'protocol_settings' => []];

        $config = Surge::buildShadowsocks('pw', $server);

        $this->assertStringContainsString('encrypt-method=', $config);
    }

    public function test_singbox_vmess_without_settings_does_not_throw(): void
    {
        $protocol = new class([], [], null) extends SingBox {
            public function callVmess(string $uuid, array $server): array
            {
                return $this->buildVmess($uuid, $server);
            }
        };

        $out = $protocol->callVmess('uuid', [
            'name' => 'n',
            'host' => 'h',
            'port' => 443,
            'protocol_settings' => [],
        ]);

        $this->assertSame('vmess', $out['type']);
        $this->assertArrayNotHasKey('tls', $out);
    }
}