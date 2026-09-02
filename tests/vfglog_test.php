<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Eamuse\Dispatcher;
use Mfg\Storage\Database;

function vl_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$tmp=tempnam(sys_get_temp_dir(),'mfg-vfglog-');
vl_ok($tmp!==false,'temp log');
$old=ini_get('error_log');ini_set('error_log',$tmp);
try{
    $db=new Database('sqlite::memory:');$d=new Dispatcher($db,'http://127.0.0.1:8080');
    $xml='<call model="VFG:J:A:A:2025122300"><vfglog method="put_msg">'
        .'<loc_id __type="str">VFG00001</loc_id>'
        .'<msg __type="str" label="network_error">2026-08-30 20:00:00,GetMenuData,630,A1DD6D1B6F9BF4E1,,parse failed</msg>'
        .'<msg __type="str" label="storage_info">{&quot;Items&quot;:[]}</msg>'
        .'</vfglog></call>';
    $res=$d->dispatch('VFG:J:A:A:2025122300','vfglog','put_msg',new SimpleXMLElement($xml));
    $root=new SimpleXMLElement($res);vl_ok(isset($root->vfglog),'response node');vl_ok((string)$root->vfglog['status']==='0','response status');
    $log=file_get_contents($tmp)?:'';
    vl_ok(str_contains($log,'network_error'),'network error missing');
    vl_ok(str_contains($log,'GetMenuData'),'request type missing');
    vl_ok(str_contains($log,'[MFG][client] storage_info:'),'normal client log missing');
    $res=$d->dispatch('VFG:J:A:A:2025122300','vfglog','unknown',new SimpleXMLElement('<call><vfglog method="unknown"/></call>'));
    $root=new SimpleXMLElement($res);vl_ok((string)$root->vfglog['status']==='0','unknown method status');
}finally{
    ini_set('error_log',(string)$old);@unlink($tmp);
}

echo "vfglog diagnostics OK\n";
