<?php
/**
 *           ____    _             __        __
 *  __  __ / ___|  | | __  _   _  \ \      / /   __ _   _ __   ___
 *  \ \/ / \___ \  | |/ / | | | |  \ \ /\ / /   / _` | | '__| / __|
 *   >  <   ___) | |   <  | |_| |   \ V  V /   | (_| | | |    \__ \
 *  /_/\_\ |____/  |_|\_\  \__, |    \_/\_/     \__,_| |_|    |___/
 *                         |___/
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

namespace skywars;

use libminigames\events\MinigameQuitEvent;
use libminigames\Minigame;
use libminigames\MinigameListener;
use libminigames\TeamArena;
use NetherGames\NGEssentials\events\NGJoinEvent;
use NetherGames\NGEssentials\NGEssentials;
use pocketmine\scheduler\ClosureTask;
use libVanilla\entity\object\FishingHook;
use libVanilla\event\fishing\FishingRodRetractionEvent;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function spl_object_hash;
use function sqrt;

class SWListener extends MinigameListener
{
    public const COOLDOWN_ROD = 0;
    public const COOLDOWN_BOW = 1;

    /** @var int[][] */
    private array $cooldown = [];

    /**
     * @return Skywars
     */
    public function getPlugin(): Minigame
    {
        /** @var Skywars $plugin */
        $plugin = parent::getPlugin();

        return $plugin;
    }

    /**
     * COMPAT 2026-10-28: 单服开发模式下没有大厅服负责派发。
     * libminigames 的通用入院逻辑只给 MurderMystery 注册过，Skywars 不生效，
     * 玩家会一直停在 sub-lobby 里进不了对局。这里连入即入队。
     */
    public function onNGJoin(NGJoinEvent $event): void
    {
        if (NGEssentials::isInDevelopmentMode()) {
            $this->getPlugin()->joinArena($event->getPlayer(), $this->resolveModeId());
        }
    }

    /**
     * COMPAT 2026-10-28: 一局打完会被送回 Hub，而 onNGJoin 只在连入时触发一次，
     * 不重新入队就只能重连。退出后延迟 3 秒自动重新排队。
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

    /**
     * 模式由环境变量 NG_SW_MODE 决定（默认 solo）。
     */
    private function resolveModeId(): int
    {
        return match (strtolower(getenv('NG_SW_MODE') ?: 'solo')) {
            'doubles', 'double' => TeamArena::MODE_DOUBLES,
            '1v1', 'duels' => SWArena::MODE_DUELS_SOLO,
            '2v2' => SWArena::MODE_DUELS_DOUBLES,
            default => TeamArena::MODE_SOLO,
        };
    }

    public function onEntityShootBow(EntityShootBowEvent $event): void
    {
        $player = $event->getEntity();
        $item = $event->getBow();

        $hasCooldown = $item->getNamedTag()->getByte('cooldown', 0) === 1;
        if ($player instanceof Player && $hasCooldown && $this->checkCooldown($player, 10, self::COOLDOWN_BOW)) {
            $player->sendMessage(TextFormat::YELLOW . TextFormat::clean($item->getCustomName()) . ' is on cooldown for §c' . $this->getCooldown($player, self::COOLDOWN_BOW) . ' §esecond(s)!');
            $event->cancel();
        } else {
            parent::onEntityShootBow($event);
        }
    }

    private function checkCooldown(Player $player, int $cooldown = 5, int $type = self::COOLDOWN_ROD): bool
    {
        $playerHash = spl_object_hash($player);

        if (!isset($this->cooldown[$playerHash][$type])) {
            $this->cooldown[$playerHash][$type] = time() + $cooldown;
            return false;
        }

        if (time() >= $this->cooldown[$playerHash][$type]) {
            $this->cooldown[$playerHash][$type] = time() + $cooldown;
            return false;
        }

        return true;
    }

    private function getCooldown(Player $player, int $type = self::COOLDOWN_ROD): int
    {
        return $this->cooldown[spl_object_hash($player)][$type] - time();
    }

    public function onFishingRodRetractionRetraction(FishingRodRetractionEvent $event): void
    {
        $rod = $event->getRod();
        $player = $event->getPlayer();
        $hook = $rod->getFishingHook($player);
        if ($hook === null) {
            return;
        }

        $isGrapplingRod = $rod->getNamedTag()->getByte("grappling_rod", 0) === 1;

        if ($player->isOnGround() && $hook->isOnGround() && $isGrapplingRod) {
            if ($this->checkCooldown($player)) {
                $player->sendMessage('§eGrappling rod is on cooldown for §c' . $this->getCooldown($player) . ' §esecond(s)!');
                return;
            }

            $this->grapple($player, $hook);

            $rod->setDamage(0);
        }
    }

    private function grapple(Player $player, FishingHook $hook): void
    {
        $deltaX = -($player->getPosition()->getX() - $hook->getPosition()->getX());
        $deltaZ = -($player->getPosition()->getZ() - $hook->getPosition()->getZ());
        $deltaY = $hook->getPosition()->getY() - $player->getPosition()->getY();

        $base = 2;
        $force = sqrt($deltaX * $deltaX + $deltaZ * $deltaZ);

        if ($force <= 0) {
            return;
        }

        $force = 1 / $force;
        $motion = clone $player->getMotion();

        $motion->x /= 2;
        $motion->z /= 2;
        $motion->x += $deltaX * $force * $base;
        $motion->z += $deltaZ * $force * $base;

        $motion->y = 0.75 + ($deltaY * 0.05);

        if ($motion->y > $base) {
            $motion->y = $base;
        }

        $player->setMotion($motion);
    }
}