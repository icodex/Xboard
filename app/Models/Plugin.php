<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $description
 * @property string $version
 * @property string $author
 * @property string $url
 * @property string $email
 * @property string $license
 * @property string $requires
 * @property string $config
 * @property string $type
 * @property boolean $is_enabled    
 */
class Plugin extends Model
{
    protected $table = 'v2_plugins';

    const TYPE_FEATURE = 'feature';
    const TYPE_PAYMENT = 'payment';

    // 默认不可删除的插件列表
    const PROTECTED_PLUGINS = [
        'epay',           // EPay
        'alipay_f2f',     // Alipay F2F
        'btcpay',         // BTCPay
        'coinbase',       // Coinbase
        'coin_payments',  // CoinPayments
        'mgate',          // MGate
        'telegram',       // Telegram
    ];

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    /**
     * 启用插件列表的缓存键：全局中间件每个请求都要读它，不缓存就是每请求一次查询。
     * 任何写入都清掉，避免"刚启用却要等缓存过期才生效"。
     */
    public const ENABLED_CACHE_KEY = 'plugins:enabled';

    protected static function booted(): void
    {
        $flush = static fn () => \Illuminate\Support\Facades\Cache::forget(self::ENABLED_CACHE_KEY);

        static::saved($flush);
        static::deleted($flush);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function isFeaturePlugin(): bool
    {
        return $this->type === self::TYPE_FEATURE;
    }

    public function isPaymentPlugin(): bool
    {
        return $this->type === self::TYPE_PAYMENT;
    }

    public function isProtected(): bool
    {
        return in_array($this->code, self::PROTECTED_PLUGINS);
    }

    public function canBeDeleted(): bool
    {
        return !$this->isProtected();
    }

}
