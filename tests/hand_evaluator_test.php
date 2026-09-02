<?php

declare(strict_types=1);

require_once __DIR__.'/../src/Mahjong/Mahjong.php';
require_once __DIR__.'/../src/Mahjong/ScoreMath.php';
require_once __DIR__.'/../src/Mahjong/HandEvaluator.php';

use Mfg\Mahjong\HandEvaluator;
use Mfg\Mahjong\Mahjong;

function ev(array $pai,int $win,bool $tsumo=true,bool $riichi=false): array {
    $hand=array_map([Mahjong::class,'paiToIdx'],$pai);
    $r=HandEvaluator::evaluate($hand,[],Mahjong::paiToIdx($win),$tsumo,1,0,$riichi,false,false,[],[],Mahjong::TONPU);
    if($r===null) throw new RuntimeException('expected winning hand');
    return $r;
}
function has(array $r,string $name): void { if(!in_array($name,$r['yaku'],true)) throw new RuntimeException("missing $name: ".json_encode($r)); }

// Pinfu: 123m 456m 123p 456p 77s, win 6p.
$r=ev([1,2,3,4,5,6,21,22,23,24,25,26,17,17],26,true,true);
has($r,'Pinfu');has($r,'Richi');

// Ittsuu: 123m 456m 789m 123p 55s.
$r=ev([1,2,3,4,5,6,7,8,9,21,22,23,15,15],23,true,false);
has($r,'Ikkitsukan');

// Sanshoku doujun: 123m 123s 123p 456m 55p.
$r=ev([1,2,3,11,12,13,21,22,23,4,5,6,25,25],6,true,false);
has($r,'Sansyokudojun');

// Chiitoitsu.
$r=ev([1,1,2,2,3,3,11,11,12,12,21,21,31,31],31,true,false);
has($r,'Chitoitsu');
if($r['fu']!==25) throw new RuntimeException('chiitoi fu');

// Kokushi.
$r=ev([1,9,11,19,21,29,31,32,33,34,35,36,37,1],1,true,false);
has($r,'Kokushi13');
if(($r['yakuman']??0)<1) throw new RuntimeException('kokushi yakuman');

// Daisangen + yakuhai shape.
$r=ev([35,35,35,36,36,36,37,37,37,1,2,3,31,31],3,true,false);
has($r,'Daisangen');
if(($r['yakuman']??0)<1) throw new RuntimeException('daisangen yakuman');

// Honroutou / toitoi style all terminals/honours.
$r=ev([1,1,1,9,9,9,31,31,31,35,35,35,37,37],37,true,false);
has($r,'Honroto');has($r,'Toitoiho');

echo "hand evaluator OK\n";
