<?php

namespace App\Protocols;

use Symfony\Component\Yaml\Yaml;
use App\Utils\Helper;
use Illuminate\Support\Facades\File;
use App\Support\AbstractProtocol;
use App\Models\Server;

class Stash extends AbstractProtocol
{
    public $flags = ['stash'];
    const CUSTOM_TEMPLATE_FILE = 'resources/rules/custom.stash.yaml';
    const CUSTOM_CLASH_TEMPLATE_FILE = 'resources/rules/custom.clash.yaml';
    const DEFAULT_TEMPLATE_FILE = 'resources/rules/default.clash.yaml';
    public $allowedProtocols = [
        Server::TYPE_SHADOWSOCKS,
        Server::TYPE_VMESS,
        Server::TYPE_VLESS,
        Server::TYPE_HYSTERIA,
        Server::TYPE_TROJAN,
        Server::TYPE_TUIC,
        Server::TYPE_ANYTLS,
        Server::TYPE_SOCKS,
        Server::TYPE_HTTP,
    ];

    protected $protocolRequirements = [
        // Global rules applied regardless of client version (features Stash never supports)
        '*' => [
            'trojan' => [
                'protocol_settings.tls' => [
                    '2' => '9999.0.0',  // Trojan Reality not supported in Stash
                ],
            ],
            'vmess' => [
                'protocol_settings.network' => [
                    'httpupgrade' => '9999.0.0',  // httpupgrade not supported in Stash
                ],
            ],
        ],
        'stash' => [
            'anytls' => [
                'base_version' => '3.3.0' // AnyTLS 协议在3.3.0版本中添加
            ],
            'vless' => [
                'protocol_settings.tls' => [
                    '2' => '3.1.0'  // Reality 在3.1.0版本中添加
                ],
                'protocol_settings.flow' => [
                    'xtls-rprx-vision' => '3.1.0',
                ]
            ],
            'hysteria' => [
                'base_version' => '2.0.0',
                'protocol_settings.version' => [
                    '1' => '2.0.0', // Hysteria 1
                    '2' => '2.5.0'  // Hysteria 2，2.5.0 版本开始支持（2023年11月8日）
                ],
                // 'protocol_settings.ports' => [
                //     'true' => '2.6.4' // Hysteria 2 端口跳转功能于2.6.4版本支持（2024年8月4日）
                // ]
            ],
            'tuic' => [
                'base_version' => '2.3.0' // TUIC 协议自身需要 2.3.0+
            ],
            'shadowsocks' => [
                'base_version' => '2.0.0',
                // ShadowSocks2022 在3.0.0版本中添加（2025年4月2日）
                'protocol_settings.cipher' => [
                    '2022-blake3-aes-128-gcm' => '3.0.0',
                    '2022-blake3-aes-256-gcm' => '3.0.0',
                    '2022-blake3-chacha20-poly1305' => '3.0.0'
                ]
            ],
            'shadowtls' => [
                'base_version' => '3.0.0' // ShadowTLS 在3.0.0版本中添加（2025年4月2日）
            ],
            'ssh' => [
                'base_version' => '2.6.4' // SSH 协议在2.6.4中添加（2024年8月4日）
            ],
            'juicity' => [
                'base_version' => '2.6.4' // Juicity 协议在2.6.4中添加（2024年8月4日）
            ]
        ]
    ];

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $appName = admin_setting('app_name', 'XBoard');

        $template = subscribe_template('stash');

        $config = Yaml::parse($template);
        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            $protocol_settings = $item['protocol_settings'];
            if ($item['type'] === Server::TYPE_SHADOWSOCKS) {
                array_push($proxy, self::buildShadowsocks($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_VMESS) {
                array_push($proxy, self::buildVmess($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_VLESS
                && in_array(data_get($protocol_settings, 'network'), ['tcp', 'ws', 'grpc', 'http'])
            ) {
                array_push($proxy, $this->buildVless($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_HYSTERIA) {
                array_push($proxy, self::buildHysteria($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_TROJAN) {
                array_push($proxy, self::buildTrojan($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_TUIC) {
                array_push($proxy, self::buildTuic($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_ANYTLS) {
                array_push($proxy, self::buildAnyTLS($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_SOCKS) {
                array_push($proxy, self::buildSocks5($item['password'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === Server::TYPE_HTTP) {
                array_push($proxy, self::buildHttp($item['password'], $item));
                array_push($proxies, $item['name']);
            }
        }

        $config['proxies'] = array_merge($config['proxies'] ? $config['proxies'] : [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            if (!is_array($config['proxy-groups'][$k]['proxies']))
                $config['proxy-groups'][$k]['proxies'] = [];
            $isFilter = false;
            foreach ($config['proxy-groups'][$k]['proxies'] as $src) {
                foreach ($proxies as $dst) {
                    if (!$this->isRegex($src))
                        continue;
                    $isFilter = true;
                    $config['proxy-groups'][$k]['proxies'] = array_values(array_diff($config['proxy-groups'][$k]['proxies'], [$src]));
                    if ($this->isMatch($src, $dst)) {
                        array_push($config['proxy-groups'][$k]['proxies'], $dst);
                    }
                }
                if ($isFilter)
                    continue;
            }
            if ($isFilter)
                continue;
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        $config['proxy-groups'] = array_filter($config['proxy-groups'], function ($group) {
            return $group['proxies'];
        });
        $config['proxy-groups'] = array_values($config['proxy-groups']);
        // Force the current subscription domain to be a direct rule
        $subsDomain = request()->header('Host');
        if ($subsDomain) {
            array_unshift($config['rules'], "DOMAIN,{$subsDomain},DIRECT");
        }

        $yaml = Yaml::dump($config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $yaml = str_replace('$app_name', admin_setting('app_name', 'XBoard'), $yaml);
        return response($yaml)
            ->header('content-type', 'text/yaml')
            ->header('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}")
            ->header('profile-update-interval', '24')
            ->header('content-disposition', 'attachment;filename*=UTF-8\'\'' . rawurlencode($appName));
    }

    public static function buildShadowsocks($uuid, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'ss';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['cipher'] = data_get($protocol_settings, 'cipher');
        $array['password'] = $uuid;
        $array['udp'] = true;
        if (data_get($protocol_settings, 'plugin') && data_get($protocol_settings, 'plugin_opts')) {
            $plugin = data_get($protocol_settings, 'plugin');
            $pluginOpts = data_get($protocol_settings, 'plugin_opts', '');
            $array['plugin'] = $plugin;

            // 解析插件选项
            $parsedOpts = collect(explode(';', $pluginOpts))
                ->filter()
                ->mapWithKeys(function ($pair) {
                    if (!str_contains($pair, '=')) {
                        return [];
                    }
                    [$key, $value] = explode('=', $pair, 2);
                    return [trim($key) => trim($value)];
                })
                ->all();

            // 根据插件类型进行字段映射
            switch ($plugin) {
                case 'obfs':
                    $array['plugin-opts'] = array_filter([
                        'mode' => $parsedOpts['obfs'] ?? ($parsedOpts['mode'] ?? 'http'),
                        'host' => $parsedOpts['obfs-host'] ?? ($parsedOpts['host'] ?? 'www.bing.com'),
                    ]);

                    // 可选path参数
                    if (isset($parsedOpts['path'])) {
                        $array['plugin-opts'] = array_filter([
                            'path' => $parsedOpts['path'] ?? '/',
                        ]);
                    }
                    break;

                case 'v2ray-plugin':
                    $array['plugin-opts'] = array_filter([
                        'mode' => $parsedOpts['mode'] ?? 'websocket',
                        'tls' => isset($parsedOpts['tls']) && $parsedOpts['tls'] == 'true',
                        'host' => $parsedOpts['host'] ?? '',
                        'path' => $parsedOpts['path'] ?? '/',
                    ], fn($v) => $v !== null);
                    break;

                default:
                    // 对于其他插件，直接使用解析出的键值对
                    $array['plugin-opts'] = $parsedOpts;
            }
        }
        return $array;
    }

    public static function buildVmess($uuid, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'vmess',
            'server' => $server['host'],
            'port' => $server['port'],
            'uuid' => $uuid,
            'alterId' => 0,
            'cipher' => 'auto',
            'udp' => true
        ];

        if (data_get($protocol_settings, 'tls')) {
            $array['tls'] = (bool) data_get($protocol_settings, 'tls');
            $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
            $array['servername'] = data_get($protocol_settings, 'tls_settings.server_name');
            self::appendEch($array, data_get($protocol_settings, 'tls_settings.ech'));
        }

        self::appendUtls($array, $protocol_settings);
        self::appendMultiplex($array, $protocol_settings);

        switch (data_get($protocol_settings, 'network')) {
            case 'tcp':
                $headerType = data_get($protocol_settings, 'network_settings.header.type', 'tcp');
                $array['network'] = ($headerType === 'http') ? 'http' : 'tcp';
                if ($headerType === 'http') {
                    if (
                        $httpOpts = array_filter([
                            'headers' => data_get($protocol_settings, 'network_settings.header.request.headers'),
                            'path' => data_get($protocol_settings, 'network_settings.header.request.path', ['/'])
                        ])
                    ) {
                        $array['http-opts'] = $httpOpts;
                    }
                }
                break;
            case 'ws':
                $array['network'] = 'ws';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['ws-opts']['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.headers.Host'))
                    $array['ws-opts']['headers'] = ['Host' => $host];
                break;
            case 'grpc':
                $array['network'] = 'grpc';
                if ($serviceName = data_get($protocol_settings, 'network_settings.serviceName'))
                    $array['grpc-opts']['grpc-service-name'] = $serviceName;
                break;
            case 'h2':
                $array['network'] = 'h2';
                $array['tls'] = true;
                $array['h2-opts'] = [];
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['h2-opts']['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host'))
                    $array['h2-opts']['host'] = is_array($host) ? $host : [$host];
                break;
            default:
                break;
        }
        return $array;
    }

    public function buildVless($uuid, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'vless',
            'server' => $server['host'],
            'port' => $server['port'],
            'uuid' => $uuid,
            'alterId' => 0,
            'cipher' => 'auto',
            'udp' => true,
            'flow' => data_get($protocol_settings, 'flow'),
            'encryption' => match (data_get($protocol_settings, 'encryption.enabled')) {
                true => data_get($protocol_settings, 'encryption.encryption', 'none'),
                default => 'none'
            },
            'tls' => false
        ];

        switch (data_get($protocol_settings, 'tls')) {
            case 1:
                $array['tls'] = true;
                $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
                if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                    $array['servername'] = $serverName;
                }
                self::appendEch($array, data_get($protocol_settings, 'tls_settings.ech'));
                self::appendUtls($array, $protocol_settings);
                break;
            case 2:
                $array['tls'] = true;
                $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'reality_settings.allow_insecure', false);
                $array['servername'] = data_get($protocol_settings, 'reality_settings.server_name');
                $array['reality-opts'] = [
                    'public-key' => data_get($protocol_settings, 'reality_settings.public_key'),
                    'short-id' => data_get($protocol_settings, 'reality_settings.short_id')
                ];
                self::appendUtls($array, $protocol_settings);
                break;
        }

        switch (data_get($protocol_settings, 'network')) {
            case 'tcp':
                $headerType = data_get($protocol_settings, 'network_settings.header.type', 'tcp');
                $array['network'] = ($headerType === 'http') ? 'http' : 'tcp';
                if ($headerType === 'http') {
                    if (
                        $httpOpts = array_filter([
                            'headers' => data_get($protocol_settings, 'network_settings.header.request.headers'),
                            'path' => data_get($protocol_settings, 'network_settings.header.request.path', ['/'])
                        ])
                    ) {
                        $array['http-opts'] = $httpOpts;
                    }
                }
                break;
            case 'ws':
                $array['network'] = 'ws';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['ws-opts']['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.headers.Host')) {
                    $array['ws-opts']['headers'] = ['Host' => $host];
                }
                break;
            case 'grpc':
                $array['network'] = 'grpc';
                if ($serviceName = data_get($protocol_settings, 'network_settings.serviceName'))
                    $array['grpc-opts']['grpc-service-name'] = $serviceName;
                break;
            case 'h2':
                $array['network'] = 'h2';
                $array['h2-opts'] = [];
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['h2-opts']['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host'))
                    $array['h2-opts']['host'] = is_array($host) ? $host : [$host];
                break;
        }

        self::appendMultiplex($array, $protocol_settings);

        return $array;
    }

    public static function buildTrojan($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $array = [
            'name' => $server['name'],
            'type' => 'trojan',
            'server' => $server['host'],
            'port' => $server['port'],
            'password' => $password,
            'udp' => true,
        ];

        $tlsMode = (int) data_get($protocol_settings, 'tls', 1);
        switch ($tlsMode) {
            case 2: // Reality
                $array['tls'] = true;
                $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'reality_settings.allow_insecure', false);
                if ($serverName = data_get($protocol_settings, 'reality_settings.server_name')) {
                    $array['sni'] = $serverName;
                }
                $array['reality-opts'] = [
                    'public-key' => data_get($protocol_settings, 'reality_settings.public_key'),
                    'short-id' => data_get($protocol_settings, 'reality_settings.short_id'),
                ];
                break;
            default: // Standard TLS
                $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
                if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                    $array['sni'] = $serverName;
                }
                self::appendEch($array, data_get($protocol_settings, 'tls_settings.ech'));
                break;
        }

        self::appendUtls($array, $protocol_settings);
        self::appendMultiplex($array, $protocol_settings);

        switch (data_get($protocol_settings, 'network')) {
            case 'tcp':
                $headerType = data_get($protocol_settings, 'network_settings.header.type', 'tcp');
                $array['network'] = ($headerType === 'http') ? 'http' : 'tcp';
                if ($headerType === 'http') {
                    $array['http-opts']['path'] = data_get($protocol_settings, 'network_settings.header.request.path', ['/']);
                }
                break;
            case 'ws':
                $array['network'] = 'ws';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['ws-opts']['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.headers.Host'))
                    $array['ws-opts']['headers'] = ['Host' => $host];
                break;
            case 'grpc':
                $array['network'] = 'grpc';
                if ($serviceName = data_get($protocol_settings, 'network_settings.serviceName'))
                    $array['grpc-opts']['grpc-service-name'] = $serviceName;
                break;
        }

        return $array;
    }

    public static function buildHysteria($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $array['name'] = $server['name'];
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['up-speed'] = data_get($protocol_settings, 'bandwidth.up');
        $array['down-speed'] = data_get($protocol_settings, 'bandwidth.down');
        $array['skip-cert-verify'] = data_get($protocol_settings, 'tls.allow_insecure');
        if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
            $array['sni'] = $serverName;
        }
        if (isset($server['ports'])) {
            $array['ports'] = $server['ports'];
        }
        switch (data_get($protocol_settings, 'version')) {
            case 1:
                $array['type'] = 'hysteria';
                $array['auth-str'] = $password;
                $array['protocol'] = 'udp';
                if (data_get($protocol_settings, 'obfs.open')) {
                    $array['obfs'] = data_get($protocol_settings, 'obfs.password');
                }
                break;
            case 2:
                $array['type'] = 'hysteria2';
                $array['auth'] = $password;
                $array['fast-open'] = true;
                if (data_get($protocol_settings, 'obfs.open')) {
                    $array['obfs'] = data_get($protocol_settings, 'obfs.type', 'salamander');
                    $array['obfs-password'] = data_get($protocol_settings, 'obfs.password');
                }
                break;
        }
        return $array;
    }

    public static function buildTuic($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'tuic',
            'server' => $server['host'],
            'port' => $server['port'],
            'congestion-controller' => data_get($protocol_settings, 'congestion_control', 'cubic'),
            'udp-relay-mode' => data_get($protocol_settings, 'udp_relay_mode', 'native'),
            'alpn' => data_get($protocol_settings, 'alpn', ['h3']),
            'reduce-rtt' => true,
            'fast-open' => true,
            'heartbeat-interval' => 10000,
            'request-timeout' => 8000,
            'max-udp-relay-packet-size' => 1500,
            'version' => data_get($protocol_settings, 'version', 5),
        ];

        if (data_get($protocol_settings, 'version') === 4) {
            $array['token'] = $password;
        } else {
            $array['uuid'] = $password;
            $array['password'] = $password;
        }

        $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls.allow_insecure', false);
        if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
            $array['sni'] = $serverName;
        }

        return $array;
    }

    public static function buildAnyTLS($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $array = [
            'name' => $server['name'],
            'type' => 'anytls',
            'server' => $server['host'],
            'port' => $server['port'],
            'password' => $password,
            'sni' => data_get($protocol_settings, 'tls.server_name'),
            'skip-cert-verify' => (bool) data_get($protocol_settings, 'tls.allow_insecure', false),
            'udp' => true,
        ];

        return $array;
    }

    public static function buildSocks5($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $array = [
            'name' => $server['name'],
            'type' => 'socks5',
            'server' => $server['host'],
            'port' => $server['port'],
            'username' => $password,
            'password' => $password,
            'udp' => true,
        ];

        if (data_get($protocol_settings, 'tls')) {
            $array['tls'] = true;
            $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
            if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                $array['sni'] = $serverName;
            }
        }

        return $array;
    }

    public static function buildHttp($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $array = [
            'name' => $server['name'],
            'type' => 'http',
            'server' => $server['host'],
            'port' => $server['port'],
            'username' => $password,
            'password' => $password,
        ];

        if (data_get($protocol_settings, 'tls')) {
            $array['tls'] = true;
            $array['skip-cert-verify'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
            if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                $array['sni'] = $serverName;
            }
        }

        return $array;
    }

    private function isRegex($exp)
    {
        if (empty($exp)) {
            return false;
        }
        try {
            return preg_match($exp, '') !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isMatch($exp, $str)
    {
        try {
            return preg_match($exp, $str);
        } catch (\Exception $e) {
            return false;
        }
    }

    protected static function appendMultiplex(&$array, $protocol_settings)
    {
        if ($multiplex = data_get($protocol_settings, 'multiplex')) {
            if (data_get($multiplex, 'enabled')) {
                $array['smux'] = array_filter([
                    'enabled' => true,
                    'protocol' => data_get($multiplex, 'protocol', 'yamux'),
                    'max-connections' => data_get($multiplex, 'max_connections'),
                    // 'min-streams' => data_get($multiplex, 'min_streams'),
                    // 'max-streams' => data_get($multiplex, 'max_streams'),
                    'padding' => data_get($multiplex, 'padding') ? true : null,
                ]);

                if (data_get($multiplex, 'brutal.enabled')) {
                    $array['smux']['brutal-opts'] = [
                        'enabled' => true,
                        'up' => data_get($multiplex, 'brutal.up_mbps'),
                        'down' => data_get($multiplex, 'brutal.down_mbps'),
                    ];
                }
            }
        }
    }

    protected static function appendUtls(&$array, $protocol_settings)
    {
        if ($utls = data_get($protocol_settings, 'utls')) {
            if (data_get($utls, 'enabled')) {
                $array['client-fingerprint'] = Helper::getTlsFingerprint($utls);
            }
        }
    }

    protected static function appendEch(&$array, $ech): void
    {
        if ($normalized = Helper::normalizeEchSettings($ech)) {
            $array['ech-opts'] = array_filter([
                'enable' => true,
                'config' => Helper::toMihomoEchConfig(data_get($normalized, 'config')),
                'query-server-name' => data_get($normalized, 'query_server_name'),
            ], fn($value) => $value !== null);
        }
    }
}
