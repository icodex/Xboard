<?php
use App\Support\Setting;

if (!function_exists('admin_setting')) {
    /**
     * 获取或保存配置参数.
     *
     * @param  string|array  $key
     * @param  mixed  $default
     * @return App\Support\Setting|mixed
     */
    function admin_setting($key = null, $default = null)
    {
        $setting = app(Setting::class);

        if ($key === null) {
            return $setting->toArray();
        }

        if (is_array($key)) {
            $setting->save($key);
            return '';
        }

        $default = config('v2board.' . $key) ?? $default;
        return $setting->get($key) ?? $default;
    }
}

if (!function_exists('subscribe_template')) {
    /**
     * Get subscribe template content by protocol name.
     */
    function subscribe_template(string $name): ?string
    {
        return \App\Models\SubscribeTemplate::getContent($name);
    }
}

if (!function_exists('admin_settings_batch')) {
    /**
     * 批量获取配置参数，性能优化版本
     *
     * @param array $keys 配置键名数组
     * @return array 返回键值对数组
     */
    function admin_settings_batch(array $keys): array
    {
        return app(Setting::class)->getBatch($keys);
    }
}

if (!function_exists('trusted_site_url')) {
    /**
     * Build an absolute URL from the configured site address only.
     * Request Host and Referer are attacker-controlled and must never decide
     * where a login token, subscription token or payment callback goes.
     */
    function trusted_site_url(string $path = ''): ?string
    {
        $base = rtrim((string) (admin_setting('app_url') ?: config('app.url')), '/');
        $scheme = parse_url($base, PHP_URL_SCHEME);

        if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('source_base_url')) {
    /**
     * 获取来源基础URL：只使用配置的站点地址，不信任 Referer/Host。
     * @param string $path
     * @return string
     */
    function source_base_url(string $path = ''): string
    {
        return trusted_site_url($path) ?? '';
    }
}
