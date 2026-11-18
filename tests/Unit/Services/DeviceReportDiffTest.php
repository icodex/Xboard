<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\DeviceStateService;
use App\WebSocket\NodeEventHandlers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

/**
 * WS 设备上报的差集逻辑：本节点集合里有、这次上报里没有的用户要移除。
 * 原来靠 SCAN 全库算差集，改成 node_users:{nodeId} 集合后这里必须仍然正确。
 */
class DeviceReportDiffTest extends TestCase
{
    use RefreshDatabase;

    private const NODE = 987651;

    private DeviceStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->service = app(DeviceStateService::class);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function test_users_missing_from_the_report_are_dropped(): void
    {
        $kept = $this->makeUser('diff-kept@example.com', '00000000-0000-0000-0000-0000000000d7');
        $dropped = $this->makeUser('diff-dropped@example.com', '00000000-0000-0000-0000-0000000000d8');

        $this->service->setDevices($kept->id, self::NODE, ['10.0.0.1']);
        $this->service->setDevices($dropped->id, self::NODE, ['10.0.0.2']);

        $this->report([$kept->id => ['10.0.0.9']]);

        $this->assertSame([$kept->id], $this->service->getNodeUserIds(self::NODE));
        $this->assertSame(0, $this->service->getDeviceCount($dropped->id));
        $this->assertSame(1, $this->service->getDeviceCount($kept->id));
    }

    public function test_empty_report_clears_the_node(): void
    {
        $user = $this->makeUser('diff-empty@example.com', '00000000-0000-0000-0000-0000000000d9');
        $this->service->setDevices($user->id, self::NODE, ['10.0.0.3']);

        $this->report([]);

        $this->assertSame([], $this->service->getNodeUserIds(self::NODE));
        $this->assertSame(0, $this->service->getDeviceCount($user->id));
    }

    public function test_report_marks_the_node_for_push(): void
    {
        $user = $this->makeUser('diff-push@example.com', '00000000-0000-0000-0000-0000000000da');

        $this->report([$user->id => ['10.0.0.4']]);

        $this->assertTrue((bool) Redis::sismember('DEVICE_PUSH_PENDING_NODES', (string) self::NODE));
    }

    /**
     * @param array<int, string[]> $devices
     */
    private function report(array $devices): void
    {
        $conn = new class extends TcpConnection {
            public function __construct()
            {
            }
        };

        NodeEventHandlers::handleDeviceReport($conn, self::NODE, ['devices' => $devices]);
    }

    private function makeUser(string $email, string $uuid): User
    {
        return User::create([
            'email' => $email,
            'password' => 'password',
            'uuid' => $uuid,
            'token' => md5($email),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 107374182400,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function cleanup(): void
    {
        Redis::del('node_users:' . self::NODE);
        Redis::srem('DEVICE_PUSH_PENDING_NODES', (string) self::NODE);
        foreach (['diff-kept', 'diff-dropped', 'diff-empty', 'diff-push'] as $email) {
            $id = User::where('email', $email . '@example.com')->value('id');
            if ($id) {
                Redis::del('user_devices:' . $id);
            }
        }
    }
}
