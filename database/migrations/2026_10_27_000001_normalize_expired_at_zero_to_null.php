<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * v2board 语义中 expired_at = 0 表示永久订阅。
     * 本仓代码只认 NULL 为永久，导致迁移来的永久用户被节点剔除、无法订阅。
     * 统一改写为 NULL（语义等同，无信息损失）。
     */
    public function up(): void
    {
        if (!Schema::hasTable('v2_user')) {
            return;
        }

        DB::table('v2_user')->where('expired_at', 0)->update(['expired_at' => null]);
    }

    public function down(): void
    {
        // 单向数据迁移：0 与 NULL 语义相同，无需回滚。
    }
};
