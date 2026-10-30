#!/usr/bin/env bash
# 本地测试面板数据初始化（幂等，可重复跑）。
# 容器须已在跑：先 `docker compose up -d`，再执行本脚本。
set -euo pipefail
cd "$(dirname "$0")"

docker() { sudo -n docker "$@"; }

CID=$(docker compose ps -q xboard)
if [ -z "$CID" ]; then
  echo "container not running; run: docker compose up -d --build" >&2
  exit 1
fi

docker cp seed-nodes.php "$CID":/www/seed-nodes.php
docker cp init.php "$CID":/www/init.php
docker exec -i "$CID" php /www/artisan tinker --execute="require '/www/init.php';"

# secure_path 拼进路由表是启动时求值，必须 restart 重载路由
docker restart "$CID" >/dev/null
sleep 8
curl -s -o /dev/null -w 'panel %{http_code}\n' "http://127.0.0.1:7001/"
curl -s -o /dev/null -w 'admin %{http_code}\n' "http://127.0.0.1:7001/ac821707"

# 生成交接 prompt（新库 uuid 重生成，按当前库刷）
UUID=$(docker exec -i "$CID" php /www/artisan tinker --execute='echo App\Models\User::first()->uuid;')
sed -e "s/__UUID__/${UUID}/" NODE-TEST-PROMPT.tpl.md > NODE-TEST-PROMPT.md
echo "prompt written: deploy/dev/NODE-TEST-PROMPT.md"
echo "========== 交接 prompt（贴到 Xboard-node 仓的 Claude 会话）=========="
cat NODE-TEST-PROMPT.md
