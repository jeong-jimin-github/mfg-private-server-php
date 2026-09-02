<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Mahjong\Mahjong;
use Mfg\Mahjong\Table;

function assert_rule(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

// Empty live wall on a non-rinshan self draw must be scored as haitei.
$s=Table::create(Mahjong::TONPU,0,44);
$s['state']='discard';
$s['hands'][0]=[1,2,3,10,11,12,19,20,21,4,4,5,5,5];
$s['drawn'][0]=5;
$s['wall']=[];
$s['last_draw_rinshan']=false;
$t=new Table($s);
$t->onCommand(Table::S_TSUMO_AGARI,0,Mahjong::idxToPai(5));
$st=$t->state();
assert_rule(str_contains($t->cellsFrom(0),'Haitei'),'haitei was not merged into table win result');
assert_rule($st['honba']===1,'dealer tsumo must continue with one honba');
assert_rule($st['advance_kyoku']===false,'dealer tsumo must not advance kyoku');

// First uninterrupted discard can declare double riichi.
$s=Table::create(Mahjong::TONPU,0,45);
$s['state']='discard';
$s['hands'][0]=[0,1,2,3,4,5,6,7,8,9,10,11,12,13];
$s['drawn'][0]=13;
$s['wall']=array_fill(0,30,20);
$t=new Table($s);
$t->onCommand(Table::S_SUTE_PAI,0,Mahjong::idxToPai(13),1,1);
$st=$t->state();
assert_rule($st['riichi'][0]===true,'riichi declaration missing');
assert_rule($st['double_riichi'][0]===true,'first uninterrupted riichi must be double riichi');
assert_rule($st['scores'][0]===24000 && $st['kyotaku']===1,'riichi stick accounting failed');

// Any prior call disables double-riichi eligibility.
$s=Table::create(Mahjong::TONPU,0,46);
$s['state']='discard';$s['any_call']=true;
$s['hands'][0]=[0,1,2,3,4,5,6,7,8,9,10,11,12,13];$s['drawn'][0]=13;$s['wall']=array_fill(0,30,20);
$t=new Table($s);
$t->onCommand(Table::S_SUTE_PAI,0,Mahjong::idxToPai(13),1,1);
assert_rule($t->state()['double_riichi'][0]===false,'call must disable double riichi');

echo "table integrated rules OK\n";
