<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Mahjong\YakuBits;
use Mfg\Mahjong\ResultXml;

function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$b=YakuBits::words(['Tanyao','Richi','Ippatsu','Menzen'],0);
ok($b['low']===0,'one-han yaku used here must live in high word');
$expected=(1<<(43-32))|(1<<(45-32))|(1<<(46-32))|(1<<(47-32));
ok($b['high']===$expected,'MFG yaku high-word bit layout mismatch');

$d=YakuBits::words([],1);
ok(($d['high'] & (1<<(53-32)))!==0,'Dora bit missing');

$res=['han'=>3,'fu'=>30,'rank'=>3,'dora'=>0,'yaku'=>['Tanyao','Richi','Ippatsu'],'yakuman'=>0];
$xml=ResultXml::yaku('yaku',$res,4,[1,2,3,4,5,6,7,8,9,10,11,12,13,4]);
ok(str_contains($xml,'<yaku1>0</yaku1>'),'yaku1 missing');
ok(str_contains($xml,'<yaku2>'),'yaku2 missing');
ok(str_contains($xml,'<han_num>3</han_num>'),'han_num mismatch');
ok(str_contains($xml,'<fu_num>30</fu_num>'),'fu_num mismatch');
ok(str_contains($xml,'<naki3>'),'dummy naki layout incomplete');

$calc=ResultXml::calcScores([25000,25000,25000,25000],[3900,-3900,0,0],[1000,0,0,0],[300,-300,0,0]);
ok(str_contains($calc,'<calc_score0>'),'calc_score0 missing');
ok(str_contains($calc,'<new_score>30200</new_score>'),'winner score breakdown mismatch');
ok(str_contains($calc,'<new_score>20800</new_score>'),'loser score breakdown mismatch');

echo "result xml OK\n";
