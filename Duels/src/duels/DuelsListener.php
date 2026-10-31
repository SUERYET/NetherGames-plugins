<?php
/**
 *        _____             _
 *       |  __ \           | |
 *  __  _| |  | |_   _  ___| |___
 *  \ \/ / |  | | | | |/ _ \ / __|
 *   >  <| |__| | |_| |  __/ \__ \
 *  /_/\_\_____/ \__,_|\___|_|___/
 *
 * Copyright (C) 2016-2026 NetherGames Network
 *
 * This is private software, you cannot redistribute and/or modify it in any way
 * unless given explicit permission to do so. If you have not been given explicit
 * permission to view or modify this software you should take the appropriate actions
 * to remove this software from your device immediately.
 *
 * @author matcracker
 *
 */
declare(strict_types=1);

namespace duels;

use libminigames\events\MinigameQuitEvent;
use libminigames\Minigame;
use libminigames\MinigameListener;
use libminigames\TeamArena;
use NetherGames\NGEssentials\events\NGJoinEvent;
use NetherGames\NGEssentials\NGEssentials;
use pocketmine\event\block\BlockBurnEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\scheduler\ClosureTask;

class DuelsListener extends MinigameListener
{
    /**
     * @param BlockBurnEvent $event
     *
     * @priority NORMAL
     */
    public function onBlockBurn(BlockBurnEvent $event): void
    {
        if ($this->getPlugin()->getArenaByWorld($event->getBlock()->getPosition()->getWorld()) !== null) {
            $event->cancel();
        }
    }

    /**
     * COMPAT 2026-10-28: Duels 完全没有 onNGJoin，单服开发模式下没有大厅服派发，
     * 玩家会一直停在 Hub 里进不了对局。这里连入即入队。
     */
    public function onNGJoin(NGJoinEvent $event): void
    {
        if (NGEssentials::isInDevelopmentMode()) {
            $this->getPlugin()->joinArena($event->getPlayer(), $this->resolveModeId());
        }
    }

    /**
     * COMPAT 2026-10-28: 打完一局会被送回 Hub，不重新入队就只能重连。
     * 退出后延迟 3 秒自动重新排队（NG_AUTO_REQUEUE=0 可关）。
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

    /** 模式由环境变量 NG_DUELS_MODE 决定（默认 solo） */
    private function resolveModeId(): int
    {
        return match (strtolower(getenv('NG_DUELS_MODE') ?: 'solo')) {
            'doubles', 'double', '2' => TeamArena::MODE_DOUBLES,
            default => TeamArena::MODE_SOLO,
        };
    }

    /**
     * @return Duels
     */
    public function getPlugin(): Minigame
    {
        /** @var Duels $plugin */
        $plugin = parent::getPlugin();

        return $plugin;
    }

    /**
     * @param EntityExplodeEvent $event
     *
     * @priority NORMAL
     */
    public function onExplode(EntityExplodeEvent $event): void
    {
        if ($this->getPlugin()->getArenaByWorld($event->getPosition()->getWorld()) !== null) {
            $event->setBlockList([]);
        }
    }
}