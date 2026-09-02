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

$tmp=tempnam(sys_get_temp_dir(),'mfg_gacha_');$db=new Database($tmp);$f=new FeatureDispatcher($db);$xml=$f->dispatch('gacha_info',[]);gpok(is_string($xml),'gacha_info missing');
$x=simplexml_load_string($xml);gpok($x!==false,'gacha_info invalid XML');
gpok(isset($x->gacha_schedule)&&count($x->gacha_schedule->info)===141,'gacha_info must expose all 141 series');
@unlink($tmp);

echo "gacha pools OK\n";
