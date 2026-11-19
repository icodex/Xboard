<?php

namespace App\Utils;

class CacheKey
{
    /**
     * 生成缓存键。
     *
     * 旧实现维护了一份 CORE_KEYS 白名单 + 通配模式表，但三个分支返回的是同一个
     * 字符串，唯一差别是 local 环境多一条警告：既挡不住拼错（照样返回、照样生效），
     * 也没有任何方位影响。要核对某个键，grep 调用点比维护这份影子清单更可靠。
     */
    public static function get(string $key, mixed $uniqueValue = null): string
    {
        return $uniqueValue ? $key . '_' . $uniqueValue : $key;
    }
}
