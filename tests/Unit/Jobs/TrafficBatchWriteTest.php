<?php

namespace Tests\Unit\Jobs;

use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\StatUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class TrafficBatchWriteTest extends TestCase
{
    use RefreshDatabase;

    protected array $userIds = [];

    protected function tearDown(): void
    {
        foreach ($this->userIds ?? [] as $uid) {
            Redis::srem('traffic:pending_check', $uid);
        }
        parent::tearDown();
    }

    public function test_traffic_fetch_increments_all_users_exactly(): void
    {
        // >= 3 users: single-user data cannot expose CASE binding misalignment
        $data = $this->makeData([[100, 200], [300, 400], [0, 0]]);

        (new TrafficFetchJob($this->serverArr(), $data, 'vmess', strtotime(date('Y-m-d'))))->handle();

        // [u, d] per user after rate 2 applied
        $expected = [[200, 400], [600, 800], [0, 0]];
        foreach (array_values($this->userIds) as $i => $uid) {
            $row = User::findOrFail($uid);
            $this->assertSame($expected[$i][0], (int) $row->u, "user {$uid} u");
            $this->assertSame($expected[$i][1], (int) $row->d, "user {$uid} d");
            $this->assertGreaterThan(0, (int) $row->t);
        }
    }

    public function test_second_run_accumulates(): void
    {
        $data = $this->makeData([[100, 200], [300, 400], [50, 25]]);

        (new TrafficFetchJob($this->serverArr(), $data, 'vmess', strtotime(date('Y-m-d'))))->handle();
        (new TrafficFetchJob($this->serverArr(), $data, 'vmess', strtotime(date('Y-m-d'))))->handle();

        $this->assertSame(400, (int) User::findOrFail($this->userIds[0])->u);
        $this->assertSame(1200, (int) User::findOrFail($this->userIds[1])->u);
        $this->assertSame(100, (int) User::findOrFail($this->userIds[2])->d);
    }

    public function test_stat_user_rows_written_per_user(): void
    {
        $data = $this->makeData([[100, 200], [300, 400], [10, 20]]);
        $recordAt = strtotime(date('Y-m-d'));

        (new StatUserJob($this->serverArr(), $data, 'vmess', 'd'))->handle();

        $this->assertSame(3, StatUser::whereIn('user_id', $this->userIds)->where('record_at', $recordAt)->count());
        $stat = StatUser::where('user_id', $this->userIds[0])->where('record_at', $recordAt)->firstOrFail();
        $this->assertSame(200, (int) $stat->u);
        $this->assertSame(400, (int) $stat->d);
        $this->assertEqualsWithDelta(2.0, (float) $stat->server_rate, 0.001);
    }

    public function test_pending_check_set_contains_users(): void
    {
        $data = $this->makeData([[1, 2], [3, 4], [5, 6]]);

        (new TrafficFetchJob($this->serverArr(), $data, 'vmess', strtotime(date('Y-m-d'))))->handle();

        foreach ($this->userIds as $uid) {
            $this->assertTrue(Redis::sismember('traffic:pending_check', $uid));
        }
    }

    private function serverArr(): array
    {
        return ['id' => 999999, 'rate' => 2];
    }

    private function makeData(array $rows): array
    {
        $this->userIds = [];
        $data = [];
        foreach ($rows as $i => $v) {
            $user = $this->makeUser([
                'email' => sprintf('batch-test-%d-%d@example.com', $i, time()),
                'token' => substr(md5('batch' . $i . microtime()), 0, 32),
                'transfer_enable' => 10737418240,
            ]);
            $this->userIds[] = $user->id;
            $data[$user->id] = $v;
        }
        return $data;
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'batch-test@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'token' => substr(md5((string) microtime()), 0, 32),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }
}
