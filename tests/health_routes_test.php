<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=__DIR__.'/../src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\App;
use Mfg\Storage\Database;

function hr_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$app=new App(new Database('sqlite::memory:'));
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['HTTP_HOST']='127.0.0.1:8080';

foreach(['/','/health','/status'] as $path){
    $_SERVER['REQUEST_URI']=$path;
    ob_start();$app->handle();$body=(string)ob_get_clean();
    hr_ok(str_contains($body,"VFG local server ok\n"),$path.' status text');
    hr_ok(str_contains($body,"e-amuse: http://127.0.0.1:8080\n"),$path.' e-amuse url');
    hr_ok(str_contains($body,"game:    http://127.0.0.1:8080/aog\n"),$path.' game url');
}

$_SERVER['REQUEST_URI']='/healthz';
ob_start();$app->handle();$body=(string)ob_get_clean();
$j=json_decode($body,true);hr_ok(is_array($j)&&($j['ok']??false)===true,'healthz json');
hr_ok(($j['service']??'')==='mfg-private-server-php','healthz service');

echo "health routes parity OK\n";
