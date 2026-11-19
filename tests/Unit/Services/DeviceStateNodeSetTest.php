<?php

namespace Tests\Unit\Services;

use App\Services\DeviceStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 设备上报的差集必须来自「本节点的用户集合」，而不是 SCAN 整个 user_devices:* 键空间。
 * 这里锁住集合的增删语义，避免退回 O(全部用户) 的扫描。
 */
class DeviceStateNodeSetTest extends TestCase
{
    use RefreshDatabase;

    private const NODE = 987654;

    private DeviceStateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DeviceStateService::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function test_set_devices_registers_the_user_for_the_node(): void
    {
        $this->service->setDevices(11, self::NODE, ['10.0.0.1']);

        $this->assertContains(11, $this->service->getNodeUserIds(self::NODE));
        $this->assertSame(1, $this->service->getDeviceCount(11));
    }

    public function test_removing_devices_drops_the_user_from_the_node_set(): void
    {
        $this->service->setDevices(12, self::NODE, ['10.0.0.2']);

        $this->service->removeNodeDevices(self::NODE, 12);

        $this->assertNotContains(12, $this->service->getNodeUserIds(self::NODE));
        $this->assertSame(0, $this->service->getDeviceCount(12));
    }

    public function test_other_nodes_devices_survive_removal(): void
    {
        $this->service->setDevices(13, self::NODE, ['10.0.0.3']);
        $this->service->setDevices(13, self::NODE + 1, ['10.0.0.4']);

        $this->service->removeNodeDevices(self::NODE, 13);

        $this->assertContains(13, $this->service->getNodeUserIds(self::NODE + 1));
        $this->assertSame(1, $this->service->getDeviceCount(13));
    }

    public function test_clearing_a_node_returns_and_forgets_its_users(): void
    {
        $this->service->setDevices(14, self::NODE, ['10.0.0.5']);
        $this->service->setDevices(15, self::NODE, ['10.0.0.6']);

        $cleared = $this->service->clearAllNodeDevices(self::NODE);

        sort($cleared);
        $this->assertSame([14, 15], $cleared);
        $this->assertSame([], $this->service->getNodeUserIds(self::NODE));
        $this->assertSame(0, $this->service->getDeviceCount(14));
    }

    private function cleanup(): void
    {
        Redis::del('node_users:' . self::NODE, 'node_users:' . (self::NODE + 1));
        foreach ([11, 12, 13, 14, 15] as $userId) {
            Redis::del('user_devices:' . $userId);
        }
    }
}
