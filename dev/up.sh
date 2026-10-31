#!/usr/bin/env bash
# 本地起一个单游戏服务端（WSL docker），数据与正式环境完全隔离
#
#   ./dev/up.sh                 # 默认 Skywars solo
#   ./dev/up.sh Bedwars solo
#   ./dev/up.sh Duels
#
# 起完用 ./dev/ip 查客户端要连的地址。
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"

GAME=${1:-Skywars}
MODE=${2:-solo}

# PMMP 钉在支持 Bedrock 26.60 / protocol 2225 的 commit 上
PMMP_REF=${PMPP_REF:-dc707708d968e36e403f3bca075346f4107b3cbf}

ASSETS=${NG_ASSETS:-$HOME/ng/assets}
WORLDS=${NG_WORLDS:-$HOME/ng/worlds-dev}
TOKEN_FILE=${NG_TOKEN_FILE:-$HOME/.ng-github-token}

[[ -d $ASSETS ]] || { echo "✗ assets 不存在：$ASSETS（见 dev/README.md）" >&2; exit 1; }
[[ -f $TOKEN_FILE ]] || { echo "✗ 缺 GitHub token：$TOKEN_FILE" >&2; exit 1; }
command -v docker >/dev/null || { echo "✗ 没有 docker（要装 docker.io 并在 docker 组里）" >&2; exit 1; }

mkdir -p "$WORLDS"
export GITHUB_TOKEN
GITHUB_TOKEN=$(tr -d '\r\n' < "$TOKEN_FILE")

# 各游戏的模式变量（按需设置，互不干扰）
export NG_BW_MODE=$MODE NG_SW_MODE=$MODE NG_DUELS_MODE=$MODE NG_MM_MODE=$MODE NG_TB_MODE=$MODE

export GAME
export ASSETS_PATH=$ASSETS
export WORLDS_PATH=$WORLDS
export PMMP_REF
# 本地一个人就能开局，方便自己测
export NG_MIN_PLAYERS=${NG_MIN_PLAYERS:-1}
# 别让本地误用正式镜像
unset NG_IMAGE NG_NO_BUILD
# 本地 phpMyAdmin 换端口，避免和正式环境的 8088 记混
export PHPMYADMIN_PORT=${PHPMYADMIN_PORT:-127.0.0.1:18088}

echo "▶ GAME=$GAME MODE=$MODE"
echo "  世界目录 $WORLDS"
echo "  地图来源 $ASSETS"
echo "  （首次构建约 2-4 分钟，之后 20 秒左右）"

docker compose -p ng-dev up --build -d

echo
echo "✓ 起好了。客户端连接地址："
echo "    $(ip -4 -o addr show eth0 2>/dev/null | awk '{print $4}' | cut -d/ -f1):19132"
echo "  看日志：docker compose -p ng-dev logs -f server"
echo "  停服：  ./dev/down.sh"
