<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=__DIR__.'/../src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\App;
use Mfg\Storage\Database;

function h_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$app=new App(new Database('sqlite::memory:'));
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['HTTP_HOST']='127.0.0.1:8080';

foreach(['/','/health','/status'] as $path){
    $_SERVER['REQUEST_URI']=$path;ob_start();$app->handle();$plain=(string)ob_get_clean();
    h_ok(str_contains($plain,"VFG local server ok\n"),$path.' compatibility body');
    h_ok(str_contains($plain,"e-amuse: http://127.0.0.1:8080\n"),$path.' e-amuse URL');
    h_ok(str_contains($plain,"game:    http://127.0.0.1:8080/aog\n"),$path.' game URL');
    h_ok(http_response_code()===200,$path.' status');
}

$_SERVER['REQUEST_URI']='/healthz';ob_start();$app->handle();$json=(string)ob_get_clean();
$data=json_decode($json,true);h_ok(is_array($data)&&($data['ok']??false)===true,'/healthz JSON');
h_ok(($data['service']??'')==='mfg-private-server-php','/healthz service');

echo "health endpoints OK\n";
