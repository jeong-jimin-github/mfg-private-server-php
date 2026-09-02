<?php

declare(strict_types=1);

namespace Mfg\Mahjong;

/**
 * Situational yaku that depend on table state rather than hand shape.
 * Kept separate so Table can feed the exact context used by the Python engine.
 */
final class SituationalYaku
{
    /**
     * @return array{han:int,yakuman:int,yaku:list<string>}
     */
    public static function evaluate(
        bool $haitei = false,
        bool $houtei = false,
        bool $rinshan = false,
        bool $chankan = false,
        bool $tenho = false,
        bool $chiho = false
    ): array {
        $han = 0;
        $yakuman = 0;
        $yaku = [];

        if ($haitei) {
            ++$han;
            $yaku[] = 'Haitei';
        }
        if ($houtei) {
            ++$han;
            $yaku[] = 'Hotei';
        }
        if ($rinshan) {
            ++$han;
            $yaku[] = 'Rinsyan';
        }
        if ($chankan) {
            ++$han;
            $yaku[] = 'Chankan';
        }
        if ($tenho) {
            ++$yakuman;
            $yaku[] = 'Tenho';
        } elseif ($chiho) {
            ++$yakuman;
            $yaku[] = 'Chiho';
        }

        return ['han' => $han, 'yakuman' => $yakuman, 'yaku' => $yaku];
    }

    /**
     * Merge situation-only yaku into an existing evaluator result.
     *
     * @param array{han:int,fu:int,rank:int,dora:int,yaku:list<string>,yakuman:int} $base
     * @param array{han:int,yakuman:int,yaku:list<string>} $extra
     * @return array{han:int,fu:int,rank:int,dora:int,yaku:list<string>,yakuman:int}
     */
    public static function merge(array $base, array $extra): array
    {
        // base['han'] already includes dora; situation yaku add normal han.
        $base['han'] += $extra['han'];
        $base['yakuman'] += $extra['yakuman'];
        $base['yaku'] = array_values(array_unique(array_merge($base['yaku'], $extra['yaku'])));
        $base['rank'] = ScoreMath::hanRank($base['han'], $base['fu'], $base['yakuman']);
        return $base;
    }
}
