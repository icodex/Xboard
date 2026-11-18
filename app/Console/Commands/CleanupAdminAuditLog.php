<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use Illuminate\Console\Command;

class CleanupAdminAuditLog extends Command
{
    protected $signature = 'cleanup:audit-log';

    protected $description = 'Delete admin audit log entries older than 90 days';

    public function handle(): void
    {
        $deleted = AdminAuditLog::where('created_at', '<', time() - 90 * 86400)->delete();
        if ($deleted > 0) {
            $this->info("Deleted {$deleted} stale audit log entries.");
        }
    }
}
