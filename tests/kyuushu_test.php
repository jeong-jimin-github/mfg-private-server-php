<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Mahjong\Mahjong;
use Mfg\Mahjong\Table;

function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$s=Table::create(Mahjong::TONPU,0,77);
$s['state']='discard';
$s['hands'][0]=[0,8,9,17,18,26,27,28,29,30,31,32,33,1];
$s['drawn'][0]=1;
$s['wall']=array_fill(0,30,2);
$before=$s['scores'];
$t=new Table($s);
$t->onCommand(Table::S_KYUSYUKYUHAI,0,0);
$st=$t->state();
ok($st['state']==='kyoku_end','kyuushu must end the hand');
ok($st['scores']===$before,'abortive draw must not move points');
ok($st['honba']===1,'abortive draw must increment honba');
ok($st['advance_kyoku']===false,'abortive draw must repeat the same kyoku');
ok(str_contains($t->cellsFrom(0),'kind="5"'),'ryuukyoku cell missing');
ok(str_contains($t->cellsFrom(0),'<reason>1</reason>'),'abortive reason flag missing');

$s=Table::create(Mahjong::TONPU,0,78);
$s['state']='discard';$s['hands'][0]=[0,8,9,17,18,26,27,28,29,30,31,32,33,1];$s['drawn'][0]=1;$s['wall']=array_fill(0,30,2);$s['any_call']=true;
$t=new Table($s);$t->onCommand(Table::S_KYUSYUKYUHAI,0,0);
ok($t->state()['state']==='discard','prior call must disable kyuushu');

echo "kyuushu OK\n";
