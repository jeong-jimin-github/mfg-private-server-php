<?php

declare(strict_types=1);

require __DIR__.'/../src/Protocol/EamuseProtocol.php';
require __DIR__.'/../src/Protocol/KBinXml.php';

use Mfg\Protocol\EamuseProtocol;
use Mfg\Protocol\KBinXml;

function hf_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$base=rtrim((string)(getenv('TEST_BASE_URL')?:'http://127.0.0.1:18080'),'/');
$info='1-89abcdef-0123';
$xml='<call model="VFG:J:A:A:2025122300" srcid="FACILITYHTTP"><facility method="get"/></call>';
$kbin=KBinXml::encode($xml,'UTF-8',true);
$wire=EamuseProtocol::encodeTransport($kbin,$info,'lz77');
$ctx=stream_context_create(['http'=>[
    'method'=>'POST','ignore_errors'=>true,'timeout'=>5,
    'header'=>implode("\r\n",[
        'User-Agent: EAMUSE','Content-Type: application/octet-stream',
        'X-Eamuse-Info: '.$info,'X-Compress: lz77','Content-Length: '.strlen($wire),
    ]),
    'content'=>$wire,
]]);
$reply=@file_get_contents($base.'/?model=VFG:J:A:A:2025122300',false,$ctx);
$headers=$http_response_header??[];
hf_ok($reply!==false,'facility HTTP request failed');
hf_ok(isset($headers[0])&&preg_match('/\s200\s/',$headers[0])===1,'facility non-200');
$decodedWire=EamuseProtocol::decodeTransport((string)$reply,$info,'lz77');
hf_ok(KBinXml::isBinary($decodedWire),'facility response is not kbin');
$decoded=KBinXml::decode($decodedWire);
hf_ok($decoded['encoding']==='UTF-8'&&$decoded['compressed']===true,'facility kbin metadata');
$r=new SimpleXMLElement($decoded['xml']);
$f=$r->facility;
hf_ok(isset($f)&&(string)$f['status']==='0','facility status');
hf_ok((string)$f->portfw->globalip['__type']==='ip4','facility globalip type');
$globalIp=(string)$f->portfw->globalip;
hf_ok($globalIp==='127.0.0.1','facility globalip value: '.var_export($globalIp,true));
hf_ok((string)$f->portfw->globalport==='18080'&&(string)$f->portfw->privateport==='18080','facility ports');
hf_ok((string)$f->location->regionjname==='東京都','facility Japanese KBin text');

echo "facility real HTTP KBin ip4 OK\n";
