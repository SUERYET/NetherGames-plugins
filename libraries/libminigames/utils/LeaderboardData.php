<?php
/**
 *   _ _ _               _       _
 *  | (_) |             (_)     (_)
 *  | |_| |__  _ __ ___  _ _ __  _  __ _  __ _ _ __ ___   ___  ___
 *  | | | '_ \| '_ ` _ \| | '_ \| |/ _` |/ _` | '_ ` _ \ / _ \/ __|
 *  | | | |_) | | | | | | | | | | | (_| | (_| | | | | | |  __/\__ \
 *  |_|_|_.__/|_| |_| |_|_|_| |_|_|\__, |\__,_|_| |_| |_|\___||___/
 *                                  __/ |
 *                                 |___/
 *
 * Copyright (C) 2016-2026 NetherGames Network
 *
 * This is private software, you cannot redistribute and/or modify it in any way
 * unless given explicit permission to do so. If you have not been given explicit
 * permission to view or modify this software you should take the appropriate actions
 * to remove this software from your device immediately.
 *
 * @author Driesboy
 *
 */
declare(strict_types=1);

namespace libminigames\utils;

use JsonException;
use NetherGames\NGEssentials\utils\CustomIcon;
use NetherGames\NGEssentials\utils\MySQLCredentials;
use pocketmine\utils\InternetRequestResult;
use function json_decode;
use function str_replace;
use function strtolower;
use function strtoupper;
use const JSON_THROW_ON_ERROR;
use const nethergames\API_URI;

class LeaderboardData
{
    /** @var array<string, array{string, string}> */
    private array $data = [];
    /** @var array<string, array{string, int, int, string, string}> */
    private array $definitions = [];

    /** @var array<string, true>|null  缓存的 player_stats 列名（用于跳过不存在的列） */
    private static ?array $validColumns = null;

    /**
     * 表里可能没有某一列（例如 Skywars 有 1v1/2v2 模式，但 schema 里只有 bw_1v1_wins，
     * 没有 sw_1v1_wins）。查之前先确认列存在，避免刷 SQL 报错。
     */
    private function ensureValidColumns(callable $onReady): void
    {
        if (self::$validColumns !== null) {
            $onReady();
            return;
        }

        MySQLCredentials::executeSelectRaw('SHOW COLUMNS FROM player_stats', [], static function (array $rows) use ($onReady): void {
            $columns = [];
            foreach ($rows as $row) {
                $columns[strtolower((string)($row['Field'] ?? ''))] = true;
            }
            self::$validColumns = $columns;
            $onReady();
        }, static function () use ($onReady): void {
            self::$validColumns = [];
            $onReady();
        });
    }

    /** 这些列在 player_stats_baseline 里有基线，排行榜要显示"本服新增" */
    private const BASELINE_COLUMNS = [
        'bw_wins', 'bw_solo_wins', 'bw_doubles_wins', 'bw_trios_wins', 'bw_squads_wins',
        'bw_1v1_wins', 'bw_2v2_wins',
        'sw_wins', 'sw_solo_wins', 'sw_doubles_wins',
        'duels_wins', 'tb_wins', 'mm_wins', 'cq_wins', 'sg_wins',
        'md_wins', 'ms_wins', 'sc_wins', 'uhc_wins',
    ];

    /**
     * @param array<string> $modes
     * @param array<string> $types
     */
    public function __construct(protected array $modes, protected array $types = [])
    {
    }

    private function getColumnName(string $columnName, int $modeId = -1, int $typeId = -1): string
    {
        return strtolower($this->getDisplayName($columnName, $modeId, $typeId));
    }

    private function getDisplayName(string $content, int $modeId = -1, int $typeId = -1): string
    {
        if ($modeId !== -1) {
            $content = str_replace('*mode*', $this->modes[$modeId], $content);
        }
        if ($typeId !== -1) {
            $content = str_replace('*type*', $this->types[$typeId], $content);
        }

        return $content;
    }

    public function load(string $columnName, int $modeId = -1, int $typeId = -1, string $title = '', string $text = ''): void
    {
        // COMPAT 2026-10-28: 上游是从 api.ngmc.co 拉官方全服排行榜（显示的是官方大佬）。
        // 我们是私服，改成查本地库，并用 player_stats_baseline 扣掉"导入官方历史"的部分，
        // 只统计从开服那天开始在本服打出来的成绩。
        $this->definitions[$this->getColumnName($columnName, $modeId, $typeId)] = [$columnName, $modeId, $typeId, $title, $text];

        $column = $this->getColumnName($columnName, $modeId, $typeId);
        $title = strtoupper($this->getDisplayName(strtolower($title), $modeId, $typeId));
        $text = $this->getDisplayName($text, $modeId, $typeId);

        // 列名全部来自插件内部的常量（*mode* 由 getModes() 替换），不来自玩家输入
        $score = in_array($column, self::BASELINE_COLUMNS, true)
            ? "GREATEST(0, s.`$column` - COALESCE(b.`$column`, 0))"
            : "s.`$column`";

        $this->ensureValidColumns(function () use ($column, $title, $text, $score): void {
        if (!isset(self::$validColumns[$column])) {
            $this->data[$column] = [$title, $text . "§r\n\n§7暂无记录（该模式没有统计列）"];
            return;
        }

        MySQLCredentials::executeSelectRaw(
            "SELECT d.player AS player, $score AS value
             FROM player_stats s
             JOIN player_data d ON d.xuid = s.xuid
             LEFT JOIN player_stats_baseline b ON b.xuid = s.xuid
             WHERE s.type = 0 AND $score > 0
             ORDER BY value DESC, d.player ASC
             LIMIT 10",
            [],
            function (array $rows) use ($column, $title, $text): void {
                $out = $text . "§r\n";
                $rank = 0;

                foreach ($rows as $row) {
                    $rank++;
                    $player = (string)($row["player"] ?? "");
                    $value = (int)($row["value"] ?? 0);

                    if ($rank === 1) {
                        $out .= "\n" . CustomIcon::TROPHY_GOLD . '§r§7- §b' . $player . ' - §e' . $value;
                    } elseif ($rank === 2) {
                        $out .= "\n" . CustomIcon::TROPHY_SILVER . '§r§7- §b' . $player . ' - §e' . $value;
                    } elseif ($rank === 3) {
                        $out .= "\n" . CustomIcon::TROPHY_BRONZE . '§r§7- §b' . $player . ' - §e' . $value;
                    } else {
                        $out .= "\n" . '§e#' . $rank . ' §r§b' . $player . ' - §e' . $value;
                    }
                }

                if ($rank === 0) {
                    $out .= "\n§7暂无记录（去打一局吧）";
                }

                $this->data[$column] = [$title, $out];
            },
            function () use ($column, $title, $text): void {
                $this->data[$column] = [$title, $text];
            }
        );
        });
    }

    /**
     * 按之前注册过的定义重新查一遍（用于定时刷新排行榜）
     */
    public function reloadAll(): void
    {
        foreach ($this->definitions as [$columnName, $modeId, $typeId, $title, $text]) {
            $this->load($columnName, $modeId, $typeId, $title, $text);
        }
    }

    /**
     * @return array{string, string}
     */
    public function get(string $columnName, int $modeId = -1, int $typeId = -1): array
    {
        return $this->data[$this->getColumnName($columnName, $modeId, $typeId)] ?? ['', ''];
    }
}