<?php

declare(strict_types=1);

namespace Mfg\Mahjong;

final class CpuAi
{
    /** @param array<string,mixed> $s @return array{0:int,1:bool} */
    public static function chooseDiscard(array $s,int $seat):array
    {
        $hand=array_values(array_map('intval',$s['hands'][$seat]??[]));
        $drawn=$s['drawn'][$seat]??null;
        if(!empty($s['riichi'][$seat])) return [(int)($drawn??end($hand)),false];

        $opened=count($s['melds'][$seat]??[]);$taku=(int)$s['taku'];
        $visible=self::visibleCounts($s,$seat);$threat=false;
        for($i=0;$i<(int)$s['seats'];$i++)if($i!==$seat&&!empty($s['riichi'][$i])){$threat=true;break;}
        $dora=[];foreach(array_slice($s['dora_ind']??[],0,(int)($s['dora_open']??1)) as $d)$dora[Mahjong::doraFromIndicator((int)$d,$taku)]=true;
        $yakuhai=array_fill_keys(self::yakuhaiKinds($s,$seat),true);
        $counts=Mahjong::countsOf($hand);

        $bestTile=$hand?end($hand):0;$bestScore=null;$bestSh=99;
        foreach(array_values(array_unique($hand)) as $tile){
            $rest=$hand;$k=array_search($tile,$rest,true);if($k===false)continue;unset($rest[$k]);$rest=array_values($rest);
            $c=Mahjong::countsOf($rest);$sh=Mahjong::shanten($c,$opened,$taku);$uk=self::ukeireCount($c,$opened,$taku,$visible);
            $danger=self::danger($s,$seat,$tile);$keep=0;
            if(isset($yakuhai[$tile])&&($counts[$tile]??0)>=2)$keep+=3;
            if(isset($dora[$tile]))$keep+=3;
            $score=$sh*120.0-$uk*1.5+$keep*6.0;
            if($threat)$score+=$danger*($sh>=2?3.0:1.2);
            $score+=($tile%17)*0.0001; // deterministic tie-breaker
            if($bestScore===null||$score<$bestScore){$bestScore=$score;$bestTile=$tile;$bestSh=$sh;}
        }
        $declare=false;
        if($opened===0&&$bestSh===0&&(int)($s['scores'][$seat]??0)>=1000&&count($s['wall']??[])>=4){
            $rest=$hand;$k=array_search($bestTile,$rest,true);if($k!==false)unset($rest[$k]);
            if(Mahjong::waitsOf(Mahjong::countsOf(array_values($rest)),0,$taku))$declare=true;
        }
        return [(int)$bestTile,$declare];
    }

    /** @param array<string,mixed> $s */
    public static function danger(array $s,int $seat,int $tile):int
    {
        $risk=0;$log=$s['discard_log']??[];$riichiAt=$s['riichi_at']??[-1,-1,-1,-1];
        for($i=0;$i<(int)$s['seats'];$i++){
            if($i===$seat||empty($s['riichi'][$i]))continue;
            if(in_array($tile,$s['discards'][$i]??[],true))continue;
            $start=max(0,(int)($riichiAt[$i]??-1));$safe=false;
            for($j=$start;$j<count($log);$j++)if((int)($log[$j][1]??-1)===$tile){$safe=true;break;}
            if($safe)continue;
            $risk+=Mahjong::isYaochu($tile)?4:10;
            if(!Mahjong::isHonor($tile)&&$tile%9>=2&&$tile%9<=6)$risk+=4;
        }
        return $risk;
    }

    /** @param array<string,mixed> $s */
    public static function wantsPon(array $s,int $seat,int $tile):bool
    {
        if(!empty($s['riichi'][$seat]))return false;$opened=count($s['melds'][$seat]??[]);$taku=(int)$s['taku'];
        $hand=$s['hands'][$seat]??[];$rest=$hand;for($i=0;$i<2;$i++){$k=array_search($tile,$rest,true);if($k!==false)unset($rest[$k]);}$rest=array_values($rest);
        $before=Mahjong::shanten(Mahjong::countsOf($hand),$opened,$taku);$after=Mahjong::shanten(Mahjong::countsOf($rest),$opened+1,$taku);
        if($after>$before)return false;if(in_array($tile,self::yakuhaiKinds($s,$seat),true))return true;if($after>=$before)return false;
        $all=array_merge($rest,[$tile,$tile,$tile]);foreach($s['melds'][$seat]??[] as $m)$all=array_merge($all,$m['tiles']??[]);
        if(!array_filter($all,fn($t)=>Mahjong::isYaochu((int)$t)))return true;
        if($opened>0&&!array_filter($s['melds'][$seat]??[],fn($m)=>(string)($m['kind']??'')==='chi'))return true;
        return false;
    }

    /** @param array<string,mixed> $s @param list<list<int>> $opts @return list<int>|null */
    public static function pickChi(array $s,int $seat,int $tile,array $opts):?array
    {
        if(!$opts||!empty($s['riichi'][$seat]))return null;$opened=count($s['melds'][$seat]??[]);$taku=(int)$s['taku'];$hand=$s['hands'][$seat]??[];
        $before=Mahjong::shanten(Mahjong::countsOf($hand),$opened,$taku);$best=null;
        foreach($opts as $o){$rest=$hand;$ok=true;foreach($o as $t){$k=array_search($t,$rest,true);if($k===false){$ok=false;break;}unset($rest[$k]);$rest=array_values($rest);}if(!$ok)continue;
            $after=Mahjong::shanten(Mahjong::countsOf($rest),$opened+1,$taku);if($after>=$before)continue;
            $all=array_merge($rest,$o,[$tile]);foreach($s['melds'][$seat]??[] as $m)$all=array_merge($all,$m['tiles']??[]);
            if(array_filter($all,fn($t)=>Mahjong::isYaochu((int)$t)))continue;
            if($best===null||$after<$best[0])$best=[$after,$o];
        }
        return $best[1]??null;
    }

    /** @param array<string,mixed> $s */
    public static function wantsKan(array $s,int $seat,int $tile,int $type):bool
    {
        if(!empty($s['riichi'][$seat]))return $type===1;$opened=count($s['melds'][$seat]??[]);$taku=(int)$s['taku'];$hand=$s['hands'][$seat]??[];
        $before=Mahjong::shanten(Mahjong::countsOf($hand),$opened,$taku);$rest=$hand;$drop=$type===1?4:1;
        for($i=0;$i<$drop;$i++){$k=array_search($tile,$rest,true);if($k!==false){unset($rest[$k]);$rest=array_values($rest);}}
        $after=Mahjong::shanten(Mahjong::countsOf($rest),$opened+1,$taku);return $after<=$before;
    }

    /** @param array<string,mixed> $s @return list<int> */
    private static function yakuhaiKinds(array $s,int $seat):array
    {
        $seats=(int)$s['seats'];$oya=(int)$s['kyoku_index']%$seats;$ba=(int)$s['kyoku_index']<$seats?0:1;$wind=($seat-$oya+$seats)%$seats;
        return [Mahjong::HON+4,Mahjong::HON+5,Mahjong::HON+6,Mahjong::HON+$ba,Mahjong::HON+$wind];
    }

    /** @param list<int> $counts @param list<int> $visible */
    private static function ukeireCount(array $counts,int $opened,int $taku,array $visible):int
    {
        $sum=0;foreach(Mahjong::ukeire($counts,$opened,$taku) as $t)$sum+=max(0,4-(int)($visible[$t]??0));return $sum;
    }

    /** @param array<string,mixed> $s @return list<int> */
    private static function visibleCounts(array $s,int $seat):array
    {
        $c=array_fill(0,34,0);foreach($s['hands'][$seat]??[] as $t)$c[(int)$t]++;
        for($i=0;$i<(int)$s['seats'];$i++){foreach($s['discards'][$i]??[] as $t)$c[(int)$t]++;foreach($s['melds'][$i]??[] as $m)foreach($m['tiles']??[] as $t)$c[(int)$t]++;}
        foreach(array_slice($s['dora_ind']??[],0,(int)($s['dora_open']??1)) as $t)$c[(int)$t]++;
        return array_map(fn($n)=>min(4,(int)$n),$c);
    }
}
