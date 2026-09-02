<?php

declare(strict_types=1);

namespace Mfg\Mahjong;

final class RoundSettlement
{
    /**
     * Exhaustive-draw noten payment. Mirrors the Python server: exactly 3000
     * points move between tenpai and noten seats when both groups exist.
     *
     * @param list<int> $tenpaiSeats
     * @return list<int> four-seat delta vector
     */
    public static function exhaustiveDrawDeltas(int $seats,array $tenpaiSeats): array
    {
        $out=[0,0,0,0];
        $tenpai=array_values(array_unique(array_filter(array_map('intval',$tenpaiSeats),static fn(int $s):bool=>$s>=0&&$s<$seats)));
        $n=count($tenpai);
        if($n===0||$n===$seats)return $out;
        $gain=intdiv(3000,$n);
        $loss=intdiv(3000,$seats-$n);
        for($s=0;$s<$seats;$s++)$out[$s]=in_array($s,$tenpai,true)?$gain:-$loss;
        return $out;
    }

    /**
     * Detect tenpai seats and their waits from serialized table hands.
     *
     * @param array<int,list<int>> $hands
     * @param array<int,list<array<string,mixed>>> $melds
     * @return array{tenpai:list<int>,waits:array<int,list<int>>}
     */
    public static function tenpaiStatus(array $hands,array $melds,int $seats,int $taku): array
    {
        $tenpai=[];$waits=[];
        for($s=0;$s<$seats;$s++){
            $hand=$hands[$s]??[];$counts=Mahjong::countsOf($hand);
            if(array_sum($counts)%3!==1)continue;
            $opened=count($melds[$s]??[]);
            if(Mahjong::shanten($counts,$opened,$taku)!==0)continue;
            $w=Mahjong::waitsOf($counts,$opened,$taku);
            if(!$w)continue;
            $tenpai[]=$s;$waits[$s]=$w;
        }
        return ['tenpai'=>$tenpai,'waits'=>$waits];
    }

    /**
     * Apply exhaustive-draw deltas to a four-seat score vector.
     *
     * @param list<int> $scores
     * @param list<int> $tenpaiSeats
     * @return array{scores:list<int>,deltas:list<int>}
     */
    public static function applyExhaustiveDraw(array $scores,int $seats,array $tenpaiSeats): array
    {
        $scores=array_values(array_pad(array_map('intval',$scores),4,0));
        $deltas=self::exhaustiveDrawDeltas($seats,$tenpaiSeats);
        for($i=0;$i<4;$i++)$scores[$i]+=$deltas[$i];
        return ['scores'=>$scores,'deltas'=>$deltas];
    }

    /**
     * Decide round continuation after a hand.
     *
     * @param list<int> $winners
     * @param list<int> $tenpaiSeats
     * @return array{renchan:bool,advance:bool,honba:int,game_over:bool}
     */
    public static function nextState(
        int $oya,
        array $winners,
        array $tenpaiSeats,
        bool $draw,
        bool $abortive,
        int $honba,
        int $kyokuIndex,
        int $totalKyoku,
        array $scores,
        int $seats
    ): array {
        if($draw)$renchan=$abortive||in_array($oya,$tenpaiSeats,true);
        else $renchan=in_array($oya,$winners,true);

        // Python reference increments honba on every draw even if the dealer is
        // not tenpai; otherwise it increments only on dealer continuation.
        $nextHonba=($renchan||$draw)?$honba+1:0;
        $advance=!$renchan;
        $last=$kyokuIndex >= $totalKyoku-1;
        $busted=false;
        for($i=0;$i<$seats;$i++)if(($scores[$i]??0)<0){$busted=true;break;}
        $gameOver=$busted||($last&&!$renchan);
        return ['renchan'=>$renchan,'advance'=>$advance,'honba'=>$nextHonba,'game_over'=>$gameOver];
    }
}
