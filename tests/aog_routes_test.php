<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Aog\Dispatcher;
use Mfg\Storage\Database;

$dbFile=sys_get_temp_dir().'/mfg_routes_'.bin2hex(random_bytes(4)).'.sqlite';
$db=new Database($dbFile);
$d=new Dispatcher($db);

// Original Python GAME_HANDLERS coverage. Stateful endpoints receive a small
// synthetic form so the smoke test exercises their normal path.
$routes=[
'appli_boot','appli_info','login','logout','create_player','get_menudata','keep_alive',
'client_state_read','client_state_write','entry_game','gget','gpost','end_game','kiken_game',
'end_show','reconnect','chk_tabooword','dojo_get_status','dojo_set_slot','dojo_gain_soul',
'gacha_info','gacha_log','req_draw_gacha','get_gacha_result','music_gacha_play',
'music_gacha_play_reserve','gchat','gget_stamp_info','player_record','get_record',
'get_haifu_list','get_haifu_data','get_jongstone_info','get_mg','mission_date','present_done',
'competition_entry','item_gain_log','item_consume_log','notice_done','important_notice_done',
'set_favorite_character','odekake_done','coop_done','eashop_done'
];

$sessionXml=$d->dispatch('login',['user_id'=>'ROUTE_TEST']);
preg_match('#<session_id>([^<]+)</session_id>#',$sessionXml,$m);$pcuid=$m[1]??'';
if($pcuid==='')throw new RuntimeException('login session missing');

$forms=[
'create_player'=>['user_id'=>'ROUTE_TEST','name'=>'TEST'],
'get_menudata'=>['pcuid'=>$pcuid],
'client_state_read'=>['pcuid'=>$pcuid],
'client_state_write'=>['pcuid'=>$pcuid,'kind'=>'test','data'=>base64_encode('ok')],
'entry_game'=>['pcuid'=>$pcuid,'gmode'=>'1'],
'gget'=>['pcuid'=>$pcuid,'ready'=>'0'],
'gpost'=>['pcuid'=>$pcuid,'kind'=>'0'],
'end_game'=>['pcuid'=>$pcuid], 'kiken_game'=>['pcuid'=>$pcuid],
'end_show'=>['voltage'=>'100','contribute_percent'=>'100','bonus'=>'0'],
'reconnect'=>['pcuid'=>$pcuid], 'chk_tabooword'=>['str'=>'TEST'],
'dojo_get_status'=>['pcuid'=>$pcuid],
'dojo_set_slot'=>['pcuid'=>$pcuid,'slot_id'=>'0','set_character'=>'OID_CHARACTER_1'],
'dojo_gain_soul'=>['pcuid'=>$pcuid,'slot_id'=>'0'],
'req_draw_gacha'=>['pcuid'=>$pcuid,'gacha_id'=>'0','count'=>'1'],
'get_gacha_result'=>['pcuid'=>$pcuid],
'music_gacha_play_reserve'=>['pcuid'=>$pcuid,'gacha_id'=>'91'],
'music_gacha_play'=>['pcuid'=>$pcuid],
'gchat'=>['tid'=>'1','mid'=>'1','pindex'=>'0','name'=>'TEST','contents'=>'TableSticker001'],
'gget_stamp_info'=>['must'=>'0,0,1,0,1','stamp_info'=>'0,0,,'],
'present_done'=>['done_ids'=>'1,2'],
'competition_entry'=>['pcuid'=>$pcuid],
];

foreach($routes as $route){
    $xml=$d->dispatch($route,$forms[$route]??[]);
    if(!is_string($xml)||$xml==='')throw new RuntimeException("$route empty response");
    libxml_use_internal_errors(true);
    $root=simplexml_load_string($xml);
    if($root===false)throw new RuntimeException("$route invalid XML: $xml");
}

@unlink($dbFile);@unlink($dbFile.'-wal');@unlink($dbFile.'-shm');
echo 'AOG routes OK: '.count($routes)."\n";
