#!/usr/bin/env python3
"""协议升级兼容性扫描。

背景：Bedrock 协议升级（例如 2193 → 2225）时，PocketMine 的几个包工厂会新增参数，
调用点不补齐就会在运行时抛 ArgumentCountError，而且**崩一次只能发现一处**。
这个脚本在改完代码、开服之前，把所有调用点的参数个数静态数一遍。

当前检查两项：

1. AddActorPacket::create()   期望 14 个参数（protocol 2225 起多了 ?PassengerOfBlockData）
2. AddPlayerPacket::create()  期望 18 个参数（同上，deviceId 被后移）
3. EntityFactory::register()  回调必须是 function (World, CompoundTag): Entity

协议再升级、签名又变时，改下面的 EXPECTED_ARGS 即可。

用法：python3 dev/scan-api.py
退出码 0 = 通过
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

EXPECTED_ARGS = {
    "AddActorPacket::create": 14,
    "AddPlayerPacket::create": 18,
}

# 这些目录不参与扫描（第三方依赖 / 生成物）
SKIP_DIRS = {"vendor", "node_modules", ".git", "build"}


def strip_comments(text: str) -> str:
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    text = re.sub(r"//[^\n]*", "", text)
    return text


def find_calls(text: str, needle: str) -> list[tuple[int, int]]:
    """返回 [(左括号下标, 右括号下标)]。"""
    out = []
    i = 0
    while True:
        i = text.find(needle, i)
        if i == -1:
            return out
        open_paren = i + len(needle)
        depth = 0
        k = open_paren
        while k < len(text):
            ch = text[k]
            if ch == "(":
                depth += 1
            elif ch == ")":
                depth -= 1
                if depth == 0:
                    out.append((open_paren, k))
                    break
            k += 1
        i = k if k > i else i + 1


def split_args(arg_text: str) -> list[str]:
    arg_text = strip_comments(arg_text)
    parts, cur, depth, in_str, quote = [], [], 0, False, ""
    for ch in arg_text:
        if in_str:
            cur.append(ch)
            if ch == quote:
                in_str = False
            continue
        if ch in "'\"":
            in_str, quote = True, ch
            cur.append(ch)
            continue
        if ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
        if ch == "," and depth == 0:
            parts.append("".join(cur))
            cur = []
            continue
        cur.append(ch)
    if "".join(cur).strip():
        parts.append("".join(cur))
    return [p for p in parts if p.strip()]


def php_files(root: Path):
    for p in root.rglob("*.php"):
        if any(part in SKIP_DIRS for part in p.parts):
            continue
        yield p


def main() -> int:
    root = Path(__file__).resolve().parent.parent
    problems: list[str] = []
    total = 0

    for path in php_files(root):
        try:
            text = path.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        if "Packet::create(" not in text and "EntityFactory" not in text:
            continue

        for needle, expected in EXPECTED_ARGS.items():
            for open_paren, close_paren in find_calls(text, needle):
                total += 1
                got = len(split_args(text[open_paren + 1:close_paren]))
                if got != expected:
                    problems.append(
                        f"{path.relative_to(root)}: {needle.split('::')[0]} 参数 {got} 个，应为 {expected}"
                    )

        # EntityFactory::register 的回调签名（PMMP 5.51 起会严格校验）
        for m in re.finditer(r"->register\(\s*([\w\\:]+)::class", text):
            prefix = text[max(0, m.start() - 160): m.start()]
            if "entityfactory" not in prefix.lower():
                continue  # 命令注册、菜单注册之类的不归这里管
            segment = re.sub(r"\s+", " ", text[m.start(): m.start() + 400])
            if "World $world" not in segment or "CompoundTag $nbt" not in segment:
                problems.append(
                    f"{path.relative_to(root)}: EntityFactory::register 回调签名不规范"
                    "（应为 function/fn (World $world, CompoundTag $nbt): Entity）"
                )

    print(f"▶ API 扫描：{total} 个 packet 调用点")
    if problems:
        print(f"✗ 发现 {len(problems)} 处问题：")
        for p in problems:
            print("   -", p)
        return 1

    print("✓ packet 参数个数与实体注册回调签名都符合预期")
    return 0


if __name__ == "__main__":
    sys.exit(main())
