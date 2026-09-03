<?php

declare(strict_types=1);

function hp_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

/** @param array<string,string> $form */
function hp_request(string $url,string $method='GET',array $form=[]):string
{
    $body=$method==='POST'?http_build_query($form):'';
    $headers=[
        'Host: jm0730.iwinv.net',
        'X-Forwarded-Proto: https',
    ];
    if($method==='POST'){
        $headers[]='Content-Type: application/x-www-form-urlencoded';
        $headers[]='Content-Length: '.strlen($body);
    }
    $ctx=stream_context_create(['http'=>[
        'method'=>$method,
        'ignore_errors'=>true,
        'timeout'=>5,
        'header'=>implode("\r\n",$headers),
        'content'=>$body,
    ]]);
    $raw=@file_get_contents($url,false,$ctx);$responseHeaders=$http_response_header??[];
    hp_ok($raw!==false,'HTTP request failed '.$url);
    hp_ok(isset($responseHeaders[0])&&preg_match('/\s200\s/',$responseHeaders[0])===1,'non-200 '.$url.' '.($responseHeaders[0]??''));
    return (string)$raw;
}

$base=rtrim((string)(getenv('TEST_BASE_URL')?:'http://127.0.0.1:18080'),'/');

$health=hp_request($base.'/health');
hp_ok(str_contains($health,"e-amuse: https://jm0730.iwinv.net\n"),'health did not honor forwarded https');
hp_ok(str_contains($health,"game:    https://jm0730.iwinv.net/aog\n"),'health game URL did not honor forwarded https');

$keepalive=hp_request($base.'/core/keepalive?pa=127.0.0.1&ia=127.0.0.1&ga=127.0.0.1&ma=127.0.0.1&t1=2&t2=10');
hp_ok($keepalive==='ok','keepalive body through proxy headers');

$bootRaw=hp_request($base.'/aog/appli_boot','POST');
$boot=new SimpleXMLElement($bootRaw);
hp_ok((string)$boot->serv_st->code==='0','appli_boot serv_st');
hp_ok((string)$boot->boot_mes->moserv_url==='https://jm0730.iwinv.net/aog','appli_boot moserv_url did not honor forwarded https');

$entryRaw=hp_request($base.'/aog/entry_game','POST',['pcuid'=>'PROXY-HTTP-SESSION','gmode'=>'3']);
$entry=new SimpleXMLElement($entryRaw);
hp_ok((string)$entry->serv_st->code==='0','entry_game serv_st');
hp_ok((string)$entry->entry->gserv_url==='https://jm0730.iwinv.net/aog/','entry_game gserv_url did not honor forwarded https');
hp_ok((string)$entry->entry->gmode==='3','entry_game gmode changed');

echo "real HTTP forwarded-HTTPS URLs OK\n";
