<?php

namespace App\Models;

use App\Utils\Helper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\User
 *
 * @property int $id 用户ID
 * @property string $email 邮箱
 * @property string $password 密码
 * @property string|null $password_algo 加密方式
 * @property string|null $password_salt 加密盐
 * @property string $token 邀请码
 * @property string $uuid
 * @property int|null $invite_user_id 邀请人
 * @property int|null $plan_id 订阅ID
 * @property int|null $group_id 权限组ID
 * @property int|null $transfer_enable 流量(KB)
 * @property int|null $speed_limit 限速Mbps
 * @property int|null $u 上行流量
 * @property int|null $d 下行流量
 * @property int|null $banned 是否封禁
 * @property int|null $remind_expire 到期提醒
 * @property int|null $remind_traffic 流量提醒
 * @property int|null $expired_at 过期时间
 * @property int|null $balance 余额
 * @property int|null $commission_balance 佣金余额
 * @property float $commission_rate 返佣比例
 * @property int|null $commission_type 返佣类型
 * @property int|null $device_limit 设备限制数量
 * @property int|null $discount 折扣
 * @property int|null $last_login_at 最后登录时间
 * @property int|null $parent_id 父账户ID
 * @property int|null $is_admin 是否管理员
 * @property int|null $next_reset_at 下次流量重置时间
 * @property int|null $last_reset_at 上次流量重置时间
 * @property int|null $telegram_id Telegram ID
 * @property int $reset_count 流量重置次数
 * @property int $created_at
 * @property int $updated_at
 * @property bool $commission_auto_check 是否自动计算佣金
 *
 * @property-read User|null $invite_user 邀请人信息
 * @property-read \App\Models\Plan|null $plan 用户订阅计划
 * @property-read ServerGroup|null $group 权限组
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InviteCode> $codes 邀请码列表
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Order> $orders 订单列表
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StatUser> $stat 统计信息
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Ticket> $tickets 工单列表
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TrafficResetLog> $trafficResetLogs 流量重置记录
 * @property-read User|null $parent 父账户
 * @property-read string $subscribe_url 订阅链接（动态生成）
 */
class User extends Authenticatable
{
    use HasApiTokens;
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'banned' => 'boolean',
        'is_admin' => 'boolean',
        'is_staff' => 'boolean',
        'remind_expire' => 'boolean',
        'remind_traffic' => 'boolean',
        'commission_auto_check' => 'boolean',
        'commission_rate' => 'float',
        'next_reset_at' => 'timestamp',
        'last_reset_at' => 'timestamp',
    ];
    protected $hidden = ['password'];

    /**
     * 列默认值是 0（v2board 遗留），而代码只认 NULL 为「永不过期」。
     * 不给默认属性的话，任何不带 expired_at 的插入都会写成 0：
     * scopeActive() 匹配不到（用户从节点/订阅里消失），续费也不重置流量。
     */
    protected $attributes = [
        'expired_at' => null,
    ];

    public const COMMISSION_TYPE_SYSTEM = 0;
    public const COMMISSION_TYPE_PERIOD = 1;
    public const COMMISSION_TYPE_ONETIME = 2;
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => self::normalizeEmail($value),
        );
    }

    /**
     * Normalize an email consistently for storage, lookup and cache keys.
     */
    public static function normalizeEmail(string $email): string
    {
        $email = trim($email);
        $email = \Normalizer::normalize($email, \Normalizer::FORM_C) ?: $email;

        $at = strrpos($email, '@');
        if ($at === false) {
            return mb_strtolower($email, 'UTF-8');
        }

        $local = mb_strtolower(substr($email, 0, $at), 'UTF-8');
        $local = \Normalizer::normalize($local, \Normalizer::FORM_C) ?: $local;
        $domain = substr($email, $at + 1);
        $asciiDomain = idn_to_ascii(
            $domain,
            IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII,
            INTL_IDNA_VARIANT_UTS46
        );

        return $local . '@' . ($asciiDomain === false
            ? mb_strtolower($domain, 'UTF-8')
            : strtolower($asciiDomain));
    }

    /**
     * Query by the canonical email identity.
     */
    public function scopeByEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', self::normalizeEmail($email));
    }

    /**
     * 「永不过期」只保留 NULL 一种表示。
     *
     * v2board 遗留用 0；两种表示并存时 OrderService 只把 NULL 当"需要重置流量"，
     * 于是 expired_at=0 的用户续费后不重置流量（新周期带着旧用量开始）。
     * 历史数据由 2026_11_19_000002 迁移归一，这里堵住新的写入。
     */
    public function setExpiredAtAttribute($value): void
    {
        $this->attributes['expired_at'] = ($value === 0 || $value === '0' || $value === '') ? null : $value;
    }

    /**
     * 有效用户：未过期。
     * 口径必须与 isActive() 一致。
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('expired_at', '>', time())
                ->orWhereNull('expired_at');
        });
    }

    // 获取邀请人信息
    public function invite_user(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invite_user_id', 'id');
    }

    /**
     * 获取用户订阅计划
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class, 'group_id', 'id');
    }

    // 获取用户邀请码列表
    public function codes(): HasMany
    {
        return $this->hasMany(InviteCode::class, 'user_id', 'id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id', 'id');
    }

    public function stat(): HasMany
    {
        return $this->hasMany(StatUser::class, 'user_id', 'id');
    }

    // 关联工单列表
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'user_id', 'id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    /**
     * 关联流量重置记录
     */
    public function trafficResetLogs(): HasMany
    {
        return $this->hasMany(TrafficResetLog::class, 'user_id', 'id');
    }

    /**
     * 检查用户是否处于活跃状态
     *
     * 口径必须与 ServerService::getAvailableUsers()（节点全量下发）一致：
     * 无 plan、expired_at=0（v2board 遗留，永不过期）都算可用。
     * 否则节点同步互相打架：全量把用户下发给节点，admin 一改字段，
     * 增量 delta 却按 remove 推送把人踢下线，下次全量又加回来。
     */
    public function isActive(): bool
    {
        return !$this->banned &&
               ($this->expired_at === null || $this->expired_at > time());
    }

    /** 
     * 检查用户是否可用节点流量且充足
     */
    public function isAvailable(): bool
    {     
        return $this->isActive() && $this->getRemainingTraffic() > 0;   
    }

    /**
     * 检查是否需要重置流量
     */
    public function shouldResetTraffic(): bool
    {
        // next_reset_at 是 'timestamp' cast → int（不是 Carbon）；写 ->timestamp
        // 会在运行时抛 "Attempt to read property on int"，让每分钟的 reset:traffic
        // 对到期用户静默失败（ErrorException 被命令的 catch 吞掉）。
        return $this->isActive()
            && $this->next_reset_at !== null
            && $this->next_reset_at <= time();
    }

    /**
     * 获取总使用流量
     */
    public function getTotalUsedTraffic(): int
    {
        return ($this->u ?? 0) + ($this->d ?? 0);
    }

    /**
     * 获取剩余流量
     */
    public function getRemainingTraffic(): int
    {
        $used = $this->getTotalUsedTraffic();
        $total = $this->transfer_enable ?? 0;
        return max(0, $total - $used);
    }

    /**
     * 获取流量使用百分比
     */
    public function getTrafficUsagePercentage(): float
    {
        $total = $this->transfer_enable ?? 0;
        if ($total <= 0) {
            return 0;
        }
        
        $used = $this->getTotalUsedTraffic();
        return min(100, ($used / $total) * 100);
    }
}
