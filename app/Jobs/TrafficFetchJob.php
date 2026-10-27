<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    public $tries = 1;
    public $timeout = 20;

    public function __construct(array $server, array $data, $protocol, int $timestamp)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
    }

    public function handle(): void
    {
        $userIds = array_keys($this->data);

        // one batched UPDATE per 500 users instead of two single-row updates each;
        // CASE id WHEN is standard SQL on mysql/pgsql/sqlite
        foreach (array_chunk($userIds, 500, true) as $chunkIds) {
            $uCases = '';
            $dCases = '';
            $uBindings = [];
            $dBindings = [];
            foreach ($chunkIds as $uid) {
                $v = $this->data[$uid];
                $uCases .= ' WHEN ? THEN ?';
                $dCases .= ' WHEN ? THEN ?';
                // the two CASE blocks consume consecutive binding runs: all u pairs first, then all d pairs
                $uBindings[] = $uid;
                $uBindings[] = (int) round($v[0] * $this->server['rate']);
                $dBindings[] = $uid;
                $dBindings[] = (int) round($v[1] * $this->server['rate']);
            }

            // ids are bound inside the CASE clauses; the IN list takes them as plain ints
            $ids = implode(',', array_map('intval', $chunkIds));
            DB::update(
                'UPDATE ' . (new User())->getTable()
                . ' SET u = u + CASE id' . $uCases . ' ELSE 0 END'
                . ', d = d + CASE id' . $dCases . ' ELSE 0 END'
                . ', t = ? WHERE id IN (' . $ids . ')',
                array_merge($uBindings, $dBindings, [time()])
            );
        }

        if (!empty($userIds)) {
            Redis::sadd('traffic:pending_check', ...$userIds);
        }
    }
}
