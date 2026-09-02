<?php

declare(strict_types=1);

namespace Mfg\Mahjong;

/**
 * Incremental PHP port of the Python scorer. It deliberately keeps the result
 * shape small; Table only needs han/fu/rank/dora to settle a hand. The common
 * closed-hand paths are implemented first and the bit-compatible full yaku
 * table can be layered on without changing callers.
 */
final class HandEvaluator
{
    /**
     * @param list<int> $hand 0..33 tile indexes, including winning tile
     * @param list<array<string,mixed>> $melds
     * @param list<int> $doraIndicators
     * @param list<int> $uraIndicators
     * @return array{han:int,fu:int,rank:int,dora:int,yaku:list<string>}|null
     */
    public static function evaluate(
        array $hand,
        array $melds,
        int $winTile,
        bool $isTsumo,
        int $seatWind,
        int $roundWind,
        bool $riichi,
        bool $doubleRiichi,
        bool $ippatsu,
        array $doraIndicators,
        array $uraIndicators,
        int $taku
    ): ?array {
        $openMelds = count($melds);
        if (!Mahjong::isAgari(Mahjong::countsOf($hand), $openMelds, $taku)) return null;

        $menzen = true;
        foreach ($melds as $m) if (($m['kind'] ?? '') !== 'ankan') { $menzen = false; break; }

        $han = 0; $yaku = [];
        if ($doubleRiichi) { $han += 2; $yaku[]='DoubleRichi'; }
        elseif ($riichi) { $han += 1; $yaku[]='Richi'; }
        if ($riichi && $ippatsu) { $han += 1; $yaku[]='Ippatsu'; }
        if ($isTsumo && $menzen) { $han += 1; $yaku[]='Menzen'; }

        $counts = Mahjong::countsOf($hand);
        $chiitoi = $openMelds===0 && self::isChiitoi($counts);
        if ($chiitoi) { $han += 2; $yaku[]='Chitoitsu'; }

        $allTiles = $hand;
        foreach ($melds as $m) foreach (($m['tiles'] ?? []) as $t) $allTiles[]=(int)$t;
        if (self::isTanyao($allTiles)) { $han += 1; $yaku[]='Tanyao'; }

        $triplets = self::tripletKinds($counts, $melds);
        foreach ($triplets as $t) {
            if ($t === Mahjong::HON+4) { $han++; $yaku[]='Haku'; }
            elseif ($t === Mahjong::HON+5) { $han++; $yaku[]='Hatsu'; }
            elseif ($t === Mahjong::HON+6) { $han++; $yaku[]='Tyun'; }
            else {
                $w=$t-Mahjong::HON;
                if ($t>=Mahjong::HON && $w===$roundWind) { $han++; $yaku[]='Bakaze'; }
                if ($t>=Mahjong::HON && $w===$seatWind) { $han++; $yaku[]='Jikaze'; }
            }
        }

        if (self::isToitoi($counts,$melds)) { $han+=2; $yaku[]='Toitoiho'; }
        $flush=self::flushHan($allTiles,$menzen);
        if ($flush['han']>0) { $han += $flush['han']; $yaku[]=$flush['name']; }

        $dora = self::doraCount($allTiles,$doraIndicators,$riichi?$uraIndicators:[],$taku);
        // Dora never makes an otherwise yakuless hand valid.
        if ($han<=0) return null;
        $totalHan=$han+$dora;
        $fu=$chiitoi?25:30;
        $rank=ScoreMath::hanRank($totalHan,$fu,0);
        return ['han'=>$totalHan,'fu'=>$fu,'rank'=>$rank,'dora'=>$dora,'yaku'=>$yaku];
    }

    /** @param list<int> $counts */
    private static function isChiitoi(array $counts): bool
    {
        $pairs=0; foreach($counts as $n){if($n===2)$pairs++; elseif($n!==0)return false;} return $pairs===7;
    }
    /** @param list<int> $tiles */
    private static function isTanyao(array $tiles): bool
    { foreach($tiles as $t) if(Mahjong::isYaochu((int)$t)) return false; return true; }

    /** @param list<int> $counts @param list<array<string,mixed>> $melds @return list<int> */
    private static function tripletKinds(array $counts,array $melds): array
    {
        $out=[]; for($i=0;$i<34;$i++)if(($counts[$i]??0)>=3)$out[]=$i;
        foreach($melds as $m){$k=(string)($m['kind']??'');if(in_array($k,['pon','ankan','minkan','kakan'],true)){$tiles=$m['tiles']??[];if($tiles)$out[]=(int)$tiles[0];}}
        return array_values(array_unique($out));
    }

    /** @param list<int> $counts @param list<array<string,mixed>> $melds */
    private static function isToitoi(array $counts,array $melds): bool
    {
        foreach($melds as $m)if(($m['kind']??'')==='chi')return false;
        $pair=0;$sets=0;
        for($i=0;$i<34;$i++){ $n=$counts[$i]??0; if($n===2)$pair++; elseif($n===3)$sets++; elseif($n===4){$sets++;$pair++;} elseif($n!==0)return false; }
        return $sets + count($melds) >=4 && $pair>=1;
    }

    /** @param list<int> $tiles @return array{han:int,name:string} */
    private static function flushHan(array $tiles,bool $menzen): array
    {
        $suits=[];$hon=false;
        foreach($tiles as $t){$t=(int)$t;if(Mahjong::isHonor($t)){$hon=true;continue;}$suits[intdiv($t,9)]=true;}
        if(count($suits)!==1)return ['han'=>0,'name'=>''];
        if($hon)return ['han'=>$menzen?3:2,'name'=>$menzen?'Honiso':'HonisoNaki'];
        return ['han'=>$menzen?6:5,'name'=>$menzen?'Chiniso':'ChinisoNaki'];
    }

    /** @param list<int> $tiles @param list<int> $dora @param list<int> $ura */
    private static function doraCount(array $tiles,array $dora,array $ura,int $taku): int
    {
        $c=Mahjong::countsOf($tiles);$n=0;
        foreach(array_merge($dora,$ura) as $ind)$n += $c[Mahjong::doraFromIndicator((int)$ind,$taku)]??0;
        return $n;
    }
}
