<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTrafficResetFieldsToUsers extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        ini_set('memory_limit', '-1');
        if (!Schema::hasColumn('v2_user', 'next_reset_at')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->integer('next_reset_at')->nullable()->after('expired_at')->comment('下次流量重置时间');
                $table->integer('last_reset_at')->nullable()->after('next_reset_at')->comment('上次流量重置时间');
                $table->integer('reset_count')->default(0)->after('last_reset_at')->comment('流量重置次数');
                $table->index('next_reset_at', 'idx_next_reset_at');
            });
        }

        // The one-shot next_reset_at backfill lives in
        // 2026_04_19_000001_backfill_users_next_reset_at, which runs after this
        // migration and checks the exit code. Running it twice wasted a full
        // table scan on every install.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropIndex('idx_next_reset_at');
            $table->dropColumn(['next_reset_at', 'last_reset_at', 'reset_count']);
        });
    }
}