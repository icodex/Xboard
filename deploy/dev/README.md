# 本地测试面板（deploy/dev）

面板 + Xboard-Node 本地联调用。全 Docker，sqlite 落 `../../.docker/.data/`。

## 快速启动

```bash
cd deploy/dev
sudo -n docker compose up -d --build   # 首次构建 xboard-test 镜像（工作区代码 overlay）
./init.sh                              # 幂等灌数据：31 节点 + 管理员 + 套餐 + server_token + secure_path
```

已有数据时 `init.sh` 可跳过；全新机器（`.docker/.data` 为空）必须跑。

`init.sh` 末尾自动生成交接 prompt `NODE-TEST-PROMPT.md`（uuid 按当前库刷）并打印——贴到 Xboard-node 仓的 Claude 会话即可。prompt 内容：xboard-node 用 multi-node 模式（nodes 1~31 + kernel auto）一次性起全部节点，逐节点起客户端，curl 国外网站通过为成功。

## 登录信息

- 面板：`http://127.0.0.1:7001`（跨机用本机 LAN IP）
- 管理后台：`http://127.0.0.1:7001/ac821707`
- 账号：`admin@xboard.test` / `xboard123`（expired 2100 / 100GB）
- server_token（UniProxy 节点 API）：`xboard-node-test-token`
- WS：8076

## 节点联调

模板 `/Users/icodex/Git/xray/Xboard/.docker/xboard-node-local.yml`，改 `node_id` / `node_type` / `kernel.type` 三处，每轮一个节点：

```bash
cd /Users/icodex/Git/xray/Xboard-node
go run ./cmd/xboard-node -c /Users/icodex/Git/xray/Xboard/.docker/xboard-node-local.yml
curl http://127.0.0.1:65530/healthz
```

31 节点矩阵与内核预期：`.docker/NODE-TEST-HANDOFF.md`。

## 已知坑

- macOS docker 一律 `sudo -n`；compose exec 偶发竞态，脚本里用 `docker compose ps -q` 取 CID 后 `docker exec`。
- 改 `v2_settings` 表后须清 redis `admin_settings`（init.php 已含），且 secure_path 变更须 `docker restart`（路由启动时求值）。
- 镜像只 overlay 工作区代码目录；改 composer.json 依赖须重建镜像（vendor 在镜像内）。
- UniProxy 接口：`GET /api/v1/server/UniProxy/{config,user}?token=<server_token>&node_id=<id>&node_type=<type>`；订阅 `/api/v1/client/subscribe?token=<user->token>`。
