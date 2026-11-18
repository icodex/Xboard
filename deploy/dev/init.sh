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
docker exec -e PRUNE_STALE=1 -i "$CID" php /www/artisan tinker --execute="require '/www/init.php';"

# secure_path 拼进路由表是启动时求值，必须 restart 重载路由
docker restart "$CID" >/dev/null
sleep 8
curl -s -o /dev/null -w 'panel %{http_code}\n' "http://127.0.0.1:7001/"
curl -s -o /dev/null -w 'admin %{http_code}\n' "http://127.0.0.1:7001/ac821707"

# sqlite 完整性：曾出现一次索引条目损坏（原因未复现/未归因），每轮先验再测
if ! integrity=$(docker exec -i "$CID" php /www/artisan tinker --execute='echo DB::select("PRAGMA integrity_check")[0]->integrity_check;' 2>&1); then
  echo "sqlite integrity check failed to run: $integrity" >&2
  exit 1
fi
if [ "$integrity" != "ok" ]; then
  echo "sqlite integrity_check: $integrity（修复：REINDEX 后重启）" >&2
  exit 1
fi
echo "sqlite integrity_check: ok"

# 下发准确性门禁：逐节点 API×DB 语义校验 + 高级参数临时探针，失败即中止
for script in verify-api.php verify-params.php; do
  docker cp "$script" "$CID":/www/"$script"
  # verify-api 默认 config：full 的 user 端点会写 LAST_CHECK_AT，紧接着跑测试会伪造在线证据。
  # 需要验 user 时显式 VERIFY_MODE=full ./init.sh（不要在测试前跑）。
  if ! out=$(docker exec -e VERIFY_MODE="${VERIFY_MODE:-config}" -i "$CID" php /www/artisan tinker --execute="require '/www/$script';" 2>&1); then
    echo "=== $script FAILED ===" >&2
    echo "$out" >&2
    exit 1
  fi
  echo "$script: $(echo "$out" | grep -E '^(CHECKED|PROBES|RESULT)=' | tr '\n' ' ')"
done

# 生成交接 prompt（新库 uuid 重生成，按当前库刷；值全部取自容器内 DB/设置，不硬编码）
UUID=$(docker exec -i "$CID" php /www/artisan tinker --execute='echo App\Models\User::where("email", "admin@xboard.test")->value("uuid");')
TOKEN=$(docker exec -i "$CID" php /www/artisan tinker --execute='echo is_array($t = admin_setting("server_token")) ? $t[0] : $t;')
ADMIN_PATH=$(docker exec -i "$CID" php /www/artisan tinker --execute='echo admin_setting("secure_path");')
ADMIN_EMAIL=$(docker exec -i "$CID" php /www/artisan tinker --execute='echo App\Models\User::where("email", "admin@xboard.test")->value("email");')
# init.php 每次跑都强制重置该账号密码，此处常量永远与库一致
PASSWD='xboard123'
ADMIN_URL="http://127.0.0.1:7001/${ADMIN_PATH}"
SUB_URL="http://127.0.0.1:7001/api/v1/client/subscribe?token=$(docker exec -i "$CID" php /www/artisan tinker --execute='echo App\Models\User::where("email", "admin@xboard.test")->value("token");')"
MATRIX=$(mktemp)
trap 'rm -f "$MATRIX"' EXIT
docker exec -i "$CID" php /www/artisan tinker --execute='$group = App\Models\ServerGroup::where("name", "全协议测试")->value("id"); foreach (App\Models\Server::whereJsonContains("group_ids", (string) $group)->orderBy("server_port")->get() as $s) { $p = $s->protocol_settings ?? []; $params = []; if (!empty($p["cipher"])) { $params[] = "cipher=" . $p["cipher"]; } if (!empty($p["version"])) { $params[] = "v" . $p["version"]; } if (!empty($p["obfs"])) { $o = $p["obfs"]; $params[] = "obfs=" . (is_array($o) ? (($o["open"] ?? false) ? ($o["type"] ?? "on") : "-") : $o); } if (!empty($p["flow"])) { $params[] = "flow=" . $p["flow"]; } printf("| %s | %s | %s | %s | %s | %s | %s | %s | %s |\n", $s->id, $s->name, $s->type, $s->server_port, $p["network"] ?? $p["transport"] ?? "-", is_array($p["tls"] ?? null) ? "TLS" : ($p["tls"] ?? "-"), $params ? implode(" ", $params) : "-", $s->kernel_type ?: "auto", $s->enabled ? "正向候选" : "禁用/负向"); }' > "$MATRIX"

sed -e "s|__UUID__|${UUID}|" \
    -e "s|__TOKEN__|${TOKEN}|" \
    -e "s|__ADMINPATH__|${ADMIN_URL}|" \
    -e "s|__ADMINURL__|${ADMIN_URL}|" \
    -e "s|__ADMIN__|${ADMIN_EMAIL}|" \
    -e "s|__PASSWD__|${PASSWD}|" \
    -e "s|__SUBURL__|${SUB_URL}|" \
    NODE-TEST-PROMPT.tpl.md | awk -v matrix="$MATRIX" '$0 == "__MATRIX__" { while ((getline line < matrix) > 0) print line; close(matrix); next } { print }' > NODE-TEST-PROMPT.md
# 占位符必须全部替换干净，残留即取值失败
if grep -qE '__[A-Z]+__' NODE-TEST-PROMPT.md; then
  echo "unresolved placeholder in NODE-TEST-PROMPT.md:" >&2
  grep -nE '__[A-Z]+__' NODE-TEST-PROMPT.md >&2
  exit 1
fi
echo "prompt written: deploy/dev/NODE-TEST-PROMPT.md"
echo "========== 交接 prompt（贴到 Xboard-node 仓的 Claude 会话）=========="
cat NODE-TEST-PROMPT.md
