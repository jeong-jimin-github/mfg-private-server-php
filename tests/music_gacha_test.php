<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Aog\FeatureDispatcher;
use Mfg\Aog\GachaPools;
use Mfg\Storage\Database;

function mg_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function mg_xml(?string $xml,string $name):SimpleXMLElement{
    mg_ok($xml!==null,$name.' returned null');
    $root=new SimpleXMLElement((string)$xml);
    mg_ok(isset($root->serv_st->code)&&(string)$root->serv_st->code==='0',$name.' serv_st');
    return $root;
}
function mg_reserve(FeatureDispatcher $f,int $series):int{
    $root=mg_xml($f->dispatch('music_gacha_play_reserve',['gacha_id'=>(string)$series]),'reserve');
    $req=(int)($root->gacha_reserve->request_id??0);
    mg_ok($req>=1001,'request id range');
    return $req;
}
function mg_play(FeatureDispatcher $f,int $request):string{
    $root=mg_xml($f->dispatch('music_gacha_play',['request_id'=>(string)$request]),'play');
    mg_ok((string)$root->gacha_result->is_success==='1','play success');
    $item=(string)($root->gacha_result->gain_items->item??'');
    mg_ok($item!=='','gain item missing');
    return $item;
}

$dbPath=sys_get_temp_dir().'/mfg-music-gacha-'.bin2hex(random_bytes(4)).'.sqlite';
try{
    $db=new Database($dbPath);
    $f=new FeatureDispatcher($db);

    $music=[91,92,107,114,132];
    $chosen=[];
    foreach($music as $sid){
        $pool=GachaPools::poolForSeries($sid);
        if(!$pool)continue;
        if(!$chosen){$chosen=[$sid,$pool];continue;}
        if(!array_intersect($chosen[1],$pool)){$chosen=[$chosen[0],$chosen[1],$sid,$pool];break;}
    }
    mg_ok(count($chosen)===4,'need two disjoint music pools');
    [$seriesA,$poolA,$seriesB,$poolB]=$chosen;

    $reqA=mg_reserve($f,$seriesA);
    $reqB=mg_reserve($f,$seriesB);
    mg_ok($reqB===$reqA+1,'request ids must be monotonic');

    // Consume out of reservation order. Python keys reservations by request_id,
    // so each play must still use the series associated with that request.
    $itemB=mg_play($f,$reqB);
    mg_ok(in_array($itemB,$poolB,true),'request B lost its series binding');
    $itemA=mg_play($f,$reqA);
    mg_ok(in_array($itemA,$poolA,true),'request A lost its series binding');

    // A consumed/unknown request falls back to series 91 rather than reusing a
    // previous pcuid-scoped reservation.
    $fallback=GachaPools::poolForSeries(91);
    mg_ok($fallback!==[],'fallback pool 91 missing');
    $again=mg_play($f,$reqB);
    mg_ok(in_array($again,$fallback,true),'consumed request did not fall back to series 91');

    // Python's MUSIC_GACHA_POOL contains only Music series. Reserving a normal
    // gacha id must therefore also fall back to series 91 on playback.
    $normalReq=mg_reserve($f,0);
    $normalItem=mg_play($f,$normalReq);
    mg_ok(in_array($normalItem,$fallback,true),'non-music reservation returned a non-song item');

    // Persistence across dispatcher instances models independent PHP-FPM requests.
    $reqPersist=mg_reserve($f,$seriesB);
    $f2=new FeatureDispatcher(new Database($dbPath));
    $persisted=mg_play($f2,$reqPersist);
    mg_ok(in_array($persisted,$poolB,true),'request reservation did not persist across PHP requests');
} finally {
    @unlink($dbPath);@unlink($dbPath.'-wal');@unlink($dbPath.'-shm');
}

echo "music gacha request-id parity OK\n";
