# 任务：Xboard-node 服务端与客户端协议矩阵验证

## 面板环境
本文件由面板仓生成，只提供面板、数据库节点和测试数据；所有节点进程及客户端测试均在 Xboard-node 仓执行。矩阵是待测试候选，未验证即不代表已支持或已连通。


- 面板：`http://127.0.0.1:7001`；节点 token：`__TOKEN__`；测试用户 UUID：`__UUID__`。
- 后台：`__ADMINURL__`；账号 `__ADMIN__` / `__PASSWD__`。
- 订阅：`__SUBURL__`。测试专用凭据，不要提交或上传。
- 面板日志：`sudo -n docker logs xboard-dev-xboard-1`。
- 节点下发：`GET /api/v1/server/UniProxy/config?token=__TOKEN__&node_id=<实际ID>&node_type=<协议>`；用户接口同路径的 `user`。
- 每次初始化都会从数据库重新生成下表。ID 不是端口减 10000，严禁假设连续；以表格为准。
- 本机联调时节点与客户端连接 `127.0.0.1`；跨机时替换为面板主机可达地址，并检查端口映射、防火墙和节点通告地址。面板未启动任何节点内核。
- 先检查端口占用和资源情况，分批执行；每批结束关闭本次启动的进程、客户端和临时文件，不终止既有服务。


## 进度汇报与续跑（强制）

- 工作目录 `./.test-results/`（Xboard-node 仓内）。每个节点测试结束立即写 `<ID>.json`（字段见“结果与清理”）；未完成节点不得预写通过。
- 每次更新同时原子重写 `./.test-results/progress.json`：先写 `progress.json.tmp` 再 `mv`，避免读到半截。字段：
  `updated_at`、`started_at`、`total`、`done`、`passed`、`failed`、`blocked`、`unverified`、`current_batch`、`current_node`、`eta_minutes`、`note`。
- 首次启用本约定或中断恢复时，先把已经开始测试的节点按现有证据补写 `<ID>.json`：有终态证据才写 pass/fail/blocked，否则写 `unverified` 并在 note 说明“记录开始前已运行”；据此初始化 `progress.json`，不要从零重新统计。
- 汇报节奏：每完成 1 个节点更新文件；每 5 个节点或每 5 分钟（先到者）在对话里打印一行：
  `PROGRESS <done>/<total> (<pct>%) pass=<n> fail=<n> blocked=<n> unverified=<n> batch=<protocol> node=#<id> eta=<m>m`
- 每批开始、结束各补一次批次小结，列出该批通过/失败/阻塞/未验证节点，不要只在全部结束时汇报。
- 单节点超过 10 分钟无终态时，把卡住节点与现象写进 `note`，继续下一个，不静默跳过。
- 续跑：会话开始或恢复后先读 `.test-results/`，列出已完成与剩余，只补剩余项；面板数据变化时以当前数据库为准并在结果中标注时间。
- `done` 只统计已有终态 verdict 的节点；获取配置、启动进程、端口监听均不计入。

## 数据库矩阵

`auto` 为基线；`singbox`、`xray` 后缀是同一配置的固定内核副本。表中“正向候选”表示待验证，不是兼容性保证。`禁用/负向` 不加入 multi-node 或订阅。

| ID | 名称 | 协议 | 端口 | 传输 | TLS | 关键参数 | 指定内核 | 用途 |
|---|---|---|---|---|---|---|---|---|
__MATRIX__

## 测试前：拓扑、隔离和环境基线

1. 记录测试时间、面板地址、节点端和客户端所在机器/容器、网络命名空间、内核及客户端版本。`127.0.0.1` 只指向当前机器或容器；跨机时配置可达地址并检查路由、防火墙和 NAT。**同机通过只证明同机链路，不代表跨机可达。**
2. 在节点服务端进程实际使用的网络环境中，先禁用代理直连 `https://www.google.com/` 和 `https://httpbin.org/get`，分别记录 DNS、TLS、HTTP 状态、最终 URL 和耗时；保留基线结果。基线不通时区分 DNS、TLS、网络和目标服务故障；受影响目标标记环境阻塞，不能推断节点通过或失败。基线仅说明出口可用，不代替代理测试。
3. 盘点已有进程与 TCP/UDP 端口，按资源分批运行；为每个服务端节点和客户端分配独立端口、日志与临时配置。不要覆盖或停止不属于本次测试的服务。关闭代理环境变量，客户端配置仅允许当前待测节点作为代理出站，不允许规则分流、直连回退或借用其他节点。验证客户端实际连接的服务端地址及端口。

## 服务端、客户端和真实公网验证

1. **下发**：按表中真实 ID 和协议逐个获取 config 和 user；检查 HTTP 状态、JSON 非空、协议、端口、TLS、传输、cipher、证书及 `kernel_type` 与数据库一致。保存必要证据但不要泄露 token 和私钥；不一致记 finding，不要伪造配置绕过。
2. **服务端**：将启用的节点分批写入节点仓 `config.yml.example` 格式的 `nodes:` 临时配置，顶层 `kernel.type: auto`，使用实际面板 URL 和 token，启动 Xboard-node。记录进程、健康状态、监听和实际内核。固定 singbox 和 xray 的同场景副本分别测试；内核选择不符单独报告。已知 xray 不支持 h2/http，singbox 不支持 xhttp/splithttp；VMess Reality 使用 xray。Hysteria2 obfs 在 xray 不生效，不计为混淆通过。
3. **客户端**：从实际下发构建对应 xray、sing-box 或协议原生客户端，核对进程、独立入站及其出站目的地址。SOCKS/HTTP 节点使用实际认证信息；不得无认证或绕过待测节点。TLS/Reality 场景分别验证握手、安全类型和 SNI；仅节点自签证书允许在测试客户端放宽校验，公网目标 HTTPS 证书必须正常验证。
4. **真实公网 TCP**：每条正向节点必须让对应客户端通过该节点访问 Google。例如客户端提供本地 SOCKS5 入站时：

   ```bash
   env -u HTTP_PROXY -u HTTPS_PROXY -u ALL_PROXY -u http_proxy -u https_proxy -u all_proxy \
     curl --noproxy '' --proxy "socks5h://127.0.0.1:${CLIENT_SOCKS_PORT}" \
       --connect-timeout 10 --max-time 25 -sS -L -o "$RESPONSE_BODY_FILE" \
       -w 'http=%{http_code} final=%{url_effective} time=%{time_total}\n' \
       https://www.google.com/
   ```

   端口必须是当前客户端的实际监听端口，`RESPONSE_BODY_FILE` 是本次测试的临时响应文件；SOCKS/HTTP 节点可直接用对应代理类型和凭据。Google 通过须同时满足：目标 HTTPS 证书校验成功、最终 URL 属于预期 Google 域名、HTTP 200、响应与 Google 页面相符，且客户端连接信息及对应服务端入站日志或计数器证实经过当前节点。注意 curl 的 `remote_ip` 对代理请求可能只是代理地址，不可单独作为出口证明。禁止 `-k` 跳过公网证书验证。
5. **负向对照和重试**：逐节点使用错误凭据，或仅关闭本次启动的当前节点，再从同一客户端路径请求同一目标，必须失败；恢复后再次成功。若节点与其他场景共享进程，不得关闭共享进程影响其他节点，改用隔离客户端错误凭据。Google 请求失败时重试一次，分别记录两次状态、耗时和错误；仍失败可另测 `https://httpbin.org/get`，要求有效 JSON 并单独报告。备用站成功不等于 Google 成功；目标基线不可达时标记环境阻塞。
6. **公网 UDP**：对支持 UDP 转发的协议，分别通过当前客户端及服务端向可控公网 UDP 目标发送带可核对内容的请求，核验实际响应及路径证据，记录目标、时间和超时。先在节点端环境测试该 UDP 目标基线；目标或工具不可用则标记未验证/环境阻塞，不以 TCP、HTTPS 或 `nc -z` 代替。
7. **订阅与上报**：获取 ClashMeta、sing-box、通用客户端订阅，逐项核对支持的协议、地址、端口、传输、安全、cipher 和认证参数；不支持的格式单独记录。至少抽查三个不同协议，记录真实访问前后的面板用户及节点流量、在线状态，等待实际上报周期并确认增量；无法确认标未验证。
8. **历史负向样本**：对禁用的 `Socks-tls`，通过 `ServerSave` 验证 TLS=1 被拒绝；通过 `ServerService::buildNodeConfig` 验证历史记录下发 `tls=0`、`tls_settings=null`。不连接禁用节点，不算 TLS 正向成功。

## 结果与清理

- 每条启用节点先把结果写成 `.test-results/<ID>.json`（字段：id、protocol、network、security、关键参数、requested_kernel、actual_kernel、config、server_start、client_start、handshake、google、fallback、negative、udp、subscription、traffic、verdict、evidence），落盘后再汇总。中断后按已完成的文件续跑，未完成项保持未验证，不凭记忆补结果。按协议分批，单批在一次会话内收尾并汇报。
- 每条启用节点分别记录 ID、协议、传输、安全及关键参数、指定/实际内核、配置下发、服务端启动、客户端启动、握手、Google、备用站、负向对照、UDP、订阅及流量上报。每阶段标记通过、失败、不适用、未验证或环境阻塞；保留两次重试的状态、耗时和脱敏的客户端/服务端证据。未运行的阶段不可标通过。
- Google 端到端通过必须满足第 4、5 步全部条件；TCP、UDP、订阅和流量上报分别统计。按节点配置 × 实际内核汇总启用数、启动数、Google 通过/失败/阻塞/未验证数，明确分母与跳过项。
- 另按协议、传输、安全模式、cipher、版本、混淆、flow 等影响协议行为的关键参数去重；排除 ID、名称、端口、UUID 和内核，以同一去重键汇总不同内核的结果。保留无法判断关键参数的记录并标注未知，说明去重规则、分母及结果，不把“节点数”称为“组合数”。
- 清理本次启动的服务端/客户端和临时配置，保留脱敏结果及关键日志；不终止既有服务、不提交凭据。发现产品问题只报告两侧行为与复现命令，不擅自修改面板代码。

覆盖边界：ACME HTTP/DNS 需要真实域名和凭据；ECH、VLESS encryption、mux、PROXY protocol、sniff、路由及其他高级参数按 `docs-params-support.md` 设计独立正反例，缺乏环境时标未验证。同机结果不得推断跨机、NAT 或公网入站可达性。
