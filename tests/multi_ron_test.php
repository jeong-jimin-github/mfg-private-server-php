<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Mahjong\Mahjong;
use Mfg\Mahjong\Table;

function mrok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$waitHand=[0,0,0,1,1,1,2,2,2,3,3,3,4]; // 5m tanki; chinitsu/toitoi shape
$s=Table::create(Mahjong::TONPU,3,1234);
$s['scores']=[25000,25000,25000,25000];
$s['honba']=1;$s['kyotaku']=2;$s['state']='discard';$s['finished']=false;
$s['hands']=[
    [4,5,6,7,8,9,10,11,12,13,14,15,16,17],
    $waitHand,
    $waitHand,
    [6,7,8,9,10,11,12,13,14,15,16,17,18],
];
$s['melds']=[[],[],[],[]];$s['discards']=[[],[],[],[]];$s['discard_log']=[];
$s['drawn']=[4,null,null,null];$s['wall']=array_fill(0,40,20);$s['rinshan']=[0,1,2,3];
$s['dora_ind']=[8,8,8,8,8];$s['ura_ind']=[8,8,8,8,8];$s['dora_open']=1;$s['kan_count']=0;
$s['riichi']=[false,true,true,false];$s['double_riichi']=[false,false,false,false];$s['ippatsu']=[false,false,false,false];
$s['riichi_at']=[-1,0,0,-1];$s['furiten']=[false,false,false,false];$s['temp_furiten']=[false,false,false,false];
$s['any_call']=false;$s['discard_count']=4;$s['kyoku_index']=0;

$t=new Table($s);
$t->onCommand(Table::S_SUTE_PAI,0,Mahjong::idxToPai(4));
$out=$t->state();
$xml=implode('',array_map('strval',$out['cells']));

mrok(str_contains($xml,'<ron_flg __count="4">0 1 1 0</ron_flg>'),'double ron flags missing');
mrok(str_contains($xml,'<yaku1>')&&str_contains($xml,'<yaku2>'),'both winner yaku payloads missing');
mrok((int)$out['scores'][1]>(int)$s['scores'][1],'first winner did not gain points');
mrok((int)$out['scores'][2]>(int)$s['scores'][2],'second winner did not gain points');
mrok((int)$out['scores'][0]<(int)$s['scores'][0],'discarder did not pay both winners');
mrok(((int)$out['scores'][1]-(int)$s['scores'][1])-((int)$out['scores'][2]-(int)$s['scores'][2])===2000,'kyotaku must go only to the first ron winner');
mrok((int)$out['kyotaku']===0,'kyotaku was not cleared');
mrok(in_array($out['state'],['kyoku_end','game_end'],true),'multi-ron did not end the hand');

echo "multi ron OK\n";
