<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 补齐三处缺失的唯一约束：缺了它们，重复数据是「静默」发生的 ——
 * 订阅按 token 取用户、优惠券按 code 取券、佣金按订单发钱都只取第一条。
 *
 * 存量库若已有重复行，迁移会带着具体的“要清理哪一列”信息失败，
 * 而不是把唯一索引建在脏数据上（那种失败发生在 MySQL 层，报错更难定位）。
 */
return new class extends Migration {
    public function up(): void
    {
        $this->addUnique('v2_user', ['token'], 'v2_user_token_unique');

        $this->addUnique('v2_coupon', ['code'], 'v2_coupon_code_unique');

        $this->addUnique(
            'v2_commission_log',
            ['trade_no', 'invite_user_id'],
            'v2_commission_log_trade_no_invite_user_id_unique'
        );
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropUnique('v2_user_token_unique');
        });
        Schema::table('v2_coupon', function (Blueprint $table) {
            $table->dropUnique('v2_coupon_code_unique');
        });
        Schema::table('v2_commission_log', function (Blueprint $table) {
            $table->dropUnique('v2_commission_log_trade_no_invite_user_id_unique');
        });
    }

    private function addUnique(string $table, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($table) || Schema::hasIndex($table, $indexName)) {
            return;
        }

        $duplicate = DB::table($table)
            ->select($columns)
            ->selectRaw('COUNT(*) as occurrences')
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->first();

        if ($duplicate) {
            $values = implode(', ', array_map(
                static fn ($column) => "{$column}=" . var_export($duplicate->{$column}, true),
                $columns
            ));

            throw new RuntimeException(
                "无法在 {$table}(" . implode(',', $columns) . ") 上建唯一索引：存量数据有重复行（{$values}）。" .
                '请先合并/删除这些行，再重新执行迁移。'
            );
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->unique($columns, $indexName);
        });
    }
};
