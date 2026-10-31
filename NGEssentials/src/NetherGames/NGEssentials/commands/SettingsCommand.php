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
 * COMPAT 2026-10-28: 本地是单服，没有大厅服，玩家拿不到大厅里的 "Profile Settings" 物品，
 * 也就打不开设置菜单（Chest UI / Shop Slider / Popup Messages 等）。
 * 这里补一个 /settings 命令，直接把同一个表单调出来。
 */
declare(strict_types=1);

namespace NetherGames\NGEssentials\commands;

use NetherGames\NGEssentials\NGEssentials;
use NetherGames\NGEssentials\player\forms\Forms;
use NetherGames\NGEssentials\player\permissions\Permissions;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

class SettingsCommand extends BaseCommand
{
    public function __construct(NGEssentials $plugin)
    {
        parent::__construct('settings', $plugin);

        $this->setPermission(Permissions::DEFAULT_COMMAND_PERMISSION);
        $this->setAliases(['prefs', 'preferences', 'options']);
        $this->setDescription('Open the settings menu (same as the lobby Profile Settings item)');
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        if ($sender instanceof Player) {
            Forms::sendSettings($sender, $this->getPlugin());
        } else {
            $sender->sendMessage($this->getPlugin()->getPrefix() . '§cThat command can only be run in-game.');
        }

        return true;
    }
}
