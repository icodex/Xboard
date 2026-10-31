<?php
// Seed full protocol matrix for kernel testing. Run inside xboard container:
// php artisan tinker --execute="require '/www/seed-nodes.php';"
use App\Models\Server;
use App\Models\ServerGroup;
use App\Utils\Helper;

$groupId = ServerGroup::value('id') ?: 1;
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
    ['socks',       'Socks-tls',            10031, ['tls' => 1, 'tls_settings' => ['server_name' => 'test.local']], $certSelf],
];

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
        'created_at'        => time(),
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
echo 'DONE total=' . Server::count() . PHP_EOL;
