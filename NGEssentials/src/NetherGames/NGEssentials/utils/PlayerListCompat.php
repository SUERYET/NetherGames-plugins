<?php
declare(strict_types=1);

namespace NetherGames\NGEssentials\utils;

use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;

/**
 * COMPAT 2026-10-27
 * 新版 bedrock-protocol 移除了 PlayerListPacket::add() / ::remove()，
 * 改为在条目级别用 PlayerListEntry::$type 区分增删（PlayerListPacket::create() 混装）。
 * 这里提供旧语义的等价实现，供仍按"整包增/删"写的调用点使用。
 */
final class PlayerListCompat
{
    /**
     * 把一组"新增条目"转换成"移除条目"并打包。
     *
     * @param PlayerListEntry[] $entries
     */
    public static function createRemoval(array $entries): PlayerListPacket
    {
        $removals = [];
        foreach ($entries as $entry) {
            $copy = clone $entry;
            $copy->type = PlayerListPacket::TYPE_REMOVE;
            $removals[] = $copy;
        }

        return PlayerListPacket::create($removals);
    }
}
