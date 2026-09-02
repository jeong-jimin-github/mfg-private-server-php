<?php

declare(strict_types=1);

namespace Mfg\Mahjong;

final class Mahjong
{
    public const MAN = 0;
    public const SOU = 9;
    public const PIN = 18;
    public const HON = 27;

    public const TONPU = 0;
    public const HANCHAN = 1;
    public const SANMA = 2;
    public const NIMA = 3;

    public const SEATS_OF = [0=>4,1=>4,2=>3,3=>2];
    public const KYOKU_COUNT = [0=>4,1=>8,2=>3,3=>2];
    public const START_SCORE = [0=>25000,1=>25000,2=>35000,3=>35000];

    /** @var array<string,array<int,array{0:int,1:int,2:int}>> */
    private static array $groupCache = [];
    /** @var array<string,int> */
    private static array $shantenCache = [];

    public static function paiNorm(int $pai): int { return $pai >= 64 ? $pai - 64 : $pai; }

    public static function paiToIdx(int $pai): int
    {
        $p = self::paiNorm($pai);
        if ($p >= 1 && $p <= 9) return self::MAN + $p - 1;
        if ($p >= 11 && $p <= 19) return self::SOU + $p - 11;
        if ($p >= 21 && $p <= 29) return self::PIN + $p - 21;
        if ($p >= 31 && $p <= 37) return self::HON + $p - 31;
        return -1;
    }

    public static function idxToPai(int $idx): int
    {
        if ($idx < self::SOU) return 1 + $idx;
        if ($idx < self::PIN) return 11 + ($idx - self::SOU);
        if ($idx < self::HON) return 21 + ($idx - self::PIN);
        return 31 + ($idx - self::HON);
    }

    public static function isHonor(int $idx): bool { return $idx >= self::HON; }
    public static function isTerminal(int $idx): bool { return !self::isHonor($idx) && in_array($idx % 9, [0,8], true); }
    public static function isYaochu(int $idx): bool { return self::isHonor($idx) || self::isTerminal($idx); }

    /** @return list<int> */
    public static function yaochuIdx(): array
    {
        $out=[]; for($i=0;$i<34;$i++) if(self::isYaochu($i)) $out[]=$i; return $out;
    }

    /** @return list<int> */
    public static function liveKinds(int $taku): array
    {
        $out=[];
        for($i=0;$i<34;$i++) {
            if ($taku === self::NIMA && ($i < self::SOU || $i === self::HON+2 || $i === self::HON+3)) continue;
            if ($taku === self::SANMA && $i >= self::MAN+1 && $i <= self::MAN+7) continue;
            $out[]=$i;
        }
        return $out;
    }

    /** @return list<int> */
    public static function buildWall(int $taku, ?int $seed = null): array
    {
        $tiles=[];
        foreach(self::liveKinds($taku) as $idx) for($i=0;$i<4;$i++) $tiles[]=$idx;
        if ($seed !== null) mt_srand($seed);
        shuffle($tiles);
        return $tiles;
    }

    public static function doraFromIndicator(int $idx, int $taku): int
    {
        if (self::isHonor($idx)) {
            $n=$idx-self::HON;
            if ($n<=3) {
                if ($taku===self::NIMA) return self::HON + ($n===1 ? 0 : 1);
                return self::HON + (($n+1)%4);
            }
            return self::HON + 4 + (($n-4+1)%3);
        }
        $suit=intdiv($idx,9); $num=$idx%9;
        if ($suit===0 && $taku===self::SANMA) return self::MAN + ($num===0 ? 8 : 0);
        return $suit*9 + (($num+1)%9);
    }

    /** @param list<int> $tiles @return list<int> */
    public static function countsOf(array $tiles): array
    {
        $c=array_fill(0,34,0); foreach($tiles as $t) if($t>=0 && $t<34) $c[$t]++; return $c;
    }

    /** @param list<int> $counts */
    public static function shantenStandard(array $counts, int $openMelds=0): int
    {
        $key=implode(',',$counts).'|'.$openMelds;
        if(isset(self::$shantenCache[$key])) return self::$shantenCache[$key];
        $groups=[
            self::groupOptions(array_slice($counts,0,9),true),
            self::groupOptions(array_slice($counts,9,9),true),
            self::groupOptions(array_slice($counts,18,9),true),
            self::groupOptions(array_slice($counts,27,7),false),
        ];
        $cur=[[0,0,0]];
        foreach($groups as $opts) {
            $nxt=[];
            foreach($cur as [$m,$p,$pr]) foreach($opts as [$m2,$p2,$pr2]) {
                if($pr && $pr2) continue;
                $nxt[]= [min(4,$m+$m2),min(4,$p+$p2),($pr||$pr2)?1:0];
            }
            $cur=self::pareto($nxt);
        }
        $best=99;
        foreach($cur as [$m,$p,$pr]) {
            $tm=min(4,$m+$openMelds); $pp=$p; if($tm+$pp>4) $pp=4-$tm;
            $v=(4-$tm)*2-$pp-($pr?1:0); if($v<$best)$best=$v;
        }
        return self::$shantenCache[$key]=$best;
    }

    /** @param list<int> $counts */
    public static function shantenChiitoi(array $counts): int
    {
        $pairs=0;$kinds=0; foreach($counts as $n){if($n>=2)$pairs++; if($n>=1)$kinds++;}
        return 6-$pairs+max(0,7-$kinds);
    }

    /** @param list<int> $counts */
    public static function shantenKokushi(array $counts): int
    {
        $kinds=0;$pair=false; foreach(self::yaochuIdx() as $i){if(($counts[$i]??0)>=1)$kinds++; if(($counts[$i]??0)>=2)$pair=true;}
        return 13-$kinds-($pair?1:0);
    }

    /** @param list<int> $counts */
    public static function shanten(array $counts,int $openMelds=0,int $taku=self::TONPU): int
    {
        $best=self::shantenStandard($counts,$openMelds);
        if($openMelds===0){$best=min($best,self::shantenChiitoi($counts)); if($taku!==self::NIMA)$best=min($best,self::shantenKokushi($counts));}
        return $best;
    }

    /** @param list<int> $counts */
    public static function isAgari(array $counts,int $openMelds=0,int $taku=self::TONPU): bool
    { return self::shanten($counts,$openMelds,$taku)<0; }

    /** @param list<int> $counts @return list<int> */
    public static function waitsOf(array $counts,int $openMelds=0,int $taku=self::TONPU): array
    {
        $out=[]; foreach(self::liveKinds($taku) as $i){if(($counts[$i]??0)>=4)continue;$counts[$i]++;if(self::isAgari($counts,$openMelds,$taku))$out[]=$i;$counts[$i]--;}
        return $out;
    }

    /** @param list<int> $counts @return list<int> */
    public static function ukeire(array $counts,int $openMelds=0,int $taku=self::TONPU): array
    {
        $base=self::shanten($counts,$openMelds,$taku);$out=[];
        foreach(self::liveKinds($taku) as $i){if(($counts[$i]??0)>=4)continue;$counts[$i]++;if(self::shanten($counts,$openMelds,$taku)<$base)$out[]=$i;$counts[$i]--;}
        return $out;
    }

    /** @param list<int> $c @return array<int,array{0:int,1:int,2:int}> */
    private static function groupOptions(array $c,bool $runs): array
    {
        $key=implode(',',$c).'|'.($runs?1:0); if(isset(self::$groupCache[$key]))return self::$groupCache[$key];
        $out=[]; self::enumGroup($c,0,0,0,false,$out,$runs); return self::$groupCache[$key]=self::pareto($out);
    }

    /** @param list<int> $c @param array<int,array{0:int,1:int,2:int}> $out */
    private static function enumGroup(array $c,int $i,int $melds,int $partials,bool $pair,array &$out,bool $runs): void
    {
        $n=count($c); while($i<$n && $c[$i]===0)$i++;
        if($i>=$n || $melds+$partials>=5){$out[]=[$melds,$partials,$pair?1:0];return;}
        if($c[$i]>=3){$c[$i]-=3;self::enumGroup($c,$i,$melds+1,$partials,$pair,$out,$runs);$c[$i]+=3;}
        if($runs && $i+2<$n && $c[$i+1]>0 && $c[$i+2]>0){$c[$i]--;$c[$i+1]--;$c[$i+2]--;self::enumGroup($c,$i,$melds+1,$partials,$pair,$out,$runs);$c[$i]++;$c[$i+1]++;$c[$i+2]++;}
        if($c[$i]>=2){
            if(!$pair){$c[$i]-=2;self::enumGroup($c,$i,$melds,$partials,true,$out,$runs);$c[$i]+=2;}
            $c[$i]-=2;self::enumGroup($c,$i,$melds,$partials+1,$pair,$out,$runs);$c[$i]+=2;
        }
        if($runs && $i+1<$n && $c[$i+1]>0){$c[$i]--;$c[$i+1]--;self::enumGroup($c,$i,$melds,$partials+1,$pair,$out,$runs);$c[$i]++;$c[$i+1]++;}
        if($runs && $i+2<$n && $c[$i+2]>0){$c[$i]--;$c[$i+2]--;self::enumGroup($c,$i,$melds,$partials+1,$pair,$out,$runs);$c[$i]++;$c[$i+2]++;}
        $saved=$c[$i];$c[$i]=0;self::enumGroup($c,$i+1,$melds,$partials,$pair,$out,$runs);$c[$i]=$saved;
    }

    /** @param array<int,array{0:int,1:int,2:int}> $options @return array<int,array{0:int,1:int,2:int}> */
    private static function pareto(array $options): array
    {
        usort($options,static fn($a,$b)=>($b[0]<=>$a[0])?:($b[1]<=>$a[1])?:($b[2]<=>$a[2]));
        $keep=[];
        foreach($options as $o){$dom=false;foreach($keep as $k){if($k[0]>=$o[0]&&$k[1]>=$o[1]&&$k[2]>=$o[2]){$dom=true;break;}}if(!$dom)$keep[]=$o;}
        return $keep;
    }
}
