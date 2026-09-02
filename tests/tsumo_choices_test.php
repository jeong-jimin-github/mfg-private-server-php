<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Mahjong\Mahjong;
use Mfg\Mahjong\Table;

function tc(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}

// Closed 14-tile hand with multiple tenpai discard choices. The client expects
// concrete ptnN blocks rather than only the F_REACH bit.
$s=Table::create(Mahjong::TONPU,0,9001);
$s['state']='discard';
$s['hands'][0]=[0,0,0,1,2,3,4,5,6,15,16,17,27,27];
$s['drawn'][0]=27;
$s['wall']=array_fill(0,30,20);
$s['dora_ind']=[8,9,18,27,31];
$t=new Table($s);

// Recreate the normal human-draw path without depending on a random wall.
$ref=new ReflectionClass($t);
$m=$ref->getMethod('offerTsumoChoices');$m->setAccessible(true);$m->invoke($t,0);
$t->flushPending();
$xml=$t->cellsFrom(0);

tc(str_contains($xml,'kind="15"'),'TSUMOCHOICES cell missing');
tc(preg_match('/<ptn_num>([1-9][0-9]*)<\/ptn_num>/', $xml)===1,'reach pattern count must be non-zero');
tc(str_contains($xml,'<ptn0>'),'ptn0 missing');
tc(str_contains($xml,'<sute_pai>'),'reach discard tile missing');
tc(str_contains($xml,'<machi_num>'),'wait count missing');
tc(str_contains($xml,'<machi_pai __count='),'wait tile array missing');
tc(str_contains($xml,'<stat __count='),'visible/dead wait stat missing');

// An existing pon plus the fourth tile must be exposed as kakan type 3.
$s=Table::create(Mahjong::TONPU,0,9002);
$s['state']='discard';
$s['hands'][0]=[5,0,1,2,9,10,11,18,19,20,27];
$s['drawn'][0]=5;
$s['melds'][0]=[['kind'=>'pon','tiles'=>[5,5,5],'called'=>5,'from_seat'=>1]];
$s['wall']=array_fill(0,30,20);
$t=new Table($s);
$ref=new ReflectionClass($t);$m=$ref->getMethod('offerTsumoChoices');$m->setAccessible(true);$m->invoke($t,0);$t->flushPending();
$xml=$t->cellsFrom(0);

tc(str_contains($xml,'<kan_type __count="1">3</kan_type>'),'kakan must be advertised as kan_type=3');
tc(str_contains($xml,'<kan_pai __count="1">'.Mahjong::idxToPai(5).'</kan_pai>'),'kakan tile missing');

echo "tsumo choices parity OK\n";
