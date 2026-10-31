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

use bedwars\shops\menu\ShopMenu;
use bedwars\shops\menu\UpgraderMenu;
use bedwars\tasks\BlockQueueTask;
use bedwars\utils\BWArenaConfig;
use bedwars\utils\entity\BridgeEgg;
use bedwars\utils\entity\EnderDragon;
use bedwars\utils\entity\ItemEntity;
use bedwars\utils\entity\Landmine;
use bedwars\utils\entity\PrimedTNT;
use bedwars\utils\entity\Snowball;
use bedwars\utils\entity\mob\Bedbug;
use bedwars\utils\entity\mob\DreamDefender;
use bedwars\utils\entity\mob\MiniSkeleton;
use libminigames\Arena;
use libminigames\Minigame;
use libminigames\TeamArena;
use libminigames\utils\Autoloader;
use libminigames\utils\LeaderboardData;
use libVanilla\VanillaPlugin;
use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\type\util\InvMenuTypeBuilders;
use NetherGames\NGEssentials\ServerManager;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\EntityDataHelper as Helper;
use pocketmine\entity\EntityFactory;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;
use function array_filter;
use function array_rand;
use function preg_match;

Autoloader::initAutoloader(dirname(__FILE__, 3) . '/vendor/autoload.php');

final class Bedwars extends Minigame
{
    private BWArenaConfig $arenaConfig;
    private BlockQueueTask $blockQueue;
    private LeaderboardData $leaderboards;

    /**
     * @param int $modeId
     * @return array<int, string>
     */
    public function getMaps(int $modeId, bool $onlyEnabled = true): array
    {
        $teams = match ($modeId) {
            TeamArena::MODE_TRIOS, TeamArena::MODE_SQUADS => 4,
            TeamArena::MODE_DOUBLES, TeamArena::MODE_SOLO => 8,
            BWArena::MODE_VERSUS_ONE, BWArena::MODE_VERSUS_TWO => "D",
            default => throw new AssumptionFailedError("Invalid mode ID $modeId"),
        };

        return array_filter(
            array: $this->getArenaConfig()->getMaps($onlyEnabled),
            callback: fn(string $mapName) => preg_match(
                    pattern: "/^([a-zA-Z]-)?BW$teams-([a-zA-Z0-9]+)/",
                    subject: $mapName
                ) && is_dir("{$this->getDataFolder()}/arenas/$mapName"),
        );
    }

    public function getArenaConfig(): BWArenaConfig
    {
        return $this->arenaConfig;
    }

    public function generateNewArena(int $modeId, bool $privateGame = false): Arena
    {
        return new BWArena($this, $modeId, $this->mapsPlayed++, $privateGame);
    }

    public function registerClasses(): void
    {
        $arenaConfig = new Config("{$this->getDataFolder()}/arenas.yml", Config::YAML, ['arenas' => []]);
        $arenaConfig->save();
        $this->arenaConfig = new BWArenaConfig($arenaConfig);

        $this->leaderboards = new LeaderboardData($this->getModes());

        $this->getServer()->getCommandMap()->register(BWCommand::class, new BWCommand($this));

        $this->getServer()->getPluginManager()->registerEvents(new BWListener($this), $this);

        $entityFactory = EntityFactory::getInstance();
        $entityFactory->register(ItemEntity::class, function (World $world, CompoundTag $nbt): ItemEntity {
            $itemTag = $nbt->getCompoundTag(ItemEntity::TAG_ITEM);
            if ($itemTag === null) {
                throw new SavedDataLoadingException("Expected \"" . ItemEntity::TAG_ITEM . "\" NBT tag not found");
            }

            $item = Item::nbtDeserialize($itemTag);
            if ($item->isNull()) {
                throw new SavedDataLoadingException("Item is invalid");
            }
            return new ItemEntity(Helper::parseLocation($nbt, $world), $item, $nbt);
        }, ['Item', 'minecraft:item']);

        // COMPAT 2026-10-27: 新版 PMMP 要求"所有会进入世界的实体类"都在 EntityFactory 注册，
        // 否则 World::addEntity() 直接抛 LogicException（实测：放一个 TNT 就崩服）。
        // 这些实体都在构造时 setCanSaveWithChunk(false)，存档名只用于满足工厂校验，不会真的落盘。
        $entityFactory->register(PrimedTNT::class, function (World $world, CompoundTag $nbt): PrimedTNT {
            return new PrimedTNT(Helper::parseLocation($nbt, $world), $nbt);
        }, ['ng:primed_tnt']);
        $entityFactory->register(Landmine::class, static function (World $world, CompoundTag $nbt): Landmine {
            throw new SavedDataLoadingException("Landmine is not persistent");
        }, ['ng:landmine']);
        $entityFactory->register(BridgeEgg::class, static function (World $world, CompoundTag $nbt): BridgeEgg {
            throw new SavedDataLoadingException("BridgeEgg is not persistent");
        }, ['ng:bridge_egg']);
        $entityFactory->register(Snowball::class, static function (World $world, CompoundTag $nbt): Snowball {
            throw new SavedDataLoadingException("Snowball is not persistent");
        }, ['ng:snowball']);
        $entityFactory->register(EnderDragon::class, static function (World $world, CompoundTag $nbt): EnderDragon {
            throw new SavedDataLoadingException("EnderDragon is not persistent");
        }, ['ng:ender_dragon']);
        // 蠹虫/梦境守卫/小骷髅：商店道具召唤的生物，同样是"进入世界就必须注册"。
        $entityFactory->register(Bedbug::class, function (World $world, CompoundTag $nbt): Bedbug {
            return new Bedbug(Helper::parseLocation($nbt, $world), $nbt);
        }, ['ng:bedbug']);
        $entityFactory->register(DreamDefender::class, function (World $world, CompoundTag $nbt): DreamDefender {
            return new DreamDefender(Helper::parseLocation($nbt, $world), $nbt);
        }, ['ng:dream_defender']);
        $entityFactory->register(MiniSkeleton::class, function (World $world, CompoundTag $nbt): MiniSkeleton {
            return new MiniSkeleton(Helper::parseLocation($nbt, $world), $nbt);
        }, ['ng:mini_skeleton']);

        $this->blockQueue = new BlockQueueTask($this->getServer());
        $this->getScheduler()->scheduleRepeatingTask($this->blockQueue, 10);

        if (!InvMenuHandler::isRegistered()) {
            InvMenuHandler::register($this);
        }

        $registry = InvMenuHandler::getTypeRegistry();
        foreach ([ShopMenu::MENU_IDENTIFIER, UpgraderMenu::MENU_IDENTIFIER] as $identifier) {
            $registry->register(
                identifier: $identifier,
                type: InvMenuTypeBuilders::DOUBLE_PAIRABLE_BLOCK_ACTOR_FIXED()
                    ->setBlock(VanillaBlocks::CHEST())
                    ->setSize(54)
                    ->setBlockActorId("Chest")
                    ->setAnimationDuration(1)
                    ->build()
            );
        }

        VanillaPlugin::FIREBALL()->register($this);

        foreach ($this->getModes() as $i => $mode) {
            $this->getLeaderboards()->load('bw_*mode*_wins', $i, -1, '§l§a*MODE* WINS LEADERBOARD', '§7Since 2026-10-27 · Bedwars *mode* wins');
        }
    }

    public function getLeaderboards(): LeaderboardData
    {
        return $this->leaderboards;
    }

    public function getModes(): array
    {
        return [
            TeamArena::MODE_SOLO => 'Solo',
            TeamArena::MODE_DOUBLES => 'Doubles',
            TeamArena::MODE_SQUADS => 'Squads',

            BWArena::MODE_VERSUS_ONE => '1v1',
            BWArena::MODE_VERSUS_TWO => '2v2',
        ];
    }

    /**
     * @param World $world
     * @return BWArena|null
     */
    public function getArenaByWorld(World $world): ?Arena
    {
        /** @var BWArena|null $arena */
        $arena = parent::getArenaByWorld($world);

        return $arena;
    }

    public function getRandomBreakMessage(): string
    {
        $messages = [
            '{TEAM} §7was destroyed by {DAMAGER}§r§7!',
            '{TEAM} §7was incinerated by {DAMAGER}§r§7!',
            '{TEAM} §7was iced by {DAMAGER}§r§7!',
            '{TEAM} §7was dismantled by {DAMAGER}§r§7!',
            '{TEAM} §7was deep fried by {DAMAGER}§r§7!',
            '{TEAM} §7was traded in for milk and cookies by {DAMAGER}§r§7!',
            '{TEAM} §7was ripped apart by {DAMAGER}§r§7!',
            '{TEAM} §7be shot with a cannon by {DAMAGER}§r§7!',
            '{TEAM} §7was spooked by {DAMAGER}§r§7!',
            '{TEAM} §7was dreadfully corrupted by {DAMAGER}§r§7!',
            '{TEAM} §7got memed by {DAMAGER}§r§7!',
            '{TEAM} §7was sent to heaven by {DAMAGER}§r§7!',
            '{TEAM} §7got isekaid by {DAMAGER}§r§7!',
            '{TEAM} §7was avada kedavra\'d by {DAMAGER}§r§7!',
            '{TEAM} §7got destroyed by {DAMAGER}§7\'s lightsaber§r§7!',
            '{TEAM} §7was in written in the death note by {DAMAGER}§r§7!',
            '{TEAM} §7was annihilated by {DAMAGER}§r§7!',
            '{TEAM} §7perished because of {DAMAGER}§r§7!',
            '{TEAM} §7was not cozy enough for {DAMAGER}§r§7!',
            '{TEAM} §7will need to sleep with their parents because of {DAMAGER}§r§7!',
            '{TEAM} §7is sleeping on the couch because of {DAMAGER}§r§7!'
        ];
        return $messages[array_rand($messages)];
    }

    public function getMinigameTag(): string
    {
        return ServerManager::BW;
    }

    public function generateDrop(Player $player, ?Player $damager = null, bool $quit = false): void
    {
        $iron = 0;
        $gold = 0;
        $diamond = 0;
        $emerald = 0;

        foreach ($player->getInventory()->getContents() as $item) {
            match ($item->getTypeId()) {
                ItemTypeIds::IRON_INGOT => $iron += $item->getCount(),
                ItemTypeIds::GOLD_INGOT => $gold += $item->getCount(),
                ItemTypeIds::DIAMOND => $diamond += $item->getCount(),
                ItemTypeIds::EMERALD => $emerald += $item->getCount(),
                default => null
            };
        }

        $items = [
            TextFormat::WHITE => VanillaItems::IRON_INGOT()->setCount($iron),
            TextFormat::GOLD => VanillaItems::GOLD_INGOT()->setCount($gold),
            TextFormat::AQUA => VanillaItems::DIAMOND()->setCount($diamond),
            TextFormat::GREEN => VanillaItems::EMERALD()->setCount($emerald),
        ];

        foreach ($items as $color => $item) {
            if ($item->getCount() === 0) {
                continue;
            }

            if ($quit) {
                $player->dropItem($item);
            } else {
                if ($damager !== null && !$damager->isSpectator()) {
                    $leftOvers = $damager->getInventory()->addItem($item);
                    $damager->sendMessage($color . '+' . $item->getCount() . ' ' . $item->getName() . ($item->getCount() > 1 ? 's' : ''));

                    if (count($leftOvers) > 0) {
                        foreach ($leftOvers as $leftOver) {
                            $player->dropItem($leftOver);
                        }
                    }
                } else {
                    $player->dropItem($item);
                }
            }
        }
    }

    public function getBlockQueue(): BlockQueueTask
    {
        return $this->blockQueue;
    }
}