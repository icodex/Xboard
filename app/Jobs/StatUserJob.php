<?php


namespace App\Jobs;

use App\Models\StatUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $data;
    protected array $server;
    protected string $protocol;
    protected string $recordType;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(array $server, array $data, string $protocol, string $recordType = 'd')
    {
        $this->onQueue('stat');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
    }

    public function handle(): void
    {
        $recordAt = $this->recordType === 'm'
            ? strtotime(date('Y-m-01'))
            : strtotime(date('Y-m-d'));

        if (config('database.default') === 'sqlite') {
            foreach ($this->data as $uid => $v) {
                $this->processUserStatForSqlite($uid, $v, $recordAt);
            }
            return;
        }

        // one multi-row upsert per 500 users instead of one statement each
        foreach (array_chunk($this->data, 500, true) as $chunk) {
            try {
                if (config('database.default') === 'pgsql') {
                    $this->processUserStatForPostgres($chunk, $recordAt);
                } else {
                    $this->processUserStatForOtherDatabases($chunk, $recordAt);
                }
            } catch (\Exception $e) {
                Log::error('StatUserJob failed: ' . $e->getMessage());
                throw $e;
            }
        }
    }

    /**
     * Multi-row upsert for PostgreSQL with arithmetic increments.
     */
    protected function processUserStatForPostgres(array $chunk, int $recordAt): void
    {
        $table = (new StatUser())->getTable();
        $now = time();
        $rows = [];
        $bindings = [];
        foreach ($chunk as $uid => $v) {
            $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $bindings,
                $uid,
                $this->server['rate'],
                $recordAt,
                $this->recordType,
                intval($v[0] * $this->server['rate']),
                intval($v[1] * $this->server['rate']),
                $now,
                $now,
            );
        }

        $sql = "INSERT INTO {$table} (user_id, server_rate, record_at, record_type, u, d, created_at, updated_at)
                VALUES " . implode(',', $rows) . "
                ON CONFLICT (user_id, server_rate, record_at)
                DO UPDATE SET
                    u = {$table}.u + EXCLUDED.u,
                    d = {$table}.d + EXCLUDED.d,
                    updated_at = EXCLUDED.updated_at";

        DB::statement($sql, $bindings);
    }

    /**
     * Multi-row upsert for MySQL / MariaDB.
     */
    protected function processUserStatForOtherDatabases(array $chunk, int $recordAt): void
    {
        $table = (new StatUser())->getTable();
        $now = time();
        $rows = [];
        $bindings = [];
        foreach ($chunk as $uid => $v) {
            $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $bindings,
                $uid,
                $this->server['rate'],
                $recordAt,
                $this->recordType,
                intval($v[0] * $this->server['rate']),
                intval($v[1] * $this->server['rate']),
                $now,
                $now,
            );
        }

        $sql = "INSERT INTO {$table} (user_id, server_rate, record_at, record_type, u, d, created_at, updated_at)
                VALUES " . implode(',', $rows) . "
                ON DUPLICATE KEY UPDATE
                    u = u + VALUES(u),
                    d = d + VALUES(d),
                    updated_at = VALUES(updated_at)";

        DB::statement($sql, $bindings);
    }

    protected function processUserStatForSqlite(int $uid, array $v, int $recordAt): void
    {
        DB::transaction(function () use ($uid, $v, $recordAt) {
            $existingRecord = StatUser::where([
                'user_id' => $uid,
                'server_rate' => $this->server['rate'],
                'record_at' => $recordAt,
                'record_type' => $this->recordType,
            ])->first();

            if ($existingRecord) {
                $existingRecord->update([
                    'u' => $existingRecord->u + intval($v[0] * $this->server['rate']),
                    'd' => $existingRecord->d + intval($v[1] * $this->server['rate']),
                    'updated_at' => time(),
                ]);
            } else {
                StatUser::create([
                    'user_id' => $uid,
                    'server_rate' => $this->server['rate'],
                    'record_at' => $recordAt,
                    'record_type' => $this->recordType,
                    'u' => intval($v[0] * $this->server['rate']),
                    'd' => intval($v[1] * $this->server['rate']),
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
            }
        }, 3);
    }
}