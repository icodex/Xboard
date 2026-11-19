<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\ServerService;
use App\Utils\CacheKey;
use App\WebSocket\NodeEventHandlers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 设备增量推送的能力协商：只有节点在 report.status 里自报 device_delta，
 * 面板才发 sync.devices.delta；旧节点继续收全量快照。
 */
class NodeDeviceDeltaCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_capabilities_reported_by_the_node_are_recorded(): void
    {
        $node = $this->makeServer();
        Cache::forget(CacheKey::get('NODE_CAPABILITIES', $node->id));

        ServerService::processStatus($node, [
            'cpu' => 1,
            'capabilities' => [NodeEventHandlers::CAPABILITY_DEVICE_DELTA],
        ]);

        $this->assertTrue(NodeEventHandlers::supportsDeviceDelta($node->id));
        $this->assertSame(
            [NodeEventHandlers::CAPABILITY_DEVICE_DELTA],
            Cache::get(CacheKey::get('NODE_CAPABILITIES', $node->id))
        );
    }

    public function test_a_node_that_reports_no_capabilities_keeps_the_full_snapshot_path(): void
    {
        $node = $this->makeServer();
        Cache::forget(CacheKey::get('NODE_CAPABILITIES', $node->id));

        ServerService::processStatus($node, ['cpu' => 1]);

        $this->assertFalse(NodeEventHandlers::supportsDeviceDelta($node->id));
    }

    public function test_unknown_capabilities_do_not_enable_the_delta(): void
    {
        $node = $this->makeServer();

        ServerService::processStatus($node, [
            'capabilities' => ['something_else'],
        ]);

        $this->assertFalse(NodeEventHandlers::supportsDeviceDelta($node->id));
    }

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'capability-node',
            'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'show' => true,
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
        ]);
    }
}