<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Aog\MiscDispatcher;
use Mfg\Storage\Database;

$dbFile=sys_get_temp_dir().'/mfg_misc_'.bin2hex(random_bytes(4)).'.sqlite';
$db=new Database($dbFile);
$d=new MiscDispatcher($db);

$xml=$d->dispatch('chk_tabooword',['str'=>'PLAYER']);
if(!str_contains((string)$xml,'<taboo_chk><result>0</result></taboo_chk>'))throw new RuntimeException('taboo contract');

$xml=$d->dispatch('get_jongstone_info',[]);
if(!str_contains((string)$xml,'<free_point>0</free_point>'))throw new RuntimeException('jongstone contract');

$xml=$d->dispatch('get_mg',[]);
if(!str_contains((string)$xml,'<additional_mg>0</additional_mg>'))throw new RuntimeException('mg contract');

$d->dispatch('gchat',['tid'=>'9','mid'=>'1','pindex'=>'0','name'=>'ME','contents'=>'TableSticker001','param'=>'']);
$xml=$d->dispatch('gchat',['tid'=>'9']);
$root=new SimpleXMLElement((string)$xml);$chat=$root->chat??null;
if(!$chat instanceof SimpleXMLElement||count($chat->d)!==1)throw new RuntimeException('chat persistence');
$row=$chat->d[0];
if((int)$row['idx']!==1||(int)$row['mid']!==1||(int)$row['pindex']!==0||(int)$row['time']<=0)throw new RuntimeException('chat attributes');
if((string)$row->name!=='ME'||(string)$row->contents!=='TableSticker001'||(string)$row->param!=='')throw new RuntimeException('chat payload');
// The Python reference's _stamp_xml contract is <chat><d .../></chat>; there
// is deliberately no legacy <last_idx> sibling. Cursoring is driven by d@idx.
if(isset($root->last_idx))throw new RuntimeException('legacy last_idx must not be emitted');

$xml=$d->dispatch('present_done',['done_ids'=>'10,11']);
if(substr_count((string)$xml,'<success>1</success>')!==2)throw new RuntimeException('present_done contract');

$xml=$d->dispatch('competition_entry',[]);
if(!str_contains((string)$xml,'<entry_result>1</entry_result>'))throw new RuntimeException('competition contract');

@unlink($dbFile);@unlink($dbFile.'-wal');@unlink($dbFile.'-shm');
echo "misc AOG OK\n";
