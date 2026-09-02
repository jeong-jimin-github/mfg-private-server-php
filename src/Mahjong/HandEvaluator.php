<?php

declare(strict_types=1);

namespace Mfg\Mahjong;

final class HandEvaluator
{
    private const KOTSU = 0;
    private const SHUNTSU = 1;

    /**
     * @param list<int> $hand closed tiles, including winning tile
     * @param list<array<string,mixed>> $melds
     * @param list<int> $doraIndicators
     * @param list<int> $uraIndicators
     * @return array{han:int,fu:int,rank:int,dora:int,yaku:list<string>,yakuman:int}|null
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
        int $taku,
        bool $allowNoYaku = false
    ): ?array {
        $openMelds = count($melds);
        $counts = Mahjong::countsOf($hand);
        if (!Mahjong::isAgari($counts, $openMelds, $taku)) return null;

        $menzen = self::isMenzen($melds);
        $common = self::commonYaku($hand,$melds,$winTile,$isTsumo,$seatWind,$roundWind,$riichi,$doubleRiichi,$ippatsu,$taku);
        $dora = self::doraCount(self::allTiles($hand,$melds),$doraIndicators,$riichi?$uraIndicators:[],$taku);

        $kokushi = self::kokushi($counts,$winTile,$melds);
        if ($kokushi !== null) {
            return self::finish(0,25,$dora,$kokushi['yaku'],$kokushi['yakuman']);
        }

        $best = null;
        if ($openMelds===0 && self::isChiitoi($counts)) {
            $yaku=$common['yaku']; $han=$common['han']; $yakuman=$common['yakuman'];
            $yaku[]='Chitoitsu'; $han+=2;
            $extra=self::globalPatternYaku($hand,$melds,true,$menzen,$winTile);
            $han+=$extra['han'];$yakuman+=$extra['yakuman'];$yaku=array_merge($yaku,$extra['yaku']);
            if ($han>0 || $yakuman>0) $best=self::finish($han,25,$dora,$yaku,$yakuman);
        }

        foreach (self::decompositions($counts) as [$pair,$sets]) {
            $full=$sets;
            foreach($melds as $m){$k=(string)($m['kind']??'');$tiles=$m['tiles']??[];if(!$tiles)continue;$full[]=[($k==='chi'?self::SHUNTSU:self::KOTSU),(int)min($tiles)];}
            if(count($full)!==4)continue;

            $han=$common['han'];$yakuman=$common['yakuman'];$yaku=$common['yaku'];
            $pinfu=self::isPinfu($pair,$sets,$melds,$winTile,$seatWind,$roundWind,$menzen);
            if($pinfu){$han++;$yaku[]='Pinfu';}

            $yh=self::yakuhai($full,$seatWind,$roundWind);$han+=$yh['han'];$yaku=array_merge($yaku,$yh['yaku']);
            $shape=self::shapeYaku($pair,$sets,$full,$melds,$hand,$winTile,$isTsumo,$menzen);
            $han+=$shape['han'];$yakuman+=$shape['yakuman'];$yaku=array_merge($yaku,$shape['yaku']);
            $extra=self::globalPatternYaku($hand,$melds,false,$menzen,$winTile);
            $han+=$extra['han'];$yakuman+=$extra['yakuman'];$yaku=array_merge($yaku,$extra['yaku']);

            if(!$allowNoYaku && $han<=0 && $yakuman<=0)continue;
            $fu=self::fuFor($pair,$sets,$melds,$hand,$winTile,$isTsumo,$seatWind,$roundWind,$pinfu,$menzen);
            $cand=self::finish($han,$fu,$dora,$yaku,$yakuman);
            if($best===null || self::better($cand,$best))$best=$cand;
        }
        return $best;
    }

    private static function finish(int $han,int $fu,int $dora,array $yaku,int $yakuman): array
    {
        $total=$han+$dora;
        $rank=ScoreMath::hanRank($total,$fu,$yakuman);
        return ['han'=>$total,'fu'=>$fu,'rank'=>$rank,'dora'=>$dora,'yaku'=>array_values(array_unique($yaku)),'yakuman'=>$yakuman];
    }
    private static function better(array $a,array $b):bool
    {if($a['yakuman']!==$b['yakuman'])return $a['yakuman']>$b['yakuman'];if($a['han']!==$b['han'])return $a['han']>$b['han'];return $a['fu']>$b['fu'];}

    /** @return array{han:int,yaku:list<string>,yakuman:int} */
    private static function commonYaku(array $hand,array $melds,int $winTile,bool $isTsumo,int $seatWind,int $roundWind,bool $riichi,bool $doubleRiichi,bool $ippatsu,int $taku):array
    {
        $han=0;$yakuman=0;$y=[];$menzen=self::isMenzen($melds);
        if($riichi){if($doubleRiichi){$han+=2;$y[]='DoubleRichi';}else{$han++;$y[]='Richi';}if($ippatsu){$han++;$y[]='Ippatsu';}}
        if($isTsumo&&$menzen){$han++;$y[]='Menzen';}
        $all=self::allTiles($hand,$melds);
        if(self::isTanyao($all)){$han++;$y[]='Tanyao';}
        $kans=0;foreach($melds as $m)if(in_array(($m['kind']??''),['ankan','minkan','kakan'],true))$kans++;
        if($kans===3){$han+=2;$y[]='Sankantsu';}elseif($kans===4){$yakuman++;$y[]='Sukantsu';}
        return ['han'=>$han,'yaku'=>$y,'yakuman'=>$yakuman];
    }

    /** @return array{han:int,yaku:list<string>,yakuman:int} */
    private static function globalPatternYaku(array $hand,array $melds,bool $chiitoi,bool $menzen,int $winTile):array
    {
        $all=self::allTiles($hand,$melds);$c=Mahjong::countsOf($all);$han=0;$ym=0;$y=[];
        $suits=[];$hon=false;for($i=0;$i<34;$i++){if(!$c[$i])continue;if(Mahjong::isHonor($i))$hon=true;else$suits[intdiv($i,9)]=true;}
        if(count($suits)===1&&!$hon){$han+=$menzen?6:5;$y[]=$menzen?'Chiniso':'ChinisoNaki';}
        elseif(count($suits)<=1&&$hon){$han+=$menzen?3:2;$y[]=$menzen?'Honiso':'HonisoNaki';}
        $used=[];for($i=0;$i<34;$i++)if($c[$i])$used[]=$i;
        if($used && !array_filter($used,fn($i)=>Mahjong::isHonor($i)) && !array_filter($used,fn($i)=>!Mahjong::isTerminal($i))){$ym++;$y[]='Chinroto';}
        elseif($used && !array_filter($used,fn($i)=>!Mahjong::isHonor($i))){$ym++;$y[]='Tsuiso';}
        elseif($used && !array_filter($used,fn($i)=>!Mahjong::isYaochu($i))){$han+=2;$y[]='Honroto';}
        $green=[10,11,12,14,16,32];if($used && !array_filter($used,fn($i)=>!in_array($i,$green,true))){$ym++;$y[]='Ryuiso';}
        $drag=[31,32,33];$trip=0;$pair=0;foreach($drag as $d){if($c[$d]>=3)$trip++;elseif($c[$d]===2)$pair++;}if($trip===3){$ym++;$y[]='Daisangen';}elseif($trip===2&&$pair===1){$han+=2;$y[]='Syosangen';}
        $winds=[27,28,29,30];$wt=0;$wp=0;foreach($winds as $d){if($c[$d]>=3)$wt++;elseif($c[$d]===2)$wp++;}if($wt===4){$ym+=2;$y[]='Daisushi';}elseif($wt===3&&$wp===1){$ym++;$y[]='Syosushi';}
        if($menzen&&!$chiitoi&&count($suits)===1&&!$hon){$base=array_key_first($suits)*9;$pat=[3,1,1,1,1,1,1,1,3];$diff=[];for($k=0;$k<9;$k++)$diff[$k]=$c[$base+$k]-$pat[$k];if(min($diff)>=0&&array_sum($diff)===1){$k=array_search(1,$diff,true);if($base+$k===$winTile){$ym+=2;$y[]='TyurenTanki';}else{$ym++;$y[]='Tyuren9';}}}
        return ['han'=>$han,'yaku'=>$y,'yakuman'=>$ym];
    }

    /** @return array{yaku:list<string>,yakuman:int}|null */
    private static function kokushi(array $c,int $winTile,array $melds):?array
    {
        if($melds)return null;$ya=Mahjong::yaochuIdx();foreach(range(0,33) as $i)if(!in_array($i,$ya,true)&&$c[$i])return null;foreach($ya as $i)if($c[$i]<1)return null;
        $minus=$c;$minus[$winTile]--; $thirteen=true;foreach($ya as $i)if($minus[$i]!==1){$thirteen=false;break;}
        return ['yaku'=>[$thirteen?'Kokushi13':'KokushiTanki'],'yakuman'=>$thirteen?2:1];
    }

    /** @param list<int> $counts @return list<array{0:int,1:list<array{0:int,1:int}>}> */
    private static function decompositions(array $counts):array
    {
        $res=[];
        for($p=0;$p<34;$p++){
            if($counts[$p]<2)continue;$c=$counts;$c[$p]-=2;$sets=[];self::decompose($c,0,[],$sets);
            $need=intdiv(array_sum($c),3);foreach($sets as $s)if(count($s)===$need)$res[]=[$p,$s];
        }
        return $res;
    }
    private static function decompose(array $c,int $i,array $cur,array &$out):void
    {
        while($i<34&&$c[$i]===0)$i++;if($i>=34){$out[]=$cur;return;}
        if($c[$i]>=3){$n=$c;$n[$i]-=3;$cc=$cur;$cc[]=[self::KOTSU,$i];self::decompose($n,$i,$cc,$out);}
        if($i<Mahjong::HON&&$i%9<=6&&$c[$i+1]>0&&$c[$i+2]>0){$n=$c;$n[$i]--;$n[$i+1]--;$n[$i+2]--;$cc=$cur;$cc[]=[self::SHUNTSU,$i];self::decompose($n,$i,$cc,$out);}
    }

    /** @return array{han:int,yaku:list<string>} */
    private static function yakuhai(array $full,int $seatWind,int $roundWind):array
    {
        $han=0;$y=[];foreach($full as [$k,$b]){if($k!==self::KOTSU||!Mahjong::isHonor($b))continue;$n=$b-Mahjong::HON;if($n===4){$han++;$y[]='Haku';}elseif($n===5){$han++;$y[]='Hatsu';}elseif($n===6){$han++;$y[]='Tyun';}else{if($n===$roundWind){$han++;$y[]='Bakaze';}if($n===$seatWind){$han++;$y[]='Jikaze';}}}return ['han'=>$han,'yaku'=>$y];
    }

    /** @return array{han:int,yaku:list<string>,yakuman:int} */
    private static function shapeYaku(int $pair,array $sets,array $full,array $melds,array $hand,int $winTile,bool $isTsumo,bool $menzen):array
    {
        $han=0;$ym=0;$y=[];$runs=[];$trips=[];foreach($full as [$k,$b]){if($k===self::SHUNTSU)$runs[]=$b;else$trips[]=$b;}
        if($menzen){$cr=[];foreach($sets as [$k,$b])if($k===self::SHUNTSU)$cr[]=$b;$dup=0;foreach(array_unique($cr) as $b)$dup+=intdiv(count(array_filter($cr,fn($x)=>$x===$b)),2);if($dup>=2){$han+=3;$y[]='Ryanpeko';}elseif($dup===1){$han++;$y[]='Ipeiko';}}
        foreach($runs as $b){$n=$b%9;if(isset($seen[$n]))continue;$seen[$n]=1;if(in_array($n,$runs,true)&&in_array(9+$n,$runs,true)&&in_array(18+$n,$runs,true)){$han+=$menzen?2:1;$y[]=$menzen?'Sansyokudojun':'SansyokudojunNaki';break;}}
        for($s=0;$s<3;$s++)if(in_array($s*9,$runs,true)&&in_array($s*9+3,$runs,true)&&in_array($s*9+6,$runs,true)){$han+=$menzen?2:1;$y[]=$menzen?'Ikkitsukan':'IkkitsukanNaki';break;}
        for($n=0;$n<9;$n++)if(in_array($n,$trips,true)&&in_array(9+$n,$trips,true)&&in_array(18+$n,$trips,true)){$han+=2;$y[]='Sansyokudoko';break;}
        if(count($trips)===4){$han+=2;$y[]='Toitoiho';}
        $closedCounts=Mahjong::countsOf($hand);$ankou=0;foreach($melds as $m)if(($m['kind']??'')==='ankan')$ankou++;foreach($sets as [$k,$b])if($k===self::KOTSU){if(!$isTsumo&&$b===$winTile&&$closedCounts[$b]===3)continue;$ankou++;}if($ankou>=4){$ym+=($pair===$winTile?2:1);$y[]=$pair===$winTile?'SuankoTanki':'Suanko';}elseif($ankou===3){$han+=2;$y[]='Sananko';}
        $touch=true;$hasRun=false;$hasHonor=Mahjong::isHonor($pair);foreach($full as [$k,$b]){if($k===self::SHUNTSU){$hasRun=true;if(!in_array($b%9,[0,6],true))$touch=false;}else{if(!Mahjong::isYaochu($b))$touch=false;if(Mahjong::isHonor($b))$hasHonor=true;}}if(!Mahjong::isYaochu($pair))$touch=false;if($touch&&$hasRun){if($hasHonor){$han+=$menzen?2:1;$y[]=$menzen?'Chanta':'ChantaNaki';}else{$han+=$menzen?3:2;$y[]=$menzen?'Junchan':'JunchanNaki';}}
        return ['han'=>$han,'yaku'=>$y,'yakuman'=>$ym];
    }

    private static function isPinfu(int $pair,array $sets,array $melds,int $winTile,int $seatWind,int $roundWind,bool $menzen):bool
    {
        if(!$menzen)return false;foreach($melds as $m)if(($m['kind']??'')==='ankan')return false;foreach($sets as [$k])if($k===self::KOTSU)return false;if(Mahjong::isHonor($pair)){$n=$pair-Mahjong::HON;if($n>=4||$n===$seatWind||$n===$roundWind)return false;}
        foreach($sets as [$k,$b]){if($k!==self::SHUNTSU)continue;if($b===$winTile&&$b%9!==6)return true;if($b+2===$winTile&&$b%9!==0)return true;}return false;
    }

    private static function fuFor(int $pair,array $sets,array $melds,array $hand,int $winTile,bool $isTsumo,int $seatWind,int $roundWind,bool $pinfu,bool $menzen):int
    {
        if($pinfu)return $isTsumo?20:30;$fu=20;
        foreach($melds as $m){$k=(string)($m['kind']??'');if($k==='chi')continue;$tiles=$m['tiles']??[];if(!$tiles)continue;$b=(int)$tiles[0];$kan=in_array($k,['ankan','minkan','kakan'],true);$v=$kan?8:2;if($k==='ankan')$v*=2;if(Mahjong::isYaochu($b))$v*=2;$fu+=$v;}
        $cc=Mahjong::countsOf($hand);foreach($sets as [$k,$b]){if($k!==self::KOTSU)continue;$concealed=!(!$isTsumo&&$b===$winTile&&$cc[$b]===3);$v=$concealed?4:2;if(Mahjong::isYaochu($b))$v*=2;$fu+=$v;}
        if(Mahjong::isHonor($pair)){$n=$pair-Mahjong::HON;if($n>=4)$fu+=2;else{if($n===$roundWind)$fu+=2;if($n===$seatWind)$fu+=2;}}
        $fu+=self::waitFu($pair,$sets,$winTile);if($isTsumo)$fu+=2;elseif($menzen)$fu+=10;return (int)(ceil($fu/10)*10);
    }
    private static function waitFu(int $pair,array $sets,int $w):int
    {if($pair===$w)return 2;$best=99;foreach($sets as [$k,$b]){if($k===self::KOTSU){if($b===$w)$best=min($best,0);continue;}if($b<=$w&&$w<=$b+2){if($w===$b+1)$best=min($best,2);elseif(($b%9===0&&$w===$b+2)||($b%9===6&&$w===$b))$best=min($best,2);else$best=min($best,0);}}return $best===99?0:$best;}

    private static function isMenzen(array $melds):bool {foreach($melds as $m)if(($m['kind']??'')!=='ankan')return false;return true;}
    private static function isChiitoi(array $counts):bool {$pairs=0;foreach($counts as $n){if($n===2)$pairs++;elseif($n!==0)return false;}return $pairs===7;}
    private static function isTanyao(array $tiles):bool {foreach($tiles as $t)if(Mahjong::isYaochu((int)$t))return false;return true;}
    private static function allTiles(array $hand,array $melds):array {$all=$hand;foreach($melds as $m)foreach(($m['tiles']??[]) as $t)$all[]=(int)$t;return $all;}
    private static function doraCount(array $tiles,array $dora,array $ura,int $taku):int {$c=Mahjong::countsOf($tiles);$n=0;foreach(array_merge($dora,$ura) as $ind)$n+=$c[Mahjong::doraFromIndicator((int)$ind,$taku)]??0;return $n;}
}
