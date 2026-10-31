<?php
declare(strict_types=1);

namespace libminigames\utils\streaks;

class Streak
{
    private string $xuid;
    private string $gameKey;
    private int $current;
    private int $best;
    private bool $bestChanged;

    /**
     * @param string $xuid
     * @param string $gameKey
     * @param int $current
     * @param int $best
     * @param bool $bestChanged
     */
    public function __construct(string $xuid, string $gameKey, int $current, int $best, bool $bestChanged = false)
    {
        $this->xuid = $xuid;
        $this->gameKey = $gameKey;
        $this->current = $current;
        $this->best = $best;
        $this->bestChanged = $bestChanged;
    }

    /**
     * @param string[] $row
     * @return Streak
     */
    public static function FromSQL(array $row): Streak
    {
        // COMPAT 2026-10-27: xbox-auth=off 时玩家没有 XUID，连胜查询可能返回全 NULL 的行，
        // 直接强转会抛 TypeError 导致整服崩溃。这里统一做空值兜底。
        return new Streak(
            (string)($row["xuid"] ?? ""),
            (string)($row["gameKey"] ?? ""),
            (int)($row["current"] ?? 0),
            (int)($row["best"] ?? 0),
            isset($row["bestChanged"]) && (bool)$row["bestChanged"]
        );
    }

    /**
     * @return string
     */
    public function getXuid(): string
    {
        return $this->xuid;
    }

    /**
     * @return string
     */
    public function getGameKey(): string
    {
        return $this->gameKey;
    }

    /**
     * @return int
     */
    public function getCurrent(): int
    {
        return $this->current;
    }

    /**
     * @return int
     */
    public function getBest(): int
    {
        return $this->best;
    }

    /**
     * @return bool
     */
    public function isBestChanged(): bool
    {
        return $this->bestChanged;
    }
}