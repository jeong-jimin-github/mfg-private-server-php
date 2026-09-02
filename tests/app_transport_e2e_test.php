<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=__DIR__.'/../src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\App;
use Mfg\Protocol\EamuseProtocol;
use Mfg\Protocol\KBinXml;
use Mfg\Storage\Database;

function at_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$db=new Database('sqlite::memory:');$app=new App($db);
$invoke=new ReflectionMethod(App::class,'handleEamuse');$invoke->setAccessible(true);
$info='1-01234567-89ab';
$_SERVER['HTTP_X_EAMUSE_INFO']=$info;
$_SERVER['HTTP_X_COMPRESS']='lz77';
$_SERVER['HTTP_HOST']='127.0.0.1:8080';

$request='<?xml version="1.0" encoding="UTF-8"?><call model="VFG:J:A:A:2025122300" srcid="00010203040506070809"><services method="get"/></call>';
$kbin=KBinXml::encode($request,'UTF-8',true);
at_ok(KBinXml::isBinary($kbin),'request not kbin');
$wire=EamuseProtocol::encodeTransport($kbin,$info,'lz77');
ob_start();$invoke->invoke($app,$wire);$responseWire=(string)ob_get_clean();
at_ok($responseWire!=='','App emitted empty binary response');
$responseKbin=EamuseProtocol::decodeTransport($responseWire,$info,'lz77');
at_ok(KBinXml::isBinary($responseKbin),'App did not mirror kbin transport');
$decoded=KBinXml::decode($responseKbin);
at_ok($decoded['encoding']==='UTF-8','kbin encoding not mirrored');
at_ok($decoded['compressed']===true,'kbin compressed-name flag not mirrored');
$root=new SimpleXMLElement($decoded['xml']);
at_ok($root->getName()==='response','response root mismatch');
at_ok(isset($root->services),'services response missing');
at_ok((string)$root->services['status']==='0','services status');
at_ok(isset($root->services->item),'services list empty');

// Also exercise the non-kbin / non-compressed App path; the same dispatcher
// must remain reachable without transport wrappers used by newer clients.
$_SERVER['HTTP_X_EAMUSE_INFO']='';
$_SERVER['HTTP_X_COMPRESS']='none';
ob_start();$invoke->invoke($app,$request);$plainWire=(string)ob_get_clean();
$plain=EamuseProtocol::decodeTransport($plainWire,null,'none');
at_ok(!KBinXml::isBinary($plain),'plain request unexpectedly returned kbin');
$plainRoot=new SimpleXMLElement($plain);
at_ok(isset($plainRoot->services)&&(string)$plainRoot->services['status']==='0','plain App dispatch failed');

// Invalid transport payload must still return a parseable compatibility response
// using the same outer RC4/LZ77/KBin flavor as the request metadata.
$_SERVER['HTTP_X_EAMUSE_INFO']=$info;
$_SERVER['HTTP_X_COMPRESS']='lz77';
$garbageKbin=KBinXml::encode('<?xml version="1.0"?><notcall/>','UTF-8',false);
$garbageWire=EamuseProtocol::encodeTransport($garbageKbin,$info,'lz77');
ob_start();$invoke->invoke($app,$garbageWire);$fallbackWire=(string)ob_get_clean();
$fallbackKbin=EamuseProtocol::decodeTransport($fallbackWire,$info,'lz77');
at_ok(KBinXml::isBinary($fallbackKbin),'fallback lost kbin flavor');
$fallback=KBinXml::decode($fallbackKbin);
at_ok($fallback['compressed']===false,'fallback kbin metadata not mirrored');
new SimpleXMLElement($fallback['xml']);

echo "App RC4/LZ77/KBin E2E OK\n";
