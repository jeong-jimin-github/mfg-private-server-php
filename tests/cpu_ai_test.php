<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Mahjong\CpuAi;
use Mfg\Mahjong\Mahjong;

function aiok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$s=[
    'taku'=>Mahjong::TONPU,'seats'=>4,'kyoku_index'=>0,'scores'=>[25000,25000,25000,25000],
    'hands'=>[
        [0,1,2,3,4,5,6,7,8,9,10,11,12,13],
        [0,0,1,1,2,2,9,9,10,10,18,18,27,27],[],[]
    ],
    'melds'=>[[],[],[],[]],'discards'=>[[],[],[],[]],'drawn'=>[13,27,null,null],
    'riichi'=>[false,false,false,false],'riichi_at'=>[-1,-1,-1,-1],'discard_log'=>[],
    'dora_ind'=>[8],'dora_open'=>1,'wall'=>array_fill(0,40,20),
];
[$tile,$reach]=CpuAi::chooseDiscard($s,0);
aiok(in_array($tile,$s['hands'][0],true),'CPU discard must come from hand');

// Against riichi, an opponent genbutsu must be treated as completely safe.
$s['riichi'][1]=true;$s['discards'][1]=[4];$s['riichi_at'][1]=0;$s['discard_log']=[[1,4]];
aiok(CpuAi::danger($s,0,4)===0,'genbutsu must be safe');
aiok(CpuAi::danger($s,0,3)>0,'non-genbutsu must carry risk');

// Yakuhai pon should be accepted when it does not worsen shanten.
$s2=$s;$s2['riichi']=[false,false,false,false];$s2['hands'][1]=[31,31,0,1,2,9,10,11,18,19,20,27,27];
aiok(CpuAi::wantsPon($s2,1,31)===true,'dragon pon should be accepted');

// Riichi players may only accept concealed-kan style kan decisions.
$s2['riichi'][1]=true;
aiok(CpuAi::wantsKan($s2,1,31,1)===true,'riichi concealed kan policy missing');
aiok(CpuAi::wantsKan($s2,1,31,3)===false,'riichi kakan must be rejected');

echo "cpu ai OK\n";
