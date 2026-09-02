<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Aog\FeatureDispatcher;
use Mfg\Aog\GachaPools;
use Mfg\Storage\Database;

function gpok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$raw=file_get_contents(__DIR__.'/../data/gacha_pools.json');
gpok($raw!==false,'gacha pool file missing');
gpok(sha1("blob ".strlen($raw)."\0".$raw)==='ecf3b26ece1bcefc113a0413be8cc6ef2f3ac06e','gacha pool blob differs from Python source');
$d=GachaPools::data();
gpok(count($d['standard_pool']??[])===332,'standard pool count mismatch');
gpok(count(GachaPools::series())===141,'series count mismatch');
gpok(GachaPools::pickupCharas(140)===['Chara18'],'series 140 pickup mismatch');
gpok(GachaPools::customPickupItems(135)===['OID_19AgariSR01'],'Shiroe unlock mismatch');
gpok(GachaPools::poolForSeries(91)===['OID_ReachBgm151','OID_ReachBgm150','OID_ReachBgm149','OID_ReachBgm148'],'MusicHiyori pool mismatch');
gpok(in_array('OID_19AgariSR01',GachaPools::poolForSeries(135),true),'unlock item missing from series pool');

$tmp=tempnam(sys_get_temp_dir(),'mfg_gacha_');$db=new Database($tmp);$f=new FeatureDispatcher($db);
putenv('VFG_GACHA_ALL');
$xml=$f->dispatch('gacha_info',[]);gpok(is_string($xml),'gacha_info missing');
$x=simplexml_load_string($xml);gpok($x!==false,'gacha_info invalid XML');
gpok(isset($x->gacha_schedule)&&count($x->gacha_schedule->info)===27,'default gacha_info must expose curated 27 series');
$ids=[];foreach($x->gacha_schedule->info as $info)$ids[]=(int)$info->id;
$expected=[0,1,25,44,56,63,74,101,125,135,91,92,107,114,132,133,124,128,129,130,131,134,136,137,138,139,140];
gpok($ids===$expected,'curated series order/ids differ from Python reference');

foreach(['1','true','yes','on'] as $truthy){
    putenv('VFG_GACHA_ALL='.$truthy);$all=simplexml_load_string((string)$f->dispatch('gacha_info',[]));
    gpok($all!==false&&count($all->gacha_schedule->info)===141,'VFG_GACHA_ALL='.$truthy.' did not expose all series');
}
putenv('VFG_GACHA_ALL=0');$off=simplexml_load_string((string)$f->dispatch('gacha_info',[]));
gpok($off!==false&&count($off->gacha_schedule->info)===27,'VFG_GACHA_ALL=0 must stay curated');

// Mirror Python test_match_e2e.py / real client field names: gacha_name + times.
putenv('VFG_GACHA_ALL');
$res=simplexml_load_string((string)$f->dispatch('req_draw_gacha',['pcuid'=>'x','gacha_name'=>'Normal','times'=>'5']));
gpok($res!==false&&strlen((string)$res->transaction_info->transaction_id)===16,'gacha transaction');
$result=simplexml_load_string((string)$f->dispatch('get_gacha_result',['pcuid'=>'x','transaction_id'=>(string)$res->transaction_info->transaction_id,'times'=>'5']));
gpok($result!==false&&count($result->lottery_result->data)===5,'times=5 lottery result count');

putenv('VFG_GACHA_ALL');@unlink($tmp);
echo "gacha pools/advertisement OK\n";
