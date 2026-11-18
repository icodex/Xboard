#!/usr/bin/env bash
# 只读进度：汇总 Xboard-node 仓 .test-results/ 的终态，判断是否停滞。
# 用法：./progress.sh [结果目录=../Xboard-node/.test-results]
set -euo pipefail
cd "$(dirname "$0")"

DIR=${1:-../Xboard-node/.test-results}
TOTAL=${TOTAL:-$(grep -c '^| [0-9]' NODE-TEST-PROMPT.md 2>/dev/null || echo 0)}

if [ ! -d "$DIR" ]; then
  echo "no results yet: $DIR" >&2
  echo "done=0/$TOTAL"
  exit 0
fi

PROGRESS="$DIR/progress.json"
if [ -f "$PROGRESS" ]; then
  jq -r '"progress.json: done=\(.done)/\(.total) pass=\(.passed) fail=\(.failed) blocked=\(.blocked) unverified=\(.unverified) batch=\(.current_batch) node=#\(.current_node) eta=\(.eta_minutes)m updated=\(.updated_at)"' "$PROGRESS" 2>/dev/null || echo "progress.json unreadable"
  NOTE=$(jq -r '.note // ""' "$PROGRESS" 2>/dev/null || true)
  [ -n "$NOTE" ] && echo "note: $NOTE"
fi

NODE_FILES=$(find "$DIR" -maxdepth 1 -name '[0-9]*.json' 2>/dev/null | wc -l | tr -d ' ')
echo "node files: $NODE_FILES/$TOTAL"
if [ "$NODE_FILES" -gt 0 ]; then
  jq -r '[.verdict // "unknown"] | @tsv' "$DIR"/[0-9]*.json 2>/dev/null \
    | sort | uniq -c | awk '{printf "  %s=%s\n", $2, $1}'
  jq -r '[.protocol // "?"] | @tsv' "$DIR"/[0-9]*.json 2>/dev/null \
    | sort | uniq -c | awk '{printf "  %s: %s\n", $2, $1}' | sed -n '1,12p'
fi

LAST=$(find "$DIR" -maxdepth 1 -name '*.json' -mmin -10 2>/dev/null | wc -l | tr -d ' ')
if [ "$LAST" -eq 0 ]; then echo "STALLED? no result/progress write in 10 minutes"; fi
