<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Aog\Dispatcher;
use Mfg\Storage\Database;

function sr_root(string $xml): SimpleXMLElement{return new SimpleXMLElement($xml);}

$_SERVER['HTTP_HOST']='127.0.0.1:8080';
unset($_SERVER['HTTP_X_FORWARDED_PROTO'],$_SERVER['HTTPS']);
putenv('VFG_EVENT_TAKU=min');
putenv('VFG_GACHA_ALL=0');

$db=new Database('sqlite::memory:');
$d=new Dispatcher($db);
$ref='PARITY-STATE';
$name='PARITY';

$login=sr_root($d->dispatch('login',['user_id'=>$ref]));
$pcuid=(string)$login->auth->session_id;
$d->dispatch('create_player',['user_id'=>$ref,'name'=>$name]);
$menu=sr_root($d->dispatch('get_menudata',['pcuid'=>$pcuid]));
$mid=(int)$menu->menudata->mpdata->mid;

$playerGame='{"SelectChara":0}';
$custom='custom-state';
$d->dispatch('client_state_write',['mid'=>(string)$mid,'kind'=>'player_game','data'=>base64_encode($playerGame)]);
$d->dispatch('client_state_write',['mid'=>(string)$mid,'kind'=>'customize_item','data'=>base64_encode($custom)]);
$read=sr_root($d->dispatch('client_state_read',['mid'=>(string)$mid,'one_kind'=>'player_game']));
$states=[];
foreach($read->state as $state)$states[(string)$state['kind']]=base64_decode((string)$state->data,true);

$entry=sr_root($d->dispatch('entry_game',['pcuid'=>$pcuid,'gmode'=>'4']));
$e=$entry->entry;
$tid=(int)$e->tid;
$must='VFG:J:A:A:2025122300/'.$pcuid.'/'.$tid.'/0/1/0';
$gget=sr_root($d->dispatch('gget',['pcuid'=>$pcuid,'ready'=>'0','must'=>$must]));
$m=$gget->game->mwait;
$humanStates=[];
if(isset($m->mend->player_0->client_states))foreach($m->mend->player_0->client_states->state as $state)$humanStates[(string)$state['kind']]=base64_decode((string)$state->data,true);

$end=sr_root($d->dispatch('end_game',['pcuid'=>$pcuid]));
$mg=$end->mgresult;
$players=[];
foreach([0,1] as $i){$p=$mg->{'player_'.$i};$players[]=['rank'=>(int)$p->rank,'score'=>(int)$p->score,'uma'=>(int)$p->uma];}

$out=[
    'session_hex'=>preg_match('/^[0-9a-f]{32}$/',$pcuid)===1,
    'menu'=>['name'=>(string)$menu->menudata->mpdata->name,'mid_positive'=>$mid>0,'nima_tenbo'=>(int)$menu->menudata->playmode_list->mode[3]->tenbo],
    'state'=>['count'=>count($states),'player_game'=>$states['player_game']??null],
    'entry'=>[
        'gserv_id'=>(int)$e->gserv_id,'tid'=>$tid,'pindex'=>(int)$e->pindex,'next_sno'=>(int)$e->next_sno,
        'gserv_url'=>(string)$e->gserv_url,'pay_mode'=>(int)$e->pay_mode,'gmode'=>(int)$e->gmode,
        'ste_limit_time'=>(int)$e->ste_limit_time,'naki_limit_time'=>(int)$e->naki_limit_time,
    ],
    'matching'=>[
        'status'=>(int)$m->status,'pnum'=>(int)$m->pnum,'cpu_num'=>(int)$m->cpu_num,'pindex'=>(int)$m->pindex,
        'name'=>(string)$m->epdata_0->name,'mid_positive'=>(int)$m->epdata_0->mid>0,
        'human_ptype'=>(int)$m->mend->player_0['ptype'],'human_zaseki'=>(int)$m->mend->player_0->zaseki,
        'cpu_ptype'=>(int)$m->mend->player_1['ptype'],'cpu_zaseki'=>(int)$m->mend->player_1->zaseki,
        'cpu_name'=>(string)$m->mend->player_1->cpu_name,'states'=>$humanStates,
    ],
    'end'=>['gmode'=>(int)$mg->gmode,'players'=>$players],
];

echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
