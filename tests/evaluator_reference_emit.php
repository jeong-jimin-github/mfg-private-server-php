<?php

declare(strict_types=1);

require_once __DIR__.'/../src/Mahjong/Mahjong.php';
require_once __DIR__.'/../src/Mahjong/ScoreMath.php';
require_once __DIR__.'/../src/Mahjong/HandEvaluator.php';

use Mfg\Mahjong\HandEvaluator;
use Mfg\Mahjong\Mahjong;

function xr_idx(array $pai):array{return array_map([Mahjong::class,'paiToIdx'],$pai);}

$cases=[
    ['name'=>'pinfu_riichi','hand'=>[1,2,3,4,5,6,21,22,23,24,25,26,17,17],'win'=>26,'tsumo'=>true,'seat'=>1,'round'=>0,'riichi'=>true],
    ['name'=>'double_riichi_ippatsu','hand'=>[1,2,3,4,5,6,11,12,13,21,22,23,25,25],'win'=>23,'tsumo'=>false,'seat'=>2,'round'=>0,'riichi'=>true,'double'=>true,'ippatsu'=>true],
    ['name'=>'ittsuu','hand'=>[1,2,3,4,5,6,7,8,9,21,22,23,15,15],'win'=>23,'tsumo'=>true,'seat'=>1,'round'=>0],
    ['name'=>'sanshoku','hand'=>[1,2,3,11,12,13,21,22,23,4,5,6,25,25],'win'=>6,'tsumo'=>true,'seat'=>1,'round'=>0],
    ['name'=>'chiitoi','hand'=>[1,1,2,2,3,3,11,11,12,12,21,21,31,31],'win'=>31,'tsumo'=>true,'seat'=>1,'round'=>0],
    ['name'=>'kokushi','hand'=>[1,9,11,19,21,29,31,32,33,34,35,36,37,1],'win'=>1,'tsumo'=>true,'seat'=>1,'round'=>0],
    ['name'=>'daisangen','hand'=>[35,35,35,36,36,36,37,37,37,1,2,3,31,31],'win'=>3,'tsumo'=>true,'seat'=>1,'round'=>0],
    ['name'=>'honroutou_toitoi','hand'=>[1,1,1,9,9,9,31,31,31,35,35,35,37,37],'win'=>37,'tsumo'=>true,'seat'=>1,'round'=>0],
    ['name'=>'chinitsu','hand'=>[1,2,3,4,5,6,6,7,8,7,8,9,5,5],'win'=>9,'tsumo'=>true,'seat'=>1,'round'=>0],
    ['name'=>'dora_ura','hand'=>[1,2,3,4,5,6,21,22,23,24,25,26,17,17],'win'=>26,'tsumo'=>true,'seat'=>1,'round'=>0,'riichi'=>true,'dora'=>[5],'ura'=>[16]],
    ['name'=>'open_tanyao','hand'=>[2,3,4,14,15,16,24,25,26,17,17],'melds'=>[['kind'=>'chi','tiles'=>[12,13,14]]],'win'=>26,'tsumo'=>false,'seat'=>1,'round'=>0],
    ['name'=>'open_haku','hand'=>[1,2,3,14,15,16,27,28,29,5,5],'melds'=>[['kind'=>'pon','tiles'=>[35,35,35]]],'win'=>29,'tsumo'=>false,'seat'=>1,'round'=>0],
    ['name'=>'ankan_menzen','hand'=>[1,2,3,4,5,6,27,28,29,15,15],'melds'=>[['kind'=>'ankan','tiles'=>[35,35,35,35]]],'win'=>29,'tsumo'=>true,'seat'=>1,'round'=>0],
    ['name'=>'open_toitoi','hand'=>[1,1,1,9,9,9,11,11,11,25,25],'melds'=>[['kind'=>'pon','tiles'=>[37,37,37]]],'win'=>25,'tsumo'=>false,'seat'=>1,'round'=>0],
];

$out=[];
foreach($cases as $c){
    $melds=[];
    foreach($c['melds']??[] as $m)$melds[]=['kind'=>$m['kind'],'tiles'=>xr_idx($m['tiles'])];
    $r=HandEvaluator::evaluate(
        xr_idx($c['hand']),$melds,Mahjong::paiToIdx($c['win']),(bool)$c['tsumo'],
        (int)$c['seat'],(int)$c['round'],(bool)($c['riichi']??false),(bool)($c['double']??false),(bool)($c['ippatsu']??false),
        xr_idx($c['dora']??[]),xr_idx($c['ura']??[]),Mahjong::TONPU
    );
    if($r===null)throw new RuntimeException('PHP evaluator rejected '.$c['name']);
    $out[]=['case'=>$c,'result'=>[
        'han'=>$r['han'],'fu'=>$r['fu'],'rank'=>$r['rank'],'dora'=>$r['dora'],'yakuman'=>$r['yakuman']
    ]];
}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
