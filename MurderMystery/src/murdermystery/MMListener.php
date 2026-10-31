<?php
/**
 * Copyright (C) 2016-2026 NetherGames Network
 *
 * COMPAT 2026-10-28: 单服开发模式下没有大厅服派发。MurderMystery 原来只注册了
 * libminigames 的通用 MinigameListener，而那个基类没有 onNGJoin，
 * 玩家会一直停在 Hub 里进不了对局。这里补上"连入即入队"。
 */
declare(strict_types=1);

namespace murdermystery;

use libminigames\events\MinigameQuitEvent;
use libminigames\Minigame;
use libminigames\MinigameListener;
use murdermystery\gamemodes\MMArena;
use NetherGames\NGEssentials\events\NGJoinEvent;
use NetherGames\NGEssentials\NGEssentials;
use pocketmine\scheduler\ClosureTask;

class MMListener extends MinigameListener
{
    public function onNGJoin(NGJoinEvent $event): void
    {
        if (NGEssentials::isInDevelopmentMode()) {
            $this->getPlugin()->joinArena($event->getPlayer(), $this->resolveModeId());
        }
    }

    /**
     * 打完一局会被送回 Hub，不重新入队就只能重连（NG_AUTO_REQUEUE=0 可关）。
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

    /** 模式由环境变量 NG_MM_MODE 决定（默认 classic） */
    private function resolveModeId(): int
    {
        return match (strtolower(getenv('NG_MM_MODE') ?: 'classic')) {
            'infection', 'if' => MMArena::MODE_INFECTION,
            default => MMArena::MODE_CLASSIC,
        };
    }

    /**
     * @return MurderMystery
     */
    public function getPlugin(): Minigame
    {
        /** @var MurderMystery $plugin */
        $plugin = parent::getPlugin();

        return $plugin;
    }
}
