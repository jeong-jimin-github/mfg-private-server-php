<?php

declare(strict_types=1);

namespace Mfg\Mahjong;

final class ScoreMath
{
    public static function baseScore(int $han,int $fu): int
    {
        if($han>=13)return 8000;
        if($han>=11)return 6000;
        if($han>=8)return 4000;
        if($han>=6)return 3000;
        if($han>=5)return 2000;
        $v=$fu << ($han+2);
        return $v>=1920?2000:$v;
    }

    public static function hanRank(int $han,int $fu,int $yakuman=0): int
    {
        if($yakuman>0)return 9+$yakuman-1;
        if($han>=13)return 9;
        if($han>=11)return 8;
        if($han>=8)return 7;
        if($han>=6)return 6;
        if($han>=5)return 5;
        if(self::baseScore($han,$fu)>=2000)return 5;
        return $han;
    }

    public static function baseScoreRank(int $rank,int $fu): int
    {
        if($rank>=9)return 8000*($rank-9+1);
        if($rank===8)return 6000;
        if($rank===7)return 4000;
        if($rank===6)return 3000;
        if($rank===5)return 2000;
        $v=$fu << ($rank+2);
        return $v>=1920?2000:$v;
    }

    /** @return array{total:int,ko:int,oya:int} */
    public static function payments(int $taku,int $rank,int $fu,bool $isOya,bool $isTsumo): array
    {
        $b=self::baseScoreRank($rank,$fu);
        $n4=self::round100(($isOya?6:4)*$b);
        if($taku===Mahjong::NIMA)return ['total'=>$n4,'ko'=>$n4,'oya'=>$n4];
        if($taku===Mahjong::SANMA){$ko=self::round100(intdiv($n4,2));$oya=$ko;$total=$isTsumo?($isOya?2*$ko:$oya+$ko):$n4;return ['total'=>$total,'ko'=>$ko,'oya'=>$oya];}
        $ko=self::round100(($isOya?2:1)*$b);$oya=self::round100(2*$b);$total=$isTsumo?($isOya?3*$ko:$oya+2*$ko):$n4;return ['total'=>$total,'ko'=>$ko,'oya'=>$oya];
    }

    private static function round100(int $v): int{return intdiv($v,100)*100+($v%100?100:0);}
}
