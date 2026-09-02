<?php

declare(strict_types=1);

function rp_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function rp_post(string $url,array $form=[]):SimpleXMLElement{
    $body=http_build_query($form);
    $ctx=stream_context_create(['http'=>[
        'method'=>'POST','ignore_errors'=>true,'timeout'=>5,
        'header'=>'Content-Type: application/x-www-form-urlencoded\r\nContent-Length: '.strlen($body),
        'content'=>$body,
    ]]);
    $raw=@file_get_contents($url,false,$ctx);$headers=$http_response_header??[];
    rp_ok($raw!==false,'HTTP request failed '.$url);
    rp_ok(isset($headers[0])&&preg_match('/\s200\s/',$headers[0])===1,'non-200 '.$url.' '.($headers[0]??''));
    $x=new SimpleXMLElement((string)$raw);
    rp_ok(isset($x->serv_st->code)&&(string)$x->serv_st->code==='0','serv_st '.$url);
    return $x;
}

$base=rtrim((string)(getenv('TEST_BASE_URL')?:'http://127.0.0.1:18080'),'/');
$pcuid='PATH-RECONNECT-SESSION';
$entry=rp_post($base.'/aog/entry_game',['pcuid'=>$pcuid,'gmode'=>'3']);
rp_ok(isset($entry->entry),'entry_game missing entry');
$tid=(int)$entry->entry->tid;
rp_ok($tid>0,'entry_game tid');
rp_ok((int)$entry->entry->gmode===3,'entry_game gmode');

// Managed client shape documented by the Python reference notes:
// /reconnect/<ver>/<session>/<webid>/ . No form pcuid is required here; the
// session path segment must be promoted by App routing.
$re=rp_post($base.'/aog/reconnect/2025122300/'.rawurlencode($pcuid).'/WEBID001/');
rp_ok(isset($re->entry),'path-shaped reconnect fell through to generic success');
rp_ok((int)$re->entry->tid===$tid,'reconnect lost stored tid');
rp_ok((int)$re->entry->gmode===3,'reconnect lost stored gmode');
rp_ok((string)$re->entry->gserv_url!=='','reconnect gserv_url');
rp_ok((int)$re->entry->next_sno>=0,'reconnect next_sno');

echo "path-shaped reconnect HTTP routing OK\n";
