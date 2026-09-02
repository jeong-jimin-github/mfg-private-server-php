<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Eamuse\Dispatcher;
use Mfg\Storage\Database;

function ce_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function ce_call(string $module,string $method,array $attrs=[],array $children=[]):SimpleXMLElement{
    $root=new SimpleXMLElement('<call model="VFG:J:A:A:2025122300"/>');
    $node=$root->addChild($module);$node->addAttribute('method',$method);
    foreach($attrs as $k=>$v)$node->addAttribute((string)$k,(string)$v);
    foreach($children as $k=>$v)$node->addChild((string)$k,(string)$v);
    return $root;
}
/** @return array<string,string> */
function ce_attrs(string $xml,string $node):array{
    $r=new SimpleXMLElement($xml);$n=$r->{$node};ce_ok(isset($n[0]),'missing '.$node);$out=[];foreach($n[0]->attributes() as $k=>$v)$out[(string)$k]=(string)$v;return $out;
}
function ce_node(string $xml,string $node):SimpleXMLElement{$r=new SimpleXMLElement($xml);return $r->{$node};}

putenv('VFG_CARDMNG_MODE');putenv('VFG_CARDMNG_INQUIRE_MODE');
$db=new Database('sqlite::memory:');
$d=new Dispatcher($db,'http://127.0.0.1:8080');
$model='VFG:J:A:A:2025122300';$card='E0047CC78DFBA459';$garbled="\u{E09B}ﾞ";

// Fresh canonical card is CARD_NEW and inquiry must not create state.
$a=ce_attrs($d->dispatch($model,'cardmng','inquire',ce_call('cardmng','inquire',['cardid'=>$card])),'cardmng');
ce_ok($a===['status'=>'112'],'new card inquiry');
ce_ok($db->getCard($card)===null,'inquire mutated card state');

// Empty/decode-failed calls are BAD_REQUEST and must not touch fallback identity.
$a=ce_attrs($d->dispatch($model,'cardmng','getrefid',new SimpleXMLElement('<call/>')),'cardmng');
ce_ok($a===['status'=>'110'],'empty getrefid');
$a=ce_attrs($d->dispatch($model,'cardmng','bindmodel',new SimpleXMLElement('<call/>')),'cardmng');
ce_ok($a===['status'=>'110'],'empty bindmodel');
ce_ok($db->getCard($card)===null,'empty request created fallback card');

// Canonical registration uses the minimal AVS success shape: refid/dataid only.
$a=ce_attrs($d->dispatch($model,'cardmng','getrefid',ce_call('cardmng','getrefid',['cardid'=>$card,'passwd'=>'1234'])),'cardmng');
ce_ok(array_keys($a)===['refid','dataid'],'getrefid shape');
ce_ok($a['refid']===$a['dataid'],'getrefid identity mismatch');$refid=$a['refid'];

// Issued but unbound inquiry.
$a=ce_attrs($d->dispatch($model,'cardmng','inquire',ce_call('cardmng','inquire',['cardid'=>$card])),'cardmng');
ce_ok(!isset($a['status'])&&$a['binded']==='0'&&$a['newflag']==='1','unbound inquire');
ce_ok(!isset($a['lastupdate']),'unbound lastupdate');

// Bind by refid only and verify bound inquiry requires lastupdate.
$a=ce_attrs($d->dispatch($model,'cardmng','bindmodel',ce_call('cardmng','bindmodel',['refid'=>$refid])),'cardmng');
ce_ok($a===['dataid'=>$refid],'bindmodel shape');
$a=ce_attrs($d->dispatch($model,'cardmng','inquire',ce_call('cardmng','inquire',['cardid'=>$card])),'cardmng');
ce_ok(!isset($a['status'])&&$a['binded']==='1'&&$a['newflag']==='0','bound inquire');
ce_ok(isset($a['lastupdate'])&&ctype_digit($a['lastupdate']),'bound lastupdate');

// Strict mode quarantines the exact malformed Spice/KAMUNITY shape.
$before=$db->getCard($card);putenv('VFG_CARDMNG_MODE=strict');
$a=ce_attrs($d->dispatch($model,'cardmng','inquire',ce_call('cardmng','inquire',['cardid'=>$garbled])),'cardmng');
ce_ok($a===['status'=>'112'],'strict malformed inquire');
$a=ce_attrs($d->dispatch($model,'cardmng','getrefid',ce_call('cardmng','getrefid',['cardid'=>$garbled,'passwd'=>'<car'])),'cardmng');
ce_ok($a===['status'=>'110'],'strict malformed getrefid');
ce_ok($db->getCard($card)===$before,'strict mode mutated fallback card');putenv('VFG_CARDMNG_MODE');

// Default compat mode maps malformed requests back to the configured local identity.
$a=ce_attrs($d->dispatch($model,'cardmng','getrefid',ce_call('cardmng','getrefid',['cardid'=>$garbled,'passwd'=>'<car'])),'cardmng');
ce_ok($a===['refid'=>$refid,'dataid'=>$refid],'compat malformed getrefid');
$a=ce_attrs($d->dispatch($model,'cardmng','inquire',ce_call('cardmng','inquire',['cardid'=>$garbled])),'cardmng');
ce_ok(!isset($a['status'])&&$a['refid']===$refid,'compat malformed inquire');

// Forced-new inquire mode reproduces the only always-safe response for this dump.
putenv('VFG_CARDMNG_INQUIRE_MODE=new');
$a=ce_attrs($d->dispatch($model,'cardmng','inquire',ce_call('cardmng','inquire',['cardid'=>$card])),'cardmng');
ce_ok($a===['status'=>'112'],'forced new inquire');putenv('VFG_CARDMNG_INQUIRE_MODE');

// Unknown refs are rejected; authpass remains permissive because Spice can corrupt passwd.
$a=ce_attrs($d->dispatch($model,'cardmng','bindmodel',ce_call('cardmng','bindmodel',['refid'=>'A000000000000000'])),'cardmng');
ce_ok($a===['status'=>'110'],'unknown bindmodel');
$a=ce_attrs($d->dispatch($model,'vfgcard','authpass',ce_call('vfgcard','authpass',['refid'=>$refid,'passwd'=>'<car'])),'vfgcard');
ce_ok($a===['status'=>'0'],'authpass compatibility');

// PASELI parity: exact typed checkin fields, mutable local session, clamp at zero.
$xml=$d->dispatch($model,'eacoin','checkin',ce_call('eacoin','checkin'));
$e=ce_node($xml,'eacoin');
ce_ok((string)$e['status']==='0','eacoin checkin status');
ce_ok((string)$e->sequence['__type']==='s16'&&(string)$e->sequence==='0','sequence type');
ce_ok((string)$e->acstatus['__type']==='u8'&&(string)$e->acstatus==='0','acstatus type');
ce_ok((string)$e->balance['__type']==='s32'&&(string)$e->balance==='57300','balance type');
ce_ok((string)$e->sessid['__type']==='str'&&strlen((string)$e->sessid)===16,'sessid type');$sess=(string)$e->sessid;

$xml=$d->dispatch($model,'eacoin','consume',ce_call('eacoin','consume',[],['sessid'=>$sess,'payment'=>'300']));$e=ce_node($xml,'eacoin');
ce_ok((string)$e->balance==='57000'&&(string)$e->autocharge['__type']==='u8','consume balance');
$xml=$d->dispatch($model,'eacoin','getbalance',ce_call('eacoin','getbalance',[],['sessid'=>$sess]));$e=ce_node($xml,'eacoin');
ce_ok((string)$e->balance==='57000','getbalance session');
$xml=$d->dispatch($model,'eacoin','consume',ce_call('eacoin','consume',[],['sessid'=>$sess,'payment'=>'999999']));$e=ce_node($xml,'eacoin');
ce_ok((string)$e->balance==='0','consume clamp');

$xml=$d->dispatch($model,'eacoin','opcheckin',ce_call('eacoin','opcheckin'));$e=ce_node($xml,'eacoin');
ce_ok(count($e->children())===1&&isset($e->sessid)&&(string)$e->sessid['__type']==='str','opcheckin shape');
foreach(['getlog','getoplog','getcampaign'] as $m){$xml=$d->dispatch($model,'eacoin',$m,ce_call('eacoin',$m));$e=ce_node($xml,'eacoin');ce_ok((string)$e->topic->sumdate==='0'&&(string)$e->topic->sumdate['__type']==='str',$m.' topic');}
$d->dispatch($model,'eacoin','checkout',ce_call('eacoin','checkout',[],['sessid'=>$sess]));

putenv('VFG_CARDMNG_MODE');putenv('VFG_CARDMNG_INQUIRE_MODE');
echo "cardmng/eacoin parity OK\n";
