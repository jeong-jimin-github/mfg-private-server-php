<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=__DIR__.'/../src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Protocol\EamuseProtocol;
use Mfg\Protocol\KBinXml;

function hn_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

/** @return array{0:string,1:list<string>} */
function hn_request(string $url,string $method='GET',string $body='',array $headers=[]):array
{
    $headers[]='Connection: close';
    if($body!==''&&!array_filter($headers,fn($h)=>str_starts_with(strtolower($h),'content-length:'))) $headers[]='Content-Length: '.strlen($body);
    $ctx=stream_context_create(['http'=>[
        'method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,
        'ignore_errors'=>true,'timeout'=>5,
    ]]);
    $data=@file_get_contents($url,false,$ctx);
    $respHeaders=isset($http_response_header)&&is_array($http_response_header)?$http_response_header:[];
    if($data===false)throw new RuntimeException('HTTP request failed: '.$url.' '.implode(' | ',$respHeaders));
    hn_ok(isset($respHeaders[0])&&str_contains($respHeaders[0],' 200 '),'HTTP status not 200: '.($respHeaders[0]??'none'));
    return [$data,array_values($respHeaders)];
}
function hn_header(array $headers,string $name):?string
{
    $prefix=strtolower($name).':';
    foreach($headers as $h)if(str_starts_with(strtolower($h),$prefix))return trim(substr($h,strlen($prefix)));
    return null;
}

$root=dirname(__DIR__);$port=random_int(22000,42000);$base='http://127.0.0.1:'.$port;
$dbPath=sys_get_temp_dir().'/mfg_http_'.bin2hex(random_bytes(5)).'.sqlite';
$logPath=sys_get_temp_dir().'/mfg_http_'.bin2hex(random_bytes(5)).'.log';
$oldDsn=getenv('DB_DSN');putenv('DB_DSN=sqlite:'.$dbPath);
$descriptors=[0=>['pipe','r'],1=>['file',$logPath,'a'],2=>['file',$logPath,'a']];
$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'public/index.php'],$descriptors,$pipes,$root);
if(!is_resource($proc))throw new RuntimeException('failed to start php -S');
fclose($pipes[0]);

try{
    $ready=false;
    for($i=0;$i<80;$i++){
        usleep(50000);
        $ctx=stream_context_create(['http'=>['timeout'=>0.3,'ignore_errors'=>true]]);
        $probe=@file_get_contents($base.'/healthz',false,$ctx);
        if(is_string($probe)&&str_contains($probe,'"ok":true')){$ready=true;break;}
        $st=proc_get_status($proc);if(!$st['running'])break;
    }
    if(!$ready){$log=@file_get_contents($logPath)?:'';throw new RuntimeException('php -S did not become ready: '.$log);}

    // Real GET route through the PHP SAPI.
    [$status]=hn_request($base.'/status');
    hn_ok(str_contains($status,"VFG local server ok\n"),'status text missing');
    hn_ok(str_contains($status,'e-amuse: '.$base),'status e-amuse URL');
    hn_ok(str_contains($status,'game:    '.$base.'/aog'),'status AOG URL');

    // Real form POST through public/index.php and the AOG router.
    [$boot]=hn_request($base.'/aog/appli_boot','POST',http_build_query(['web_id'=>'1']),['Content-Type: application/x-www-form-urlencoded']);
    $bootXml=new SimpleXMLElement($boot);
    hn_ok((string)$bootXml->serv_st->code==='0','AOG boot serv_st');
    hn_ok((string)$bootXml->boot_mes->status==='0','AOG boot status');
    hn_ok((string)$bootXml->boot_mes->moserv_url===$base.'/aog','AOG boot URL');

    // Full network transport: KBin -> outer LZ77 -> RC4 -> HTTP POST, then reverse it.
    $model='VFG:J:A:A:2025122300';$info='1-01234567-89ab';
    $xml='<?xml version="1.0" encoding="UTF-8"?><call model="'.$model.'" srcid="HTTP-E2E-PCB"><services method="get"/></call>';
    $payload=KBinXml::encode($xml,'UTF-8',true);
    $wire=EamuseProtocol::encodeTransport($payload,$info,'lz77');
    [$responseWire,$headers]=hn_request($base.'/','POST',$wire,[
        'Content-Type: application/octet-stream','X-Eamuse-Info: '.$info,'X-Compress: lz77',
    ]);
    hn_ok(hn_header($headers,'X-Eamuse-Info')===$info,'X-Eamuse-Info response not mirrored');
    hn_ok(strtolower((string)hn_header($headers,'X-Compress'))==='lz77','X-Compress response not mirrored');
    $decoded=EamuseProtocol::decodeTransport($responseWire,$info,'lz77');
    hn_ok(KBinXml::isBinary($decoded),'network response lost KBin');
    $meta=KBinXml::decode($decoded);hn_ok($meta['compressed']===true,'network KBin compressed-name flag');
    $rootXml=new SimpleXMLElement($meta['xml']);
    hn_ok(isset($rootXml->services)&&(string)$rootXml->services['status']==='0','network services.get failed');
    hn_ok(count($rootXml->services->item)>=1,'network services list empty');

    // Verify persistence across independent HTTP requests handled by the built-in server.
    [$loginBody]=hn_request($base.'/aog/login','POST',http_build_query(['user_id'=>'HTTP-E2E','guest'=>'0']),['Content-Type: application/x-www-form-urlencoded']);
    $login=new SimpleXMLElement($loginBody);$sid=(string)$login->auth->session_id;hn_ok($sid!=='','HTTP login session missing');
    $literal='network-state-'.bin2hex(random_bytes(3));
    hn_request($base.'/aog/client_state_write','POST',http_build_query(['pcuid'=>$sid,'kind'=>'profile','data'=>base64_encode($literal)]),['Content-Type: application/x-www-form-urlencoded']);
    [$readBody]=hn_request($base.'/aog/client_state_read','POST',http_build_query(['pcuid'=>$sid,'one_kind'=>'profile']),['Content-Type: application/x-www-form-urlencoded']);
    $read=new SimpleXMLElement($readBody);hn_ok(base64_decode((string)$read->state->data,true)===$literal,'HTTP cross-request state mismatch');

    echo "real HTTP network E2E OK\n";
} finally {
    proc_terminate($proc);for($i=0;$i<20;$i++){usleep(50000);$st=proc_get_status($proc);if(!$st['running'])break;}proc_close($proc);
    if($oldDsn===false)putenv('DB_DSN');else putenv('DB_DSN='.$oldDsn);
    @unlink($dbPath);@unlink($dbPath.'-wal');@unlink($dbPath.'-shm');@unlink($logPath);
}
