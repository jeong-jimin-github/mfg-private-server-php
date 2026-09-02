<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=__DIR__.'/../src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Aog\Dispatcher;
use Mfg\Aog\FeatureDispatcher;
use Mfg\Storage\Database;

function mp_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$dsn=(string)(getenv('TEST_DB_DSN')?:'');
if($dsn===''){echo "mysql request persistence skipped\n";exit(0);}
$user=(string)(getenv('TEST_DB_USER')?:'');$pass=(string)(getenv('TEST_DB_PASS')?:'');
$tag=strtoupper(bin2hex(random_bytes(6)));$ref='MYSQL'.$tag;

// Simulate one PHP-FPM request/process writing state.
$db1=new Database($dsn,$user,$pass);mp_ok($db1->driver()==='mysql','not mysql');
$d1=new Dispatcher($db1);$f1=new FeatureDispatcher($db1);
$login=new SimpleXMLElement($d1->dispatch('login',['user_id'=>$ref]));$pcuid=(string)$login->auth->session_id;
mp_ok(strlen($pcuid)===32,'login session id');
$state='persistent-state-'.$tag;
$d1->dispatch('client_state_write',['pcuid'=>$pcuid,'kind'=>'player_game','data'=>base64_encode($state)]);
$entry=new SimpleXMLElement($d1->dispatch('entry_game',['pcuid'=>$pcuid,'gmode'=>'3']));
$tid=(int)$entry->entry->tid;mp_ok($tid>=1,'entry tid');
$ready=new SimpleXMLElement($d1->dispatch('gget',['pcuid'=>$pcuid,'ready'=>'1','next_sno'=>'0']));
mp_ok(isset($ready->game->taikyoku->cell_info)&&(string)$ready->game->taikyoku->cell_info['available']==='1','initial match stream');
$f1->dispatch('gchat',['tid'=>(string)$tid,'mid'=>'1','pindex'=>'0','name'=>'MYSQL','contents'=>'TableSticker001','param'=>'']);
$tx=new SimpleXMLElement((string)$f1->dispatch('req_draw_gacha',['pcuid'=>$pcuid,'gacha_name'=>'Normal','times'=>'3']));
mp_ok(strlen((string)$tx->transaction_info->transaction_id)===16,'gacha transaction');

// New Database/Dispatcher instances model a later independent PHP request.
$db2=new Database($dsn,$user,$pass);$d2=new Dispatcher($db2);$f2=new FeatureDispatcher($db2);
$read=new SimpleXMLElement($d2->dispatch('client_state_read',['pcuid'=>$pcuid,'one_kind'=>'player_game']));
mp_ok(isset($read->state),'state missing after reconnect');
mp_ok(base64_decode((string)$read->state->data,true)===$state,'state payload changed across connections');
$match=$db2->getMatch($pcuid);mp_ok(is_array($match),'match missing after reconnect');
mp_ok((int)($match['gmode']??0)===3,'match gmode changed');
mp_ok(is_array($match['table']??null)&&count($match['table']['cells']??[])>0,'serialized table missing cells');
$poll=new SimpleXMLElement($d2->dispatch('gget',['pcuid'=>$pcuid,'ready'=>'1','next_sno'=>'0']));
mp_ok(isset($poll->game->taikyoku->cell_info),'persisted match cannot be polled');
$chat=new SimpleXMLElement((string)$f2->dispatch('gchat',['tid'=>(string)$tid]));
mp_ok(str_contains($chat->asXML()?:'','TableSticker001'),'sticker KV missing after reconnect');
$result=new SimpleXMLElement((string)$f2->dispatch('get_gacha_result',['pcuid'=>$pcuid,'times'=>'3']));
mp_ok(count($result->lottery_result->data)===3,'gacha KV missing after reconnect');

$db2->deleteMatch($pcuid);$db2->deleteSession($pcuid);$db2->deleteKv('stamps',(string)$tid);$db2->deleteKv('gacha',$pcuid);
echo "mysql cross-request AOG persistence OK\n";
