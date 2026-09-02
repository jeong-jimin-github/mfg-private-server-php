<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Aog\FeatureDispatcher;
use Mfg\Aog\MiscDispatcher;
use Mfg\Storage\Database;

function tl_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function tl_success(?string $xml,string $name):void{
    tl_ok($xml!==null,$name.' returned null');
    $root=new SimpleXMLElement((string)$xml);
    tl_ok($root->getName()==='root',$name.' root');
    tl_ok(isset($root->serv_st->code)&&(string)$root->serv_st->code==='0',$name.' serv_st');
}

$dbPath=sys_get_temp_dir().'/mfg-telemetry-'.bin2hex(random_bytes(4)).'.sqlite';
$logPath=sys_get_temp_dir().'/mfg-telemetry-'.bin2hex(random_bytes(4)).'.log';
$oldLog=ini_get('error_log');
$oldErrors=ini_get('log_errors');
ini_set('log_errors','1');
ini_set('error_log',$logPath);

try{
    $db=new Database($dbPath);
    $feature=new FeatureDispatcher($db);
    $misc=new MiscDispatcher($db);

    $gachaPayload='{"series":"Normal","result":"UR","message":"ガチャ成功"}';
    $itemGain='{"item":"OID_CHARACTER_1","amount":1,"message":"獲得"}';
    $itemConsume='{"item":"OID_TICKET_1","amount":1,"message":"消費"}';

    // Python applies unquote_plus() before base64 decoding. Exercise both a
    // normal parsed form value and a still-percent-escaped value.
    tl_success($feature->dispatch('gacha_log',['log'=>base64_encode($gachaPayload)]),'gacha_log');
    tl_success($misc->dispatch('item_gain_log',['log'=>rawurlencode(base64_encode($itemGain))]),'item_gain_log');
    tl_success($misc->dispatch('item_consume_log',['log'=>base64_encode($itemConsume)]),'item_consume_log');

    clearstatcache(true,$logPath);
    $logged=is_file($logPath)?(string)file_get_contents($logPath):'';
    tl_ok(str_contains($logged,'[MFG][gacha] '.$gachaPayload),'gacha payload not decoded/logged');
    tl_ok(str_contains($logged,'[MFG][itemlog] '.$itemGain),'item gain payload not decoded/logged');
    tl_ok(str_contains($logged,'[MFG][itemlog] '.$itemConsume),'item consume payload not decoded/logged');

    $before=$logged;
    // Python swallows malformed base64 / invalid UTF-8 telemetry and still
    // returns a normal success response.
    tl_success($feature->dispatch('gacha_log',['log'=>'%%%not-base64%%%']),'bad gacha_log');
    tl_success($misc->dispatch('item_gain_log',['log'=>base64_encode("\xFF\xFE")]),'bad item_gain_log');
    clearstatcache(true,$logPath);
    $after=is_file($logPath)?(string)file_get_contents($logPath):'';
    tl_ok($after===$before,'malformed telemetry should not be logged');
} finally {
    if($oldLog!==false)ini_set('error_log',(string)$oldLog);
    if($oldErrors!==false)ini_set('log_errors',(string)$oldErrors);
    @unlink($dbPath);@unlink($dbPath.'-wal');@unlink($dbPath.'-shm');@unlink($logPath);
}

echo "telemetry log parity OK\n";
