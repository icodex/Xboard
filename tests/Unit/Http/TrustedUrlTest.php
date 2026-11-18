<?php

namespace Tests\Unit\Http;

use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TrustedUrlTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['app_url' => '']);
        config(['app.url' => 'https://panel.example']);
    }

    public function test_helper_ignores_attacker_host(): void
    {
        app()->instance('request', Request::create('https://attacker.example/'));

        $this->assertSame('https://panel.example/api/x', trusted_site_url('/api/x'));
        $this->assertSame('https://panel.example', trusted_site_url());
    }

    public function test_source_base_url_ignores_referer(): void
    {
        app()->instance('request', Request::create(
            'https://attacker.example/',
            'GET',
            [],
            [],
            [],
            ['HTTP_REFERER' => 'https://evil.example/page']
        ));

        $this->assertSame('https://panel.example/#/order/abc', source_base_url('/#/order/abc'));
    }

    public function test_subscribe_url_uses_configured_site_when_no_override(): void
    {
        app()->instance('request', Request::create('https://attacker.example/'));

        $this->assertStringStartsWith(
            'https://panel.example/',
            Helper::getSubscribeUrl('token-value')
        );
    }

    public function test_invalid_site_url_is_rejected(): void
    {
        config(['app.url' => 'not-a-url']);

        $this->assertNull(trusted_site_url('/api/x'));
    }
}
