<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=__DIR__.'/../src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Debug\CaptureComparator;

function cc_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function cc_rm(string $path):void{if(!is_dir($path)){@unlink($path);return;}foreach(scandir($path)?:[] as $n){if($n==='.'||$n==='..')continue;cc_rm($path.'/'.$n);}@rmdir($path);}

$base=sys_get_temp_dir().'/mfg_cmp_'.bin2hex(random_bytes(4));$a=$base.'/python';$b=$base.'/php';
foreach([$a.'/responses',$a.'/transport',$b.'/responses',$b.'/transport'] as $dir)mkdir($dir,0775,true);
file_put_contents($a.'/responses/0001_services_get.xml','<response><services status="0"><value __type="u8">1</value></services></response>');
file_put_contents($b.'/responses/0004_services_get.xml','<response><services status="9"><value __type="u8">999</value></services></response>');
$meta=['x_compress'=>'lz77','used_kbin'=>true,'kbin_encoding'=>'UTF-8','kbin_compressed'=>true,'module'=>'services','method'=>'get','wire_bytes'=>123];
file_put_contents($a.'/transport/0002_services_get.json',json_encode($meta));$meta['wire_bytes']=999;file_put_contents($b.'/transport/0002_services_get.json',json_encode($meta));

$c=new CaptureComparator();$struct=$c->compare($a,$b,false);
cc_ok($struct['reference_files']===1&&$struct['candidate_files']===1&&$struct['compared']===1,'compare counts');
cc_ok($struct['differences']===[],'structural compare should ignore dynamic values/wire size');
$strict=$c->compare($a,$b,true);cc_ok($strict['differences']!==[],'strict value comparison should notice values');

file_put_contents($b.'/responses/0004_services_get.xml','<response><services status="0"><value __type="s8">1</value></services></response>');
$typeDiff=$c->compare($a,$b,false);cc_ok($typeDiff['differences']!==[],'structural compare must notice __type changes');

cc_rm($base);echo "capture comparator OK\n";
