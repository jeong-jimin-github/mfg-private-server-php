<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Eamuse\Dispatcher;
use Mfg\Storage\Database;

const MODEL='VFG:J:A:A:2025122300';
const CARD='E0047CC78DFBA459';
const GARBLED="\u{E09B}ﾞ";

function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function callNode(string $module,string $method,array $attrs=[]):SimpleXMLElement{
    $xml='<call model="'.MODEL.'"><'.$module.' method="'.$method.'"';
    foreach($attrs as $k=>$v)$xml.=' '.$k.'="'.htmlspecialchars((string)$v,ENT_QUOTES|ENT_XML1,'UTF-8').'"';
    return new SimpleXMLElement($xml.'/></call>');
}
/** @return array<string,string> */
function attrs(string $xml,string $node):array{
    $r=new SimpleXMLElement($xml);$n=$r->{$node};ok(isset($n),$node.' response missing');$out=[];foreach($n->attributes() as $k=>$v)$out[(string)$k]=(string)$v;return $out;
}
function node(string $xml,string $name):SimpleXMLElement{$r=new SimpleXMLElement($xml);return $r->{$name};}

putenv('VFG_CARDMNG_MODE');putenv('VFG_CARDMNG_INQUIRE_MODE');
$db=new Database('sqlite::memory:');$d=new Dispatcher($db,'http://127.0.0.1:8080');

// New canonical card: CARD_NEW, no persistent mutation.
$a=attrs($d->dispatch(MODEL,'cardmng','inquire',callNode('cardmng','inquire',['cardid'=>CARD])),'cardmng');
ok($a===['status'=>'112'],'new card inquire shape');
ok($db->getCard(CARD)===null,'new inquire mutated card');

// Total decode failure must not touch fallback identity.
$a=attrs($d->dispatch(MODEL,'cardmng','getrefid',new SimpleXMLElement('<call/>')),'cardmng');
ok($a===['status'=>'110'],'empty getrefid status');
$a=attrs($d->dispatch(MODEL,'cardmng','bindmodel',new SimpleXMLElement('<call/>')),'cardmng');
ok($a===['status'=>'110'],'empty bindmodel status');
ok($db->getCard(CARD)===null,'empty request mutated fallback');

// Canonical registration: minimal AVS success, no status/pcode.
$a=attrs($d->dispatch(MODEL,'cardmng','getrefid',callNode('cardmng','getrefid',['cardid'=>CARD,'passwd'=>'1234'])),'cardmng');
ok(!isset($a['status'])&&!isset($a['pcode']),'getrefid extra attrs');
ok(($a['refid']??'')!==''&&$a['refid']===$a['dataid'],'getrefid ids');$refid=$a['refid'];

$a=attrs($d->dispatch(MODEL,'cardmng','inquire',callNode('cardmng','inquire',['cardid'=>CARD])),'cardmng');
ok(!isset($a['status']),'issued inquire has status');
ok(($a['binded']??'')==='0'&&($a['newflag']??'')==='1','issued unbound flags');
ok(!isset($a['lastupdate']),'unbound lastupdate');

$a=attrs($d->dispatch(MODEL,'cardmng','bindmodel',callNode('cardmng','bindmodel',['refid'=>$refid])),'cardmng');
ok($a===['dataid'=>$refid],'bindmodel shape');
$a=attrs($d->dispatch(MODEL,'cardmng','inquire',callNode('cardmng','inquire',['cardid'=>CARD])),'cardmng');
ok(($a['binded']??'')==='1'&&($a['newflag']??'')==='0','bound flags');
ok(isset($a['lastupdate'])&&ctype_digit($a['lastupdate']),'bound lastupdate');

// authpass is deliberately permissive on this client build.
$a=attrs($d->dispatch(MODEL,'cardmng','authpass',callNode('cardmng','authpass',['refid'=>$refid,'passwd'=>'9999'])),'cardmng');
ok($a===['status'=>'0'],'authpass shape');

// Strict mode quarantines malformed card traffic and never mutates identity.
$before=$db->getCard(CARD);putenv('VFG_CARDMNG_MODE=strict');
$a=attrs($d->dispatch(MODEL,'cardmng','inquire',callNode('cardmng','inquire',['cardid'=>GARBLED])),'cardmng');
ok($a===['status'=>'112'],'strict malformed inquire');
$a=attrs($d->dispatch(MODEL,'cardmng','getrefid',callNode('cardmng','getrefid',['cardid'=>GARBLED,'passwd'=>'<car'])),'cardmng');
ok($a===['status'=>'110'],'strict malformed getrefid');
ok($db->getCard(CARD)===$before,'strict malformed mutation');
putenv('VFG_CARDMNG_MODE');

// Compat mode resumes the single local identity even for the broken AVS IDm.
$a=attrs($d->dispatch(MODEL,'cardmng','getrefid',callNode('cardmng','getrefid',['cardid'=>GARBLED,'passwd'=>'<car'])),'cardmng');
ok($a===['refid'=>$refid,'dataid'=>$refid],'compat malformed getrefid');
ok(($db->getCard(CARD)['pin']??'')==='1234','garbled passwd replaced stored pin');
$a=attrs($d->dispatch(MODEL,'cardmng','inquire',callNode('cardmng','inquire',['cardid'=>GARBLED])),'cardmng');
ok(($a['refid']??'')===$refid&&!isset($a['status']),'compat malformed inquire');

putenv('VFG_CARDMNG_INQUIRE_MODE=new');
$a=attrs($d->dispatch(MODEL,'cardmng','inquire',callNode('cardmng','inquire',['cardid'=>CARD])),'cardmng');
ok($a===['status'=>'112'],'forced CARD_NEW mode');
putenv('VFG_CARDMNG_INQUIRE_MODE');

$a=attrs($d->dispatch(MODEL,'cardmng','bindmodel',callNode('cardmng','bindmodel',['refid'=>'A000000000000000'])),'cardmng');
ok($a===['status'=>'110'],'unknown bindmodel');

// Shadow module mirrors the exact response element name.
$a=attrs($d->dispatch(MODEL,'vfgcard','inquire',callNode('vfgcard','inquire',['cardid'=>CARD])),'vfgcard');
ok(($a['refid']??'')===$refid,'vfgcard mirror');

// eacoin/PASELI exact response types and session balance behavior.
$x=$d->dispatch(MODEL,'eacoin','checkin',callNode('eacoin','checkin'));
$e=node($x,'eacoin');
ok((string)$e['status']==='0','eacoin checkin status');
ok((string)$e->sequence['__type']==='s16'&&(string)$e->sequence==='0','sequence type');
ok((string)$e->acstatus['__type']==='u8'&&(string)$e->acstatus==='0','acstatus type');
ok((string)$e->balance['__type']==='s32'&&(string)$e->balance==='57300','balance type');
ok((string)$e->sessid['__type']==='str'&&strlen((string)$e->sessid)===16,'sessid type');
$sess=(string)$e->sessid;
$consume=new SimpleXMLElement('<call><eacoin method="consume"><sessid>'.$sess.'</sessid><payment>300</payment></eacoin></call>');
$e=node($d->dispatch(MODEL,'eacoin','consume',$consume),'eacoin');
ok((string)$e->autocharge['__type']==='u8'&&(string)$e->autocharge==='0','autocharge type');
ok((string)$e->balance==='57000','consume balance');
$get=new SimpleXMLElement('<call><eacoin method="getbalance"><sessid>'.$sess.'</sessid></eacoin></call>');
$e=node($d->dispatch(MODEL,'eacoin','getbalance',$get),'eacoin');ok((string)$e->balance==='57000','getbalance');
$checkout=new SimpleXMLElement('<call><eacoin method="checkout"><sessid>'.$sess.'</sessid></eacoin></call>');
$e=node($d->dispatch(MODEL,'eacoin','checkout',$checkout),'eacoin');ok(count($e->children())===0,'checkout empty');
$e=node($d->dispatch(MODEL,'eacoin','getbalance',$get),'eacoin');ok((string)$e->balance==='57300','checkout resets session fallback');
foreach(['getlog','getoplog','getcampaign'] as $m){$e=node($d->dispatch(MODEL,'eacoin',$m,callNode('eacoin',$m)),'eacoin');ok((string)$e->topic->sumdate['__type']==='str'&&(string)$e->topic->sumdate==='0',$m.' topic');}

$e=node($d->dispatch(MODEL,'eacoin','opcheckin',callNode('eacoin','opcheckin')),'eacoin');ok(isset($e->sessid)&&count($e->children())===1,'opcheckin shape');

putenv('VFG_CARDMNG_MODE');putenv('VFG_CARDMNG_INQUIRE_MODE');
echo "cardmng/eacoin parity OK\n";
