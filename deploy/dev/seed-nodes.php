<?php
// Seed full protocol matrix for kernel testing. Run inside xboard container:
// php artisan tinker --execute="require '/www/seed-nodes.php';"
use App\Models\Server;
use App\Models\ServerGroup;
use App\Utils\Helper;

$groupId = isset($group) ? $group->id : (ServerGroup::value('id') ?: 1);
$reality = [
    'private_key' => '-GGW-J_QWKaaaqnZe4q9T7Uu6L3kPeMpWuZgMg5Gymg',
    'public_key'  => 'YrYngPHn0t67mvgLXFmqbKqKzZKe_UXlt2dCoUpq2wc',
];
$certSelf = ['cert_config' => ['cert_mode' => 'self', 'domain' => 'test.local']];
$host = '127.0.0.1';

$nodes = [
    // type, name, server_port, protocol_settings, extra
    ['shadowsocks', 'SS-aes256',            10001, ['cipher' => 'aes-256-gcm'], []],
    ['shadowsocks', 'SS2022-aes256',        10002, ['cipher' => '2022-blake3-aes-256-gcm'], []],
    // 2022-blake3-chacha20-poly1305 已从面板白名单移除（双内核 multi-user 均不支持），
    // 测试矩阵改用 aes-128 变体补 SS2022 密钥长度覆盖
    ['shadowsocks', 'SS2022-aes128',        10003, ['cipher' => '2022-blake3-aes-128-gcm'], []],
    ['vmess',       'VMess-tcp',            10004, ['tls' => 0, 'network' => 'tcp'], []],
    ['vmess',       'VMess-ws-tls',         10005, ['tls' => 1, 'network' => 'ws', 'network_settings' => ['path' => '/vmws'], 'tls_settings' => ['server_name' => 'test.local']], $certSelf],
    ['vmess',       'VMess-grpc-tls',       10006, ['tls' => 1, 'network' => 'grpc', 'network_settings' => ['serviceName' => 'vmgrpc'], 'tls_settings' => ['server_name' => 'test.local']], $certSelf],
    ['vmess',       'VMess-reality',        10007, ['tls' => 2, 'network' => 'tcp', 'tls_settings' => ['server_name' => 'test.local'], 'reality_settings' => ['private_key' => $reality['private_key'], 'public_key' => $reality['public_key'], 'short_id' => '0123abcd', 'server_name' => 'www.microsoft.com', 'dest' => 'www.microsoft.com:443']], []],
    ['vless',       'VLESS-reality-vision', 10008, ['tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision', 'reality_settings' => ['private_key' => $reality['private_key'], 'public_key' => $reality['public_key'], 'short_id' => '0123abcd', 'server_name' => 'www.microsoft.com', 'dest' => 'www.microsoft.com:443']], []],
    ['vless',       'VLESS-ws-tls',         10009, ['tls' => 1, 'network' => 'ws', 'network_settings' => ['path' => '/vlws'], 'tls_settings' => ['server_name' => 'test.local']], $certSelf],
    ['vless',       'VLESS-xhttp-tls',      10010, ['tls' => 1, 'network' => 'xhttp', 'network_settings' => ['path' => '/vlx', 'host' => 'test.local', 'mode' => 'auto'], 'tls_settings' => ['server_name' => 'test.local']], $certSelf],
    ['vless',       'VLESS-h2-tls',         10011, ['tls' => 1, 'network' => 'h2', 'network_settings' => ['path' => '/vlh2', 'host' => 'test.local'], 'tls_settings' => ['server_name' => 'test.local']], $certSelf],
    ['trojan',      'Trojan-tls',           10012, ['tls' => 1, 'network' => 'tcp', 'server_name' => 'test.local'], $certSelf],
    ['trojan',      'Trojan-reality',       10013, ['tls' => 2, 'network' => 'tcp', 'reality_settings' => ['private_key' => $reality['private_key'], 'public_key' => $reality['public_key'], 'short_id' => '0123abcd', 'server_name' => 'www.microsoft.com', 'dest' => 'www.microsoft.com:443']], []],
    ['hysteria',    'Hysteria2-obfs',       10014, ['version' => 2, 'bandwidth' => ['up' => 100, 'down' => 200], 'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'obfspass'], 'tls' => ['server_name' => 'test.local']], $certSelf],
    ['hysteria',    'Hysteria1',            10015, ['version' => 1, 'bandwidth' => ['up' => 100, 'down' => 200], 'tls' => ['server_name' => 'test.local']], $certSelf],
    ['tuic',        'TUIC-bbr',             10016, ['version' => 5, 'congestion_control' => 'bbr', 'alpn' => ['h3'], 'tls' => ['server_name' => 'test.local']], $certSelf],
    ['anytls',      'AnyTLS',               10017, ['tls' => ['server_name' => 'test.local']], $certSelf],
    ['naive',       'Naive-tls',            10018, ['tls' => 1, 'tls_settings' => ['server_name' => 'test.local']], $certSelf],
    ['socks',       'Socks-plain',          10019, ['tls' => 0], []],
    ['http',        'HTTP-tls',             10020, ['tls' => 1, 'tls_settings' => ['server_name' => 'test.local']], $certSelf],
    ['mieru',       'Mieru-tcp',            10021, ['transport' => 'TCP', 'traffic_pattern' => ''], []],
    // ── XTLS/Xray-examples 对齐补充 ──
    ['vless',       'VLESS-xhttp-reality',  10022, ['tls' => 2, 'network' => 'xhttp', 'network_settings' => ['path' => '/vlxr', 'host' => 'www.microsoft.com', 'mode' => 'auto'], 'reality_settings' => ['private_key' => $reality['private_key'], 'public_key' => $reality['public_key'], 'short_id' => '0123abcd', 'server_name' => 'www.microsoft.com', 'dest' => 'www.microsoft.com:443']], []],
    ['vless',       'VLESS-grpc-reality',   10023, ['tls' => 2, 'network' => 'grpc', 'network_settings' => ['serviceName' => 'vlgr'], 'reality_settings' => ['private_key' => $reality['private_key'], 'public_key' => $reality['public_key'], 'short_id' => '0123abcd', 'server_name' => 'www.microsoft.com', 'dest' => 'www.microsoft.com:443']], []],
    ['vless',       'VLESS-tcp-tls-vision', 10024, ['tls' => 1, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision', 'tls_settings' => ['server_name' => 'test.local']], $certSelf],
    ['vless',       'VLESS-tcp-tls-min',    10025, ['tls' => 1, 'network' => 'tcp', 'tls_settings' => ['server_name' => 'test.local']], $certSelf],
    ['vless',       'VLESS-tcp-plain',      10026, ['tls' => 0, 'network' => 'tcp'], []],
    ['vless',       'VLESS-grpc-plain',     10027, ['tls' => 0, 'network' => 'grpc', 'network_settings' => ['serviceName' => 'vlgp']], []],
    ['vless',       'VLESS-httpupgrade-tls',10028, ['tls' => 1, 'network' => 'httpupgrade', 'network_settings' => ['path' => '/vlhu', 'host' => 'test.local'], 'tls_settings' => ['server_name' => 'test.local']], $certSelf],
    ['vmess',       'VMess-tcp-tls',        10029, ['tls' => 1, 'network' => 'tcp', 'tls_settings' => ['server_name' => 'test.local']], $certSelf],
    ['vmess',       'VMess-ws-plain',       10030, ['tls' => 0, 'network' => 'ws', 'network_settings' => ['path' => '/vmwsp']], []],
    // 历史非法配置仅用于验证下发归一化，不加入正向连接矩阵或订阅。
    ['socks',       'Socks-tls',            10031, ['tls' => 1, 'tls_settings' => ['server_name' => 'test.local']], array_merge($certSelf, ['enabled' => 0, 'show' => 0])],
];

// Keep generated names and ports stable across repeated seeds; IDs are database-assigned.
$port = 10032;
foreach (['aes-128-gcm', 'aes-192-gcm', 'chacha20-ietf-poly1305', 'xchacha20-ietf-poly1305'] as $cipher) {
    $nodes[] = ['shadowsocks', 'SS-' . $cipher, $port++, ['cipher' => $cipher], []];
}
$nodes[] = ['hysteria', 'Hysteria2-plain', $port++, ['version' => 2, 'bandwidth' => ['up' => 100, 'down' => 200], 'tls' => ['server_name' => 'test.local']], $certSelf];
$nodes[] = ['hysteria', 'Hysteria1-obfs', $port++, ['version' => 1, 'bandwidth' => ['up' => 100, 'down' => 200], 'obfs' => ['open' => true, 'password' => 'obfspass'], 'tls' => ['server_name' => 'test.local']], $certSelf];
foreach (['cubic', 'new_reno'] as $cc) {
    $nodes[] = ['tuic', 'TUIC-' . $cc, $port++, ['version' => 5, 'congestion_control' => $cc, 'tls' => ['server_name' => 'test.local']], $certSelf];
}
$nodes[] = ['http', 'HTTP-plain', $port++, ['tls' => 0], []];
$nodes[] = ['mieru', 'Mieru-udp', $port++, ['transport' => 'UDP', 'traffic_pattern' => ''], []];

// Candidate combinations are deliberately tested rather than asserted compatible.
// Reality only uses transports with a defined Reality handshake; h2/http and
// xhttp/splithttp have mutually exclusive kernel support.
foreach (['vmess', 'vless', 'trojan'] as $protocol) {
    foreach (['tcp', 'ws', 'grpc', 'h2', 'http', 'httpupgrade', 'xhttp', 'splithttp'] as $network) {
        foreach ([0, 1, 2] as $tls) {
            if ($tls === 2 && !in_array($network, ['tcp', 'grpc', 'xhttp', 'splithttp'], true)) {
                continue;
            }
            if ($protocol === 'trojan' && $tls === 0) {
                continue;
            }
            $settings = ['tls' => $tls, 'network' => $network];
            if ($network !== 'tcp') {
                $settings['network_settings'] = $network === 'grpc'
                    ? ['serviceName' => 'matrix']
                    : ['path' => '/matrix', 'host' => 'test.local'];
            }
            if ($tls === 1) {
                $settings['tls_settings'] = ['server_name' => 'test.local'];
            } elseif ($tls === 2) {
                $settings['reality_settings'] = $reality + ['short_id' => '0123abcd', 'server_name' => 'www.microsoft.com', 'dest' => 'www.microsoft.com:443'];
            }
            $nodes[] = [$protocol, strtoupper($protocol) . '-' . $network . '-tls' . $tls . '-matrix', $port++, $settings, $tls === 1 ? $certSelf : []];
        }
    }
}

// One fixed-kernel copy per eligible scenario. Both copies are independent
// listeners; protocol/transport incompatibilities are excluded, not masked by auto.
$scenarios = $nodes;
foreach ($scenarios as [$type, $name, $unused, $settings, $extra]) {
    if (($extra['enabled'] ?? 1) === 0) {
        continue;
    }
    $network = $settings['network'] ?? 'tcp';
    $xraySsCiphers = ['aes-128-gcm', 'aes-256-gcm', 'chacha20-ietf-poly1305', 'xchacha20-ietf-poly1305', '2022-blake3-aes-128-gcm', '2022-blake3-aes-256-gcm'];
    $kernels = match (true) {
        $type === 'shadowsocks' => in_array($settings['cipher'] ?? '', $xraySsCiphers, true) ? ['singbox', 'xray'] : ['singbox'],
        in_array($type, ['vmess', 'vless', 'trojan'], true) => ['singbox', 'xray'],
        $type === 'hysteria' && ($settings['version'] ?? null) === 2 => ['singbox', 'xray'],
        default => ['singbox'],
    };
    foreach ($kernels as $kernel) {
        if (($kernel === 'singbox' && in_array($network, ['xhttp', 'splithttp'], true))
            || ($kernel === 'xray' && in_array($network, ['h2', 'http'], true))
            || ($kernel === 'singbox' && $type === 'vmess' && ($settings['tls'] ?? null) === 2)) {
            continue;
        }
        $nodes[] = [$type, $name . '-' . $kernel, $port++, $settings, array_merge($extra, ['kernel_type' => $kernel])];
    }
}

foreach ($nodes as [$type, $name, $port, $ps, $extra]) {
    $exists = Server::where('name', $name)->first();
    $attrs = array_merge([
        'name'              => $name,
        'type'              => $type,
        // 订阅匹配 whereJsonContains('group_ids', (string) group_id)，必须存字符串
        'group_ids'         => [(string) $groupId],
        'host'              => $host,
        'port'              => $port,
        'server_port'       => $port,
        'rate'              => 1,
        'show'              => 1,
        'enabled'           => 1,
        'protocol_settings' => $ps,
        // 标记由本 seed 管理，仅这些行允许被 PRUNE_STALE 清理
        'tags'              => ['dev-seed'],
        'created_at'        => $exists ? $exists->created_at : time(),
        'updated_at'        => time(),
    ], $extra);
    if ($exists) {
        $exists->fill($attrs)->save();
        echo "UPDATED {$name} #{$exists->id}" . PHP_EOL;
    } else {
        $n = new Server();
        $n->fill($attrs)->save();
        echo "CREATED {$name} #{$n->id}" . PHP_EOL;
    }
}
// init.php 用此清单做保存规则预检与遗留节点检测
$seededNames = array_column($nodes, 1);

echo 'DONE total=' . Server::count() . PHP_EOL;
