<?php
/**
 *                                _                                   _
 *       /'\_/`\                 ( )             /'\_/`\             ( )_
 *       |     | _   _  _ __    _| |   __   _ __ |     | _   _   ___ | ,_)   __   _ __  _   _
 * (`\/')| (_) |( ) ( )( '__) /'_` | /'__`\( '__)| (_) |( ) ( )/',__)| |   /'__`\( '__)( ) ( )
 *  >  < | | | || (_) || |   ( (_| |(  ___/| |   | | | || (_) |\__, \| |_ (  ___/| |   | (_) |
 * (_/\_)(_) (_)`\___/'(_)   `\__,_)`\____)(_)   (_) (_)`\__, |(____/`\__)`\____)(_)   `\__, |
 *                                                      ( )_| |                        ( )_| |
 *                                                      `\___/'                        `\___/'
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

namespace murdermystery;

use libminigames\Arena;
use libminigames\Minigame;
use libminigames\MinigameListener;
use murdermystery\gamemodes\classic\MMArenaClassic;
use murdermystery\gamemodes\infection\MMArenaInfection;
use murdermystery\gamemodes\MMArena;
use murdermystery\utils\MMArenaConfig;
use murdermystery\utils\MMChance;
use murdermystery\utils\MMKnife;
use murdermystery\utils\MMPlayer;
use NetherGames\NGEssentials\ServerManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\SavedDataLoadingException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\utils\Config;
use pocketmine\world\World;
use function array_filter;
use function is_dir;

class MurderMystery extends Minigame
{
    /** @var MMArenaConfig */
    private MMArenaConfig $arenaConfig;
    /** @var MMChance */
    private MMChance $chance;

    public function getMaps(bool $onlyEnabled): array
    {
        return array_filter(
            array: $this->getArenaConfig()->getMaps($onlyEnabled),
            callback: fn(string $mapName) => preg_match(
                    pattern: "/^([a-zA-Z]-)?MM-([a-zA-Z0-9]+)/",
                    subject: $mapName
                ) && is_dir("{$this->getDataFolder()}/arenas/$mapName"),
        );
    }

    public function getArenaConfig(): MMArenaConfig
    {
        return $this->arenaConfig;
    }

    public function registerClasses(): void
    {
        $arenaConfig = new Config($this->getDataFolder() . 'arenas.yml', Config::YAML, ['arenas' => []]);
        $arenaConfig->save();
        $this->arenaConfig = new MMArenaConfig($arenaConfig);
        $this->chance = new MMChance();

        $this->getServer()->getCommandMap()->register(MMCommand::class, new MMCommand($this));

        $this->getServer()->getPluginManager()->registerEvents(new MinigameListener($this), $this);

        // COMPAT 2026-10-28: 单服开发模式下补"连入即入队"（基类 MinigameListener 没有 onNGJoin）
        $this->getServer()->getPluginManager()->registerEvents(new MMListener($this), $this);

        // COMPAT 2026-10-28: 新版 PMMP 要求所有会进入世界的实体都注册，否则 addEntity 抛 LogicException
        $entityFactory = EntityFactory::getInstance();
        foreach ([MMPlayer::class, MMKnife::class] as $class) {
            if ($entityFactory->isRegistered($class)) {
                continue;
            }

            $shortName = substr($class, (int)strrpos($class, '\\') + 1);
            $entityFactory->register($class, static function (World $world, CompoundTag $nbt) use ($shortName): Entity {
                throw new SavedDataLoadingException($shortName . ' is not persistent');
            }, ['ng:mm_' . strtolower($shortName)]);
        }

        MMKnife::setup();
    }

    public function getMinigameTag(): string
    {
        return ServerManager::MM;
    }

    public function getModes(): array
    {
        return [
            MMArena::MODE_CLASSIC => 'Classic',
            MMArena::MODE_INFECTION => 'Infection'
        ];
    }

    public function generateNewArena(int $modeId, bool $privateGame = false): Arena
    {
        if ($modeId === MMArena::MODE_CLASSIC) {
            return new MMArenaClassic($this, $modeId, $this->mapsPlayed++, $privateGame);
        }

        return new MMArenaInfection($this, $modeId, $this->mapsPlayed++, $privateGame);
    }

    public function getChanceHandler(): MMChance
    {
        return $this->chance;
    }
}