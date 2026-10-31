<?php
/**
 *         _____            _
 *        | ___ \          | |
 *  __  __| |_/ /  ___   __| |__      __  __ _  _ __  ___
 *  \ \/ /| ___ \ / _ \ / _` |\ \ /\ / / / _` || '__|/ __|
 *   >  < | |_/ /|  __/| (_| | \ V  V / | (_| || |   \__ \
 *  /_/\_\\____/  \___| \__,_|  \_/\_/   \__,_||_|   |___/
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

namespace bedwars;

use bedwars\utils\entity\ItemEntity;
use libminigames\Minigame;
use libminigames\events\MinigameQuitEvent;
use libminigames\MinigameListener;
use libVanilla\item\Fireball;
use NetherGames\NGEssentials\events\NGJoinEvent;
use NetherGames\NGEssentials\events\NGLoginEvent;
use NetherGames\NGEssentials\NGEssentials;
use NetherGames\NGEssentials\player\NGPlayer;
use NetherGames\NGEssentials\player\PlayerData;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\tile\Bed;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\entity\ItemDespawnEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\player\PlayerBedEnterEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\math\Facing;
use pocketmine\scheduler\ClosureTask;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\InputMode;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\utils\TextFormat;

final class BWListener extends MinigameListener
{
    /**
     * @param NGJoinEvent $event
     *
     * @priority NORMAL
     */
    private function resolveModeId(): int
    {
        $mode = strtolower(getenv('NG_BW_MODE') ?: 'solo');

        return match ($mode) {
            '1v1', 'versus' => BWArena::MODE_VERSUS_ONE,
            '2v2' => BWArena::MODE_VERSUS_TWO,
            'doubles', 'double' => \libminigames\TeamArena::MODE_DOUBLES,
            'trios' => \libminigames\TeamArena::MODE_TRIOS,
            'squads' => \libminigames\TeamArena::MODE_SQUADS,
            default => \libminigames\TeamArena::MODE_SOLO,
        };
    }

    /**
     * COMPAT 2026-10-27: 单服开发模式下没有大厅负责派发。
     * 玩家用"Return to Lobby"退出竞技场后会停在 Hub，再也进不来
     * （onNGJoin 只在连入那一刻触发一次）。
     * 这里在退出后延迟 3 秒自动重新排队，让它表现得像"重新匹配"。
     *
     * @param MinigameQuitEvent $event
     *
     * @priority MONITOR
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
            // 退出可能被取消（例如队伍里还有人），此时玩家仍在竞技场内，不要重复入队
            if ($player->isOnline() && $this->getPlugin()->getArena($player) === null) {
                $player->sendMessage('§a已离开对局，正在重新排队…');
                $this->getPlugin()->joinArena($player, $modeId);
            }
        }), 60);
    }

    public function onNGJoin(NGJoinEvent $event): void
    {
        // COMPAT 2026-10-27: 本机没有 WaterdogPE 代理，开发模式下没有大厅服负责派发，
        // 玩家会永远停在 sub-lobby。这里直接让 Bedwars 自己把玩家送进竞技场。
        // 注意：MinigameListener 的通用入院逻辑只有 MurderMystery 注册，Bedwars 不生效。
        if (NGEssentials::isInDevelopmentMode()) {
            // 模式由环境变量 NG_BW_MODE 决定（默认 solo）。
            // 地图前缀：BW8-* → solo/doubles，BW4-* → trios/squads，BWD-* → 1v1/2v2
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
                    /** @var BWArena $arena */
                    if (($team = $arena->getTeamByXuid($player->getXuid())) !== null) {
                        /** @var BWTeam $team */
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
     * @return Bedwars
     */
    public function getPlugin(): Minigame
    {
        /** @var Bedwars $plugin */
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


    /**
     * @param PlayerBedEnterEvent $event
     *
     * @priority NORMAL
     */
    public function onPlayerBedEnter(PlayerBedEnterEvent $event): void
    {
        if ((($arena = $this->getPlugin()->getArenaByWorld($event->getPlayer()->getWorld())) !== null) && $arena->isRunning()) {
            $event->cancel();
        }
    }

    /**
     * @param ChunkLoadEvent $event
     *
     * @priority NORMAL
     */
    public function onChunkLoad(ChunkLoadEvent $event): void
    {
        $world = $event->getWorld();

        if (($arena = $this->getPlugin()->getArenaByWorld($world)) !== null) {
            if ($arena->isWaiting()) {
                return;
            }

            $chunk = $event->getChunk();

            foreach ($chunk->getTiles() as $tile) {
                if ($tile instanceof Bed) {
                    $block = $tile->getBlock();

                    if ($block instanceof \pocketmine\block\Bed) {
                        $team = $arena->getTeamByBed($block);
                        if ($team === null || $block->getOtherHalf() === null) {
                            continue;
                        }

                        if (!$team->isBedAlive()) {
                            foreach ($block->getAffectedBlocks() as $block) {
                                $world->setBlock($block->getPosition(), VanillaBlocks::AIR());
                            }
                        } elseif ($arena->hasBedDefense() && !$team->hasBedDefense()) {
                            $team->setBedDefense(true);

                            $callable = function (Block $block, array $blocks) use ($world, $arena): array {
                                /** @var Block[] $blocks */
                                $changedBlocks = [];

                                foreach ($blocks as $b) {
                                    foreach (Facing::ALL as $side) {
                                        $sideBlock = $b->getSide($side);
                                        if ($sideBlock->getTypeId() !== BlockTypeIds::AIR) {
                                            continue;
                                        }

                                        $changedBlocks[] = $sideBlock;

                                        $world->setBlock($sideBlock->getPosition(), $block);
                                        $arena->getBlockCollector()->addBlock($sideBlock->getPosition());
                                    }
                                }

                                return $changedBlocks;
                            };

                            $callable(VanillaBlocks::END_STONE(), $callable(VanillaBlocks::OAK_PLANKS(), $block->getAffectedBlocks()));
                        }
                    }
                }
            }
        }
    }

    /**
     * @param ItemDespawnEvent $event
     *
     * @priority NORMAL
     */
    public function onEntityDespawn(ItemDespawnEvent $event): void
    {
        $entity = $event->getEntity();

        if ($entity instanceof ItemEntity && $this->getPlugin()->getArenaByWorld($entity->getWorld()) !== null) {
            $entity->flagForDespawn(); // make sure it's removed before spawning the new item, otherwise it will be merged

            $newEntity = new ItemEntity($entity->getLocation(), $entity->getItem());
            $newEntity->setPickupDelay($entity->getPickupDelay());
            $newEntity->setCanDuplicate($entity->canDuplicate());
            $newEntity->spawnToAll();
        }
    }

    /**
     * @param InventoryTransactionEvent $event
     *
     * @priority HIGH
     */
    public function onInventoryTransaction(InventoryTransactionEvent $event): void
    {
        parent::onInventoryTransaction($event);
    }

    /**
     * @param PlayerQuitEvent $event
     *
     * @priority NORMAL
     */
    public function onPlayerQuit(PlayerQuitEvent $event): void
    {
        parent::onPlayerQuit($event);
        $player = $event->getPlayer();

        /** @var BWArena|null $arena */
        $arena = $this->getPlugin()->getArena($player);
        $arena?->getShop()->onQuit($player);
    }

    /**
     * @param DataPacketReceiveEvent $event
     *
     * @priority NORMAL
     */
    public function onDataReceive(DataPacketReceiveEvent $event): void
    {
        /** @var NGPlayer|null $player */
        $player = $event->getOrigin()->getPlayer();
        if ($player !== null) {
            $packet = $event->getPacket();

            /** @var LevelSoundEventPacket $packet */
            if ($packet->pid() == LevelSoundEventPacket::NETWORK_ID && $packet->sound === LevelSoundEvent::ATTACK_NODAMAGE) {
                if (!($player->getInventory()->getItemInHand() instanceof Fireball && $player->getInputMode() === InputMode::TOUCHSCREEN) || $player->isUsingItem()) {
                    return;
                }

                $player->useHeldItem();
            }
        }
    }
}