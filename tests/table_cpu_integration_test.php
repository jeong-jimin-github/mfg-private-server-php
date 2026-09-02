<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Mahjong\Mahjong;
use Mfg\Mahjong\Table;

function tcpu(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$s=Table::create(Mahjong::TONPU,0,1234);
$s['hands']=[[],[],[],[0,1,2,9,10,11,18,19,20,21,22,23,27,28]];
$s['melds']=[[],[],[],[]];
$s['discards']=[[],[],[],[]];
$s['discard_log']=[];
$s['riichi_at']=[-1,-1,-1,-1];
$s['riichi']=[false,false,false,false];
$s['double_riichi']=[false,false,false,false];
$s['ippatsu']=[false,false,false,false];
$s['furiten']=[false,false,false,false];
$s['temp_furiten']=[false,false,false,false];
$s['drawn']=[null,null,null,28];
$s['turn']=3;
$s['wall']=array_fill(0,20,5);
$s['rinshan']=[6,7,8,9];
$s['dora_ind']=[8,8,8,8,8];
$s['ura_ind']=[17,17,17,17,17];
$s['dora_open']=1;
$s['state']='discard';
$s['cells']=[];

$t=new Table($s);
$m=new ReflectionMethod(Table::class,'cpuTurn');
$m->setAccessible(true);
$m->invoke($t,3);
$r=$t->state();

tcpu($r['riichi'][3]===true,'live Table CPU must declare riichi from tenpai');
tcpu($r['scores'][3]===24000,'riichi deposit must be deducted');
tcpu($r['kyotaku']===1,'riichi stick must enter kyotaku');
tcpu(count($r['discard_log'])>=1,'discard_log must persist CPU discard');
tcpu(($r['discard_log'][0][0]??-1)===3,'discard_log seat mismatch');
tcpu(($r['riichi_at'][3]??-1)===1,'riichi_at must point after declaration discard');
tcpu(str_contains(implode('',$r['cells']),'<stat>1</stat>'),'CPU riichi discard cell missing');

echo "table cpu integration OK\n";
