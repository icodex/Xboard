<?php

// 面板下发准确性验证：逐个节点比对「API 实际返回」「buildNodeConfig 组装」，
// 并用独立于 buildNodeConfig 的语义断言核对 DB 原始字段是否如实传达。
// 容器内执行：php artisan tinker --execute="require '/www/verify-api.php';"
// 退出码 0=全部通过，1=存在失败项。

use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Utils\Helper;

$baseUrl = 'http://127.0.0.1:7001/api/v1/server/UniProxy';
$token = admin_setting('server_token');
$token = is_array($token) ? ($token[0] ?? '') : (string) $token;
$testUser = User::where('email', 'admin@xboard.test')->firstOrFail();
$mode = getenv('VERIFY_MODE') ?: 'config';

function apiGet(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr((string) $raw, 0, $headerSize);
    $body = substr((string) $raw, $headerSize);
    $etag = '';
    if (preg_match('/^ETag:\s*"?([^"\r\n]+)"?/mi', $head, $m)) {
        $etag = $m[1];
    }
    return ['status' => $status, 'body' => $body, 'etag' => $etag];
}

function diffDeep($expected, $actual, string $path, array &$diffs): void
{
    if (is_array($expected)) {
        if (!is_array($actual)) {
            $diffs[] = "{$path}: expected array, got " . gettype($actual);
            return;
        }
        foreach (array_keys($expected) as $k) {
            if (!array_key_exists($k, $actual)) {
                $diffs[] = "{$path}.{$k}: missing in API";
                continue;
            }
            diffDeep($expected[$k], $actual[$k], "{$path}.{$k}", $diffs);
        }
        foreach (array_keys($actual) as $k) {
            if (!array_key_exists($k, $expected)) {
                $diffs[] = "{$path}.{$k}: unexpected in API (" . json_encode($actual[$k]) . ')';
            }
        }
        return;
    }
    if ($expected !== $actual) {
        $diffs[] = "{$path}: expected " . json_encode($expected) . ', got ' . json_encode($actual);
    }
}

$failures = [];
$checked = 0;
$notes = [];

foreach (Server::whereJsonContains('group_ids', (string) (App\Models\ServerGroup::where('name', '全协议测试')->value('id')))->orderBy('id')->get() as $node) {
    $checked++;
    $ps = $node->protocol_settings ?? [];
    $fail = function (string $msg) use (&$failures, $node) {
        $failures[] = "#{$node->id} {$node->name}: {$msg}";
    };

    $url = $baseUrl . '/config?' . http_build_query([
        'token' => $token, 'node_id' => $node->id, 'node_type' => $node->type,
    ]);
    $res = apiGet($url);
    if ($res['status'] !== 200) {
        $fail("config HTTP {$res['status']}");
        continue;
    }
    $actual = json_decode($res['body'], true);
    if (!is_array($actual)) {
        $fail('config body is not JSON object');
        continue;
    }

    // 1) 传输保真：API 必须与面板组装结果逐字段一致（含类型），仅多 base_config。
    $expected = ServerService::buildNodeConfig($node);
    $expected['base_config'] = [
        'push_interval' => (int) admin_setting('server_push_interval', 60),
        'pull_interval' => (int) admin_setting('server_pull_interval', 60),
    ];
    $diffs = [];
    diffDeep($expected, $actual, 'config', $diffs);
    foreach ($diffs as $d) {
        $fail($d);
    }

    // 2) 语义保真：核心字段必须与 DB 原始配置一致，规则独立于 buildNodeConfig。
    if ($actual['protocol'] !== $node->type) {
        $fail("protocol {$actual['protocol']} != db {$node->type}");
    }
    if ($actual['server_port'] !== (int) $node->server_port) {
        $fail('server_port mismatch');
    }
    $expectedNetwork = $ps['network'] ?? null;
    if (($actual['network'] ?? null) !== $expectedNetwork) {
        $fail('network mismatch');
    }

    switch ($node->type) {
        case 'shadowsocks':
            if ($actual['cipher'] !== ($ps['cipher'] ?? null)) {
                $fail('cipher mismatch');
            }
            $size = match ($ps['cipher'] ?? '') {
                '2022-blake3-aes-128-gcm' => 16,
                '2022-blake3-aes-256-gcm' => 32,
                default => null,
            };
            if ($size === null) {
                if ($actual['server_key'] !== null) {
                    $fail('server_key must be null for non-2022 cipher');
                }
            } else {
                $createdAt = $node->parent_id ? $node->parent->created_at : $node->created_at;
                if ($actual['server_key'] !== Helper::getServerKey($createdAt, $size)) {
                    $fail('server_key mismatch');
                }
                if (strlen(base64_decode((string) $actual['server_key'], true) ?: '') !== $size) {
                    $fail('server_key decoded length mismatch');
                }
            }
            if ($actual['plugin'] !== null || $actual['plugin_opts'] !== null) {
                $fail('plugin must be null');
            }
            break;

        case 'vmess':
        case 'vless':
        case 'trojan':
            $tls = (int) ($ps['tls'] ?? 0);
            if (($actual['tls'] ?? null) !== $tls) {
                $fail("tls {$actual['tls']} != db {$tls}");
            }
            if ($tls === 2) {
                $reality = $actual['tls_settings'] ?? [];
                foreach ($ps['reality_settings'] ?? [] as $k => $v) {
                    if (($reality[$k] ?? null) !== $v) {
                        $fail("reality_settings.{$k} mismatch");
                    }
                }
            } elseif ($tls === 1) {
                $sent = $actual['tls_settings'] ?? [];
                foreach ($ps['tls_settings'] ?? [] as $k => $v) {
                    if (($sent[$k] ?? null) !== $v) {
                        $fail("tls_settings.{$k} mismatch");
                    }
                }
                if ($node->type === 'trojan') {
                    $want = $ps['tls_settings']['server_name'] ?? ($ps['server_name'] ?? null);
                    if ($actual['server_name'] !== $want) {
                        $fail('trojan server_name fallback mismatch');
                    }
                }
            } else {
                if (!in_array($actual['tls_settings'] ?? null, [null, []], true)) {
                    $fail('tls=0 must not carry tls_settings');
                }
            }
            if ($node->type === 'vless') {
                if ($actual['flow'] !== ($ps['flow'] ?? null)) {
                    $fail('flow mismatch');
                }
                $decryption = ($ps['encryption']['enabled'] ?? false) ? ($ps['encryption']['decryption'] ?? null) : null;
                if (($actual['decryption'] ?? null) !== $decryption) {
                    $fail('decryption mismatch');
                }
            }
            if (array_key_exists('multiplex', $ps) && $ps['multiplex'] !== ($actual['multiplex'] ?? null)) {
                $fail('multiplex mismatch');
            }
            break;

        case 'hysteria':
            if (($actual['version'] ?? null) !== (int) ($ps['version'] ?? 0)) {
                $fail('hysteria version mismatch');
            }
            if (($actual['up_mbps'] ?? null) !== (int) ($ps['bandwidth']['up'] ?? 0)
                || ($actual['down_mbps'] ?? null) !== (int) ($ps['bandwidth']['down'] ?? 0)) {
                $fail('hysteria bandwidth mismatch');
            }
            if ((int) ($ps['version'] ?? 0) === 1) {
                if (($actual['obfs'] ?? null) !== ($ps['obfs']['password'] ?? null)) {
                    $fail('hysteria1 obfs mismatch');
                }
            } else {
                $wantObfs = ($ps['obfs']['open'] ?? false) ? ($ps['obfs']['type'] ?? null) : null;
                if (($actual['obfs'] ?? null) !== $wantObfs) {
                    $fail('hysteria2 obfs type mismatch');
                }
                if (($actual['obfs-password'] ?? null) !== ($ps['obfs']['password'] ?? null)) {
                    $fail('hysteria2 obfs password mismatch');
                }
            }
            break;

        case 'tuic':
            if (($actual['version'] ?? null) !== (int) ($ps['version'] ?? 0)) {
                $fail('tuic version mismatch');
            }
            if (($actual['congestion_control'] ?? null) !== ($ps['congestion_control'] ?? null)) {
                $fail('tuic congestion_control mismatch');
            }
            break;

        case 'anytls':
            if (($actual['server_name'] ?? null) !== ($ps['tls']['server_name'] ?? null)) {
                $fail('anytls server_name mismatch');
            }
            if (($actual['tls_settings'] ?? null) !== ($ps['tls'] ?? null)) {
                $fail('anytls tls_settings mismatch');
            }
            break;

        case 'naive':
        case 'http':
            if (($actual['tls'] ?? null) !== (int) ($ps['tls'] ?? 0)) {
                $fail("{$node->type} tls mismatch");
            }
            if (($actual['tls_settings'] ?? null) !== ($ps['tls_settings'] ?? null)) {
                $fail("{$node->type} tls_settings mismatch");
            }
            break;

        case 'socks':
            if (($actual['tls'] ?? null) !== 0
                || !array_key_exists('tls_settings', $actual)
                || $actual['tls_settings'] !== null) {
                $fail('socks must be downgraded to plaintext (tls=0, tls_settings=null)');
            }
            if ($node->enabled && (int) ($ps['tls'] ?? 0) !== 0) {
                $fail('enabled socks row stores TLS; save validation should have rejected it');
            }
            break;

        case 'mieru':
            if (($actual['transport'] ?? null) !== ($ps['transport'] ?? 'TCP')) {
                $fail('mieru transport mismatch');
            }
            if (($actual['traffic_pattern'] ?? null) !== ($ps['traffic_pattern'] ?? null)) {
                $fail('mieru traffic_pattern mismatch');
            }
            break;
    }

    // 3) 证书：self/file/content/http/dns 的 cert_mode 与 domain 必须如实下发。
    $cert = $node->cert_config;
    if ($cert && ($cert['cert_mode'] ?? $cert['mode'] ?? 'none') !== 'none') {
        if (($actual['cert_config']['cert_mode'] ?? null) !== ($cert['cert_mode'] ?? $cert['mode'])) {
            $fail('cert_config.cert_mode mismatch');
        }
        if (array_key_exists('domain', $cert) && ($actual['cert_config']['domain'] ?? null) !== $cert['domain']) {
            $fail('cert_config.domain mismatch');
        }
    }

    // 4) 可选覆盖只在设置时下发。
    if ($node->kernel_type) {
        if (($actual['kernel_type'] ?? null) !== $node->kernel_type) {
            $fail('kernel_type mismatch');
        }
    } elseif (array_key_exists('kernel_type', $actual)) {
        $fail('kernel_type sent although DB is empty');
    }
    foreach (['kernel_log_level', 'accept_proxy_protocol', 'sniff', 'quic', 'udp_idle_timeout'] as $optional) {
        if (array_key_exists($optional, $actual)) {
            $fail("{$optional} sent although DB is empty");
        }
    }

    // 5) 条件请求：ETag 必须与响应一致且 304 后不带 body。
    if ($res['etag'] === '') {
        $fail('missing ETag');
    } else {
        $second = apiGet($url, ['If-None-Match: "' . $res['etag'] . '"']);
        if ($second['status'] !== 304) {
            $fail("conditional request returned {$second['status']}, want 304");
        }
        if (trim($second['body']) !== '') {
            $fail('304 response carries body');
        }
    }

    // 6) 用户下发（仅 full 模式）：uuid 必须与面板用户一致，且不重复。
    // 该端点会调用 touchNode() 写 LAST_CHECK_AT（TTL 1h），测试进行中跑会伪造在线证据，
    // 所以默认只验 config；init.sh 在测试开始前显式传 VERIFY_MODE=full。
    if ($mode === 'full') {
        $userRes = apiGet($baseUrl . '/user?' . http_build_query(['token' => $token, 'node_id' => $node->id, 'node_type' => $node->type]));
        if ($userRes['status'] !== 200) {
            $fail("user HTTP {$userRes['status']}");
        } else {
            $users = json_decode($userRes['body'], true)['users'] ?? null;
            if (!is_array($users) || $users === []) {
                $fail('user payload empty');
            } elseif (count($users) !== count(array_unique(array_column($users, 'uuid')))) {
                $fail('duplicate uuid in user payload');
            } elseif (!in_array($testUser->uuid, array_column($users, 'uuid'), true)) {
                $fail('test user uuid missing from payload');
            }
        }
    }

    if (!$node->enabled) {
        $notes[] = "#{$node->id} {$node->name}: 禁用样本仍可通过节点 API 取到配置（tls 已归一化为 0）";
    }
}

// 7) 鉴权：错误 token 必须被拒绝。
$bad = apiGet($baseUrl . '/config?' . http_build_query(['token' => 'invalid-token', 'node_id' => 1, 'node_type' => 'shadowsocks']));
if ($bad['status'] === 200) {
    $failures[] = 'wrong token was accepted';
}

echo 'MODE=' . $mode . ' CHECKED=' . $checked . PHP_EOL;
foreach ($notes as $note) {
    echo 'NOTE ' . $note . PHP_EOL;
}
if ($failures === []) {
    echo 'RESULT=PASS' . PHP_EOL;
    exit(0);
}
echo 'FAILURES=' . count($failures) . PHP_EOL;
foreach (array_slice($failures, 0, 60) as $f) {
    echo 'FAIL ' . $f . PHP_EOL;
}
echo 'RESULT=FAIL' . PHP_EOL;
exit(1);