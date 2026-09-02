<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Aog\Dispatcher;
use Mfg\Storage\Database;

function am_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function am_xml(string $xml):SimpleXMLElement{$r=new SimpleXMLElement($xml);am_ok($r->getName()==='root','AOG root');am_ok((string)$r->serv_st->code==='0','serv_st');return $r;}

$_SERVER['HTTP_HOST']='127.0.0.1:18080';
$db=new Database('sqlite::memory:');
$d=new Dispatcher($db);

$boot=am_xml($d->dispatch('appli_boot',[]));
am_ok((string)$boot->server_setting->mask_ac_link_scene==='0','boot mask');
am_ok((string)$boot->server_setting->reviewed_version==='false','reviewed_version');
am_ok(!isset($boot->server_setting->enable_player_name_entry),'legacy player-name flag leaked');
am_ok((string)$boot->boot_mes->status==='0','boot status');
am_ok((string)$boot->boot_mes->moserv_url==='http://127.0.0.1:18080/aog','moserv_url');
am_ok((string)$boot->boot_mes->message==='0','boot message');

$login=am_xml($d->dispatch('login',['user_id'=>'MATCH_REF']));
$pcuid=(string)$login->auth->session_id;am_ok($pcuid!=='','session');
$p=$db->getProfile('MATCH_REF');am_ok($p!==null,'profile');
$payload=$p['payload'];
$payload['name']='MATCHER';
$payload['states']['player_game']=json_encode(['SelectChara'=>1],JSON_UNESCAPED_SLASHES);
$payload['states']['customize_item']='custom-state';
$db->saveProfilePayload('MATCH_REF',$payload);

$entry=am_xml($d->dispatch('entry_game',['pcuid'=>$pcuid,'gmode'=>'3']));
am_ok((string)$entry->entry->gmode==='3','entry gmode');
$gget=am_xml($d->dispatch('gget',['pcuid'=>$pcuid,'ready'=>'0','must'=>'0/0/0/0/0/0']));
$m=$gget->game->mwait;
am_ok((string)$m->status==='1','mwait status');
am_ok((string)$m->pnum==='1','mwait pnum');
am_ok((string)$m->cpu_num==='2','sanma cpu count');
am_ok((string)$m->pindex==='0','mwait pindex');
am_ok((string)$m->epdata_0->name==='MATCHER','mwait name');
am_ok((int)$m->epdata_0->mid===(int)$p['player_id'],'mwait mid');
am_ok((string)$m->mend->player_0['ptype']==='1','human ptype');
am_ok((string)$m->mend->player_0->zaseki==='0'&&(string)$m->mend->player_0->cpu_level==='0','human seat');
am_ok(isset($m->mend->player_0->client_states),'client states missing');
$states=[];foreach($m->mend->player_0->client_states->state as $s)$states[(string)$s['kind']]=base64_decode((string)$s->data,true);
am_ok(($states['player_game']??'')===$payload['states']['player_game'],'player_game state');
am_ok(($states['customize_item']??'')==='custom-state','customize state');
am_ok((string)$m->mend->player_1['ptype']==='3'&&(string)$m->mend->player_2['ptype']==='3','CPU players');
am_ok((string)$m->mend->player_1->cpu_name!=='OID_CHARACTER_2','CPU reused selected character');

// Original client separates `must` fields with '/', not commas. Verify the
// misc dispatcher routes a stamp request to the TID supplied at index 2.
$d->dispatch('gchat',['tid'=>'7','mid'=>'1','pindex'=>'0','name'=>'MATCHER','contents'=>'STAMP-SLASH']);
$stamp=am_xml($d->dispatch('gget_stamp_info',['must'=>'0/0/7/0/1','stamp_info'=>'0,0,,']));
am_ok(str_contains($stamp->asXML()?:'','STAMP-SLASH'),'slash-delimited must was not parsed');

echo "AOG boot/matching/must contract OK\n";
