<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            // 每节点内核选择：singbox/xray/auto（空 = 节点侧 auto 解析），下发 kernel_type
            $table->string('kernel_type')->nullable()->after('kernel_log_level');
            // 结构化跨内核路由规则（xboard-node docs-custom-routes.md），下发 custom_route_rules
            $table->json('custom_route_rules')->nullable()->after('custom_routes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropColumn('kernel_type');
            $table->dropColumn('custom_route_rules');
        });
    }
};
