<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Aog\Dispatcher;
use Mfg\Storage\Database;

function mr_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function mr_root(string $xml):SimpleXMLElement{$r=new SimpleXMLElement($xml);mr_ok($r->getName()==='root','root');mr_ok((string)$r->serv_st->code==='0','serv_st');return $r;}

$db=new Database('sqlite::memory:');$d=new Dispatcher($db);
$db->saveMatch('FOUR',['gmode'=>1,'taku'=>0,'seats'=>4,'state'=>'playing','table'=>['scores'=>[10000,40000,30000,20000],'kyoku_index'=>1,'state'=>'playing','finished'=>false]]);
$r=mr_root($d->dispatch('end_game',['pcuid'=>'FOUR']));$m=$r->mgresult;
mr_ok((string)$m->gmode==='1','4p gmode');mr_ok((string)$m->taku_class==='1','taku class');mr_ok((string)$m->continue_state==='0'&&(string)$m->continue_fee==='0','continue fields');
$expected=[[3,10000,-20000],[0,40000,20000],[1,30000,10000],[2,20000,-10000]];
foreach($expected as $i=>$v){$p=$m->{'player_'.$i};mr_ok([(int)$p->rank,(int)$p->score,(int)$p->uma]===$v,'4p player '.$i);}
$stored=$db->getMatch('FOUR');mr_ok(($stored['state']??'')==='game_end','match end state');mr_ok(($stored['table']['finished']??false)===true,'table finished');

$db->saveMatch('THREE',['gmode'=>3,'taku'=>2,'seats'=>3,'state'=>'playing','table'=>['scores'=>[35000,50000,20000,0],'kyoku_index'=>0,'state'=>'playing','finished'=>false]]);
$r=mr_root($d->dispatch('kiken_game',['pcuid'=>'THREE']));$m=$r->mgresult;
mr_ok((string)$m->gmode==='3','3p gmode');
$expected=[[1,35000,0],[0,50000,20000],[2,20000,-20000]];
foreach($expected as $i=>$v){$p=$m->{'player_'.$i};mr_ok([(int)$p->rank,(int)$p->score,(int)$p->uma]===$v,'3p player '.$i);}
mr_ok(!isset($m->player_3),'3p should not emit player_3');

// Tie break follows the current dealer-relative seat wind, matching Table::_ranks.
$db->saveMatch('TIE',['gmode'=>4,'taku'=>3,'seats'=>2,'state'=>'playing','table'=>['scores'=>[50000,50000,0,0],'kyoku_index'=>1,'state'=>'playing','finished'=>false]]);
$r=mr_root($d->dispatch('end_game',['pcuid'=>'TIE']));$m=$r->mgresult;
mr_ok((int)$m->player_1->rank===0&&(int)$m->player_1->uma===10000,'2p dealer-relative tie winner');
mr_ok((int)$m->player_0->rank===1&&(int)$m->player_0->uma===-10000,'2p tie loser');

// Python reference does not instantiate a table until the client is ready.
// Ending from the matching room must therefore return initial scores and zero uma.
$entry=mr_root($d->dispatch('entry_game',['pcuid'=>'PRE_READY','gmode'=>'4']));
$tid=(int)$entry->entry->tid;
$d->dispatch('gget',['pcuid'=>'PRE_READY','ready'=>'0','must'=>'VFG:J:A:A:2025122300/PRE_READY/'.$tid.'/0/1/0']);
$pre=$db->getMatch('PRE_READY');mr_ok(($pre['table']??null)===null,'pre-ready gget must not instantiate table');
$r=mr_root($d->dispatch('end_game',['pcuid'=>'PRE_READY']));$m=$r->mgresult;
mr_ok((int)$m->player_0->rank===0&&(int)$m->player_0->score===35000&&(int)$m->player_0->uma===0,'pre-ready NIMA player 0');
mr_ok((int)$m->player_1->rank===1&&(int)$m->player_1->score===35000&&(int)$m->player_1->uma===0,'pre-ready NIMA player 1');

echo "end_game mgresult contract OK\n";
