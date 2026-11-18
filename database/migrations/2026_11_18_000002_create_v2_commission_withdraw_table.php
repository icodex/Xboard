<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_commission_withdraw')) {
            Schema::create('v2_commission_withdraw', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('user_id')->index();
                $table->integer('amount')->comment('提现金额（分）');
                $table->string('method', 50);
                $table->string('account');
                $table->integer('status')->default(0)->index()->comment('0待处理 1已支付 2已拒绝');
                $table->integer('ticket_id')->nullable();
                $table->integer('admin_id')->nullable();
                $table->string('reason')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_commission_withdraw');
    }
};
