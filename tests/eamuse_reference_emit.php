<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Eamuse\Dispatcher;
use Mfg\Storage\Database;

const ER_MODEL='VFG:J:A:A:2025122300';
const ER_BASE='http://127.0.0.1:8080';

function er_call(Dispatcher $d,string $module,string $method,string $xml='<call/>'):string{
    return $d->dispatch(ER_MODEL,$module,$method,new SimpleXMLElement($xml));
}
function er_attr(string $xml,string $xpath,string $attr):string{
    $r=new SimpleXMLElement($xml);$nodes=$r->xpath($xpath)?:[];return $nodes?(string)$nodes[0][$attr]:'';
}
function er_text(string $xml,string $xpath):string{
    $r=new SimpleXMLElement($xml);$nodes=$r->xpath($xpath)?:[];return $nodes?trim((string)$nodes[0]):'';
}

putenv('VFG_CARDMNG_MODE=compat');
putenv('VFG_CARDMNG_INQUIRE_MODE=auto');
$db=new Database('sqlite::memory:');
$d=new Dispatcher($db,ER_BASE);
$out=[];
$out['services']=er_call($d,'services','get','<call srcid="PCB-PARITY"/>');
$out['pcbtracker']=er_call($d,'pcbtracker','alive');
$out['message']=er_call($d,'message','get');
$out['facility']=er_call($d,'facility','get');
$out['package']=er_call($d,'package','list');
$out['pcbevent']=er_call($d,'pcbevent','put');
$out['eventlog']=er_call($d,'eventlog','write');
$out['vfgac']=er_call($d,'vfgac','service_list');
$out['vfgac_update_refer']=er_call($d,'vfgac','update_refer');
$out['vfgac_ext_campaign']=er_call($d,'vfgac','ext_campaign');
$out['vfgac_send_paylog']=er_call($d,'vfgac','send_paylog');

foreach(['posevent','pkglist','userdata','userid','sidmgr','netlog','local','local2'] as $module){
    $out['generic_'.$module]=er_call($d,$module,'noop');
}
$out['generic_unknown']=er_call($d,'unknown_module','noop');

$card='E0047CC78DFBA459';
$out['card_missing_inquire']=er_call($d,'vfgcard','inquire');
$out['card_missing_getrefid']=er_call($d,'vfgcard','getrefid');
$out['card_missing_bind']=er_call($d,'vfgcard','bindmodel');
$out['card_unknown_bind']=er_call($d,'vfgcard','bindmodel','<call><vfgcard refid="A000000000000000"/></call>');
$out['card_new']=er_call($d,'vfgcard','inquire','<call><vfgcard cardid="'.$card.'"/></call>');
$out['card_issue']=er_call($d,'vfgcard','getrefid','<call><vfgcard cardid="'.$card.'" passwd="1234"/></call>');
$refid=er_attr($out['card_issue'],'//vfgcard','refid');
$out['card_unbound']=er_call($d,'vfgcard','inquire','<call><vfgcard cardid="'.$card.'"/></call>');
$out['card_auth']=er_call($d,'vfgcard','authpass','<call><vfgcard refid="'.$refid.'" passwd="1234"/></call>');
$out['card_getdatalist']=er_call($d,'vfgcard','getdatalist','<call><vfgcard refid="'.$refid.'"/></call>');
$out['card_bind']=er_call($d,'vfgcard','bindmodel','<call><vfgcard refid="'.$refid.'"/></call>');
$out['card_bound']=er_call($d,'vfgcard','inquire','<call><vfgcard cardid="'.$card.'"/></call>');
putenv('VFG_CARDMNG_INQUIRE_MODE=new');
$out['card_forced_new']=er_call($d,'vfgcard','inquire','<call><vfgcard cardid="'.$card.'"/></call>');
putenv('VFG_CARDMNG_INQUIRE_MODE=auto');
putenv('VFG_CARDMNG_MODE=strict');
$out['card_malformed_strict']=er_call($d,'vfgcard','inquire','<call><vfgcard cardid="BAD"/></call>');
$out['card_malformed_getrefid_strict']=er_call($d,'vfgcard','getrefid','<call><vfgcard cardid="BAD" passwd="1234"/></call>');
putenv('VFG_CARDMNG_MODE=compat');
$out['card_malformed_compat']=er_call($d,'vfgcard','inquire','<call><vfgcard cardid="BAD"/></call>');
$out['legacy_card_missing']=er_call($d,'cardmng','inquire');

$out['eacoin_checkin']=er_call($d,'eacoin','checkin');
$sess=er_text($out['eacoin_checkin'],'//sessid');
$out['eacoin_consume']=er_call($d,'eacoin','consume','<call><sessid>'.$sess.'</sessid><payment>300</payment></call>');
$out['eacoin_balance']=er_call($d,'eacoin','getbalance','<call><sessid>'.$sess.'</sessid></call>');
$out['eacoin_checkout']=er_call($d,'eacoin','checkout','<call><sessid>'.$sess.'</sessid></call>');
$out['eacoin_balance_after_checkout']=er_call($d,'eacoin','getbalance','<call><sessid>'.$sess.'</sessid></call>');
$out['eacoin_opcheckin']=er_call($d,'eacoin','opcheckin');
$out['eacoin_log']=er_call($d,'eacoin','getlog');
$out['eacoin_oplog']=er_call($d,'eacoin','getoplog');
$out['eacoin_campaign']=er_call($d,'eacoin','getcampaign');
$out['eacoin_unknown']=er_call($d,'eacoin','unknown');

echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
