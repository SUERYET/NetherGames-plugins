#!/usr/bin/env bash
# 把本地改动推到 N100（中转仓库），并提示下一步
#
#   ./dev/publish.sh                  # 提交 → 推 n100 → 打印 N100 上要做什么
#   ./dev/publish.sh "提交信息"        # 顺便 git commit
#   ./dev/publish.sh --push-github    # 额外推到 GitHub fork（需要 N100 上的 deploy key，见下）
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"

BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [[ $BRANCH != "ng-homelab" ]]; then
  echo "⚠ 当前分支是 $BRANCH，不是 ng-homelab（确认要继续？Ctrl-C 取消）"
  sleep 2
fi

msg=""
push_github=0
for arg in "$@"; do
  case "$arg" in
    --push-github) push_github=1 ;;
    *) msg="$arg" ;;
  esac
done

if [[ -n $(git status --porcelain) ]]; then
  if [[ -z $msg ]]; then
    echo "✗ 有未提交的改动，请给个提交信息：./dev/publish.sh \"说明\"" >&2
    git status --short
    exit 1
  fi
  git add -A
  git -c user.name="${GIT_AUTHOR_NAME:-homelab}" -c user.email="${GIT_AUTHOR_EMAIL:-homelab@local}" \
      commit -q -m "$msg"
  echo "✓ 已提交：$msg"
else
  echo "（工作区干净，直接推送现有提交）"
fi

echo "▶ 推送到 N100 中转仓库"
git push n100 "$BRANCH"

echo
echo "✓ 推送完成。接下来在 N100 上执行："
echo "    cd ~/minecraft-develop/NetherGames-data/plugins"
echo "    git pull \$HOME/repos/ng-plugins.git $BRANCH"
echo "    cd ~/minecraft-develop && ./play.sh <游戏> [模式]"
echo
echo "  （./play.sh 会检测到源码比镜像新，自动重建后再起服）"

if [[ $push_github -eq 1 ]]; then
  echo
  echo "▶ 另外推到 GitHub（只能在 N100 上做，deploy key 在那边）"
  echo "    ssh -p 54321 sueryeto@100.110.120.22 \\"
  echo "      \"cd ~/minecraft-develop/NetherGames-data/plugins && git push fork $BRANCH\""
fi
