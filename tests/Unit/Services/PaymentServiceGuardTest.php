<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 支付回调的守卫是钱与越权两条线的交点：回调必须用订单配置的校验器、
 * 未启用的通道必须拒绝、没有站点地址时不能生成回调 URL。
 *
 * 这里不构造真实支付插件（plugins-core 依赖外部网关），只锁这两个守卫本身的语义。
 */
class PaymentServiceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_notify_rejects_a_method_that_differs_from_the_configured_one(): void
    {
        $service = $this->serviceWith(method: 'other', configuredMethod: 'epay', config: ['enable' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('payment method mismatch');

        $service->notify([]);
    }

    public function test_notify_rejects_a_disabled_gateway(): void
    {
        $service = $this->serviceWith(method: 'epay', configuredMethod: 'epay', config: ['enable' => 0]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('gate is not enable');

        $service->notify([]);
    }

    public function test_pay_refuses_to_build_a_notify_url_without_a_site_url(): void
    {
        admin_setting(['app_url' => '']);
        config(['app.url' => '']);

        $service = $this->serviceWith(method: 'epay', configuredMethod: 'epay', config: [
            'enable' => 1,
            'uuid' => 'order-uuid',
            'notify_domain' => '',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Site URL is not configured');

        $service->pay(['trade_no' => 'trade-1']);
    }

    /**
     * 绕过构造函数：真实构造需要一行 v2_payment + 已启用的支付插件，
     * 而这两个守卫只依赖 method / configuredMethod / config 三个字段。
     */
    private function serviceWith(string $method, ?string $configuredMethod, array $config): PaymentService
    {
        $service = (new ReflectionClass(PaymentService::class))->newInstanceWithoutConstructor();

        $service->method = $method;
        $this->setProperty($service, 'configuredMethod', $configuredMethod);
        $this->setProperty($service, 'config', $config);

        return $service;
    }

    private function setProperty(PaymentService $service, string $name, mixed $value): void
    {
        $property = new ReflectionProperty(PaymentService::class, $name);
        $property->setAccessible(true);
        $property->setValue($service, $value);
    }
}