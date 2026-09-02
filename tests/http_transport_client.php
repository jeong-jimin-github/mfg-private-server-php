<?php

declare(strict_types=1);

require __DIR__.'/../src/Protocol/EamuseProtocol.php';
require __DIR__.'/../src/Protocol/KBinXml.php';

use Mfg\Protocol\EamuseProtocol;
use Mfg\Protocol\KBinXml;

function ht_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

/** @return array{0:string,1:list<string>} */
function ht_request(string $url,string $method='GET',string $body='',array $headers=[]):array
{
    $opts=['http'=>[
        'method'=>$method,
        'ignore_errors'=>true,
        'timeout'=>5,
        'header'=>implode("\r\n",$headers),
    ]];
    if($method!=='GET')$opts['http']['content']=$body;
    $ctx=stream_context_create($opts);
    $data=@file_get_contents($url,false,$ctx);
    $rh=$http_response_header??[];
    ht_ok($data!==false,'HTTP request failed '.$method.' '.$url);
    ht_ok(isset($rh[0])&&preg_match('/\s200\s/',$rh[0])===1,'non-200 '.$method.' '.$url.' '.($rh[0]??''));
    return [(string)$data,array_values($rh)];
}

function ht_header(array $headers,string $name):?string
{
    foreach($headers as $h){
        $p=strpos($h,':');if($p===false)continue;
        if(strcasecmp(trim(substr($h,0,$p)),$name)===0)return trim(substr($h,$p+1));
    }
    return null;
}

/** @return array{0:SimpleXMLElement,1:list<string>} */
function ht_eamuse(string $base,string $xml,string $info='1-01234567-89ab'):array
{
    $bin=KBinXml::encode($xml,'UTF-8',true);
    $wire=EamuseProtocol::encodeTransport($bin,$info,'lz77');
    [$reply,$headers]=ht_request(
        $base.'/?model=VFG:J:A:A:2025122300',
        'POST',
        $wire,
        [
            'User-Agent: EAMUSE',
            'Content-Type: application/octet-stream',
            'X-Eamuse-Info: '.$info,
            'X-Compress: lz77',
            'Content-Length: '.strlen($wire),
        ]
    );
    ht_ok(ht_header($headers,'X-Compress')==='lz77','HTTP response lost X-Compress');
    ht_ok(ht_header($headers,'X-Eamuse-Info')===$info,'HTTP response lost X-Eamuse-Info');
    $replyBin=EamuseProtocol::decodeTransport($reply,$info,'lz77');
    ht_ok(KBinXml::isBinary($replyBin),'HTTP response lost KBin');
    $decoded=KBinXml::decode($replyBin);
    ht_ok($decoded['encoding']==='UTF-8'&&$decoded['compressed']===true,'HTTP response lost KBin metadata');
    return [new SimpleXMLElement($decoded['xml']),$headers];
}

$base=rtrim((string)(getenv('TEST_BASE_URL')?:'http://127.0.0.1:18080'),'/');

foreach(['/health','/status'] as $statusPath){
    [$health]=ht_request($base.$statusPath);
    ht_ok(str_contains($health,'VFG local server ok'),$statusPath.' body');
    ht_ok(str_contains($health,'e-amuse: '.$base),$statusPath.' e-amuse URL');
    ht_ok(str_contains($health,'game:    '.$base.'/aog'),$statusPath.' game URL');
}

[$services]=ht_eamuse($base,'<call model="VFG:J:A:A:2025122300" srcid="HTTPTEST0001"><services method="get"/></call>');
ht_ok(isset($services->services)&&(string)$services->services['status']==='0','services.get over HTTP');
ht_ok(isset($services->services->item),'services.get empty');

// Strict mode must quarantine the malformed Spice/KAMUNITY card shape over the
// real web stack, not only when Dispatcher is called directly.
$garbled="\u{E09B}ﾞ";
[$card]=ht_eamuse($base,'<call model="VFG:J:A:A:2025122300"><cardmng method="inquire" cardid="'.htmlspecialchars($garbled,ENT_QUOTES|ENT_XML1,'UTF-8').'" cardtype="2104083072"/></call>');
ht_ok(isset($card->cardmng)&&(string)$card->cardmng['status']==='112','malformed card HTTP quarantine');

[$coin]=ht_eamuse($base,'<call model="VFG:J:A:A:2025122300"><eacoin method="checkin"><cardid __type="str">E0047CC78DFBA459</cardid><passwd __type="str">1234</passwd></eacoin></call>');
ht_ok(isset($coin->eacoin)&&(string)$coin->eacoin['status']==='0','PASELI HTTP checkin');
ht_ok((int)$coin->eacoin->balance===57300,'PASELI HTTP balance');

[$aog]=ht_request(
    $base.'/aog/appli_boot',
    'POST',
    'pcuid=HTTPTEST&sic=VFG%3AJ%3AA%3AA%3A2025122300',
    ['Content-Type: application/x-www-form-urlencoded']
);
$aogXml=new SimpleXMLElement($aog);
ht_ok($aogXml->getName()==='root'&&(string)$aogXml->serv_st->code==='0','AOG HTTP root/serv_st');
ht_ok(isset($aogXml->boot_mes)&&str_ends_with((string)$aogXml->boot_mes->moserv_url,'/aog'),'AOG appli_boot HTTP payload');

// Independent HTTP requests must see the same persisted session/profile state.
[$loginBody]=ht_request(
    $base.'/aog/login','POST',http_build_query(['user_id'=>'HTTP-E2E','guest'=>'0']),
    ['Content-Type: application/x-www-form-urlencoded']
);
$login=new SimpleXMLElement($loginBody);$sid=(string)$login->auth->session_id;
ht_ok($sid!=='','AOG HTTP login session missing');
$literal='network-state-'.bin2hex(random_bytes(3));
ht_request(
    $base.'/aog/client_state_write','POST',
    http_build_query(['pcuid'=>$sid,'kind'=>'profile','data'=>base64_encode($literal)]),
    ['Content-Type: application/x-www-form-urlencoded']
);
[$readBody]=ht_request(
    $base.'/aog/client_state_read','POST',http_build_query(['pcuid'=>$sid,'one_kind'=>'profile']),
    ['Content-Type: application/x-www-form-urlencoded']
);
$read=new SimpleXMLElement($readBody);
ht_ok(isset($read->state->data),'AOG HTTP state read missing');
ht_ok(base64_decode((string)$read->state->data,true)===$literal,'AOG HTTP cross-request state mismatch');

// Python's game handlers append the in-match sticker stream to both gget and
// gpost. Verify the public PHP response does the same, including original d
// attributes/children and the empty gpost chat cursor response.
foreach(['TableSticker001','TableSticker002'] as $sticker){
    ht_request(
        $base.'/aog/gchat','POST',
        http_build_query(['tid'=>'9','mid'=>'1','pindex'=>'0','name'=>'ME','contents'=>$sticker,'param'=>'']),
        ['Content-Type: application/x-www-form-urlencoded']
    );
}
$mustGet='VFG:J:A:A:2025122300/HTTPCHAT/9/0/1/0/0';
[$ggetBody]=ht_request(
    $base.'/aog/gget','POST',http_build_query(['pcuid'=>'HTTPCHAT','ready'=>'0','must'=>$mustGet]),
    ['Content-Type: application/x-www-form-urlencoded']
);
$gget=new SimpleXMLElement($ggetBody);ht_ok(isset($gget->chat),'gget chat stream missing');
$chatRows=$gget->chat->d;ht_ok(count($chatRows)>=2,'gget did not echo sticker stream');
foreach($chatRows as $row){
    ht_ok((string)$row['idx']!==''&&(string)$row['mid']!==''&&(string)$row['pindex']!==''&&(string)$row['time']!=='','chat d attributes');
    ht_ok(isset($row->name)&&isset($row->contents)&&isset($row->param),'chat d children');
}
$mustPost='VFG:J:A:A:2025122300/HTTPCHAT/9/0/1/0/0/0/0/0/0/0/0/0/0';
[$gpostBody]=ht_request(
    $base.'/aog/gpost','POST',http_build_query(['pcuid'=>'HTTPCHAT','must'=>$mustPost]),
    ['Content-Type: application/x-www-form-urlencoded']
);
$gpost=new SimpleXMLElement($gpostBody);ht_ok(isset($gpost->chat),'gpost chat node missing');
ht_ok(count($gpost->chat->d)===0,'gpost should use an exhausted chat cursor');

echo "real HTTP RC4/LZ77/KBin/AOG/chat integration OK\n";
