<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Storage\Database;

function db_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$dsn=getenv('TEST_DB_DSN');
$user=getenv('TEST_DB_USER');
$pass=getenv('TEST_DB_PASS');
if($dsn===false||$dsn==='')$dsn='sqlite::memory:';
$db=new Database($dsn,$user!==false&&$user!==''?$user:null,$pass!==false?$pass:null);

db_ok(in_array($db->driver(),['sqlite','mysql'],true),'unexpected driver');
$p=$db->ensureProfile('TESTREF000000001','PLAYER');
db_ok($p['name']==='PLAYER','profile create');
$db->saveProfilePayload('TESTREF000000001',['name'=>'Tester','states'=>['x'=>'y']]);
db_ok(($db->getProfile('TESTREF000000001')['payload']['name']??'')==='Tester','profile payload');

$c=$db->issueCard('E004000000000001','1234');
db_ok($c['pin']==='1234','card issue');
$db->bindCardByRefid((string)$c['refid']);
db_ok((int)($db->getCard('E004000000000001')['bound']??0)===1,'card bind');

$db->touchCabinet('PCB-TEST','VFG:J:A:A:2025122300');
$db->touchCabinet('PCB-TEST','VFG:J:A:A:2025122301');

$db->saveSession('SESSION-TEST','TESTREF000000001',['guest'=>false]);
$db->saveSession('SESSION-TEST','TESTREF000000001',['guest'=>true]);
db_ok(($db->getSession('SESSION-TEST')['payload']['guest']??false)===true,'session upsert');

$db->saveMatch('MATCH-TEST',['turn'=>1]);
$db->saveMatch('MATCH-TEST',['turn'=>2,'table'=>['x'=>1]]);
db_ok(($db->getMatch('MATCH-TEST')['turn']??0)===2,'match upsert');

$db->setKv('test','hello',['value'=>1]);
$db->setKv('test','hello',['value'=>2]);
db_ok(($db->getKv('test','hello')['value']??0)===2,'kv upsert');
$db->deleteKv('test','hello');
db_ok($db->getKv('test','hello',null)===null,'kv delete');

$db->deleteSession('SESSION-TEST');
$db->deleteMatch('MATCH-TEST');

echo 'database '.$db->driver()." OK\n";
