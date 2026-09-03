<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Protocol\EamuseProtocol;
use Mfg\Protocol\KBinXml;

const TR_INFO='1-01234567-89ab';

function tr_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function tr_xml():string{return '<?xml version="1.0" encoding="UTF-8"?><call model="VFG:J:A:A:2025122300"><eventlog method="write"><gamesession __type="s64">9223372036854775807</gamesession><message __type="str">麻雀ファイトガール</message><globalip __type="ip4">127.0.0.1</globalip></eventlog></call>';}
function tr_assert(string $xml):void{
    $d=new DOMDocument();tr_ok(@$d->loadXML($xml,LIBXML_NONET),'decoded XML invalid');
    $r=$d->documentElement;tr_ok($r instanceof DOMElement&&$r->tagName==='call','root');
    tr_ok($r->getAttribute('model')==='VFG:J:A:A:2025122300','model');
    $e=$d->getElementsByTagName('eventlog')->item(0);tr_ok($e instanceof DOMElement&&$e->getAttribute('method')==='write','eventlog');
    tr_ok($d->getElementsByTagName('gamesession')->item(0)?->textContent==='9223372036854775807','s64');
    tr_ok($d->getElementsByTagName('message')->item(0)?->textContent==='麻雀ファイトガール','UTF-8 string');
    tr_ok($d->getElementsByTagName('globalip')->item(0)?->textContent==='127.0.0.1','ip4');
}

$mode=$argv[1]??'';$path=$argv[2]??'';tr_ok($path!=='','usage: php transport_reference_interop.php emit|check FILE');
if($mode==='emit'){
    $kbin=KBinXml::encode(tr_xml(),'UTF-8',true);
    $wire=EamuseProtocol::encodeTransport($kbin,TR_INFO,'lz77');
    tr_ok(file_put_contents($path,$wire)!==false,'cannot write PHP transport');
    $round=EamuseProtocol::decodeTransport($wire,TR_INFO,'lz77');
    $meta=KBinXml::decode($round);tr_assert($meta['xml']);
    echo "PHP RC4/LZ77/KBin transport emitted\n";exit(0);
}
if($mode==='check'){
    $wire=file_get_contents($path);tr_ok($wire!==false,'cannot read Python transport');
    $kbin=EamuseProtocol::decodeTransport($wire,TR_INFO,'lz77');
    tr_ok(KBinXml::isBinary($kbin),'Python transport did not decode to KBin');
    $meta=KBinXml::decode($kbin);tr_ok($meta['encoding']==='UTF-8'&&$meta['compressed']===true,'Python KBin metadata');
    tr_assert($meta['xml']);
    echo "Python reference RC4/LZ77/KBin transport decoded by PHP\n";exit(0);
}
throw new RuntimeException('unknown mode '.$mode);
