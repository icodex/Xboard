# 任务：全节点矩阵端到端验证（31 节点，一次性起全部，真实流量转发）

## 面板环境（已就绪，跑在本机 docker，macOS docker 一律 sudo -n）

- 面板：`http://127.0.0.1:7001`，server_token：`xboard-node-test-token`
- 测试用户 uuid：`__UUID__`（ UniProxy user 接口返回同值）
- 管理后台：`http://127.0.0.1:7001/ac821707`（admin@xboard.test / `xboard123`），可网页确认节点在线/流量
- 订阅（客户端导入/对照用）：`http://127.0.0.1:7001/api/v1/client/subscribe?token=16d1317da074da3922de589a4ebc6e16`（clash-verge UA 出全 31 节点；v2rayN/General UA 缺 naive/mieru/hysteria2——客户端格式覆盖缺口，不影响节点直连测试）
- 面板日志：`sudo -n docker logs -f xboard-dev-xboard-1`
- 下发接口：`GET /api/v1/server/UniProxy/config?token={{SECRET_p2lg4elz}}&node_id=<id>&node_type=<type>`
- 节点端口规则：**port = 10000 + node_id**（10001~10031），面板监听 IP 0.0.0.0

## 节点矩阵（id / node_type / 名称 / 内核预期）

| id | type | 名称 | 内核预期 |
|----|------|------|----------|
| 1 | shadowsocks | SS-aes256 | 双内核 |
| 2 | shadowsocks | SS2022-aes256 | 双内核（server_key 面板派发） |
| 3 | shadowsocks | SS2022-aes128 | 双内核（chacha2022 已移除，双内核 multi-user 不支持） |
| 4 | vmess | VMess-tcp | 双内核 |
| 5 | vmess | VMess-ws-tls | 双内核 |
| 6 | vmess | VMess-grpc-tls | 双内核 |
| 7 | vmess | VMess-reality | **仅 xray**（singbox 静默降级明文=bug） |
| 8 | vless | VLESS-reality-vision | 双内核 |
| 9 | vless | VLESS-ws-tls | 双内核 |
| 10 | vless | VLESS-xhttp-tls | **仅 xray** |
| 11 | vless | VLESS-h2-tls | 双内核 |
| 12 | trojan | Trojan-tls | 双内核 |
| 13 | trojan | Trojan-reality | 双内核 |
| 14 | hysteria | Hysteria2-obfs | 双内核（obfs 仅 singbox 生效） |
| 15 | hysteria | Hysteria1 | **仅 singbox** |
| 16 | tuic | TUIC-bbr | **仅 singbox** |
| 17 | anytls | AnyTLS | **仅 singbox** |
| 18 | naive | Naive-tls | **仅 singbox** |
| 19 | socks | Socks-plain | 仅 singbox（无 TLS） |
| 20 | http | HTTP-tls | 仅 singbox |
| 21 | mieru | Mieru-tcp | **仅 singbox** |
| 22 | vless | VLESS-xhttp-reality | **仅 xray** |
| 23 | vless | VLESS-grpc-reality | 推荐 xray |
| 24 | vless | VLESS-tcp-tls-vision | 双内核 |
| 25 | vless | VLESS-tcp-tls-min | 双内核 |
| 26 | vless | VLESS-tcp-plain | 双内核 |
| 27 | vless | VLESS-grpc-plain | 双内核 |
| 28 | vless | VLESS-httpupgrade-tls | 双内核 |
| 29 | vmess | VMess-tcp-tls | 双内核 |
| 30 | vmess | VMess-ws-plain | 双内核 |
| 31 | socks | Socks-tls | ⚠️ 节点端忽略 TLS，实际明文，按明文 socks 测 |

tls=1/2 节点面板已推自签证书（self），客户端/内核侧 `allowInsecure`。Reality keypair/short_id `0123abcd` 面板下发，从 config JSON 取，不要猜。SS2022 用户密码从下发字段取；缺失时按 `base64(substr(md5(created_at),0,32)):base64(substr(uuid,0,32))` 派生（created_at 为面板节点记录值）。

## 步骤

1. **拉全量下发**：对 31 个节点逐个 `curl -s ".../UniProxy/config?token={{SECRET_iokzmvbk}}"`（node_type 按上表）存 `./.test-cfg/<id>.json`；user 接口抽查确认 uuid。任何 4xx/空配置先停下报告，不要硬跑。
2. **一次性起全部节点**：用 **multi-node 模式**——配置 `nodes:` 列出 1~31（共享 panel url/token），`kernel.type: "auto"`（按节点传输自动选内核），`health_port: 65530`，起 `go run ./cmd/xboard-node -c <配置>`。
   - `curl http://127.0.0.1:65530/healthz` 正常
   - 校验 31 个端口全部监听：`for p in $(seq 10001 10031); do nc -z 127.0.0.1 $p || echo "PORT $p DOWN"; done`
   - 对照下发确认每个节点的内核选择正确（xhttp/reality-vmess 必须 xray；tuic/anytls/naive/mieru/hysteria1/socks/http 必须 singbox）
3. **逐节点起客户端**（端口 socks 入站 = 11000 + node_id）：
   - 客户端内核选择：vmess/vless/trojan/shadowsocks（含 SS2022 aes 变体）用 xray 客户端；hysteria/hysteria2/tuic/anytls/naive/mieru/http 用 sing-box 客户端
   - **socks 节点（19、31）不需要客户端内核**：curl 直接走 `socks5h://127.0.0.1:10019` / `10031`
   - 客户端出站参数全部从对应 `<id>.json` 下发取（uuid 同面板用户），自签证书 `allowInsecure`
4. **连通验证（成功判据）**：每个节点经客户端 curl 国外网站：
   ```bash
   code=$(curl -x socks5h://127.0.0.1:$((11000+id)) -sS -o /dev/null -w '%{http_code}' --max-time 15 https://www.google.com)
   ```
   - 200 = 通过；非 200 重试一次并换 `https://httpbin.org/get`（判 JSON 正常返回）
   - socks 节点直接 `curl -x socks5h://127.0.0.1:10019 ...`
5. **流量闭环抽查**：任选 3 个已通过节点，各跑 2 次网页访问后查面板侧：`sudo -n docker exec -i xboard-dev-xboard-1 php /www/artisan tinker --execute="print_r(App\Models\StatUser::orderBy('id','desc')->limit(5)->get()->toArray());"` u/d 增长、节点在线、面板日志无 error。
6. **汇总产出**：
   - 表格：id / 名称 / 实际内核 / curl 状态码 / 耗时 / 结论
   - 目标 31/31 通过（auto 内核选择正确时全部可通）；任何失败、静默降级（如 #7 走 singbox）、端口未监听记为 finding
   - 失败贴关键日志（节点 + 内核 + 面板各一段）
   - 下发字段与内核消费不匹配的，给字段名与两侧行为，**不改面板代码**（面板仓另修）

## 参考

- 下发组装：面板仓 `app/Services/ServerService.php` `buildNodeConfig()`
- 协议矩阵：本仓 `docs-protocol-support.md`
- multi-node 配置格式：本仓 `config.yml.example` MULTI-NODE MODE 段
