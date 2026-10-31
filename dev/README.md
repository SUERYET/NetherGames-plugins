# dev/ — 本地开发与测试

这个目录是**本地开发用**的辅助脚本，不参与服务端构建（镜像构建只编译 `*/src`）。

```
编辑（Windows VS Code）
   ↓
dev/check.sh            语法 + API 兼容性扫描        ~5 秒
   ↓
dev/up.sh Skywars solo  起本地服务端（WSL docker）   首次 2-4 分钟
   ↓  客户端连 dev/ip 打印的地址
dev/down.sh             停服并清理
   ↓ 玩一局确认
dev/publish.sh          推到 N100，再由 N100 重建上正式服
```

## 前置条件（WSL Debian）

```bash
php -v          # 8.4.x，与 N100 同版本
docker version  # 26.x，且当前用户在 docker 组
composer --version
```

以及：

- `~/.ng-github-token`：GitHub token（composer 拉依赖用）
- `~/ng/assets`：从 assets 仓库复制过来的地图（见下面"准备 assets"）

### 准备 assets（只需一次）

```bash
mkdir -p ~/ng
cp -a /mnt/e/Develop/minecraft-develop/NetherGames-data/assets ~/ng/assets
rm -rf ~/ng/assets/.git      # 只留数据，不要 git 历史
```

放在 WSL 内部（ext4）而不是 `/mnt/e`，因为容器启动时要整份复制，9p 文件系统会慢好几倍。

## 常用命令

```bash
./dev/check.sh              # 只检查相对 HEAD 改动的 PHP 文件（快）
./dev/check.sh --all        # 全量检查（约 30 秒）

./dev/up.sh Skywars solo    # 起本地服（项目名 ng-dev，独立于正式环境）
./dev/ip                    # 打印客户端该连的地址（WSL 的 IP）
./dev/down.sh               # 停服并删除容器/卷

./dev/publish.sh            # git push n100 + 提示 N100 上执行什么
```

## 为什么客户端要连 WSL 的 IP

Bedrock 走 **UDP 19132**，而 WSL2 的 NAT 只把 **TCP** 的 localhost 映射到 Windows，
**UDP 不会转发**。所以不能用 `127.0.0.1:19132`，要用 `./dev/ip` 打印出来的地址
（形如 `172.27.x.x:19132`）。WSL 重启后 IP 会变，重新跑 `./dev/ip` 即可。

## 本地环境与正式环境的隔离

| | 本地（ng-dev） | 正式（N100） |
|---|---|---|
| compose 项目名 | `ng-dev` | `nethergames` |
| 世界目录 | `~/ng/worlds-dev` | `~/minecraft-develop/worlds*` |
| 数据库 | 独立的 `ng-dev` 卷 | `nethergames` 卷 |
| phpMyAdmin | `127.0.0.1:18088` | `127.0.0.1:8088` |
| 端口 | 19132（WSL 内） | 19132（公网） |

两者数据完全不共享，本地随便造。
