<?php

declare(strict_types=1);

require_once __DIR__.'/../src/Mahjong/RoundSettlement.php';

use Mfg\Mahjong\RoundSettlement;

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
