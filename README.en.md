# NetherGames Single-Server Fork (ng-homelab)

**English** · [中文](README.md)

> A fork of [`NetherGamesMC/plugins`](https://github.com/NetherGamesMC/plugins).
> Upstream is built for a full network (lobby servers + proxy + many game servers + Kafka);
> the `ng-homelab` branch collects everything needed to run the same games on
> **a single machine, without a lobby server and without a WaterdogPE proxy**.
>
> The original upstream README is preserved as [`README.upstream.md`](README.upstream.md).

| | Upstream `master` | This branch `ng-homelab` |
|---|---|---|
| Target environment | Official network (lobby + proxy + multi-server) | Single host (Docker Compose) |
| Bedrock protocol | Follows upstream | **26.60 / protocol 2225** |
| Player flow | Lobby dispatches to a game server | **Self-queueing** (dev mode) |
| Leaderboards | Official API | **Local database + baseline** |
| Baseline commit | `1e3ce22` | `1e3ce22` + one patch commit |

---

## 1. What changed

### 1.1 Protocol bump: Bedrock 26.60 (protocol 2225)

| Packet | Upstream change | Fix |
|---|---|---|
| `AddActorPacket::create()` | New trailing `?PassengerOfBlockData` (13 → 14 args) | `null` appended at all 16 call sites |
| `AddPlayerPacket::create()` | New `?PassengerOfBlockData`, `deviceId` shifted | same |
| `LevelChunkPacket::create()` | New `bool $clientBiomeUpdate` | `false` |
| `EntityFactory::register()` | Callback signature is validated strictly | All callbacks use `function (World, CompoundTag): Entity` |

> Lesson learned: fixing only the crashing call site always misses others.
> The final pass **statically scanned every call site** (paren-balancing + argument counting).

### 1.2 Entity registration (20+ custom entities)

New PocketMine requires **every entity class that can enter a world** to be registered in
`EntityFactory`, otherwise `World::addEntity()` throws `LogicException` and the server crashes.
Symptoms: throwing a fishing rod, placing TNT, or spawning a mob from a Skywars drop crashed the server.

- Central entry point: `libminigames/Minigame::registerVanillaEntities()` (covers all 10 minigames)
- 20 `libVanilla` entities (fishing hook, crossbow arrow, fireball, trident, lightning, passive/hostile mobs)
- Per-game entities registered individually: Bedwars (TNT, bedbug, dream defender…), Skywars (`skywars\entities\*`),
  MurderMystery (`MMPlayer`, `MMKnife`), SurvivalGames (`Graveyard`)

### 1.3 Dev-mode auto-queueing (6 minigames)

Upstream relies on the lobby to dispatch players. On a single server that never happens, and each
minigame's `onNGJoin` turned out to be implemented differently — or not at all:

| Game | Upstream | This branch |
|---|---|---|
| Bedwars | missing | auto-queue + auto-requeue |
| Skywars | missing | same |
| TheBridge | present but wrapped in `!isInDevelopmentMode()`, reconnect-only | same |
| Duels | **no listener at all** | same |
| MurderMystery | only registers the base listener (which lacks `onNGJoin`) | same |
| SurvivalGames | listener exists, no `onNGJoin` | same |

Players are re-queued 3 seconds after leaving a match (`NG_AUTO_REQUEUE=0` disables it).

### 1.4 Spawn points and cross-world coordinates

Upstream hardcodes lobby coordinates per game — they are only valid for the official maps.
With the community `assets` maps (same names, different terrain) this produced
**"falling into the void forever"** on join.

Rule applied: arena waiting lobby uses that game's own coordinates; returning to the lobby uses
**the default world's own spawn**; games with no dedicated spawn (SG, CQ, …) use the arena world's spawn.

### 1.5 Local leaderboards with an "since opening" baseline

Upstream pulls the official network-wide leaderboard from `api.ngmc.co`. This branch queries the local DB:

```sql
SELECT d.player, GREATEST(0, s.bw_solo_wins - COALESCE(b.bw_solo_wins, 0)) AS value
FROM player_stats s JOIN player_data d ON d.xuid = s.xuid
LEFT JOIN player_stats_baseline b ON b.xuid = s.xuid
WHERE s.type = 0 AND value > 0 ORDER BY value DESC LIMIT 10
```

- `player_stats_baseline` holds the snapshot taken when official stats were imported,
  so the difference is *"wins earned on this server since it opened"*
- Recomputed every 5 minutes via `reloadAll()`
- Columns missing from the schema (e.g. Skywars `sw_1v1_wins`, which upstream never created)
  are skipped instead of spamming SQL errors

### 1.6 Command additions

| Command | Purpose |
|---|---|
| `/stats [player]` | Stats form: overall + 11 per-game sections (only when data exists) + playtime; works for offline players by resolving name → XUID |
| `/settings` (aliases `/prefs`, `/options`) | There is no lobby item to open "Profile Settings" on a single server, so this command opens the same menu (chest UI, shop slider, popups, scoreboard type…) |

### 1.7 Losses actually counted

Upstream only increments `wins` for the winning team, so `losses` stayed `0` forever.
This branch adds `LOSSES + 1` for every player on a losing team (even if their bed survived).

### 1.8 Social: online status and a broken SQL statement

- `SocialManager::requestPlayerInfos()` is a stub upstream (returns `null` for everyone), so the
  **guild member list was always fully "Offline"**. Now it resolves players online on this server
  (by name or XUID), so guild/friend lists show correct status.
- `player_relations.set` used stored-procedure syntax (`IF EXISTS … END IF`), which is a syntax error
  when sent as a plain statement (adding a friend just failed). Rewritten as
  `INSERT … ON DUPLICATE KEY UPDATE` with `LEAST/GREATEST` normalisation.

### 1.9 Misc

- `PlayerListCompat` for the newer player-list packet
- `/stats` shows `deaths` instead of "best streak" (nothing in upstream ever wrote that column)
- The companion resource pack (vanilla icons) lives with the deployment, not in this repo

---

## 2. Environment variables

| Variable | Effect | Default |
|---|---|---|
| `NG_BW_MODE` | Bedwars mode: `solo` / `doubles` / `trios` / `squads` / `1v1` / `2v2` | `solo` |
| `NG_SW_MODE` | Skywars mode: `solo` / `doubles` / `1v1` / `2v2` | `solo` |
| `NG_DUELS_MODE` | Duels mode: `solo` / `doubles` | `solo` |
| `NG_MM_MODE` | MurderMystery mode: `classic` / `infection` | `classic` |
| `NG_TB_MODE` | TheBridge mode: `solo` / `doubles` | `solo` |
| `NG_MIN_PLAYERS` | Override minimum players to start | empty (plugin defaults) |
| `NG_AUTO_REQUEUE` | `0` disables auto-requeue after a match | enabled |
| `NG_IMAGE` | Use an existing image tag, skip build | none |
| `NG_NO_BUILD` | `1` = use the image as-is | none |

---

## 3. Known limitations

| Limitation | Detail |
|---|---|
| No lobby server / proxy | Cross-server features unavailable (cross-server presence, party transfer, server selector) |
| Some maps log decode errors | `Deserialize error: Property "minecraft:corner" is missing` — maps were saved by an older Bedrock version; a few stair shapes may render oddly, gameplay unaffected |
| UHC has no maps | Upstream assets ship zero UHC arenas |
| Baseline is manual | To restart the leaderboard from a given day, rewrite `player_stats_baseline` with a snapshot |
| Single protocol target | Built for 26.60 (2225); other client versions depend on PMMP's accepted-protocol list |

---

## 4. Build & run (short version)

This repo contains plugin sources only. **Deployment scripts (`up.sh`, `play.sh`, `prebuild.sh`) and
`docker-compose.override.yml` live on the deployment host, not here.**

```bash
# build the image for one gamemode (GAME decides which minigame gets compiled in)
GAME=Skywars docker compose up --build -d

# requires a GitHub token (composer private deps) and the assets repo mounted at /assets
```

The server listens on `19132/udp` (Bedrock native protocol).

---

## 5. Branch policy

| Branch | Purpose |
|---|---|
| `master` | Mirrors upstream, **no local changes** — used for diffing / pulling updates |
| `ng-homelab` | Every patch described above; this is what the deployment runs |

Rebasing onto a newer upstream:

```bash
git fetch origin
git checkout master && git merge --ff-only origin/master
git checkout ng-homelab && git rebase master
```

---

## 6. Credits & license

- Upstream: <https://github.com/NetherGamesMC/plugins> (AGPL-3.0, see [`LICENSE`](LICENSE))
- This branch only adds patches required for a single-host deployment;
  original code is copyright NetherGames Network
- Maps & assets: <https://github.com/NetherGamesMC/assets>
