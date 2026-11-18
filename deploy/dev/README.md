# 本地测试面板（deploy/dev）

面板 + Xboard-Node 本地联调用。全 Docker，sqlite 落 `../../.docker/.data/`。

## 快速启动

```bash
git submodule update --init --recursive   # public/assets/admin 后台 SPA（缺失则后台 404）
cd deploy/dev
sudo -n docker compose up -d --build      # 从本地 Dockerfile 构建 xboard-test 镜像
./init.sh                                 # 幂等更新协议矩阵、管理员、套餐和交接 prompt
```

已有数据库也须在矩阵更新后运行 `init.sh`。脚本会重置测试管理员密码并按节点名称更新测试节点；不会删除其他节点或清空数据库。

镜像完全由本仓 `deploy/dev/Dockerfile` 构建，**不基于上游 `ghcr.io/cedar2025/xboard`**：

- `runtime` stage 是根 `Dockerfile` runtime stage 的副本（同 base、同扩展、同 apk 包），改 PHP 扩展/apk 包时两处同步。
- `app` stage 直接 `COPY` 工作区代码（含未 push 的本地提交）并按 `composer.lock` 安装 vendor，不跑 `git clone`。
- 构建上下文过滤在 `deploy/dev/Dockerfile.dockerignore`（BuildKit 优先用它，替代仓库根 `.dockerignore`；关键点是**保留 composer.lock**、剔除 `.git` 与 `.docker/.data`）。
- 首次构建需编译 intl 等扩展（数分钟）；后续改代码只重跑 app stage（约 40 秒）。代码改完必须 `up -d --build` 才进容器（代码在镜像内，不是挂载）。

`init.sh` 自动从当前数据库生成交接 prompt `NODE-TEST-PROMPT.md`，含实际 ID、端口、传输、关键参数（cipher/版本/obfs/flow）和内核，贴到 Xboard-node 会话。固定 `singbox` / `xray` 的副本分别验证双内核；`Socks-tls` 是禁用的历史负向样本，不是加密节点。

初始化现在会先用 `ServerSave` 规则逐条预检启用种子，违反即抛错中止，避免脏配置进节点测试；矩阵改名后残留的旧节点只告警（`WARN stale test nodes`），不自动删除。

初始化末尾自动跑两个下发门禁，任一失败即中止：

- `verify-api.php`：233 条逐节点比对真实 UniProxy `config`/`user` 响应与面板组装结果。默认 `VERIFY_MODE=config` 只验 `config`；`init.sh` 显式传 `full` 才验 `user`。因为 `user` 端点会 `touchNode()` 写 `LAST_CHECK_AT`（TTL 1h），**测试进行中只能跑 config 模式**，否则会伪造在线证据。其余断言：协议、端口、传输、TLS/Reality 透传、cipher、SS2022 `server_key`、hysteria 版本/带宽/obfs、tuic 拥塞、mieru、证书归一化、kernel 覆盖、可选键未空发、ETag/304、错误 token 拒绝。Socks 记录会归一化为明文并单独标记。
- `verify-params.php`：临时创建 10 个带高级参数的探针节点（mux、sniff、udpIdleTimeout、PROXY protocol、QUIC 调优、ECH/ALPN、VLESS encryption、masquerade/resolver/congestion、padding_scheme、file 证书），走真实 API 断言后删除；用于覆盖种子矩阵没有的高级字段。会写库，**测试进行中不要跑**。

## 登录信息

- 面板：`http://127.0.0.1:7001`（跨机用本机 LAN IP）
- 管理后台：`http://127.0.0.1:7001/ac821707`
- 账号：`admin@xboard.test` / `xboard123`（expired 2100 / 100GB）
- server_token（UniProxy 节点 API）：`xboard-node-test-token`
- WS：8076

## 节点联调

面板仓只准备数据库节点与交接材料，不启动 Xboard-node 或测试客户端。测试进行中可用只读观测：

```bash
./watch.sh          # 15s 一刷：在线数、push 新鲜度、真实流量增量、面板错误数
./watch.sh 30 --once
./progress.sh       # 只读读 ../Xboard-node/.test-results/：完成数、终态分布、是否停滞
```

将下面的 prompt 发给 Xboard-node 仓会话：

```text
请读取 /Users/icodex/Git/xray/Xboard/deploy/dev/NODE-TEST-PROMPT.md，先记录节点服务端网络环境基线，再按实际节点表分批启动 Xboard-node 服务端与客户端。逐节点通过对应客户端和服务端访问 Google，验证证书、响应、路径及负向对照；UDP 独立测试。逐阶段记录并分别汇总节点×内核和去重协议组合结果；环境阻塞和未验证不得算通过。同机结果不代表跨机可达。
```

生成的交接文件包含测试凭据，已被 gitignore；不要提交或公开。新增组合是待验证候选，不保证所有内核均支持。

## 已知坑

- macOS docker 一律 `sudo -n`；compose exec 偶发竞态，脚本里用 `docker compose ps -q` 取 CID 后 `docker exec`。
- sqlite 曾出现一次索引条目损坏（`integrity_check` 报 missing index row，`quick_check=ok`，数据未丢）。恢复方式：`REINDEX` + 重启。原因**未复现未归因**：WAL + 并发写 + 中途重启对照两轮均 ok，不要据此改 journal_mode。`init.sh` 每轮先跑完整性门禁，失败即中止。
- 改 `v2_settings` 表后须清 redis `admin_settings`（init.php 已含），且 secure_path 变更须 `docker restart`（路由启动时求值）。
- 代码与 vendor 都在镜像内：改代码或 composer 依赖须 `up -d --build`；vendor 由 `composer.lock` 在构建时安装，无 dev 依赖（phpunit/debugbar 不在镜像里）。
- 根 `Dockerfile` 的 `app` stage 仍是 `git clone origin/master`（发布用），本地测试只走 `deploy/dev/Dockerfile`，不要再给 dev 镜像叠上游镜像层。
- UniProxy 接口：`GET /api/v1/server/UniProxy/{config,user}?token=<server_token>&node_id=<id>&node_type=<type>`；订阅 `/api/v1/client/subscribe?token=<user->token>`。
