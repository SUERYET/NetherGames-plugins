#!/usr/bin/env bash
# 本地静态检查：php -l + API 兼容性扫描
#
#   ./dev/check.sh            只检查相对 HEAD 改动的 PHP 文件（快，日常用）
#   ./dev/check.sh --all      全量检查（约 30 秒）
#   ./dev/check.sh foo.php    指定文件
#
# 退出码 0 = 通过；非 0 = 有失败项
set -uo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT" || exit 1

PHP=${PHP:-php}
if ! command -v "$PHP" >/dev/null 2>&1; then
  echo "✗ 找不到 php，请先安装 php8.4-cli（或在 WSL 里执行）" >&2
  exit 1
fi

mode=${1:-changed}
files=()

collect_all() {
  while IFS= read -r f; do files+=("$f"); done < <(git ls-files '*.php' | grep -v '/vendor/')
}

collect_changed() {
  while IFS= read -r f; do [[ -f $f ]] && files+=("$f"); done < <(
    { git diff --name-only HEAD -- '*.php'; git diff --cached --name-only -- '*.php'; git ls-files --others --exclude-standard -- '*.php'; } | sort -u | grep -v '/vendor/'
  )
}

case "$mode" in
  --all) collect_all ;;
  changed|"") collect_changed ;;
  *) files=("$@") ;;
esac

if [[ ${#files[@]} -eq 0 ]]; then
  echo "没有需要检查的 PHP 文件（用 --all 检查全部）"
else
  echo "▶ php -l：${#files[@]} 个文件"
  fail=0
  for f in "${files[@]}"; do
    out=$("$PHP" -l "$f" 2>&1)
    if [[ $? -ne 0 ]]; then
      echo "$out"
      fail=1
    fi
  done
  if [[ $fail -eq 0 ]]; then
    echo "✓ 语法全部通过"
  else
    echo "✗ 有语法错误，已中止" >&2
    exit 1
  fi
fi

# API 兼容性扫描（协议升级后最容易踩的坑）
if command -v python3 >/dev/null 2>&1; then
  echo
  python3 "$ROOT/dev/scan-api.py" || exit 1
else
  echo "（跳过 API 扫描：没有 python3）"
fi
