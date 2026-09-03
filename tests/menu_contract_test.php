<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Aog\Dispatcher;
use Mfg\Storage\Database;

function mc_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$db=new Database('sqlite::memory:');
$d=new Dispatcher($db);
$xml=$d->dispatch('get_menudata',['pcuid'=>'MENU-CONTRACT']);
$r=new SimpleXMLElement($xml);
$m=$r->menudata;
mc_ok(isset($m[0]),'menudata missing');
mc_ok(isset($m->mpdata->mid)&&isset($m->mpdata->name),'mpdata contract');

$expectedTaku=[1=>0,2=>1,3=>2,4=>3,5=>0,6=>0,7=>2,8=>0,9=>0,10=>2,11=>0,12=>2,13=>0,14=>2,15=>0,16=>0,17=>2,18=>2,19=>2,20=>0,21=>2,22=>0,23=>2];
$seats=[0=>4,1=>4,2=>3,3=>2];
$score=[0=>25000,1=>25000,2=>35000,3=>35000];
$modes=[];
foreach($m->playmode_list->mode as $node){
    $g=(int)$node->gmode;$modes[$g]=true;
    mc_ok(isset($expectedTaku[$g]),'unknown gmode '.$g);
    $t=$expectedTaku[$g];
    mc_ok((int)$node->taku_class===1,'taku_class '.$g);
    mc_ok((int)$node->payment_mode===0,'payment_mode '.$g);
    mc_ok((int)$node->table_type===0,'table_type '.$g);
    mc_ok((int)$node->pmax===$seats[$t],'pmax '.$g);
    mc_ok((int)$node->tenbo===$score[$t],'tenbo '.$g);
    mc_ok((int)$node->state===1,'state '.$g);
    mc_ok((int)$node->rate===0,'rate '.$g);
    mc_ok((int)$node->superior_border===0,'superior_border '.$g);
}
mc_ok(array_keys($modes)===range(1,23),'all 23 play modes');

$battle=$m->battle_item_settings;
mc_ok(isset($battle[0]),'battle_item_settings missing');
mc_ok(isset($battle->basic_settings),'basic_settings missing');
mc_ok(isset($battle->playmode_settings),'playmode_settings missing');
$covered=[];
foreach($battle->playmode_settings->setting as $s){
    mc_ok((string)$s['taku_class']==='1','battle taku_class');
    $covered[]=(int)$s['gmode'];
}
sort($covered);
mc_ok($covered===range(1,23),'battle settings do not cover all modes');

echo "get_menudata parser contract OK\n";
