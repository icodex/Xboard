<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot backfill of users.next_reset_at for legacy installs.
 *
 * Replaces the previous `reset:traffic --force` step in `xboard:update`,
 * which had to run on every container start. Now it runs exactly once per
 * database (Laravel migrations are tracked).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'next_reset_at')) {
            return;
        }

        // A silent failure here is permanent: the migration gets recorded, so the
        // backfill never runs again, and every affected user keeps next_reset_at
        // NULL - which means their traffic is never reset. Fail the migration
        // instead, so the next deploy retries it.
        if (Artisan::call('reset:traffic', ['--fix-null' => true]) !== 0) {
            throw new RuntimeException(
                'reset:traffic --fix-null failed, some users still have next_reset_at = null. ' .
                'Fix the underlying error and re-run the migration.'
            );
        }
    }

    public function down(): void
    {
        // Backfill is non-destructive; nothing to roll back.
    }
};
