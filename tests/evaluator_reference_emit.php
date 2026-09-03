<?php

declare(strict_types=1);

require_once __DIR__.'/../src/Mahjong/Mahjong.php';
require_once __DIR__.'/../src/Mahjong/ScoreMath.php';
require_once __DIR__.'/../src/Mahjong/HandEvaluator.php';

use Mfg\Mahjong\HandEvaluator;
use Mfg\Mahjong\Mahjong;

function xr_idx(array $pai):array{return array_map([Mahjong::class,'paiToIdx'],$pai);}
function xr_pai(array $idx):array{return array_map([Mahjong::class,'idxToPai'],$idx);}
function xr_next(int &$state):int{$state=(int)((1103515245*$state+12345)&0x7fffffff);return $state;}

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

// Deterministic closed winning-hand corpus. Riichi guarantees a legal yaku so
// both scorers can compare decomposition choice, wait fu and pattern yaku.
for($sample=0;$sample<256;$sample++){
    $state=(0x5A17C9+$sample*0x10231)&0x7fffffff;
    $counts=array_fill(0,34,0);$idx=[];
    $pair=xr_next($state)%34;$counts[$pair]=2;$idx=[$pair,$pair];
    for($set=0;$set<4;$set++){
        for($attempt=0;$attempt<200;$attempt++){
            $r=xr_next($state);
            if(($r&1)===0){$b=($r>>1)%34;$tiles=[$b,$b,$b];}
            else{$suit=($r>>1)%3;$b=$suit*9+(($r>>8)%7);$tiles=[$b,$b+1,$b+2];}
            $ok=true;$need=[];foreach($tiles as $t){$need[$t]=($need[$t]??0)+1;if($counts[$t]+$need[$t]>4){$ok=false;break;}}
            if(!$ok)continue;
            foreach($tiles as $t){$counts[$t]++;$idx[]=$t;}
            break;
        }
    }
    if(count($idx)!==14)throw new RuntimeException('generated hand failed '.$sample);
    sort($idx);$pick=xr_next($state)%14;$win=$idx[$pick];
    $dora=xr_next($state)%34;$ura=xr_next($state)%34;
    $cases[]=[
        'name'=>'generated_'.$sample,'hand'=>xr_pai($idx),'win'=>Mahjong::idxToPai($win),
        'tsumo'=>(bool)($sample&1),'seat'=>$sample%4,'round'=>intdiv($sample,4)%2,
        'riichi'=>true,'dora'=>[Mahjong::idxToPai($dora)],'ura'=>[Mahjong::idxToPai($ura)],
    ];
}

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
