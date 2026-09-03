<?php

declare(strict_types=1);

require_once __DIR__.'/../src/Mahjong/Mahjong.php';
require_once __DIR__.'/../src/Mahjong/CpuAi.php';

use Mfg\Mahjong\CpuAi;
use Mfg\Mahjong\Mahjong;

function cr_state(array $hand,int $kyoku=0):array{
    return [
        'taku'=>Mahjong::TONPU,'seats'=>4,'kyoku_index'=>$kyoku,
        'hands'=>[$hand,[],[],[]],
        'melds'=>[[],[],[],[]],
        'discards'=>[[],[],[],[]],
        'discard_log'=>[],
        'riichi'=>[false,false,false,false],
        'riichi_at'=>[-1,-1,-1,-1],
        'drawn'=>[null,null,null,null],
        'scores'=>[25000,25000,25000,25000],
        'wall'=>array_fill(0,30,0),
        'dora_ind'=>[0,9,18,27,31],'dora_open'=>1,
    ];
}

$cases=[];

$s=cr_state([1,2,3,4,5,6,9,10,11,18,19,20,31]);
$cases[]=['name'=>'danger_none','op'=>'danger','state'=>$s,'seat'=>0,'tile'=>4];
$s['riichi'][1]=true;$s['riichi_at'][1]=0;
$cases[]=['name'=>'danger_central','op'=>'danger','state'=>$s,'seat'=>0,'tile'=>4];
$cases[]=['name'=>'danger_terminal','op'=>'danger','state'=>$s,'seat'=>0,'tile'=>0];
$s['discards'][1]=[4];
$cases[]=['name'=>'danger_genbutsu','op'=>'danger','state'=>$s,'seat'=>0,'tile'=>4];
$s['discards'][1]=[];$s['discard_log']=[[2,7],[1,4]];
$cases[]=['name'=>'danger_post_riichi_log','op'=>'danger','state'=>$s,'seat'=>0,'tile'=>4];
$s['discard_log']=[];$s['riichi'][2]=true;$s['riichi_at'][2]=0;
$cases[]=['name'=>'danger_two_riichi','op'=>'danger','state'=>$s,'seat'=>0,'tile'=>4];

// Pon decisions: yakuhai, tanyao improvement, riichi rejection and an opened toitoi path.
$s=cr_state([31,31,0,1,2,3,4,5,10,11,12,20,20]);
$cases[]=['name'=>'pon_round_wind','op'=>'pon','state'=>$s,'seat'=>0,'tile'=>31];
$s=cr_state([4,4,1,2,3,10,11,12,19,20,21,22,23]);
$cases[]=['name'=>'pon_simple','op'=>'pon','state'=>$s,'seat'=>0,'tile'=>4];
$s['riichi'][0]=true;
$cases[]=['name'=>'pon_riichi_reject','op'=>'pon','state'=>$s,'seat'=>0,'tile'=>4];
$s=cr_state([4,4,0,0,0,9,9,9,18,18,18,22,22]);
$s['melds'][0]=[['kind'=>'pon','tiles'=>[27,27,27]]];
$cases[]=['name'=>'pon_open_toitoi','op'=>'pon','state'=>$s,'seat'=>0,'tile'=>4];

// Chi decisions with multiple legal options, riichi rejection, and terminal contamination.
$s=cr_state([2,3,4,5,6,10,11,12,13,14,20,21,22]);
$cases[]=['name'=>'chi_simple','op'=>'chi','state'=>$s,'seat'=>0,'tile'=>4,'opts'=>[[2,3],[3,5],[5,6]]];
$s['riichi'][0]=true;
$cases[]=['name'=>'chi_riichi_reject','op'=>'chi','state'=>$s,'seat'=>0,'tile'=>4,'opts'=>[[2,3],[3,5],[5,6]]];
$s=cr_state([0,1,2,3,4,10,11,12,13,14,20,21,22]);
$cases[]=['name'=>'chi_terminal_reject','op'=>'chi','state'=>$s,'seat'=>0,'tile'=>3,'opts'=>[[1,2],[2,4],[4,5]]];

// Kan decisions.
$s=cr_state([4,4,4,4,1,2,3,10,11,12,19,20,21,22]);
$cases[]=['name'=>'ankan_normal','op'=>'kan','state'=>$s,'seat'=>0,'tile'=>4,'type'=>1];
$s['riichi'][0]=true;$s['drawn'][0]=4;
$cases[]=['name'=>'ankan_riichi','op'=>'kan','state'=>$s,'seat'=>0,'tile'=>4,'type'=>1];
$cases[]=['name'=>'kakan_riichi_reject','op'=>'kan','state'=>$s,'seat'=>0,'tile'=>4,'type'=>3];
$s=cr_state([4,1,2,3,10,11,12,19,20,21,22]);
$s['melds'][0]=[['kind'=>'pon','tiles'=>[4,4,4]]];
$cases[]=['name'=>'kakan_normal','op'=>'kan','state'=>$s,'seat'=>0,'tile'=>4,'type'=>3];

$out=[];
foreach($cases as $c){
    $s=$c['state'];$seat=(int)$c['seat'];
    $value=match($c['op']){
        'danger'=>CpuAi::danger($s,$seat,(int)$c['tile']),
        'pon'=>CpuAi::wantsPon($s,$seat,(int)$c['tile']),
        'chi'=>CpuAi::pickChi($s,$seat,(int)$c['tile'],$c['opts']),
        'kan'=>CpuAi::wantsKan($s,$seat,(int)$c['tile'],(int)$c['type']),
    };
    $out[]=['case'=>$c,'value'=>$value];
}

echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
