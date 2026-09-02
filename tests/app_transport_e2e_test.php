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
function at_eamuse(App $app,ReflectionMethod $invoke,string $xml,string $info='1-01234567-89ab',string $compress='lz77',bool $kbin=true,bool $kbinCompressed=true):array{
    $_SERVER['HTTP_X_EAMUSE_INFO']=$info;$_SERVER['HTTP_X_COMPRESS']=$compress;$_SERVER['HTTP_HOST']='127.0.0.1:8080';
    $payload=$kbin?KBinXml::encode($xml,'UTF-8',$kbinCompressed):$xml;
    $wire=EamuseProtocol::encodeTransport($payload,$info!==''?$info:null,$compress);
    ob_start();$invoke->invoke($app,$wire);$responseWire=(string)ob_get_clean();
    at_ok($responseWire!=='','App emitted empty response');
    $decodedWire=EamuseProtocol::decodeTransport($responseWire,$info!==''?$info:null,$compress);
    if($kbin){
        at_ok(KBinXml::isBinary($decodedWire),'App did not mirror kbin transport');$meta=KBinXml::decode($decodedWire);
        at_ok($meta['encoding']==='UTF-8','kbin encoding not mirrored');at_ok($meta['compressed']===$kbinCompressed,'kbin compressed-name flag not mirrored');
        return [new SimpleXMLElement($meta['xml']),$meta];
    }
    at_ok(!KBinXml::isBinary($decodedWire),'plain request unexpectedly returned kbin');
    return [new SimpleXMLElement($decodedWire),null];
}
function at_aog(App $app,ReflectionMethod $invoke,string $path,array $form):SimpleXMLElement{
    ob_start();$invoke->invoke($app,$path,http_build_query($form));$xml=(string)ob_get_clean();at_ok($xml!=='','AOG App response empty');return new SimpleXMLElement($xml);
}

$db=new Database('sqlite::memory:');$app=new App($db);
$eamuse=new ReflectionMethod(App::class,'handleEamuse');$eamuse->setAccessible(true);
$aog=new ReflectionMethod(App::class,'handleAog');$aog->setAccessible(true);
$model='VFG:J:A:A:2025122300';$info='1-01234567-89ab';

// Full encrypted + outer LZ77 + UTF-8 KBin round-trip through App and dispatcher.
$request='<?xml version="1.0" encoding="UTF-8"?><call model="'.$model.'" srcid="00010203040506070809"><services method="get"/></call>';
[$root,$meta]=at_eamuse($app,$eamuse,$request,$info,'lz77',true,true);
at_ok($root->getName()==='response','response root mismatch');at_ok(isset($root->services),'services response missing');
at_ok((string)$root->services['status']==='0','services status');at_ok(isset($root->services->item),'services list empty');

// Plain XML/no-compression compatibility path.
[$plainRoot]=at_eamuse($app,$eamuse,$request,'','none',false,false);
at_ok(isset($plainRoot->services)&&(string)$plainRoot->services['status']==='0','plain App dispatch failed');

// Strict card mode must quarantine the exact malformed Spice/KAMUNITY identity
// even after KBin + RC4 + LZ77 encode/decode.
$garbled="\u{E09B}ﾞ";putenv('VFG_CARDMNG_MODE=strict');
$cardXml='<?xml version="1.0" encoding="UTF-8"?><call model="'.$model.'"><cardmng method="inquire" cardid="'.htmlspecialchars($garbled,ENT_QUOTES|ENT_XML1,'UTF-8').'" cardtype="2104083072"/></call>';
[$cardRoot]=at_eamuse($app,$eamuse,$cardXml,$info,'lz77',true,true);
at_ok(isset($cardRoot->cardmng)&&(string)$cardRoot->cardmng['status']==='112','strict malformed card transport');putenv('VFG_CARDMNG_MODE');

// PASELI checkin must survive the complete binary transport path and return a wallet.
$coinXml='<?xml version="1.0" encoding="UTF-8"?><call model="'.$model.'"><eacoin method="checkin"><cardid __type="str">E0047CC78DFBA459</cardid><passwd __type="str">1234</passwd></eacoin></call>';
[$coinRoot]=at_eamuse($app,$eamuse,$coinXml,$info,'lz77',true,true);$coin=$coinRoot->eacoin;
at_ok(isset($coin)&&(string)$coin['status']==='0','eacoin transport status');at_ok((int)$coin->balance>0,'eacoin balance');at_ok(strlen((string)$coin->sessid)===16,'eacoin sessid');

// Exercise form parsing and AOG routing through App, not just the dispatcher.
$login=at_aog($app,$aog,'/aog/login',['user_id'=>'APP-E2E','guest'=>'0']);$sid=(string)$login->auth->session_id;at_ok($sid!=='','AOG login session');
$literal='round-trip-state';$write=at_aog($app,$aog,'/aog/client_state_write',['pcuid'=>$sid,'kind'=>'profile','data'=>base64_encode($literal)]);
at_ok((string)$write->serv_st->code==='0','AOG state write status');
$read=at_aog($app,$aog,'/aog/client_state_read',['pcuid'=>$sid,'one_kind'=>'profile']);
at_ok(isset($read->state->data),'AOG state read missing');$back=base64_decode((string)$read->state->data,true);at_ok($back===$literal,'AOG state round-trip mismatch');

// A non-call KBin document still has to produce valid XML while preserving KBin metadata.
$fallbackXml='<?xml version="1.0"?><notcall/>';
[$fallback,$fallbackMeta]=at_eamuse($app,$eamuse,$fallbackXml,$info,'lz77',true,false);
at_ok($fallbackMeta['compressed']===false,'fallback kbin metadata not mirrored');

putenv('VFG_CARDMNG_MODE');
echo "App transport/integration E2E OK\n";
