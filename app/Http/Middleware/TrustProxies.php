<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * 历史默认列表（Cloudflare 段 + 私网段）。
     *
     * 保持原样：现有部署的行为零变化。注意私网段意味着同网段任意主机都能自带
     * X-Forwarded-For 伪造客户端 IP，从而绕过登录限流与审计 IP。
     * 把实际前置代理写进 TRUSTED_PROXIES（逗号分隔）即可收紧。
     */
    private const DEFAULT_PROXIES = [
        "173.245.48.0/20",
        "103.21.244.0/22",
        "103.22.200.0/22",
        "103.31.4.0/22",
        "141.101.64.0/18",
        "108.162.192.0/18",
        "190.93.240.0/20",
        "188.114.96.0/20",
        "197.234.240.0/22",
        "198.41.128.0/17",
        "162.158.0.0/15",
        "104.16.0.0/13",
        "104.24.0.0/14",
        "172.64.0.0/13",
        "131.0.72.0/22",
        "10.0.0.0/8",
        "172.16.0.0/12",
        "192.168.0.0/16",
        "169.254.0.0/16",
        "127.0.0.0/8",
    ];

    /**
     * 可信代理列表：未配置时用上面的历史默认值。
     * @var array<int, string>|string|null
     */
    protected $proxies;

    public function __construct()
    {
        $configured = config('app.trusted_proxies');
        $this->proxies = !empty($configured) ? $configured : self::DEFAULT_PROXIES;
    }

    /**
     * 代理头映射
     * @var int
     */
    protected $headers =
    Request::HEADER_X_FORWARDED_FOR |
    Request::HEADER_X_FORWARDED_HOST |
    Request::HEADER_X_FORWARDED_PORT |
    Request::HEADER_X_FORWARDED_PROTO |
    Request::HEADER_X_FORWARDED_AWS_ELB;
}
