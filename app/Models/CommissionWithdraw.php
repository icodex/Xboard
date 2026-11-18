<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionWithdraw extends Model
{
    protected $table = 'v2_commission_withdraw';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'amount' => 'integer',
        'status' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    const STATUS_PENDING = 0;
    const STATUS_PAID = 1;
    const STATUS_REJECTED = 2;

    public static $statusMap = [
        self::STATUS_PENDING => '待处理',
        self::STATUS_PAID => '已支付',
        self::STATUS_REJECTED => '已拒绝',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
