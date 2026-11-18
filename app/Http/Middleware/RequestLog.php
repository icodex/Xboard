<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;

class RequestLog
{
    public function handle($request, Closure $next)
    {
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        $response = $next($request);

        try {
            $admin = $request->user();
            if (!$admin || !$admin->is_admin) {
                return $response;
            }

            $action = $this->resolveAction($request->path());
            $data = self::scrub($request->all());

            AdminAuditLog::insert([
                'admin_id' => $admin->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => $request->getPathInfo(),
                'request_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'ip' => $request->getClientIp(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log write failed: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * 递归剔除敏感字段：表单里常有 smtp_password / payment_key 这类嵌套键，
     * 顶层 except 拦不住。
     */
    protected static function scrub(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            if (
                str_contains($lower, 'password')
                || str_contains($lower, 'secret')
                || str_contains($lower, 'token')
                || str_contains($lower, 'api_key')
                || $lower === 'key'
                || str_ends_with($lower, '_key')
            ) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::scrub($value) : $value;
        }
        return $clean;
    }

    private function resolveAction(string $path): string
    {
        // api/v2/{secure_path}/user/update → user.update
        $path = preg_replace('#^api/v[12]/[^/]+/#', '', $path);
        // gift-card/create-template → gift_card.create_template
        $path = str_replace('-', '_', $path);
        // user/update → user.update, server/manage/sort → server_manage.sort
        $segments = explode('/', $path);
        $method = array_pop($segments);
        $resource = implode('_', $segments);

        return $resource . '.' . $method;
    }
}
