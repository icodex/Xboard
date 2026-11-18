<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            // 已取消订单收到迟到付款后，人工退款完成的标记；订单状态保持已取消
            $table->integer('refunded_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            $table->dropColumn('refunded_at');
        });
    }
};
