#!/usr/bin/env bash
# 测试期间只读快照：节点上报、真实流量增量、面板错误数。不写库、不重启。
# 用法：./watch.sh [间隔秒=15] [--once]
set -euo pipefail
cd "$(dirname "$0")"

INTERVAL=${1:-15}
ONCE=${2:-}

docker() { sudo -n docker "$@"; }

CID=$(docker compose ps -q xboard)
if [ -z "$CID" ]; then
  echo "container not running" >&2
  exit 1
fi

snapshot() {
  docker exec -i "$CID" php /www/artisan tinker --execute='
$now = time();
$online = 0;
$push60 = 0;
$enabled = 0;
foreach (App\Models\Server::where("enabled", 1)->get() as $s) {
    $enabled++;
    if ($s->is_online) {
        $online++;
    }
    if (($s->last_push_at ?? 0) > $now - 60) {
        $push60++;
    }
}
$prevFile = "/tmp/watch-prev.json";
$prev = is_file($prevFile) ? json_decode(file_get_contents($prevFile), true) : [];
$cur = [];
// 统计按天重置：跨日时 record_at 变化，旧值不能作基线
$day = DB::table("v2_stat_server")->max("record_at");
if (($prev["_day"] ?? null) !== $day) {
    $prev = [];
}
$cur["_day"] = $day;
foreach (DB::table("v2_stat_server")->get(["server_id", "u", "d"]) as $r) {
    $cur["s" . $r->server_id] = [(int) $r->u, (int) $r->d];
}
$user = DB::table("v2_stat_user")->orderByDesc("id")->first();
if ($user) {
    $cur["user" . $user->user_id] = [(int) $user->u, (int) $user->d];
}
file_put_contents($prevFile, json_encode($cur));
$deltas = [];
foreach ($cur as $k => [$u, $d]) {
    if ($k === "_day") {
        continue;
    }
    if (isset($prev[$k])) {
        $du = $u - $prev[$k][0];
        $dd = $d - $prev[$k][1];
        if ($du > 0 || $dd > 0) {
            $deltas[$k] = [$du, $dd];
        }
        if ($du < 0 || $dd < 0) {
            $deltas[$k] = [max(0, $du), max(0, $dd)];
            unset($deltas[$k]);
        }
    }
}
$ports = [];
foreach (App\Models\Server::where("enabled", 1)->get() as $s) {
    if ($s->is_online) {
        $ports[] = (int) $s->server_port;
    }
}
sort($ports);
$ranges = [];
$start = null;
$prev = null;
foreach ($ports as $p) {
    if ($start === null) { $start = $prev = $p; continue; }
    if ($p === $prev + 1) { $prev = $p; continue; }
    $ranges[] = $start === $prev ? (string) $start : "{$start}-{$prev}";
    $start = $prev = $p;
}
if ($start !== null) {
    $ranges[] = $start === $prev ? (string) $start : "{$start}-{$prev}";
}
echo date("H:i:s") . " online={$online}/{$enabled} push60={$push60} moving=" . count($deltas) . (is_file($prevFile) && $prev !== [] ? "" : " (baseline)") . PHP_EOL;
echo "  online_ports(5m窗口，含刚停)= " . implode(",", array_slice($ranges, 0, 8)) . (count($ranges) > 8 ? " (+" . (count($ranges) - 8) . ")" : "") . PHP_EOL;
uasort($deltas, fn ($a, $b) => ($b[0] + $b[1]) <=> ($a[0] + $a[1]));
$shown = 0;
foreach ($deltas as $k => [$du, $dd]) {
    $label = str_starts_with($k, "s") ? "server#" . substr($k, 1) : "user#" . substr($k, 4);
    echo "  {$label} +up={$du} +down={$dd}" . PHP_EOL;
    if (++$shown >= 10) {
        break;
    }
}
' 2>&1 | tail -12
  docker logs --since 2m "$CID" 2>&1 | grep -cE 'ERROR|exception|malformed' || true
}

while true; do
  echo "--- $(date '+%F %T') errors(2m) below ---"
  snapshot
  [ "$ONCE" = "--once" ] && break
  sleep "$INTERVAL"
done