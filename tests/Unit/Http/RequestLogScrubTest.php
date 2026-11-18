<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\RequestLog;
use App\Models\AdminAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Admin audit entries used to redact only top-level keys, so nested settings
 * forms (smtp_password, payment_key, ...) leaked secrets into the DB.
 */
class RequestLogScrubTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_uri_never_contains_query_secrets(): void
    {
        $request = Request::create('/api/v2/admin/user/update?token=secret&name=public', 'POST', [
            'config' => ['api_key' => 'secret', 'host' => 'example.test'],
        ]);
        $request->setUserResolver(fn () => (object) ['id' => 1, 'is_admin' => true]);

        (new RequestLog())->handle($request, fn () => response('ok'));

        $log = AdminAuditLog::query()->sole();
        $this->assertSame('/api/v2/admin/user/update', $log->uri);
        $this->assertStringNotContainsString('secret', $log->request_data);
        $this->assertStringContainsString('example.test', $log->request_data);
    }

    private function scrub(array $data): array
    {
        $log = new class extends RequestLog {
            public static function call(array $data): array
            {
                return self::scrub($data);
            }
        };

        return $log::call($data);
    }

    public function test_nested_secrets_are_dropped(): void
    {
        $clean = $this->scrub([
            'config' => ['smtp_password' => 'x', 'host' => 'h'],
            'key' => 'k',
            'name' => 'n',
        ]);

        $this->assertArrayNotHasKey('smtp_password', $clean['config']);
        $this->assertArrayNotHasKey('key', $clean);
        $this->assertSame('h', $clean['config']['host']);
        $this->assertSame('n', $clean['name']);
    }

    public function test_deeply_nested_suffix_keys_are_dropped(): void
    {
        $clean = $this->scrub([
            'payment' => [
                'stripe' => ['api_key' => 'sk', 'webhook_secret' => 'wh', 'enabled' => 1],
                'alipay' => ['public_key' => 'pk', 'token' => 't'],
            ],
        ]);

        $this->assertSame(['enabled' => 1], $clean['payment']['stripe']);
        $this->assertSame([], $clean['payment']['alipay']);
    }
}
