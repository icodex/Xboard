<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\StatController;
use App\Models\Stat;
use App\Models\Server;
use App\Models\Server as ServerModel;
use App\Models\StatServer;
use App\Services\StatisticalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 排行榜的求和必须和「哪些节点入选」用同一个时间区间：
 * 无约束的 withSum 会把历史全量算进来，「今日排行」实际显示累计流量。
 */
class StatisticalRankTest extends TestCase
{
    use RefreshDatabase;

    private ?int $serverId = null;

    public function test_server_rank_totals_only_the_requested_range(): void
    {
        $server = $this->makeServer();
        $todayStart = strtotime(date('Y-m-d'));
        $yesterdayStart = strtotime('-1 day', $todayStart);

        $this->makeStat($server->id, $todayStart, 100, 200);
        $this->makeStat($server->id, $yesterdayStart, 5000, 7000);

        $today = collect(StatisticalService::getServerRank('today'))->firstWhere('server_id', $server->id);

        $this->assertNotNull($today);
        $this->assertSame(100, $today['u']);
        $this->assertSame(200, $today['d']);
        $this->assertSame(300, $today['total']);
    }

    public function test_unknown_stat_type_returns_empty_collection(): void
    {
        $service = new StatisticalService();
        $service->setStartAt(0);
        $service->setEndAt(time());

        $this->assertTrue($service->getStatRecord('not-a-type')->isEmpty());
    }

    public function test_stat_record_returns_data_with_default_and_explicit_ranges(): void
    {
        $now = time();
        $record = Stat::create([
            'record_at' => $now - 3600, 'record_type' => 'd',
            'order_count' => 0, 'order_total' => 0, 'commission_count' => 0,
            'commission_total' => 0, 'paid_count' => 1, 'paid_total' => 1200,
            'register_count' => 0, 'invite_count' => 0, 'transfer_used_total' => '0',
        ]);
        $controller = new StatController(new StatisticalService());
        $default = $controller->getStatRecord(Request::create('/', 'GET', ['type' => 'paid_total']));
        $explicit = $controller->getStatRecord(Request::create('/', 'GET', [
            'type' => 'paid_total', 'start_at' => $now - 7200, 'end_at' => $now,
        ]));

        $this->assertSame($record->id, $default['data']->sole()->id);
        $this->assertEquals(12, $explicit['data']->sole()->paid_total);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $controller->getStatRecord(Request::create('/', 'GET', [
            'type' => 'paid_total', 'start_at' => $now, 'end_at' => $now - 1,
        ]));
    }

    private function makeServer(): Server
    {
        return ServerModel::create([
            'name' => 'rank-node',
            'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'enabled' => true,
        ]);
    }

    private function makeStat(int $serverId, int $recordAt, int $u, int $d): void
    {
        StatServer::create([
            'server_id' => $serverId,
            'server_type' => Server::TYPE_VMESS,
            'record_at' => $recordAt,
            'record_type' => 'd',
            'u' => $u,
            'd' => $d,
            'created_at' => $recordAt,
            'updated_at' => $recordAt,
        ]);
    }
}
