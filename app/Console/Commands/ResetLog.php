<?php

namespace App\Console\Commands;

use App\Models\MailLog;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Utils\Dict;
use Illuminate\Console\Command;

class ResetLog extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:log';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清空日志';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $cutoff = strtotime('-' . Dict::STAT_RETENTION_MONTHS . ' month', time());
        StatUser::where('record_at', '<', $cutoff)->delete();
        StatServer::where('record_at', '<', $cutoff)->delete();
        // 发信日志每次发信都写一行，不清理会无限增长；审计日志由 cleanup:audit-log 单独负责
        MailLog::where('created_at', '<', strtotime('-1 month', time()))->delete();
    }
}
