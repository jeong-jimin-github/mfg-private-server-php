<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Mfg\\')) return;
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use Mfg\Mahjong\RoundSettlement;
use Mfg\Mahjong\Mahjong;

$d=RoundSettlement::exhaustiveDrawDeltas(4,[0]);
if($d!==[3000,-1000,-1000,-1000])throw new RuntimeException('one tenpai');
$d=RoundSettlement::exhaustiveDrawDeltas(4,[0,2]);
if($d!==[1500,-1500,1500,-1500])throw new RuntimeException('two tenpai');
$d=RoundSettlement::exhaustiveDrawDeltas(3,[1]);
if($d!==[-1500,3000,-1500,0])throw new RuntimeException('sanma tenpai');
$d=RoundSettlement::exhaustiveDrawDeltas(2,[0]);
if($d!==[3000,-3000,0,0])throw new RuntimeException('nima tenpai');
if(RoundSettlement::exhaustiveDrawDeltas(4,[])!==[0,0,0,0])throw new RuntimeException('all noten');
if(RoundSettlement::exhaustiveDrawDeltas(4,[0,1,2,3])!==[0,0,0,0])throw new RuntimeException('all tenpai');

$applied=RoundSettlement::applyExhaustiveDraw([25000,25000,25000,25000],4,[0,2]);
if($applied['scores']!==[26500,23500,26500,23500])throw new RuntimeException('draw score apply');

$tenpaiHand=[0,1,2,9,10,11,18,19,20,27,27,27,28];
$notenHand=[0,1,3,9,10,12,18,19,21,27,27,28,29];
$status=RoundSettlement::tenpaiStatus([$tenpaiHand,$notenHand,[],[]],[[],[],[],[]],2,Mahjong::NIMA);
if($status['tenpai']!==[0]||!in_array(28,$status['waits'][0]??[],true))throw new RuntimeException('tenpai status');

$n=RoundSettlement::nextState(0,[],[0,2],true,false,1,1,4,[25000,25000,25000,25000],4);
if(!$n['renchan']||$n['advance']||$n['honba']!==2||$n['game_over'])throw new RuntimeException('dealer tenpai draw');
$n=RoundSettlement::nextState(0,[],[1],true,false,0,1,4,[25000,25000,25000,25000],4);
if($n['renchan']||!$n['advance']||$n['honba']!==1)throw new RuntimeException('dealer noten draw');
$n=RoundSettlement::nextState(0,[0],[],false,false,2,2,4,[25000,25000,25000,25000],4);
if(!$n['renchan']||$n['honba']!==3)throw new RuntimeException('dealer win');
$n=RoundSettlement::nextState(0,[1],[],false,false,2,2,4,[25000,25000,25000,25000],4);
if($n['renchan']||$n['honba']!==0)throw new RuntimeException('child win');
$n=RoundSettlement::nextState(0,[1],[],false,false,0,3,4,[25000,25000,25000,25000],4);
if(!$n['game_over'])throw new RuntimeException('all last child win');
$n=RoundSettlement::nextState(0,[0],[],false,false,0,3,4,[25000,25000,25000,25000],4);
if($n['game_over'])throw new RuntimeException('all last dealer renchan');
$n=RoundSettlement::nextState(0,[],[],true,true,0,1,4,[25000,25000,25000,25000],4);
if(!$n['renchan'])throw new RuntimeException('abortive draw');

echo "round settlement OK\n";
