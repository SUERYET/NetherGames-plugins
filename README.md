# NetherGames 单服改造版（ng-homelab）

[English](README.en.md) · **中文**

> 这是 [`NetherGamesMC/plugins`](https://github.com/NetherGamesMC/plugins) 的一个 fork。
> 官方版本是为一整套网络（大厅服 + 代理 + 多台游戏服 + Kafka 集群）设计的；
> 本分支 `ng-homelab` 记录的是把它改造成**一台机器、没有大厅服、没有 WaterdogPE 代理也能正常玩**
> 所需要的全部补丁。
>
> 上游原版 README 保留在 [`README.upstream.md`](README.upstream.md)。

| | 上游 `master` | 本分支 `ng-homelab` |
|---|---|---|
| 目标环境 | 官方网络（大厅 + 代理 + 多服） | 单机 / 单服（Docker Compose） |
| Bedrock 协议 | 跟随上游 | **26.60 / protocol 2225** |
| 玩家进服 | 由大厅服派发到游戏服 | **自己入队**（开发模式） |
| 排行榜 | 官方 API | **本地数据库 + 开服基线** |
| 基线 commit | `1e3ce22` | `1e3ce22` + 1 次补丁提交 |

---

## 一、这个分支改了什么

### 1. 协议跟进：Bedrock 26.60（protocol 2225）

客户端版本一升级，服务端就报 `Incompatible protocol version`。本分支把 PMMP 钉到支持 2225 的 commit，
并适配了随之变化的包签名：

| 包 | 变化 | 处理 |
|---|---|---|
| `AddActorPacket::create()` | 末尾新增 `?PassengerOfBlockData`（13→14 参数） | 全部 16 个调用点补 `null` |
| `AddPlayerPacket::create()` | 新增 `?PassengerOfBlockData`，`deviceId` 后移 | 同上 |
| `LevelChunkPacket::create()` | 新增 `bool $clientBiomeUpdate` | 补 `false` |
| `EntityFactory::register()` | 回调签名被强校验 | 统一成 `function (World, CompoundTag): Entity` |

> 教训：上游改签名时"崩到哪修到哪"一定会漏。本项目最后是**静态扫描全部调用点**（括号配平数参数）才扫干净。

### 2. 实体注册：20+ 个自定义实体

新版 PMMP 要求"所有会进入世界的实体类"都必须注册到 `EntityFactory`，否则 `World::addEntity()`
直接抛 `LogicException` → **崩服**。表现为：抛一次鱼竿、放一个 TNT、刷一只掉落物里的鸡就崩。

- 统一入口：`libminigames/Minigame::registerVanillaEntities()`（覆盖全部 10 个游戏）
- 注册范围：`libVanilla` 的 20 个实体（鱼竿 / 弩 / 火球 / 三叉戟 / 闪电 / 各种被动敌对生物）
- 各游戏自己的实体单独注册：Bedwars（TNT、蠹虫、梦境守卫…）、Skywars（`skywars\entities\*`）、
  MurderMystery（`MMPlayer`、`MMKnife`）、SurvivalGames（`Graveyard`）

### 3. 开发模式自动入队（6 个游戏）

官方靠大厅服把玩家派发到游戏服。单服没有大厅，而各游戏的 `onNGJoin` 实现质量参差不齐：

| 游戏 | 上游状态 | 本分支 |
|---|---|---|
| Bedwars | 无 | ✅ 自动入队 + 退出重排 |
| Skywars | 无 | ✅ 同上 |
| TheBridge | 有，但被 `!isInDevelopmentMode()` 挡住，且只处理重连 | ✅ 同上 |
| Duels | **连方法都没有** | ✅ 同上 |
| MurderMystery | 只注册了基类（基类没有 `onNGJoin`） | ✅ 同上 |
| SurvivalGames | 有监听器但没有 `onNGJoin` | ✅ 同上 |

退出对局后 3 秒自动重新排队，可用 `NG_AUTO_REQUEUE=0` 关闭。

### 4. 出生点与"跨世界坐标"问题

官方对每个游戏写死了大厅坐标，那些坐标只对**官方那张地图**成立。我们用 assets 里的替代图
（名字一样、地形不同），于是出现"一进服就无限掉虚空"。

修复原则：**竞技场等待大厅**用各游戏自己的坐标；**回大厅**用默认世界自己的出生点；
**没有专用坐标的游戏**（SG / CQ 等）直接用竞技场世界的出生点。

### 5. 排行榜本地化 + 开服基线

上游排行榜是从 `api.ngmc.co` 拉**官方全服榜**（显示的是官方大佬）。本分支改成查本地库：

```sql
SELECT d.player, GREATEST(0, s.bw_solo_wins - COALESCE(b.bw_solo_wins, 0)) AS value
FROM player_stats s JOIN player_data d ON d.xuid = s.xuid
LEFT JOIN player_stats_baseline b ON b.xuid = s.xuid
WHERE s.type = 0 AND value > 0 ORDER BY value DESC LIMIT 10
```

- `player_stats_baseline` = 导入官方存档时的快照，相减得到"**本服开服以来新增**"
- 每 5 分钟 `reloadAll()` 重算一次
- 表里没有的列（例如 Skywars 的 `sw_1v1_wins`，上游 schema 根本没建）自动跳过，不再刷 SQL 报错

### 6. 命令补全：`/stats` 与 `/settings`

| 命令 | 说明 |
|---|---|
| `/stats [玩家]` | 统计表单：综合 + 11 个游戏分区（有数据才显示）+ 在线时长；离线玩家按名字反查 XUID |
| `/settings`（别名 `/prefs` `/options`） | 单服拿不到大厅的 "Profile Settings" 物品，用这个命令打开同一套设置（Chest UI、Shop Slider、弹窗提示、计分栏类型…） |

### 7. 负场统计修复

上游 `MatchTimeTask::finishArena()` 只给赢家加 `wins`，输家什么也不加，导致 `losses` 永远是 0。
本分支给每个没赢的队伍加 `LOSSES + 1`（床没被拆也算）。

### 8. 社交：在线状态与 SQL 修复

- `SocialManager::requestPlayerInfos()` 上游是空桩（全部返回 null），导致**公会成员列表永远全 Offline**。
  本分支改成查本服在线列表（按名字或 XUID），公会/好友都能正确显示 Online。
- `player_relations.set` 用的是存储过程语法 `IF EXISTS … END IF`，当普通语句执行必报
  `ERROR 1064`（加好友直接失败）。改成 `INSERT … ON DUPLICATE KEY UPDATE` + `LEAST/GREATEST` 归一化。

### 9. 其它

- `PlayerListCompat`：兼容新版玩家列表包
- `/stats` 里"最佳连胜"换成"死亡"（上游 schema 没有连胜列的生产者）
- 材质包配套（图标换成原版贴图）在部署侧维护，不在本仓库

---

## 二、环境变量

| 变量 | 作用 | 默认 |
|---|---|---|
| `NG_BW_MODE` | Bedwars 模式：`solo` / `doubles` / `trios` / `squads` / `1v1` / `2v2` | `solo` |
| `NG_SW_MODE` | Skywars 模式：`solo` / `doubles` / `1v1` / `2v2` | `solo` |
| `NG_DUELS_MODE` | Duels 模式：`solo` / `doubles` | `solo` |
| `NG_MM_MODE` | MurderMystery 模式：`classic` / `infection` | `classic` |
| `NG_TB_MODE` | TheBridge 模式：`solo` / `doubles` | `solo` |
| `NG_MIN_PLAYERS` | 强制最少开局人数（覆盖插件默认：`solo=2`、`doubles=4`…） | 空（用插件默认） |
| `NG_AUTO_REQUEUE` | 设为 `0` 可关闭"打完自动重排" | 开 |
| `NG_IMAGE` | 指定已有镜像标签，跳过构建 | 无 |
| `NG_NO_BUILD` | 设为 `1` 直接用现成镜像 | 无 |

---

## 三、已知限制

| 限制 | 说明 |
|---|---|
| 无大厅服 / 无代理 | 跨服功能（跨服好友状态、party 跨服转移、服务器选择器）不可用 |
| 部分老地图有解码告警 | `Deserialize error: Property "minecraft:corner" is missing`——地图用旧版 Bedrock 存盘，个别楼梯外观可能不对，不影响玩法 |
| UHC 没有地图 | 上游 assets 里 UHC 的 arena 数为 0，无法开局 |
| 排行榜基线需手工维护 | 想"从某天重新开始算"，需要把当时的 `player_stats` 快照写进 `player_stats_baseline` |
| 协议为单一版本 | 服务端当前面向 26.60（2225），其它版本能否连取决于 PMMP 的多协议表 |

---

## 四、构建与运行（简版）

本仓库只包含插件源码；**部署脚本（`up.sh` / `play.sh` / `prebuild.sh`）和 `docker-compose.override.yml`
在部署机上，不在本仓库**。

```bash
# 1) 构建某个游戏的镜像（GAME 决定编译进哪个游戏）
GAME=Skywars docker compose up --build -d

# 2) 需要 GitHub token（composer 拉私有依赖）与 assets（地图）挂载到 /assets
```

服务器启动后监听 `19132/udp`（Bedrock 原生协议）。

---

## 五、分支约定

| 分支 | 用途 |
|---|---|
| `master` | 与上游同步，**不含**任何本地改动，用于对比 / 拉取官方更新 |
| `ng-homelab` | 本文件描述的全部补丁；部署机使用的就是这条分支 |

同步上游的方式：

```bash
git fetch origin
git checkout master && git merge --ff-only origin/master
git checkout ng-homelab && git rebase master   # 补丁整层挪到新基线
```

---

## 六、来源与许可

- 上游：<https://github.com/NetherGamesMC/plugins>（AGPL-3.0，见 [`LICENSE`](LICENSE)）
- 本分支仅包含本地部署所需补丁；原始代码版权归 NetherGames Network 所有
- 地图与资源：<https://github.com/NetherGamesMC/assets>
