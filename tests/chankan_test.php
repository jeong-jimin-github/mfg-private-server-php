<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Mahjong\Mahjong;
use Mfg\Mahjong\Table;
use Mfg\Mahjong\YakuBits;

function ck(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

// Human seat 0 upgrades a pon of 6m (index 5). CPU seat 1 is waiting on that
// tile with no ordinary yaku. Chankan alone must make the ron legal before rinshan draw.
$s=Table::create(Mahjong::TONPU,0,777);
$s['state']='discard';
$s['hands'][0]=[5,0,1,2,9,10,11,18,19,20,27];
$s['melds'][0]=[['kind'=>'pon','tiles'=>[5,5,5],'called'=>5,'from_seat'=>1]];
$s['hands'][1]=[0,1,2,12,13,14,24,25,26,27,27,3,4];
$s['hands'][2]=[0,1,2,3,6,7,8,9,13,14,18,22,23];
$s['hands'][3]=[0,1,2,6,7,8,9,10,11,18,19,20,30];
$s['wall']=array_fill(0,30,8);
$s['rinshan']=[9,10,11,12];
$s['dora_ind']=[0,1,2,3,4];
$s['ura_ind']=[9,10,11,12,13];
$before=$s['scores'];
$t=new Table($s);
$t->onCommand(Table::S_KAKAN,0,Mahjong::idxToPai(5));
$after=$t->state();
$xml=$t->cellsFrom(0);

ck(str_contains($xml,'kind="10"'),'kakan cell missing');
ck(str_contains($xml,'kind="4"'),'rob-kakan ron cell missing');
preg_match_all('/<yaku2>(\d+)<\/yaku2>/',$xml,$m);
$chankan=YakuBits::words(['Chankan'])['high'];
$found=false;foreach($m[1]??[] as $word)if((((int)$word)&$chankan)!==0){$found=true;break;}
ck($found,'chankan yaku bit missing from result');
ck($after['scores'][1]>$before[1],'rob-kakan winner score did not increase');
ck($after['scores'][0]<$before[0],'kan declarer score did not decrease');
ck($after['state']==='kyoku_end','chankan must end the hand');
ck($after['kan_count']===1 && $after['dora_open']===2,'kakan state was not committed');

echo "chankan table flow OK\n";
