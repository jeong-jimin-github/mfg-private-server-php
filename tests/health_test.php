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
unset($_SERVER['HTTP_X_FORWARDED_PROTO'],$_SERVER['HTTPS']);

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

// services.get advertises this exact path. The Python reference returns a
// simple 200 "ok" here rather than handing an empty GET to e-Amusement XML.
$_SERVER['REQUEST_URI']='/core/keepalive?pa=127.0.0.1&ia=127.0.0.1&ga=127.0.0.1&ma=127.0.0.1&t1=2&t2=10';
ob_start();$app->handle();$keepalive=(string)ob_get_clean();
h_ok($keepalive==='ok','/core/keepalive body');
h_ok(http_response_code()===200,'/core/keepalive status');

// Shared hosting commonly terminates TLS at a reverse proxy. Public URLs must
// still be advertised as https even when PHP itself receives the request over
// the proxy's internal HTTP connection.
$_SERVER['HTTP_HOST']='jm0730.iwinv.net';
$_SERVER['HTTP_X_FORWARDED_PROTO']='https';
$_SERVER['REQUEST_URI']='/health';
ob_start();$app->handle();$proxied=(string)ob_get_clean();
h_ok(str_contains($proxied,"e-amuse: https://jm0730.iwinv.net\n"),'forwarded https e-amuse URL');
h_ok(str_contains($proxied,"game:    https://jm0730.iwinv.net/aog\n"),'forwarded https game URL');
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);

echo "health/keepalive/proxy endpoints OK\n";
