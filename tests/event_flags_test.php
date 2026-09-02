<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Aog\Dispatcher;
use Mfg\Storage\Database;

function ef_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function ef_events(Dispatcher $d,string $mode):array{
    putenv('VFG_EVENT_TAKU='.$mode);
    $root=new SimpleXMLElement($d->dispatch('appli_info',[]));
    $rows=[];
    foreach($root->info_data as $n){
        if((string)$n['kind']!=='events')continue;
        $raw=base64_decode((string)$n,true);ef_ok($raw!==false,'events base64');
        $j=json_decode($raw,true);ef_ok(is_array($j),'events json');$rows=$j['list']??[];
    }
    ef_ok($rows!==[],'events missing');return $rows;
}
function ef_names(array $rows):array{return array_values(array_map(static fn($r)=>(string)$r['name'],$rows));}

$d=new Dispatcher(new Database('sqlite::memory:'));
$tableFlags=['BlowAwaySanma','FireReach2','Competition7','Competition8','AotenjoEvent2','ComebackTakuEvent','KirisameTakuEvent','MeldBonusTakuEvent2','Competition6','ReversalTakuEvent','BombTakuEvent','AllGreenTaku'];
$panels=['BlowAwaySanma'=>1,'FireReach2'=>1,'Competition7'=>0,'Competition8'=>0,'AotenjoEvent2'=>1,'ComebackTakuEvent'=>1,'KirisameTakuEvent'=>1,'MeldBonusTakuEvent2'=>2,'Competition6'=>0,'ReversalTakuEvent'=>1,'BombTakuEvent'=>2,'AllGreenTaku'=>2];

$off=ef_events($d,'off');$offNames=ef_names($off);
ef_ok(in_array('SpiritGymBonusEvent',$offNames,true),'base event missing');
foreach($tableFlags as $f)ef_ok(!in_array($f,$offNames,true),'off advertised '.$f);
foreach($off as $r){ef_ok(($r['active']??false)===true,'inactive base event');$p=(string)($r['param']??'');if($p!=='')ef_ok(str_contains($p,'='),'param not key=value');}

$min=ef_names(ef_events($d,'min'));
$minFlags=array_values(array_intersect($tableFlags,$min));sort($minFlags);
$expected=['ComebackTakuEvent','FireReach2','KirisameTakuEvent'];sort($expected);
ef_ok($minFlags===$expected,'min event table set mismatch');
$minPanels=array_sum(array_map(static fn($f)=>$panels[$f]??1,$minFlags));ef_ok($minPanels===3,'min panel budget');

$all=ef_names(ef_events($d,'all'));
foreach($tableFlags as $f)ef_ok(in_array($f,$all,true),'all missing '.$f);
$allPanels=array_sum(array_map(static fn($f)=>$panels[$f]??1,$tableFlags));ef_ok($allPanels===12,'all panel count must reproduce known overflow');

$bad=ef_names(ef_events($d,'not-a-mode'));
foreach($expected as $f)ef_ok(in_array($f,$bad,true),'invalid mode did not fall back to min: '.$f);
putenv('VFG_EVENT_TAKU');

echo "event table flags OK\n";
