<?php

declare(strict_types=1);

require __DIR__.'/../src/Protocol/EamuseProtocol.php';
require __DIR__.'/../src/Protocol/KBinXml.php';

use Mfg\Protocol\EamuseProtocol;
use Mfg\Protocol\KBinXml;

function ce_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

/** @return array{0:string,1:list<string>} */
function ce_request(string $url,string $body):array
{
    $ctx=stream_context_create(['http'=>[
        'method'=>'POST','ignore_errors'=>true,'timeout'=>5,
        'header'=>'Content-Type: application/x-www-form-urlencoded',
        'content'=>$body,
    ]]);
    $data=@file_get_contents($url,false,$ctx);$headers=$http_response_header??[];
    ce_ok($data!==false,'HTTP request failed '.$url);
    ce_ok(isset($headers[0])&&preg_match('/\s200\s/',$headers[0])===1,'non-200 '.$url.' '.($headers[0]??''));
    return [(string)$data,array_values($headers)];
}

function ce_aog(string $base,string $name,array $form=[]):SimpleXMLElement
{
    [$body]=ce_request($base.'/aog/'.$name,http_build_query($form));
    $root=new SimpleXMLElement($body);
    ce_ok($root->getName()==='root',$name.' root');
    ce_ok(isset($root->serv_st->code)&&(string)$root->serv_st->code==='0',$name.' serv_st');
    return $root;
}

function ce_eamuse(string $base,string $xml):SimpleXMLElement
{
    $info='1-fedcba98-7654';
    $kbin=KBinXml::encode($xml,'UTF-8',true);
    $wire=EamuseProtocol::encodeTransport($kbin,$info,'lz77');
    $ctx=stream_context_create(['http'=>[
        'method'=>'POST','ignore_errors'=>true,'timeout'=>5,
        'header'=>implode("\r\n",[
            'User-Agent: EAMUSE','Content-Type: application/octet-stream',
            'X-Eamuse-Info: '.$info,'X-Compress: lz77','Content-Length: '.strlen($wire),
        ]),
        'content'=>$wire,
    ]]);
    $reply=@file_get_contents($base.'/?model=VFG:J:A:A:2025122300',false,$ctx);
    $headers=$http_response_header??[];
    ce_ok($reply!==false,'e-Amusement HTTP failed');
    ce_ok(isset($headers[0])&&preg_match('/\s200\s/',$headers[0])===1,'e-Amusement non-200');
    $decodedWire=EamuseProtocol::decodeTransport((string)$reply,$info,'lz77');
    ce_ok(KBinXml::isBinary($decodedWire),'e-Amusement response lost kbin');
    $decoded=KBinXml::decode($decodedWire);
    ce_ok($decoded['encoding']==='UTF-8'&&$decoded['compressed']===true,'e-Amusement kbin metadata');
    return new SimpleXMLElement($decoded['xml']);
}

$base=rtrim((string)(getenv('TEST_BASE_URL')?:'http://127.0.0.1:18080'),'/');
$model='VFG:J:A:A:2025122300';
$cardId='E0047CC78DFBA459';
$pin='1234';

// Prepare the persistent-card state once. The captured production sequence below
// starts with inquire because the physical card had already been registered.
$issued=ce_eamuse($base,'<call model="'.$model.'"><vfgcard method="getrefid" cardid="'.$cardId.'" passwd="'.$pin.'"/></call>');
ce_ok(isset($issued->vfgcard),'setup getrefid node');
$refid=(string)($issued->vfgcard['refid']??$issued->vfgcard['dataid']??'');
ce_ok($refid!=='','setup refid missing');
ce_ok(!isset($issued->vfgcard['status']),'getrefid successful shape must omit status');
$bound=ce_eamuse($base,'<call model="'.$model.'"><vfgcard method="bindmodel" refid="'.$refid.'"/></call>');
ce_ok(isset($bound->vfgcard),'setup bindmodel node');
ce_ok(!isset($bound->vfgcard['status'])&&(string)$bound->vfgcard['dataid']===$refid,'setup bindmodel minimal success shape');

// Captured real-client card-entry order (notes/progress.md in the Python
// reference): inquire -> update_refer -> authpass, then the AOG load chain.
$inquire=ce_eamuse($base,'<call model="'.$model.'"><vfgcard method="inquire" cardid="'.$cardId.'" cardtype="1"/></call>');
ce_ok(isset($inquire->vfgcard),'vfgcard.inquire node');
ce_ok(!isset($inquire->vfgcard['status']),'successful inquire must omit status');
ce_ok((string)$inquire->vfgcard['refid']===$refid&&(string)$inquire->vfgcard['dataid']===$refid,'inquire identity');
ce_ok((string)$inquire->vfgcard['binded']==='1','inquire bound flag');
ce_ok((string)$inquire->vfgcard['newflag']==='0','inquire newflag');
ce_ok(ctype_digit((string)$inquire->vfgcard['lastupdate']),'inquire lastupdate');

$refer=ce_eamuse($base,'<call model="'.$model.'"><vfgac method="update_refer"><refid __type="str">'.$refid.'</refid></vfgac></call>');
ce_ok(isset($refer->vfgac)&&(string)$refer->vfgac['status']==='0','vfgac.update_refer');

$auth=ce_eamuse($base,'<call model="'.$model.'"><vfgcard method="authpass" refid="'.$refid.'" pass="'.$pin.'"/></call>');
ce_ok(isset($auth->vfgcard)&&(string)$auth->vfgcard['status']==='0','vfgcard.authpass');

$info=ce_aog($base,'appli_info');
ce_ok(isset($info->info_data),'appli_info data');

$login=ce_aog($base,'login',['user_id'=>$refid,'dataid'=>$refid,'guest'=>'0']);
$sid=(string)($login->auth->session_id??'');
ce_ok($sid!=='','login session');

$menu=ce_aog($base,'get_menudata',['pcuid'=>$sid]);
ce_ok(isset($menu->menudata->mpdata),'get_menudata mpdata');
ce_ok(isset($menu->menudata->battle_item_settings->basic_settings),'get_menudata basic_settings');
ce_ok(isset($menu->menudata->battle_item_settings->playmode_settings),'get_menudata playmode_settings');

// A fresh profile legitimately has no version_game state. The real client probes
// this key before the rest of the card-entry load chain.
$version=ce_aog($base,'client_state_read',['pcuid'=>$sid,'one_kind'=>'version_game']);
ce_ok(!isset($version->state),'fresh version_game probe should be an empty success');

$mission=ce_aog($base,'mission_date',['pcuid'=>$sid]);
$missionKinds=[];foreach($mission->info_data as $n)$missionKinds[]=(string)$n['kind'];
ce_ok(in_array('missions',$missionKinds,true),'mission_date missions');

$gacha=ce_aog($base,'gacha_info',['pcuid'=>$sid]);
ce_ok(isset($gacha->gacha_schedule),'gacha_info schedule');
ce_ok(count($gacha->gacha_schedule->children())>=1,'gacha_info empty schedule');

ce_aog($base,'client_state_read',['pcuid'=>$sid]);
$record=ce_aog($base,'player_record',['pcuid'=>$sid]);
ce_ok(isset($record->player_record),'player_record node');

echo "captured card-entry HTTP sequence OK\n";
