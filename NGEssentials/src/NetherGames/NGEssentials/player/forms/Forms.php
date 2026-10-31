<?php
/**
 *   _   _  _____ ______                    _   _       _
 *  | \ | |/ ____|  ____|                  | | (_)     | |
 *  |  \| | |  __| |__   ___ ___  ___ _ __ | |_ _  __ _| |___
 *  | . ` | | |_ |  __| / __/ __|/ _ \ '_ \| __| |/ _` | / __|
 *  | |\  | |__| | |____\__ \__ \  __/ | | | |_| | (_| | \__ \
 *  |_| \_|\_____|______|___/___/\___|_| |_|\__|_|\__,_|_|___/
 *
 * Copyright (C) 2016-2026 NetherGames Network
 *
 * This is private software, you cannot redistribute and/or modify it in any way
 * unless given explicit permission to do so. If you have not been given explicit
 * permission to view or modify this software you should take the appropriate actions
 * to remove this software from your device immediately.
 *
 * @author k3ithos, matcracker, driesboy
 *
 */
declare(strict_types=1);

namespace NetherGames\NGEssentials\player\forms;

use Closure;
use libforms\elements\Button;
use libforms\elements\ImageButton;
use libforms\elements\Toggle;
use libforms\FormManager;
use libforms\SimpleForm;
use NetherGames\NGEssentials\NGEssentials;
use NetherGames\NGEssentials\player\chat\emojis\Emojis;
use NetherGames\NGEssentials\player\enforcement\ReplayManager;
use NetherGames\NGEssentials\player\NGPlayer;
use NetherGames\NGEssentials\player\permissions\Permissions;
use NetherGames\NGEssentials\player\permissions\RankManager;
use NetherGames\NGEssentials\player\PlayerData;
use NetherGames\NGEssentials\player\PlayerStats;
use NetherGames\NGEssentials\utils\MySQLCredentials;
use NetherGames\NGEssentials\player\Translator;
use NetherGames\NGEssentials\ServerManager;
use NetherGames\NGEssentials\servers\Server;
use NetherGames\NGEssentials\servers\ServersCluster;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function array_diff;
use function count;

abstract class Forms
{
    public static function sendMinigameSelector(Player $player, NGEssentials $ess): void
    {
        $form = FormManager::createSimpleForm($player);

        if ($form !== null) {
            $form->setTitle(Translator::getTranslationPlayer($player, "forms.teleporter"));
            $form->setType(SimpleForm::FORM_COLUMN_2);

            foreach (ServerManager::BUTTONS as $server => $data) {
                if (isset($data['serverType']) && !isset($data['dummy'])) {
                    $serverType = $data['serverType'];
                    $gameType = '';

                    if (isset($data['gameTypes'])) {
                        $gameTypes = $data['gameTypes'];
                        if (count($gameTypes) > 1) {
                            $status = [
                                Server::OFFLINE => 0,
                                Server::ONLINE => 0,
                                Server::FULL => 0
                            ];

                            $onlinePlayers = 0;

                            foreach ($gameTypes as $gameType) {
                                $cluster = $ess->getServerManager()->getCluster($serverType, $gameType);

                                $onlinePlayers += $cluster->getOnlinePlayers();
                                $status[$cluster->getStatus()]++;
                            }

                            if ($status[Server::OFFLINE] === count($data['gameTypes'])) {
                                $status = Translator::getTranslationPlayer($player, "forms.teleporter.offline", Translator::TYPE_ERROR);
                            } elseif ($status[Server::OFFLINE] > 0) {
                                $status = Translator::getTranslationPlayer($player, "forms.teleporter.partly_online", Translator::TYPE_WARNING);
                            } else {
                                $status = TextFormat::DARK_GREEN . $onlinePlayers . ' playing';
                            }

                            $form->addButton(new ImageButton($server . TextFormat::EOL . $status, ImageButton::IMAGE_TYPE_PATH, $data['icon'], static function (Player $player) use ($ess, $serverType) {
                                self::sendGameTypeSelector($player, $serverType, $ess, function (Player $player) use ($ess) {
                                    self::sendMinigameSelector($player, $ess);
                                });
                            }));
                            continue;
                        }

                        $gameType = $gameTypes[0];
                    }

                    $cluster = $ess->getServerManager()->getCluster($serverType, $gameType);

                    $form->addButton(new ImageButton($server . TextFormat::EOL . $cluster->getStringStatus($player), ImageButton::IMAGE_TYPE_PATH, $data['icon'], static function (Player $player) use ($ess, $cluster) {
                        if ($cluster instanceof ServersCluster) {
                            self::sendServerSelector($player, $cluster, $ess, static function (Player $player) use ($ess) {
                                self::sendMinigameSelector($player, $ess);
                            });
                        } else {
                            $ess->getPlayerManager()->transferPlayer($player, $cluster);
                        }
                    }));
                } elseif (isset($data['teleport'])) {
                    if ($server === 'Arcade') {
                        $status = [
                            Server::OFFLINE => 0,
                            Server::ONLINE => 0,
                            Server::FULL => 0
                        ];

                        $onlinePlayers = 0;

                        foreach ([ServerManager::SC/*, ServerManager::SP*/, ServerManager::MS] as $serverType) {
                            $server2 = ServerManager::BUTTONS[ServerManager::getName($serverType)];

                            if (isset($server2['gameTypes'])) {
                                $gameTypes = $server2['gameTypes'];

                                foreach ($gameTypes as $gameType) {
                                    $cluster = $ess->getServerManager()->getCluster($serverType, $gameType);

                                    $onlinePlayers += $cluster->getOnlinePlayers();

                                    $status[$cluster->getStatus()]++;
                                }
                            } else {
                                $cluster = $ess->getServerManager()->getCluster($serverType);

                                $onlinePlayers += $cluster->getOnlinePlayers();

                                $status[$cluster->getStatus()]++;
                            }
                        }

                        if ($status[Server::OFFLINE] > 0) {
                            $status = Translator::getTranslationPlayer($player, "forms.teleporter.partly_online", Translator::TYPE_WARNING);
                        } else {
                            $status = TextFormat::DARK_GREEN . $onlinePlayers . ' playing';
                        }

                        $form->addButton(new ImageButton($server . TextFormat::EOL . $status, ImageButton::IMAGE_TYPE_PATH, $data['icon'], static function (Player $player) use ($ess, $data) {
                            $player->teleport($ess->getServerManager()->getSpawn($data['teleport']));
                        }));
                    } else {
                        $form->addButton(new ImageButton($server, ImageButton::IMAGE_TYPE_PATH, $data['icon'], static function (Player $player) use ($ess, $data) {
                            $player->teleport($ess->getServerManager()->getSpawn($data['teleport']));
                        }));
                    }
                }
            }

            $form->sendForm();
        }
    }

    public static function sendGameTypeSelector(Player $player, string $serverType, NGEssentials $ess, ?callable $onBack = null): void
    {
        $form = FormManager::createSimpleForm($player);

        if ($form !== null) {
            $server = ServerManager::BUTTONS[ServerManager::getName($serverType)];

            if (($gameTypesCount = count($server['gameTypes'])) < 5) {
                $form->setType(SimpleForm::getDynamicType($gameTypesCount));
            }
            $form->setTitle(Translator::getTranslationPlayer($player, "forms.teleporter"));
            $form->setBackClosure($onBack);

            foreach ($server['gameTypes'] as $gameType) {
                $cluster = $ess->getServerManager()->getCluster($serverType, $gameType);

                $form->addButton(new ImageButton(TextFormat::GOLD . $gameType . TextFormat::EOL . $cluster->getStringStatus($player), ImageButton::IMAGE_TYPE_PATH, $server['icon'], static function (Player $player) use ($ess, $cluster, $serverType, $gameType) {
                    if ($cluster instanceof ServersCluster && count($cluster->getServers()) > 1) {
                        self::sendGameRegionSelector($player, $cluster, $ess);
                    } else {
                        $ess->getPlayerManager()->transferPlayer($player, $serverType, $gameType);
                    }
                }));
            }

            $form->sendForm();
        }
    }

    public static function sendGameRegionSelector(Player $player, ServersCluster $cluster, NGEssentials $ess): void
    {
        $form = FormManager::createSimpleForm($player);

        if ($form !== null) {
            $serverData = ServerManager::BUTTONS[ServerManager::getName($cluster->getServerType())];

            $form->setTitle(Translator::getTranslationPlayer($player, "forms.teleporter"));
            $form->setType(SimpleForm::getDynamicType(count($servers = $cluster->getServers())));
            $form->setContent('Select a region:');

            foreach ($servers as $server) {
                $form->addButton(new ImageButton(TextFormat::GOLD . ServerManager::REGION_TO_NAME[$server->getRegion()] . TextFormat::EOL . $cluster->getStringStatus($player, $server), ImageButton::IMAGE_TYPE_PATH, $serverData['icon'], static function (Player $player) use ($ess, $server) {
                    $ess->getPlayerManager()->transferPlayer($player, $server);
                }));
            }

            $form->sendForm();
        }
    }

    public static function sendServerSelector(Player $player, ServersCluster $cluster, NGEssentials $ess, ?callable $onBack): void
    {
        $form = FormManager::createSimpleForm($player);

        if ($form !== null) {
            $serverManager = $ess->getServerManager();

            $form->setTitle('Server Selector');
            $form->setBackClosure($onBack);

            foreach (array_values($cluster->getServers()) as $serverId => $server) {
                $isCurrent = $server->getUniqueId() === $serverManager->getUniqueId();

                $addon = $isCurrent ? TextFormat::EOL . TextFormat::YELLOW . 'You are on this server' : '';
                $teleport = $isCurrent ? $cluster : $server;

                $form->addButton(new Button($server->getCluster()->getName() . ' ' . ($serverId + 1) . TextFormat::GRAY . ' - ' . $cluster->getStringStatus($player, $server) . $addon, static function (Player $player) use ($teleport, $ess, $onBack) {
                    if ($teleport instanceof ServersCluster) {
                        self::sendServerSelector($player, $teleport, $ess, $onBack);
                    } else {
                        $ess->getPlayerManager()->transferPlayer($player, $teleport, '');
                    }
                }));
            }

            $form->sendForm();
        }
    }

    public static function sendTrackMenu(Player $player): void
    {
        $form = FormManager::createSimpleForm($player);

        if ($form !== null) {
            $playerManager = NGEssentials::getInstance()->getPlayerManager();
            $ingamePlayers = $playerManager->getPlayerNames(array_diff($player->getWorld()->getPlayers(), [$player]));

            $form->setTitle('Track Menu');
            $form->setContent('Select a player:');

            foreach ($ingamePlayers as $ingamePlayer) {
                $form->addButton(new ImageButton('Teleport to ' . $ingamePlayer, ImageButton::IMAGE_TYPE_FACE, $ingamePlayer, static function (Player $player) use ($ingamePlayer) {
                    if (($ingamePlayer = $player->getServer()->getPlayerExact($ingamePlayer)) !== null) {
                        $playerData = NGEssentials::getInstance()->getPlayerData();

                        if ($playerData->getBool($player, PlayerData::TRACK)) {
                            $player->teleport($ingamePlayer->getLocation());
                            $playerData->setValue($player, PlayerData::TRACK, $ingamePlayer->getName());
                        } else {
                            $player->sendMessage('§cYou are not in tracking mode.');
                        }
                    } else {
                        $player->sendMessage(TextFormat::RED . 'That player is not in this server anymore.');
                    }
                }));
            }

            $form->sendForm();
        }
    }

    public static function sendSettings(Player $player, NGEssentials $ess): void
    {
        /** @var NGPlayer $player */
        $form = FormManager::createSimpleForm($player);

        if ($form !== null) {
            $goBack = static function (Player $player) use ($ess) {
                self::sendSettings($player, $ess);
            };

            $form->setTitle('Settings');

            $form->addButton(new Button('Preferences', static function (Player $player) use ($ess, $goBack) {
                $form = FormManager::createCustomForm($player, $goBack);

                if ($form !== null) {
                    $form->setTitle('Preferences');

                    $playerData = $ess->getPlayerData();
                    $form->addElement(new Toggle(Translator::getTranslationPlayer($player, "forms.settings.hideplayers"), $playerData->getBool($player, PlayerData::HIDE_PLAYERS), function (Player $player, bool $value) use ($ess, $playerData) {
                        $playerData->setValue($player, PlayerData::HIDE_PLAYERS, $value);
                        $ess->getPlayerManager()->updatePlayerVisibility($player);
                        Translator::sendMessage($player, "formhandler.settings.hideplayers", Translator::TYPE_SUCCESS, ...["enabledOrDisabled" => Translator::getTranslationPlayer($player, $value ? "formhandler.settings.hiding" : "formhandler.settings.showing")]);
                    }));

                    if ($player->hasPermission(Permissions::RANK_ULTRA)) {
                        $form->addElement(new Toggle(Translator::getTranslationPlayer($player, "forms.settings.fly"), $player->getAllowFlight(), static function (Player $player, bool $value) use ($ess) {
                            $lobbyPlugin = $ess->getServerManager()->getLobbyPlugin();
                            if ($lobbyPlugin !== null && $lobbyPlugin->getFeaturesManager()->getParkour()->isPlaying($player)) {
                                $player->sendMessage(TextFormat::RED . "You can't fly while playing parkour.");
                                return;
                            }

                            if ($ess->getPlayerData()->getString($player, PlayerData::SELECTED_RANK) === RankManager::NO_RANK || $ess->getPlayerData()->getBool($player, PlayerData::NICK)) {
                                Translator::sendMessage($player, "formhandler.settings.fly.hidingrank", Translator::TYPE_ERROR);
                            } else {
                                $player->setFlying($value);
                                $player->setAllowFlight($value);

                                Translator::sendMessage($player, "formhandler.settings.fly", Translator::TYPE_SUCCESS, ...["enabledOrDisabled" => Translator::getTranslationPlayer($player, $value ? "formhandler.settings.enabled" : "formhandler.settings.disabled")]);
                            }
                        }));
                    }
                    if ($player->hasPermission(Permissions::RANK_ULTRA)) {
                        $form->addElement(new Toggle(Translator::getTranslationPlayer($player, 'forms.settings.announcements'), $playerData->getBool($player, PlayerData::ANNOUNCEMENTS), static function (Player $player, bool $value) use ($playerData) {
                            $playerData->setValue($player, PlayerData::ANNOUNCEMENTS, $value);
                            Translator::sendMessage(
                                $player,
                                "formhandler.settings.announcements",
                                Translator::TYPE_SUCCESS,
                                ...["enabledOrDisabled" => Translator::getTranslationPlayer($player, $value ? "formhandler.settings.enabled" : "formhandler.settings.disabled")]
                            );
                        }));
                    }
                    if ($player->hasPermission(Permissions::RANK_ADVISOR)) {
                        $form->addElement(new Toggle(Translator::getTranslationPlayer($player, "forms.settings.knockback"), $playerData->getBool($player, PlayerData::KNOCKBACK), static function (Player $player, bool $value) use ($playerData) {
                            $playerData->setValue($player, PlayerData::KNOCKBACK, $value);
                            Translator::sendMessage($player, "formhandler.settings.knockback", Translator::TYPE_SUCCESS, ...["enabledOrDisabled" => Translator::getTranslationPlayer($player, $value ? "formhandler.settings.enabled" : "formhandler.settings.disabled")]);
                        }));
                    }
                    $form->addElement(new Toggle('FPS mode', $playerData->getBool($player, PlayerData::FPS_MODE), static function (Player $player, bool $value) use ($ess) {
                        $ess->getPlayerData()->setValue($player, PlayerData::FPS_MODE, $value);

                        if ($value) {
                            $player->sendMessage('§aEnabled FPS mode. You will no longer see fancy graphics and animations.');
                        } else {
                            $player->sendMessage('§cDisabled FPS mode. You will now see fancy graphics and animations.');
                        }

                        /*if (($presents = $ess->getPlayerManager()->getWorldFeatures()->getPresents()) !== null) {
                            $presents->sendWeather($player, $value);
                            }*/
                    }));
                    $form->addElement(new Toggle('Show Bossbar', $playerData->getBool($player, PlayerData::BOSS_BAR), static function (Player $player, bool $value) use ($ess) {
                        $ess->getPlayerData()->setValue($player, PlayerData::BOSS_BAR, $value);

                        if ($value) {
                            $player->sendMessage('§aEnabled Bossbar. The bossbar will now be shown everywhere.');
                        } else {
                            $player->sendMessage('§cDisabled Bossbar. The bossbar will be hidden everywhere except the lobby.');
                        }
                    }));

                    $form->sendForm();
                }
            }));
            $form->addButton(new Button('Minigame Settings', static function (Player $player) use ($ess, $goBack) {
                $ess->getPlayerData()->getGameSettings()->sendForm($player, $goBack);
            }));
            $form->addButton(new Button('Replays', static function (Player $player) use ($goBack) {
                ReplayManager::sendReplayMenu($player, $goBack);
            }));
            $form->addButton(new ImageButton('Language', ImageButton::IMAGE_TYPE_URL, Translator::getFlag($player->getNGLanguage()), static function (NGPlayer $player) use ($ess, $goBack) {
                Translator::sendLanguageSelector($player, $ess, $goBack);
            }));
            $form->addButton(new Button('Punishments', static function (Player $player) use ($ess, $goBack) {
                $ess->getPlayerManager()->getEnforcementHandler()->sendPunishmentsViewer($player, null, $goBack);
            }));

            $form->sendForm();
        }
    }

    /**
     * COMPAT 2026-10-28: 上游把统计表单摘成了空桩（只回一句 unavailable），
     * 但数据其实一直在记录（player_stats 表 159 列）。这里补上基于数据库的查看界面。
     */
    public static function sendStats(Player $player, ?string $playerName = null, ?callable $callback = null): void
    {
        // 注意：上游第 3 个参数是"返回上一级"的闭包，签名 function (Player $player): void
        // （社交菜单、公会成员菜单都会传）。必须原样转交给表单，不能用无参调用，否则 ArgumentCountError 崩服。
        $targetName = $playerName ?? $player->getName();
        $target = NGEssentials::getInstance()->getServer()->getPlayerExact($targetName);

        if ($target instanceof NGPlayer && $target->getXuid() !== '') {
            self::sendStatsForm($player, $targetName, $target->getXuid(), $callback);
            return;
        }

        // 离线玩家也能查：按名字反查 xuid
        NGPlayer::getXuidByName($targetName, static function (?string $xuid, ?string $name) use ($player, $targetName, $callback): void {
            if ($xuid === null || $xuid === '') {
                $player->sendMessage(TextFormat::RED . "找不到玩家 " . $targetName . " 的统计数据。");
                if ($callback !== null) {
                    $callback($player);
                }
                return;
            }

            self::sendStatsForm($player, $name ?? $targetName, $xuid, $callback);
        });
    }

    private static function sendStatsForm(Player $player, string $name, string $xuid, ?callable $callback): void
    {
        MySQLCredentials::executeSelectRaw(
            "SELECT * FROM player_stats WHERE xuid = ? AND type = ?",
            [$xuid, PlayerStats::TYPE_GLOBAL],
            static function (array $rows) use ($player, $name, $callback): void {
                if (count($rows) === 0) {
                    $player->sendMessage(TextFormat::RED . $name . " 还没有统计数据（先玩一局吧）。");
                    if ($callback !== null) {
                        $callback($player);
                    }
                    return;
                }

                $row = $rows[0];
                $get = static fn(string $key): int => (int)($row[$key] ?? 0);

                $kills = $get('kills');
                $deaths = $get('deaths');
                $wins = $get('wins');
                $losses = $get('losses');
                $kdr = $deaths > 0 ? round($kills / $deaths, 2) : (float)$kills;
                $wdr = $losses > 0 ? round($wins / $losses, 2) : (float)$wins;
                $minutes = $get('online_time');

                $lines = [
                    TextFormat::GOLD . TextFormat::BOLD . $name . TextFormat::RESET . TextFormat::GRAY . ' 的总战绩',
                    '',
                    TextFormat::YELLOW . '综合',
                    TextFormat::GRAY . '  击杀 ' . TextFormat::WHITE . $kills . TextFormat::GRAY . '   死亡 ' . TextFormat::WHITE . $deaths . TextFormat::GRAY . '   K/D ' . TextFormat::WHITE . $kdr,
                    TextFormat::GRAY . '  胜场 ' . TextFormat::WHITE . $wins . TextFormat::GRAY . '   负场 ' . TextFormat::WHITE . $losses . TextFormat::GRAY . '   W/L ' . TextFormat::WHITE . $wdr,
                    '',
                ];

                // COMPAT 2026-10-28: 之前只写了起床战争，换成别的游戏后表单里就没有对应数据了。
                // 现在按"有数据才显示"的方式渲染所有小游戏，免得再加游戏又要改这里。
                // 每项：[标题, 判定有没有数据的列, 明细(标签 => 列名), 分模式(标签 => 列名)]
                $games = [
                    ['起床战争', ['bw_wins', 'bw_kills'], [
                        '胜场' => 'bw_wins', '击杀' => 'bw_kills', '最终击杀' => 'bw_final_kills',
                        '拆床' => 'bw_beds_broken', '死亡' => 'bw_deaths',
                        '钻石' => 'bw_diamonds_collected', '绿宝石' => 'bw_emeralds_collected',
                        '铁' => 'bw_iron_collected', '金' => 'bw_gold_collected',
                    ], [
                        'solo' => 'bw_solo_wins', 'doubles' => 'bw_doubles_wins', 'trios' => 'bw_trios_wins',
                        'squads' => 'bw_squads_wins', '1v1' => 'bw_1v1_wins', '2v2' => 'bw_2v2_wins',
                    ]],
                    ['空岛战争', ['sw_wins', 'sw_kills'], [
                        '胜场' => 'sw_wins', '击杀' => 'sw_kills', '死亡' => 'sw_deaths',
                        '负场' => 'sw_losses', '破坏方块' => 'sw_blocks_broken', '放置方块' => 'sw_blocks_placed',
                        '丢鸡蛋' => 'sw_eggs_thrown', '末影珍珠' => 'sw_epearls_thrown',
                    ]],
                    ['决斗', ['duels_wins', 'duels_kills'], [
                        '胜场' => 'duels_wins', '负场' => 'duels_losses', '击杀' => 'duels_kills', '死亡' => 'duels_deaths',
                    ]],
                    ['战桥', ['tb_wins', 'tb_kills'], [
                        '胜场' => 'tb_wins', '负场' => 'tb_losses', '进球' => 'tb_goals',
                        '击杀' => 'tb_kills', '死亡' => 'tb_deaths',
                    ]],
                    ['密室杀人', ['mm_wins', 'mm_kills'], [
                        '胜场' => 'mm_wins', '击杀' => 'mm_kills', '弓击杀' => 'mm_bow_kills',
                        '刀击杀' => 'mm_knife_kills', '飞刀击杀' => 'mm_throw_knife_kills', '死亡' => 'mm_deaths',
                    ]],
                    ['征服', ['cq_wins', 'cq_kills'], [
                        '胜场' => 'cq_wins', '击杀' => 'cq_kills', '夺旗' => 'cq_flags_captured', '死亡' => 'cq_deaths',
                    ]],
                    ['生存游戏', ['sg_wins', 'sg_kills'], [
                        '胜场' => 'sg_wins', '击杀' => 'sg_kills', '死亡' => 'sg_deaths',
                    ]],
                    ['融化', ['md_wins', 'md_kills'], [
                        '胜场' => 'md_wins', '击杀' => 'md_kills', '死亡' => 'md_deaths', '道具' => 'md_powerups_collected',
                    ]],
                    ['妈妈说', ['ms_successes', 'ms_fails'], [
                        '成功' => 'ms_successes', '失败' => 'ms_fails',
                    ]],
                    ['足球', ['sc_wins', 'sc_goals'], [
                        '胜场' => 'sc_wins', '进球' => 'sc_goals',
                    ]],
                    ['UHC', ['uhc_wins', 'uhc_kills'], [
                        '胜场' => 'uhc_wins', '击杀' => 'uhc_kills',
                        '铁' => 'uhc_iron_mined', '金' => 'uhc_gold_mined', '钻石' => 'uhc_diamond_mined',
                    ]],
                ];

                foreach ($games as $game) {
                    [$title, $gate, $items] = $game;
                    $subs = $game[3] ?? [];

                    $hasData = false;
                    foreach ($gate as $column) {
                        if ($get($column) > 0) {
                            $hasData = true;
                            break;
                        }
                    }
                    if (!$hasData) {
                        continue;
                    }

                    $lines[] = TextFormat::YELLOW . $title;

                    $chunk = [];
                    foreach ($items as $label => $column) {
                        $chunk[] = TextFormat::GRAY . $label . ' ' . TextFormat::WHITE . $get($column);
                        if (count($chunk) === 3) {
                            $lines[] = '  ' . implode('   ', $chunk);
                            $chunk = [];
                        }
                    }
                    if (count($chunk) > 0) {
                        $lines[] = '  ' . implode('   ', $chunk);
                    }

                    if (count($subs) > 0) {
                        $chunk = [];
                        foreach ($subs as $label => $column) {
                            $chunk[] = TextFormat::GRAY . $label . ' ' . TextFormat::WHITE . $get($column);
                            if (count($chunk) === 4) {
                                $lines[] = '  ' . implode('   ', $chunk);
                                $chunk = [];
                            }
                        }
                        if (count($chunk) > 0) {
                            $lines[] = '  ' . implode('   ', $chunk);
                        }
                    }

                    $lines[] = '';
                }

                $lines[] = TextFormat::GRAY . '在线时长 ' . TextFormat::WHITE . intdiv($minutes, 60) . ' 小时 ' . ($minutes % 60) . ' 分';

                $form = FormManager::createSimpleForm($player);
                if ($form === null) {
                    return;
                }

                $form->setTitle(TextFormat::GOLD . '玩家统计');
                $form->setContent(implode("\n", $lines));
                $form->addButton(new Button(TextFormat::GREEN . '关闭'));

                if ($callback !== null) {
                    $form->setCloseClosure($callback instanceof Closure ? $callback : Closure::fromCallable($callback));
                }

                $form->sendForm();
            },
            static function () use ($player): void {
                $player->sendMessage(TextFormat::RED . "统计数据读取失败（数据库错误）。");
            }
        );
    }

    public static function sendEmojiHelpForm(Player $player): void
    {
        $form = FormManager::createSimpleForm($player);

        if ($form !== null) {
            $form->setTitle("Emoji Dictionary");

            $form->setContent("Backslash ('\') can be used as escape character to prevent text from being replaced.\nExample: '\:skull:'\n\nEmojis and Usages:");

            foreach (Emojis::getInstance()->getEmojisForHelpMenu() as [$emoji, $usage]) {
                $form->addButton(new Button("{$emoji} - {$usage}", function (NGPlayer $player) use ($emoji) {
                    $player->chat($emoji);
                }));
            }

            $form->sendForm();
        }
    }
}