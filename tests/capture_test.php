<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=__DIR__.'/../src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\App;
use Mfg\Debug\CaptureStore;
use Mfg\Protocol\EamuseProtocol;
use Mfg\Protocol\KBinXml;
use Mfg\Storage\Database;

function cp_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function cp_rm(string $path):void{
    if(!is_dir($path)){@unlink($path);return;}
    foreach(scandir($path)?:[] as $name){if($name==='.'||$name==='..')continue;cp_rm($path.DIRECTORY_SEPARATOR.$name);}
    @rmdir($path);
}

$old=getenv('VFG_CAPTURE_DIR');putenv('VFG_CAPTURE_DIR');
$disabled=new CaptureStore();cp_ok(!$disabled->enabled(),'captures must be disabled by default');
cp_ok($disabled->saveText('requests','disabled','x')===null,'disabled capture must not write');

$dir=sys_get_temp_dir().'/mfg_capture_'.bin2hex(random_bytes(5));putenv('VFG_CAPTURE_DIR='.$dir);
$storeA=new CaptureStore();$storeB=new CaptureStore();
$p1=$storeA->saveText('requests','a.b','one');$p2=$storeB->saveText('responses','a.b','two');$p3=$storeA->saveJson('transport','a.b',['used_kbin'=>true]);
cp_ok($p1!==null&&str_contains($p1,'0001_a.b.xml'),'capture seq 1');
cp_ok($p2!==null&&str_contains($p2,'0002_a.b.xml'),'capture seq shared across instances');
cp_ok($p3!==null&&str_contains($p3,'0003_a.b.json'),'capture seq 3');
cp_ok(trim((string)file_get_contents($dir.'/.seq'))==='3','capture sequence file');

// Reset the directory and exercise the App itself with a real encoded request.
cp_rm($dir);mkdir($dir,0775,true);
$info='1-01234567-89ab';$xml='<call model="VFG:J:A:A:2025122300" srcid="CAPTURE-PCB"><services method="get"/></call>';
$bin=KBinXml::encode($xml,'UTF-8',true);$wire=EamuseProtocol::encodeTransport($bin,$info,'lz77');
$_SERVER['HTTP_X_EAMUSE_INFO']=$info;$_SERVER['HTTP_X_COMPRESS']='lz77';$_SERVER['HTTP_HOST']='127.0.0.1:8080';$_GET=[];
$app=new App(new Database('sqlite::memory:'));
$method=new ReflectionMethod(App::class,'handleEamuse');
ob_start();$method->invoke($app,$wire);$reply=(string)ob_get_clean();
$replyBin=EamuseProtocol::decodeTransport($reply,$info,'lz77');$decoded=KBinXml::decode($replyBin);
cp_ok(str_contains($decoded['xml'],'<services'),'captured request response still valid');

$req=glob($dir.'/requests/*_services_get.xml')?:[];$resp=glob($dir.'/responses/*_services_get.xml')?:[];
$tb=glob($dir.'/transport/*_services_get.bin')?:[];$tj=glob($dir.'/transport/*_services_get.json')?:[];
cp_ok(count($req)===1&&count($resp)===1,'decoded request/response captures');
cp_ok(count($tb)===1&&count($tj)===1,'transport binary/meta captures');
cp_ok(str_contains((string)file_get_contents($req[0]),'<services method="get"'),'request capture payload');
cp_ok(str_contains((string)file_get_contents($resp[0]),'<services expire="10800"'),'response capture payload');
$meta=json_decode((string)file_get_contents($tj[0]),true);
cp_ok(is_array($meta)&&($meta['used_kbin']??false)===true,'transport kbin metadata');
cp_ok(($meta['kbin_encoding']??'')==='UTF-8'&&($meta['kbin_compressed']??false)===true,'transport metadata fidelity');
cp_ok(($meta['module']??'')==='services'&&($meta['method']??'')==='get','transport route metadata');

if($old===false)putenv('VFG_CAPTURE_DIR');else putenv('VFG_CAPTURE_DIR='.$old);cp_rm($dir);
echo "optional real-client capture pipeline OK\n";
