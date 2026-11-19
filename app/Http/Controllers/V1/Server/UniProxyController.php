<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Services\DeviceStateService;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;

class UniProxyController extends Controller
{
    public function __construct(
        private readonly DeviceStateService $deviceStateService
    ) {
    }

    private function getNodeInfo(Request $request)
    {
        return $request->attributes->get('node_info');
    }

    public function user(Request $request)
    {
        ini_set('memory_limit', -1);
        $node = $this->getNodeInfo($request);

        ServerService::touchNode($node);

        $version = (string) (Cache::get(CacheKey::get('SERVER_USERS_VERSION')) ?? '0');
        $cacheKey = CacheKey::get('SERVER_USERS_PAYLOAD', $node->id . '_' . $version);

        $payload = Cache::get($cacheKey);
        if (!is_string($payload)) {
            $payload = json_encode(['users' => ServerService::getAvailableUsers($node)]);
            Cache::put($cacheKey, $payload, 60);
        }

        $eTag = sha1($payload);
        if (str_contains($request->header('If-None-Match', ''), $eTag)) {
            return response(null, 304);
        }

        return response($payload, 200, ['Content-Type' => 'application/json'])
            ->header('ETag', "\"{$eTag}\"");
    }

    public function push(Request $request)
    {
        $res = json_decode(request()->getContent(), true);
        if (!is_array($res)) {
            return $this->fail([422, 'Invalid data format']);
        }

        $node = $this->getNodeInfo($request);

        ServerService::processTraffic($node, $res);

        return $this->success(true);
    }

    public function config(Request $request)
    {
        $node = $this->getNodeInfo($request);
        $response = ServerService::buildNodeConfig($node);

        $response['base_config'] = [
            'push_interval' => (int) admin_setting('server_push_interval', 60),
            'pull_interval' => (int) admin_setting('server_pull_interval', 60)
        ];

        $eTag = sha1(json_encode($response));
        if (str_contains($request->header('If-None-Match', ''), $eTag)) {
            return response(null, 304);
        }
        return response($response)->header('ETag', "\"{$eTag}\"");
    }

    public function alivelist(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);
        $deviceLimitUsers = ServerService::getAvailableUsers($node)
            ->where('device_limit', '>', 0);

        $alive = $this->deviceStateService->getAliveList(collect($deviceLimitUsers));

        return response()->json(['alive' => (object) $alive]);
    }

    public function alive(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);
        $data = json_decode(request()->getContent(), true);
        if ($data === null) {
            return response()->json(['error' => 'Invalid online data'], 400);
        }

        ServerService::processAlive($node->id, $data);

        return response()->json(['data' => true]);
    }

    /**
     * REST 兜底在线连接数上报：{uid: 活跃连接数}。
     * hysteria fork 的 ReportOnlineLoop 在 WS 断开期间轮询此端点
     * （此前 v1 无此路由恒 404，REST 期连接数静默丢失）；v2 走 report.online。
     */
    public function online(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return response()->json(['error' => 'Invalid online data'], 400);
        }

        // 连接数上报已停用：写入的 USER_ONLINE_CONN_* 缓存全仓无人读取。
        // 端点保留返回 200，避免旧节点因 404 反复重试。
        return response()->json(['data' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);

        $data = $request->validate([
            'cpu' => 'required|numeric|min:0|max:100',
            'mem.total' => 'required|integer|min:0',
            'mem.used' => 'required|integer|min:0',
            'swap.total' => 'required|integer|min:0',
            'swap.used' => 'required|integer|min:0',
            'disk.total' => 'required|integer|min:0',
            'disk.used' => 'required|integer|min:0',
        ]);

        ServerService::processStatus($node, $data);

        return response()->json(['data' => true, 'code' => 0, 'message' => 'success']);
    }
}
