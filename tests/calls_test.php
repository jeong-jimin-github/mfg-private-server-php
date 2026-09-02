<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Mahjong\Table;
use Mfg\Mahjong\Mahjong;

function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$t=new Table(Table::create(Mahjong::TONPU,0,1234));
$t->startKyoku();
$t->flushPending();
$s=$t->state();
ok(count($s['hands'][0])===14,'dealer must begin with 14 tiles');
ok(count($s['cells'])>=3,'start must emit kyokustart/tsumo/choices');

// Force a deterministic pon opportunity for the human and ensure the call cell
// and persisted meld survive serialization.
$s['hands'][0]=[0,0,1,2,3,4,5,6,7,8,9,10,11];sort($s['hands'][0]);
$s['hands'][1]=[0,12,13,14,15,16,17,18,19,20,21,22,23,24];
$s['drawn'][1]=24;$s['turn']=1;$s['state']='discard';
$t=new Table($s);
$t->onCommand(Table::S_SUTE_PAI,1,Mahjong::idxToPai(0));
$t->flushPending();
$xml=$t->cellsFrom(0);
ok(str_contains($xml,'kind="16"'),'human should receive SUTECHOICES');
$t->onCommand(Table::S_PON,0,Mahjong::idxToPai(0));
$s=$t->state();
ok(count($s['melds'][0])===1 && $s['melds'][0][0]['kind']==='pon','pon must persist');
ok(count($s['hands'][0])===11,'pon removes two owned tiles');

// A simple closed tanyao tsumo should be recognized by the incremental scorer.
$s=Table::create(Mahjong::TONPU,0,99);
$s['state']='discard';
$s['hands'][0]=[1,2,3,10,11,12,19,20,21,4,4,5,5,5]; // 234m 234s 234p 55m 666m
$s['drawn'][0]=5;
$t=new Table($s);
$before=$t->state()['scores'][0];
$t->onCommand(Table::S_TSUMO_AGARI,0,Mahjong::idxToPai(5));
$after=$t->state();
ok($after['state']==='kyoku_end','tsumo should end kyoku');
ok($after['scores'][0]>$before,'winner score must increase');
ok(str_contains($t->cellsFrom(0),'kind="3"'),'tsumo result cell missing');

echo "calls/wins OK\n";
