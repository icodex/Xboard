<?php


namespace App\Http\Requests\Admin;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;

class ServerSave extends FormRequest
{
    // utls 与 hop_interval 仅客户端订阅消费（app/Protocols/* 的 uTLS 指纹与端口跳跃），
    // buildNodeConfig 从不下发、节点契约（xboard-node internal/panel/types.go）无此字段。
    // 不是死字段，勿删；想下发节点需节点侧先加字段。
    private const UTLS_RULES = [
        'utls.enabled' => 'nullable|boolean',
        'utls.fingerprint' => 'nullable|string',
    ];

    private const MULTIPLEX_RULES = [
        'multiplex.enabled' => 'nullable|boolean',
        'multiplex.padding' => 'nullable|boolean',
        'multiplex.brutal.enabled' => 'nullable|boolean',
        'multiplex.brutal.up_mbps' => 'nullable|integer',
        'multiplex.brutal.down_mbps' => 'nullable|integer',
    ];

    // 内核实际消费的传输层；kcp/quic/raw 等节点端无实现
    private const NETWORK_RULE = 'required|string|in:tcp,ws,grpc,h2,http,httpupgrade,xhttp,splithttp';

    private const TLS_LEVEL_RULE = 'integer|in:0,1,2';

    // 2022-blake3-chacha20-poly1305 已移除：sing-box 服务端 iPSK 仅实现 blake3-aes，
    // xray 同样无 multi-user 形态，双内核配了都拒启（xboard-node docs-protocol-support）
    private const SHADOWSOCKS_CIPHERS = 'in:aes-128-gcm,aes-192-gcm,aes-256-gcm,chacha20-ietf-poly1305,xchacha20-ietf-poly1305,2022-blake3-aes-128-gcm,2022-blake3-aes-256-gcm';

    private const ECH_RULES = [
        'enabled' => 'nullable|boolean',
        'config' => 'nullable|string',
        'query_server_name' => 'nullable|string',
        'key' => 'nullable|string',
    ];

    private const REALITY_RULES = [
        'reality_settings.allow_insecure' => 'nullable|boolean',
        'reality_settings.server_name' => 'nullable|string',
        'reality_settings.server_port' => 'nullable|integer',
        'reality_settings.dest' => 'nullable|string',
        'reality_settings.public_key' => 'nullable|string',
        'reality_settings.private_key' => 'nullable|string',
        'reality_settings.short_id' => 'nullable|string',
    ];

    // ACME DNS-01 供应商白名单 = xboard-node docs-dns-providers.md 主名（21 家）。
    // 节点侧另收别名（cf/aliyun/do/gcp…），面板只放行主名，避免双份漂移。
    private const CERT_DNS_PROVIDERS = 'in:cloudflare,alidns,tencentcloud,route53,godaddy,namecheap,namesilo,digitalocean,linode,hetzner,gandi,porkbun,netlify,azure,googleclouddns,huaweicloud,bunny,duckdns,ovh,vultr,desec';

    private const CERT_CONFIG_RULES = [
        // cert_mode 为规范键；mode 是历史键，buildNodeConfig 下发前会归一到 cert_mode
        'cert_config.cert_mode' => 'nullable|string|in:none,dns,http,self,file,content',
        'cert_config.mode' => 'nullable|string|in:none,dns,http,self,file,content',
        'cert_config.domain' => 'nullable|string|required_if:cert_config.cert_mode,dns,http|required_if:cert_config.mode,dns,http',
        'cert_config.email' => 'nullable|email',
        'cert_config.dns_provider' => 'nullable|string|' . self::CERT_DNS_PROVIDERS
            . '|required_if:cert_config.cert_mode,dns|required_if:cert_config.mode,dns',
        'cert_config.dns_env' => 'nullable|array',
        'cert_config.dns_env.*' => 'string',
        'cert_config.http_port' => 'nullable|integer|min:1|max:65535',
        'cert_config.cert_file' => 'nullable|string|required_if:cert_config.cert_mode,file|required_if:cert_config.mode,file',
        'cert_config.key_file' => 'nullable|string|required_if:cert_config.cert_mode,file|required_if:cert_config.mode,file',
        'cert_config.cert_content' => 'nullable|string|required_if:cert_config.cert_mode,content|required_if:cert_config.mode,content',
        'cert_config.key_content' => 'nullable|string|required_if:cert_config.cert_mode,content|required_if:cert_config.mode,content',
    ];

    // 结构化跨内核路由规则（xboard-node docs-custom-routes.md）：
    // match 7 条件 + action direct/block/route，双内核等价编译，优先级高于 custom_routes
    private const CUSTOM_ROUTE_RULE_RULES = [
        'custom_route_rules' => 'nullable|array',
        'custom_route_rules.*.name' => 'nullable|string|max:255',
        'custom_route_rules.*.disabled' => 'nullable|boolean',
        'custom_route_rules.*.match' => 'nullable|array',
        'custom_route_rules.*.match.domains' => 'nullable|array',
        'custom_route_rules.*.match.domains.*' => 'string',
        'custom_route_rules.*.match.domain_suffixes' => 'nullable|array',
        'custom_route_rules.*.match.domain_suffixes.*' => 'string',
        'custom_route_rules.*.match.ip_cidrs' => 'nullable|array',
        'custom_route_rules.*.match.ip_cidrs.*' => 'string',
        'custom_route_rules.*.match.ports' => 'nullable|array',
        'custom_route_rules.*.match.ports.*' => 'string',
        'custom_route_rules.*.match.networks' => 'nullable|array',
        'custom_route_rules.*.match.networks.*' => 'string|in:tcp,udp',
        'custom_route_rules.*.match.source_cidrs' => 'nullable|array',
        'custom_route_rules.*.match.source_cidrs.*' => 'string',
        'custom_route_rules.*.match.source_ports' => 'nullable|array',
        'custom_route_rules.*.match.source_ports.*' => 'string',
        'custom_route_rules.*.action.type' => 'required|in:direct,block,route',
        'custom_route_rules.*.action.target' => 'nullable|string|required_if:custom_route_rules.*.action.type,route',
    ];

    private const PROTOCOL_RULES = [
        'shadowsocks' => [
            'cipher' => 'required|string|' . self::SHADOWSOCKS_CIPHERS,
            'obfs' => 'nullable|string',
            'obfs_settings.path' => 'nullable|string',
            'obfs_settings.host' => 'nullable|string',
            // SIP003 双内核均无实现：节点 ValidateNodeSpec 对非空 plugin 直接拒收整个
            // config（节点停留在旧配置）。保存侧禁止非空；空串/null/缺省可过。
            'plugin' => 'prohibited',
            'plugin_opts' => 'prohibited',
        ],
        'vmess' => [
            'tls' => 'required|' . self::TLS_LEVEL_RULE,
            'network' => self::NETWORK_RULE,
            'network_settings' => 'nullable|array',
            'rules' => 'nullable|array',
            'accept_proxy_protocol' => 'nullable|boolean',
        ],
        'trojan' => [
            'tls' => 'nullable|' . self::TLS_LEVEL_RULE,
            'network' => self::NETWORK_RULE,
            'network_settings' => 'nullable|array',
            'server_name' => 'nullable|string',
            'allow_insecure' => 'nullable|boolean',
            'accept_proxy_protocol' => 'nullable|boolean',
        ],
        // hysteria fork server.yaml 能力面板化：字段语义/范围与 server.yaml 对齐
        // （QUIC 窗口下限 16384、udpIdleTimeout 2-600s、masquerade.statusCode
        //  200-599 且 233 为 hysteria 控制端口保留值）。顶层 alpn 死规则已删，
        // 真 ALPN 走 tls.alpn（buildTlsObjectRules）。
        'hysteria' => [
            'version' => 'required|integer|in:1,2',
            'obfs.open' => 'nullable|boolean',
            'obfs.type' => 'string|nullable',
            'obfs.password' => 'string|nullable',
            'bandwidth.up' => 'nullable|integer',
            'bandwidth.down' => 'nullable|integer',
            'bandwidth.disableLossCompensation' => 'nullable|boolean',
            'hop_interval' => 'integer|nullable', // 仅客户端订阅消费（SingBox/ClashMeta 端口跳跃），不下发节点
            'accept_proxy_protocol' => 'nullable|boolean',
            'sniff' => 'nullable|array',
            'sniff.enable' => 'nullable|boolean',
            'sniff.timeout' => 'nullable|integer|min:1|max:60',
            'sniff.rewriteDomain' => 'nullable|boolean',
            'sniff.tcpPorts' => 'nullable|string',
            'sniff.udpPorts' => 'nullable|string',
            'masquerade' => 'nullable|array',
            'masquerade.type' => 'nullable|string|in:404,file,proxy,string',
            'masquerade.listenHTTP' => 'nullable|string',
            'masquerade.listenHTTPS' => 'nullable|string',
            'masquerade.forceHTTPS' => 'nullable|boolean',
            'masquerade.file.dir' => 'nullable|string',
            'masquerade.proxy.url' => 'nullable|url',
            'masquerade.proxy.rewriteHost' => 'nullable|boolean',
            'masquerade.proxy.xForwarded' => 'nullable|boolean',
            'masquerade.proxy.insecure' => 'nullable|boolean',
            'masquerade.string.content' => 'nullable|string',
            'masquerade.string.statusCode' => 'nullable|integer|min:200|max:599|not_in:233',
            'quic' => 'nullable|array',
            'quic.initStreamReceiveWindow' => 'nullable|integer|min:16384',
            'quic.maxStreamReceiveWindow' => 'nullable|integer|min:16384',
            'quic.initConnReceiveWindow' => 'nullable|integer|min:16384',
            'quic.maxConnReceiveWindow' => 'nullable|integer|min:16384',
            'quic.maxIdleTimeout' => 'nullable|integer|min:4|max:120',
            'quic.maxIncomingStreams' => 'nullable|integer|min:8',
            'quic.disablePathMTUDiscovery' => 'nullable|boolean',
            'quic.disableStatelessReset' => 'nullable|boolean',
            'congestion' => 'nullable|array',
            'congestion.type' => 'nullable|string|in:bbr,reno',
            'congestion.bbrProfile' => 'nullable|string',
            'resolver' => 'nullable|array',
            'resolver.type' => 'nullable|string|in:tcp,udp,tls,https',
            'resolver.tcp.addr' => 'nullable|string',
            'resolver.tcp.timeout' => 'nullable|integer|min:1|max:60',
            'resolver.udp.addr' => 'nullable|string',
            'resolver.udp.timeout' => 'nullable|integer|min:1|max:60',
            'resolver.tls.addr' => 'nullable|string',
            'resolver.tls.timeout' => 'nullable|integer|min:1|max:60',
            'resolver.tls.sni' => 'nullable|string',
            'resolver.tls.insecure' => 'nullable|boolean',
            'resolver.https.addr' => 'nullable|string',
            'resolver.https.timeout' => 'nullable|integer|min:1|max:60',
            'resolver.https.sni' => 'nullable|string',
            'resolver.https.insecure' => 'nullable|boolean',
            'disableUDP' => 'nullable|boolean',
            'udpIdleTimeout' => 'nullable|integer|min:2|max:600',
            'ignoreClientBandwidth' => 'nullable|boolean',
        ],
        'vless' => [
            'tls' => 'required|' . self::TLS_LEVEL_RULE,
            'network' => self::NETWORK_RULE,
            'network_settings' => 'nullable|array',
            'flow' => 'nullable|string',
            'encryption' => 'nullable|array',
            'encryption.enabled' => 'nullable|boolean',
            'encryption.encryption' => 'nullable|string',
            'encryption.decryption' => 'nullable|string',
            'accept_proxy_protocol' => 'nullable|boolean',
        ],
        // socks 内核入站无 TLS 选项（sing-box/xray 均不支持），tls=1 保存后实际明文服务，
        // 面板却显示"已加密"——只允许关闭。不用 in:0：其消息 key 会被
        // protocol_settings.*.in 通配盖掉，not_in 无此冲突且 null/缺省可过。
        'socks' => [
            'tls' => 'nullable|integer|not_in:1,2',
        ],
        'naive' => [
            'tls' => 'required|' . self::TLS_LEVEL_RULE,
        ],
        'http' => [
            'tls' => 'required|' . self::TLS_LEVEL_RULE,
            'accept_proxy_protocol' => 'nullable|boolean',
        ],
        'tuic' => [
            'version' => 'nullable|integer',
            'congestion_control' => 'nullable|string',
            'alpn' => 'nullable|array',
            'udp_relay_mode' => 'nullable|string',
        ],
        'mieru' => [
            'transport' => 'required|string|in:TCP,UDP',
            'traffic_pattern' => 'string',
        ],
        'anytls' => [
            'tls' => 'nullable|array',
            'alpn' => 'nullable|string',
            'padding_scheme' => 'nullable|array',
        ],
    ];

    private function getBaseRules(): array
    {
        return [
            'type' => 'required|in:' . implode(',', Server::VALID_TYPES),
            'spectific_key' => 'nullable|string',
            'code' => 'nullable|string',
            'show' => '',
            'name' => 'required|string',
            'group_ids' => 'nullable|array',
            'route_ids' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'excludes' => 'nullable|array',
            'ips' => 'nullable|array',
            'rate' => 'required|numeric',
            'rate_time_enable' => 'nullable|boolean',
            'rate_time_ranges' => 'nullable|array',
            'custom_outbounds' => 'nullable|array',
            'custom_routes' => 'nullable|array',
            'cert_config' => 'nullable|array',
            // 每节点内核选择；空 = 不下发，节点侧 kernel.type auto 解析
            'kernel_type' => 'nullable|string|in:auto,singbox,xray',
            'kernel_log_level' => 'nullable|string|in:trace,debug,info,warn,warning,error,fatal',
            ...self::CUSTOM_ROUTE_RULE_RULES,
            ...self::CERT_CONFIG_RULES,
            'rate_time_ranges.*.start' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.end' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.rate' => 'required_with:rate_time_ranges|numeric|min:0',
            'protocol_settings' => 'array',
            'transfer_enable' => 'nullable|integer|min:0',
        ];
    }

    private function getProtocolRules(string $type): array
    {
        $rules = self::PROTOCOL_RULES[$type] ?? [];

        return match ($type) {
            'vmess' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(),
                self::REALITY_RULES,
                self::MULTIPLEX_RULES,
                self::UTLS_RULES,
            ),
            'trojan' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(includeRoot: true),
                self::REALITY_RULES,
                self::MULTIPLEX_RULES,
                self::UTLS_RULES,
            ),
            'hysteria' => array_merge(
                $rules,
                $this->buildTlsObjectRules(),
            ),
            'tuic' => array_merge(
                $rules,
                $this->buildTlsObjectRules(),
            ),
            'mieru' => array_merge(
                $rules,
                self::MULTIPLEX_RULES,
            ),
            'vless' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(),
                self::REALITY_RULES,
                self::MULTIPLEX_RULES,
                self::UTLS_RULES,
            ),
            'socks', 'naive', 'http' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(includeRoot: $type !== 'socks'),
            ),
            'anytls' => array_merge(
                $rules,
                $this->buildTlsObjectRules(includeRoot: true),
            ),
            default => $rules,
        };
    }

    private function buildTlsSettingsRules(bool $includeRoot = false): array
    {
        return array_merge(
            $includeRoot ? ['tls_settings' => 'nullable|array'] : [],
            [
                'tls_settings.server_name' => 'nullable|string',
                'tls_settings.allow_insecure' => 'nullable|boolean',
                'tls_settings.alpn' => 'nullable|array',
                'tls_settings.ech' => 'nullable|array',
            ],
            $this->prefixRules('tls_settings.ech.', self::ECH_RULES),
        );
    }

    private function buildTlsObjectRules(bool $includeRoot = false): array
    {
        return array_merge(
            $includeRoot ? ['tls' => 'nullable|array'] : [],
            [
                'tls.server_name' => 'nullable|string',
                'tls.allow_insecure' => 'nullable|boolean',
                'tls.alpn' => 'nullable|array',
                'tls.ech' => 'nullable|array',
            ],
            $this->prefixRules('tls.ech.', self::ECH_RULES),
        );
    }

    private function prefixRules(string $prefix, array $rules): array
    {
        $result = [];
        foreach ($rules as $field => $rule) {
            $result[$prefix . $field] = $rule;
        }
        return $result;
    }

    public function rules(): array
    {
        $type = $this->input('type');
        $rules = $this->getBaseRules();
        $protocolRules = $this->getProtocolRules($type);

        foreach ($protocolRules as $field => $rule) {
            $rules['protocol_settings.' . $field] = $rule;
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'protocol_settings.cipher' => '加密方式',
            'protocol_settings.obfs' => '混淆类型',
            'protocol_settings.network' => '传输协议',
            'protocol_settings.port_range' => '端口范围',
            'protocol_settings.traffic_pattern' => 'Traffic Pattern',
            'protocol_settings.transport' => '传输方式',
            'protocol_settings.version' => '协议版本',
            'protocol_settings.password' => '密码',
            'protocol_settings.handshake.server' => '握手服务器',
            'protocol_settings.handshake.server_port' => '握手端口',
            'protocol_settings.multiplex.enabled' => '多路复用',
            'protocol_settings.multiplex.protocol' => '复用协议',
            'protocol_settings.multiplex.max_connections' => '最大连接数',
            'protocol_settings.multiplex.min_streams' => '最小流数',
            'protocol_settings.multiplex.max_streams' => '最大流数',
            'protocol_settings.multiplex.padding' => '复用填充',
            'protocol_settings.multiplex.brutal.enabled' => 'Brutal加速',
            'protocol_settings.multiplex.brutal.up_mbps' => 'Brutal上行速率',
            'protocol_settings.multiplex.brutal.down_mbps' => 'Brutal下行速率',
            'protocol_settings.utls.enabled' => 'uTLS',
            'protocol_settings.utls.fingerprint' => 'uTLS指纹',
            'protocol_settings.tls_settings.ech.enabled' => 'ECH',
            'protocol_settings.tls_settings.ech.config' => 'ECH配置',
            'protocol_settings.tls_settings.ech.query_server_name' => 'ECH查询域名',
            'protocol_settings.tls_settings.ech.key' => 'ECH密钥',
            'protocol_settings.tls_settings.alpn' => 'ALPN',
            'protocol_settings.tls.alpn' => 'ALPN',
            'protocol_settings.accept_proxy_protocol' => '接受PROXY Protocol',
            'protocol_settings.sniff.enable' => '域名嗅探',
            'protocol_settings.masquerade.type' => '伪装站类型',
            'protocol_settings.masquerade.string.statusCode' => '伪装站状态码',
            'protocol_settings.quic.maxIdleTimeout' => 'QUIC空闲超时',
            'protocol_settings.quic.maxIncomingStreams' => 'QUIC最大流数',
            'protocol_settings.congestion.type' => '拥塞控制算法',
            'protocol_settings.congestion.bbrProfile' => 'BBR档位',
            'protocol_settings.resolver.type' => 'DNS解析器类型',
            'protocol_settings.disableUDP' => '禁用UDP转发',
            'protocol_settings.udpIdleTimeout' => 'UDP空闲超时',
            'protocol_settings.ignoreClientBandwidth' => '忽略客户端带宽',
            'protocol_settings.bandwidth.disableLossCompensation' => '禁用丢包补偿',
            'protocol_settings.plugin' => 'SIP003插件',
            'protocol_settings.plugin_opts' => 'SIP003插件参数',
            'kernel_type' => '节点内核',
            'custom_route_rules' => '结构化路由规则',
            'cert_config.cert_mode' => '证书模式',
            'cert_config.dns_provider' => 'DNS供应商',
            'cert_config.domain' => '证书域名',
            'protocol_settings.tls.ech.enabled' => 'ECH',
            'protocol_settings.tls.ech.config' => 'ECH配置',
            'protocol_settings.tls.ech.query_server_name' => 'ECH查询域名',
            'protocol_settings.tls.ech.key' => 'ECH密钥',
        ];
    }

    public function messages()
    {
        $messages = [
            'name.required' => '节点名称不能为空',
            'group_ids.required' => '权限组不能为空',
            'group_ids.array' => '权限组格式不正确',
            'route_ids.array' => '路由组格式不正确',
            'parent_id.integer' => '父ID格式不正确',
            'host.required' => '节点地址不能为空',
            'port.required' => '连接端口不能为空',
            'server_port.required' => '后端服务端口不能为空',
            'tls.required' => 'TLS不能为空',
            'tags.array' => '标签格式不正确',
            'rate.required' => '倍率不能为空',
            'rate.numeric' => '倍率格式不正确',
            'network.required' => '传输协议不能为空',
            'network.in' => '传输协议格式不正确',
            'networkSettings.array' => '传输协议配置有误',
            'ruleSettings.array' => '规则配置有误',
            'tlsSettings.array' => 'tls配置有误',
            'dnsSettings.array' => 'dns配置有误',
            'protocol_settings.*.required' => ':attribute 不能为空',
            'protocol_settings.*.required_if' => ':attribute 不能为空',
            'protocol_settings.*.string' => ':attribute 必须是字符串',
            'protocol_settings.*.integer' => ':attribute 必须是整数',
            'protocol_settings.*.in' => ':attribute 的值不合法',
            'transfer_enable.integer' => '流量上限必须是整数',
            'transfer_enable.min' => '流量上限不能小于0',
        ];

        if ($this->input('type') === 'socks') {
            $messages['protocol_settings.tls.not_in'] = 'socks 节点不支持 TLS（内核无 TLS 入站，开启后实际明文），请保持关闭';
        }

        if ($this->input('type') === 'shadowsocks') {
            $messages['protocol_settings.plugin.prohibited'] = 'SIP003 插件双内核均无实现，节点会拒收该节点配置，请留空';
            $messages['protocol_settings.plugin_opts.prohibited'] = 'SIP003 插件双内核均无实现，节点会拒收该节点配置，请留空';
        }

        return $messages;
    }
}
