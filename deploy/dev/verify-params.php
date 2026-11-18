<?php

// 高级参数下发探针：矩阵种子只覆盖基础字段，这里临时创建带全部高级参数的节点，
// 经真实节点 API 断言字段名、值、可选键存在性，跑完删除。容器内执行：
// php artisan tinker --execute="require '/www/verify-params.php';"
// 退出码 0=全部通过，1=存在失败项。

use App\Models\Server;
use App\Services\ServerService;

Illuminate\Database\Eloquent\Model::unguard();

$baseUrl = 'http://127.0.0.1:7001/api/v1/server/UniProxy';
$token = admin_setting('server_token');
$token = is_array($token) ? ($token[0] ?? '') : (string) $token;
$prefix = '__verify_probe__';
Server::where('name', 'like', $prefix . '%')->delete();

function containsSubset($expected, $actual): bool
{
    if (!is_array($expected)) {
        return $expected === $actual;
    }
    if (!is_array($actual)) {
        return false;
    }
    $expIsList = array_is_list($expected);
    if ($expIsList) {
        if (!array_is_list($actual) || count($expected) !== count($actual)) {
            return false;
        }
        foreach ($expected as $i => $v) {
            if (!containsSubset($v, $actual[$i])) {
                return false;
            }
        }
        return true;
    }
    foreach ($expected as $k => $v) {
        if (!array_key_exists($k, $actual) || !containsSubset($v, $actual[$k])) {
            return false;
        }
    }
    return true;
}

function probeGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode((string) $body, true)];
}

$reality = [
    'private_key' => 'PRIVATE-KEY-PROBE',
    'public_key' => 'PUBLIC-KEY-PROBE',
    'short_id' => '0123abcd',
    'server_name' => 'probe.example',
    'dest' => 'probe.example:443',
];
$selfCert = ['cert_mode' => 'self', 'domain' => 'probe.local'];
$tlsSettings = ['server_name' => 'probe.local', 'allow_insecure' => true, 'alpn' => ['h2', 'http/1.1'], 'ech' => ['enabled' => true, 'key' => 'ECH-KEY']];
$sniff = ['enable' => true, 'rewriteDomain' => true, 'timeout' => 5, 'tcpPorts' => '1-65535', 'udpPorts' => '53'];
$quic = ['initStreamReceiveWindow' => 65536, 'maxIdleTimeout' => 30, 'disablePathMTUDiscovery' => true];
$multiplex = ['enabled' => true, 'padding' => true, 'brutal' => ['enabled' => true, 'up_mbps' => 10, 'down_mbps' => 20], 'protocol' => 'yamux', 'max_connections' => 4];
$certConfig = ['cert_mode' => 'file', 'cert_file' => '/tmp/probe.crt', 'key_file' => '/tmp/probe.key', 'domain' => 'probe.local'];

$probes = [
    ['vmess', 19901, [
        'tls' => 2, 'network' => 'grpc', 'network_settings' => ['serviceName' => 'probe'],
        'reality_settings' => $reality, 'multiplex' => $multiplex,
    ], ['cert_config' => $certConfig, 'kernel_type' => 'xray', 'kernel_log_level' => 'debug'],
        function (array $c) use ($multiplex, $reality) {
            $d = [];
            if ($c['tls'] !== 2) $d[] = 'tls';
            if (!containsSubset($reality, $c['tls_settings'] ?? [])) $d[] = 'reality passthrough';
            if (!containsSubset($multiplex, $c['multiplex'] ?? [])) $d[] = 'multiplex';
            if (($c['networkSettings']['serviceName'] ?? null) !== 'probe') $d[] = 'networkSettings';
            if (($c['kernel_type'] ?? null) !== 'xray' || ($c['kernel_log_level'] ?? null) !== 'debug') $d[] = 'kernel overrides';
            if (($c['cert_config']['cert_file'] ?? null) !== '/tmp/probe.crt' || ($c['cert_config']['cert_mode'] ?? null) !== 'file') $d[] = 'cert_config';
            return $d;
        }],
    ['vless', 19902, [
        'tls' => 1, 'network' => 'ws', 'network_settings' => ['path' => '/probe', 'host' => 'probe.local', 'max_early_data' => 2048],
        'flow' => 'xtls-rprx-vision', 'tls_settings' => $tlsSettings,
        'encryption' => ['enabled' => true, 'encryption' => 'mlkem768x25519plus', 'decryption' => 'mlkem768x25519plus'],
        'multiplex' => $multiplex, 'sniff' => $sniff, 'udpIdleTimeout' => 60, 'accept_proxy_protocol' => true,
    ], ['cert_config' => $selfCert],
        function (array $c) use ($tlsSettings, $sniff) {
            $d = [];
            if ($c['decryption'] !== 'mlkem768x25519plus') $d[] = 'decryption';
            if ($c['flow'] !== 'xtls-rprx-vision') $d[] = 'flow';
            if (!containsSubset($tlsSettings, $c['tls_settings'] ?? [])) $d[] = 'tls_settings/alpn/ech';
            if (!containsSubset($sniff, $c['sniff'] ?? [])) $d[] = 'sniff';
            if (($c['udp_idle_timeout'] ?? null) !== 60) $d[] = 'udp_idle_timeout';
            if (($c['accept_proxy_protocol'] ?? null) !== true) $d[] = 'accept_proxy_protocol';
            if (($c['networkSettings']['max_early_data'] ?? null) !== 2048) $d[] = 'ws early data';
            return $d;
        }],
    ['trojan', 19903, [
        'tls' => 1, 'network' => 'tcp', 'server_name' => 'trojan-probe.local',
        'tls_settings' => ['server_name' => 'trojan-probe.local'], 'multiplex' => $multiplex,
    ], ['cert_config' => $selfCert],
        function (array $c) use ($multiplex) {
            $d = [];
            if (($c['server_name'] ?? null) !== 'trojan-probe.local') $d[] = 'server_name';
            if (!containsSubset($multiplex, $c['multiplex'] ?? [])) $d[] = 'multiplex';
            return $d;
        }],
    ['hysteria', 19904, [
        'version' => 2, 'bandwidth' => ['up' => 111, 'down' => 222, 'disableLossCompensation' => true],
        'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'probe-obfs'],
        'tls' => ['server_name' => 'probe.local', 'alpn' => ['h3']],
        'masquerade' => ['type' => 'string', 'string' => ['content' => 'ok', 'statusCode' => 200]],
        'congestion' => ['type' => 'bbr', 'bbrProfile' => 'aggressive'],
        'resolver' => ['type' => 'tls', 'tls' => ['addr' => '1.1.1.1:853', 'sni' => 'cloudflare-dns.com']],
        'quic' => $quic, 'disableUDP' => true, 'ignoreClientBandwidth' => true, 'sniff' => $sniff,
    ], ['cert_config' => $selfCert],
        function (array $c) use ($quic, $sniff) {
            $d = [];
            if ($c['obfs'] !== 'salamander' || ($c['obfs-password'] ?? null) !== 'probe-obfs') $d[] = 'obfs';
            if ($c['up_mbps'] !== 111 || $c['down_mbps'] !== 222) $d[] = 'bandwidth';
            if (($c['masquerade']['string']['content'] ?? null) !== 'ok') $d[] = 'masquerade';
            if (($c['congestion']['bbrProfile'] ?? null) !== 'aggressive') $d[] = 'congestion';
            if (($c['resolver']['tls']['addr'] ?? null) !== '1.1.1.1:853') $d[] = 'resolver';
            if (!containsSubset($quic, $c['quic'] ?? [])) $d[] = 'quic';
            if (($c['disable_udp'] ?? null) !== true || ($c['ignore_client_bandwidth'] ?? null) !== true) $d[] = 'hysteria switches';
            if (($c['disable_loss_compensation'] ?? null) !== true) $d[] = 'loss compensation';
            if (!containsSubset($sniff, $c['sniff'] ?? [])) $d[] = 'sniff';
            return $d;
        }],
    ['tuic', 19905, [
        'version' => 5, 'congestion_control' => 'cubic', 'udp_relay_mode' => 'quic',
        'tls' => ['server_name' => 'probe.local', 'alpn' => ['h3']], 'quic' => $quic, 'sniff' => $sniff,
    ], ['cert_config' => $selfCert],
        function (array $c) use ($quic, $sniff) {
            $d = [];
            if ($c['congestion_control'] !== 'cubic') $d[] = 'congestion_control';
            if (($c['tls_settings']['alpn'] ?? null) !== ['h3']) $d[] = 'tuic alpn';
            if (!containsSubset($quic, $c['quic'] ?? [])) $d[] = 'quic';
            if (!containsSubset($sniff, $c['sniff'] ?? [])) $d[] = 'sniff';
            if (array_key_exists('udp_relay_mode', $c)) $d[] = 'client-only udp_relay_mode leaked';
            return $d;
        }],
    ['anytls', 19906, [
        'tls' => ['server_name' => 'probe.local'], 'padding_scheme' => ['stop=8', '0=30-30'],
    ], ['cert_config' => $selfCert],
        function (array $c) {
            return (($c['padding_scheme'] ?? null) === ['stop=8', '0=30-30']) ? [] : ['padding_scheme'];
        }],
    ['naive', 19907, ['tls' => 1, 'tls_settings' => $tlsSettings], ['cert_config' => $selfCert],
        function (array $c) use ($tlsSettings) {
            return (($c['tls'] ?? null) === 1 && containsSubset($tlsSettings, $c['tls_settings'] ?? [])) ? [] : ['naive tls'];
        }],
    ['http', 19908, ['tls' => 0], [],
        function (array $c) {
            return (($c['tls'] ?? null) === 0) ? [] : ['http tls'];
        }],
    ['mieru', 19909, ['transport' => 'UDP', 'traffic_pattern' => 'probe-pattern'], [],
        function (array $c) {
            return (($c['transport'] ?? null) === 'UDP' && ($c['traffic_pattern'] ?? null) === 'probe-pattern') ? [] : ['mieru'];
        }],
    ['shadowsocks', 19910, ['cipher' => '2022-blake3-aes-256-gcm'], [],
        function (array $c) {
            return (strlen(base64_decode((string) $c['server_key'], true) ?: '') === 32) ? [] : ['ss server_key'];
        }],
];

$failures = [];
$checked = 0;
try {
    foreach ($probes as $i => [$type, $port, $settings, $extra, $assert]) {
        $checked++;
        $node = Server::create(array_merge([
            'name' => $prefix . $i . '_' . $type,
            'type' => $type,
            'group_ids' => ['999999'],
            'host' => '127.0.0.1',
            'port' => $port,
            'server_port' => $port,
            'rate' => 1,
            'show' => 0,
            'enabled' => 1,
            'protocol_settings' => $settings,
            'created_at' => time(),
            'updated_at' => time(),
        ], $extra));

        $url = $baseUrl . '/config?' . http_build_query(['token' => $token, 'node_id' => $node->id, 'node_type' => $type]);
        $res = probeGet($url);
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            $failures[] = "#{$type}: config HTTP {$res['status']}";
        } elseif (($diffs = $assert($res['json'])) !== []) {
            foreach ($diffs as $d) {
                $failures[] = "#{$type}: {$d}";
            }
        }
        $expected = ServerService::buildNodeConfig($node);
        unset($expected['base_config']);
        foreach (['protocol', 'server_port', 'network', 'networkSettings'] as $k) {
            if (array_key_exists($k, $expected)
                && (!array_key_exists($k, $res['json']) || !containsSubset($expected[$k], $res['json'][$k]))) {
                $failures[] = "#{$type}: {$k} fidelity";
            }
        }
        $node->delete();
    }
} finally {
    Server::where('name', 'like', $prefix . '%')->delete();
}

echo 'PROBES=' . $checked . PHP_EOL;
if ($failures === []) {
    echo 'RESULT=PASS' . PHP_EOL;
    exit(0);
}
echo 'FAILURES=' . count($failures) . PHP_EOL;
foreach ($failures as $f) {
    echo 'FAIL ' . $f . PHP_EOL;
}
echo 'RESULT=FAIL' . PHP_EOL;
exit(1);