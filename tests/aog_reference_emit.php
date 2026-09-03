<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Aog\Dispatcher;
use Mfg\Storage\Database;

$_SERVER['HTTP_HOST']='127.0.0.1:8080';
unset($_SERVER['HTTP_X_FORWARDED_PROTO'],$_SERVER['HTTPS']);
putenv('VFG_EVENT_TAKU=min');
putenv('VFG_GACHA_ALL=0');

$d=new Dispatcher(new Database('sqlite::memory:'));
$cases=[
    'appli_boot'=>[],
    'appli_info'=>[],
    'get_menudata'=>[],
    'keep_alive'=>[],
    'get_jongstone_info'=>[],
    'get_mg'=>[],
    'mission_date'=>[],
    'player_record'=>[],
    'get_record'=>[],
    'get_haifu_list'=>[],
    'get_haifu_data'=>[],
    'present_done'=>['done_ids'=>'10,20'],
    'competition_entry'=>[],
    'chk_tabooword'=>['str'=>'PARITY-NAME'],
    'end_show'=>['voltage'=>'1234','contribute_percent'=>'87','bonus'=>'45'],
];
$out=[];
foreach($cases as $name=>$form)$out[$name]=$d->dispatch($name,$form);
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
