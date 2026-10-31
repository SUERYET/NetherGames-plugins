#!/usr/bin/env bash
# 停掉本地服务端容器
#
#   ./dev/down.sh          # 停容器（保留世界与数据库卷）
#   ./dev/down.sh --purge  # 连卷一起删（相当于本地环境重置）
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"

if [[ ${1:-} == "--purge" ]]; then
  docker compose -p ng-dev down -v
  rm -rf "${NG_WORLDS:-$HOME/ng/worlds-dev}"
  echo "✓ 容器、卷、本地世界都已删除"
else
  docker compose -p ng-dev down
  echo "✓ 容器已停止（世界和数据库卷保留，下次 up 会接着用）"
fi
