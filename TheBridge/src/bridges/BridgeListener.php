<?php
/**
 *     _______ _          ____       _     _
 *    |__   __| |        |  _ \     (_)   | |
 *  __  _| |  | |__   ___| |_) |_ __ _  __| | __ _  ___
 *  \ \/ / |  | '_ \ / _ \  _ <| '__| |/ _` |/ _` |/ _ \
 *   >  <| |  | | | |  __/ |_) | |  | | (_| | (_| |  __/
 *  /_/\_\_|  |_| |_|\___|____/|_|  |_|\__,_|\__, |\___|
 *                                            __/ |
 *                                           |___/
 *
 * Copyright (C) 2016-2026 NetherGames Network
 *
 * This is private software, you cannot redistribute and/or modify it in any way
 * unless given explicit permission to do so. If you have not been given explicit
 * permission to view or modify this software you should take the appropriate actions
 * to remove this software from your device immediately.
 *
 * @author Ragnok123
 *
 */
declare(strict_types=1);

namespace bridges;

use bridges\utils\InvMenuListenerUtils;
use libminigames\events\MinigameQuitEvent;
use libminigames\Minigame;
use libminigames\MinigameListener;
use libminigames\TeamArena;
use muqsit\invmenu\inventory\InvMenuInventory;
use NetherGames\NGEssentials\events\NGJoinEvent;
use NetherGames\NGEssentials\events\NGLoginEvent;
use NetherGames\NGEssentials\NGEssentials;
use NetherGames\NGEssentials\player\PlayerData;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\inventory\PlayerInventory;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;

class BridgeListener extends MinigameListener
{
    /**
     * @param NGJoinEvent $event
     *
     * @priority NORMAL
     */
    public function onNGJoin(NGJoinEvent $event): void
    {
        // COMPAT 2026-10-28: 单服开发模式下没有大厅服负责派发，而这里的逻辑整体被
        // !isInDevelopmentMode() 包着，导致玩家会一直停在 Hub 里进不了对局。
        if (NGEssentials::isInDevelopmentMode()) {
            $this->getPlugin()->joinArena($event->getPlayer(), $this->resolveModeId());
            return;
        }

        if (!NGEssentials::isInDevelopmentMode()) {
            $player = $event->getPlayer();
            $plugin = $this->getPlugin();
            $ess = $plugin->getEssentials();

            if ($ess->getPlayerData()->getBool($player, PlayerData::RECONNECT)) {
                $ess->getPlayerData()->setValue($player, PlayerData::RECONNECT, false);

                if (($arena = $plugin->getArenaByXuid($player->getXuid())) !== null && $arena->isRunning()) {
                    /** @var BridgeArena $arena */
                    if (($team = $arena->getTeamByXuid($player->getXuid())) !== null) {
                        /** @var BridgeTeam $team */
                        $team->reconnectPlayer($player);
                        return;
                    }
                }

                $player->sendMessage(TextFormat::RED . "Couldn't connect you to that match, so you were put in another Bedwars match!");

                if ($plugin->isStandAloneGame()) {
                    $plugin->joinArena($player);
                }
            }
        }
    }

    /**
     * COMPAT 2026-10-28: 一局打完会被送回 Hub，而 onNGJoin 只在连入时触发一次，
     * 不重新入队就只能重连。退出后延迟 3 秒自动重新排队（NG_AUTO_REQUEUE=0 可关）。
     */
    public function onMinigameQuit(MinigameQuitEvent $event): void
    {
        if (!NGEssentials::isInDevelopmentMode() || getenv('NG_AUTO_REQUEUE') === '0') {
            return;
        }

        $reason = $event->getReason();
        if ($reason === MinigameQuitEvent::DISCONNECT || $reason === MinigameQuitEvent::DISCONNECT_KICK) {
            return;
        }

        $player = $event->getPlayer();
        $modeId = $this->resolveModeId();

        $this->getPlugin()->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($player, $modeId): void {
            if ($player->isOnline() && $this->getPlugin()->getArena($player) === null) {
                $player->sendMessage('§a已离开对局，正在重新排队…');
                $this->getPlugin()->joinArena($player, $modeId);
            }
        }), 60);
    }

    /** 模式由环境变量 NG_TB_MODE 决定（默认 solo） */
    private function resolveModeId(): int
    {
        return match (strtolower(getenv('NG_TB_MODE') ?: 'solo')) {
            'doubles', 'double', '2' => TeamArena::MODE_DOUBLES,
            default => TeamArena::MODE_SOLO,
        };
    }

    /**
     * @return TheBridge
     */
    public function getPlugin(): Minigame
    {
        /** @var TheBridge $plugin */
        $plugin = parent::getPlugin();

        return $plugin;
    }

    /**
     * @param NGLoginEvent $event
     *
     * @priority NORMAL
     */
    public function onNGLogin(NGLoginEvent $event): void
    {
        if (!NGEssentials::isInDevelopmentMode()) {
            $player = $event->getPlayer();
            $ess = $this->getPlugin()->getEssentials();

            if (!$ess->getPlayerData()->getBool($player, PlayerData::RECONNECT)) {
                parent::onNGLogin($event);
            }
        }
    }

    public function onInventoryOpen(InventoryOpenEvent $event): void
    {
        if (($arena = $this->getPlugin()->getArena($event->getPlayer())) !== null) {
            $inventory = $event->getInventory();

            if ($arena->isRunning()) {
                $arena->getListener()->onInventoryOpen($event);
            } else if ($inventory instanceof InvMenuInventory) {
                $event->uncancel();
            } else {
                $event->cancel();
            }
        }
    }

    /**
     * @param InventoryTransactionEvent $event
     *
     * @priority NORMAL
     */
    public function onInventoryTransaction(InventoryTransactionEvent $event): void
    {
        if (($arena = $this->getPlugin()->getArena($player = $event->getTransaction()->getSource())) !== null) {
            $openWindow = $event->getTransaction()->getSource()->getCurrentWindow();

            $inventory = $event->getTransaction()->getInventories();
            $isInvMenu = array_filter($inventory, static function ($inventory) {
                return !($inventory instanceof PlayerInventory);
            });

            if ($arena->isSpectator($player)) {
                $event->cancel();
            } elseif ($arena->isRunning()) {
                $arena->getListener()->onInventoryTransaction($event);
            } else if (count($isInvMenu) === 2 || ((count($inventory) === 1) && !($inventory[array_key_first($inventory)] instanceof PlayerInventory))) {
                $event->uncancel();
            } else {
                if ($openWindow !== null) {
                    InvMenuListenerUtils::protectionChecks($player);
                }

                $event->cancel();
            }
        }
    }
}