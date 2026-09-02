<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=__DIR__.'/../src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Aog\Dispatcher;
use Mfg\Mahjong\Table;
use Mfg\Storage\Database;

function as_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function as_xml(string $xml):SimpleXMLElement{return new SimpleXMLElement($xml);}
function as_ints(?SimpleXMLElement $n):array{
    if($n===null)return [];$s=trim((string)$n);if($s==='')return [];
    return array_values(array_map('intval',preg_split('/\s+/', $s)?:[]));
}

final class AllModesClient
{
    private int $pindex=0;
    private int $nextSno=0;
    private array $hand=[];
    private int $kyokuStarts=0;
    private bool $done=false;

    public function __construct(
        private Dispatcher $d,
        private Database $db,
        private string $pcuid,
        private int $gmode,
        private int $seedBase=0x534F0000,
    ){}

    /** @return array{kyoku:int,loops:int,players:int} */
    public function run():array
    {
        $entry=as_xml($this->d->dispatch('entry_game',['pcuid'=>$this->pcuid,'gmode'=>(string)$this->gmode]));
        as_ok(isset($entry->entry),'entry_game missing gmode='.$this->gmode);
        $this->pindex=(int)$entry->entry->pindex;$this->nextSno=(int)$entry->entry->next_sno;

        $match=$this->db->getMatch($this->pcuid);as_ok(is_array($match)&&is_array($match['table']??null),'match not persisted gmode='.$this->gmode);
        $match['table']['seed']=$this->seedBase+$this->gmode;
        $this->db->saveMatch($this->pcuid,$match);

        $this->d->dispatch('gget',['pcuid'=>$this->pcuid,'ready'=>'0','next_sno'=>(string)$this->nextSno]);
        $queue=[as_xml($this->d->dispatch('gget',['pcuid'=>$this->pcuid,'ready'=>'1','next_sno'=>(string)$this->nextSno]))];
        $guard=0;$idle=0;
        while(!$this->done&&$guard<5000){
            $guard++;
            if(!$queue){
                $root=as_xml($this->d->dispatch('gget',['pcuid'=>$this->pcuid,'ready'=>'1','next_sno'=>(string)$this->nextSno]));
                if(!$this->hasCells($root)){if(++$idle>=5)throw new RuntimeException('stalled gmode='.$this->gmode.' sno='.$this->nextSno);continue;}
                $idle=0;$queue[]=$root;
            }
            $root=array_shift($queue);
            foreach($this->consume($root) as $action)$queue[]=as_xml($this->d->dispatch('gpost',$action));
        }
        as_ok($this->done,'never reached final KYOKUEND gmode='.$this->gmode);
        as_ok($this->kyokuStarts>=1,'no KYOKUSTART gmode='.$this->gmode);

        $end=as_xml($this->d->dispatch('end_game',['pcuid'=>$this->pcuid]));
        as_ok(isset($end->mgresult),'mgresult missing gmode='.$this->gmode);
        $players=0;foreach($end->mgresult->children() as $name=>$node)if(str_starts_with((string)$name,'player_'))$players++;
        $stored=$this->db->getMatch($this->pcuid);$seats=(int)($stored['seats']??0);
        as_ok($players===$seats,'player count mismatch gmode='.$this->gmode.' got='.$players.' seats='.$seats);
        return ['kyoku'=>$this->kyokuStarts,'loops'=>$guard,'players'=>$players];
    }

    private function hasCells(SimpleXMLElement $root):bool
    {return isset($root->game->taikyoku->cell_info)&&(string)$root->game->taikyoku->cell_info['available']==='1';}

    /** @return list<array<string,string>> */
    private function consume(SimpleXMLElement $root):array
    {
        $info=$root->game->taikyoku->cell_info??null;
        if(!$info instanceof SimpleXMLElement||(string)$info['available']!=='1')return [];
        $sno=$info->cell_sno??null;if($sno instanceof SimpleXMLElement){$this->nextSno=max($this->nextSno,(int)$sno['start']+(int)$sno['count']);}
        $actions=[];
        foreach($info->children() as $tag=>$cell){
            if(!str_starts_with((string)$tag,'cell_data_'))continue;
            $kind=(int)$cell['kind'];
            if($kind===Table::K_KYOKUSTART){
                $this->kyokuStarts++;$pn='player_info'.$this->pindex;$p=$cell->{$pn};
                as_ok($p instanceof SimpleXMLElement,'player_info missing gmode='.$this->gmode);$this->hand=as_ints($p->tepai??null);
            }elseif($kind===Table::K_TSUMO){
                if((int)$cell->pindex===$this->pindex)$this->hand[]=(int)$cell->pai;
            }elseif($kind===Table::K_SUTEHAI){
                if((int)$cell->pindex===$this->pindex)$this->removePai((int)$cell->pai);
            }elseif($kind===Table::K_TSUMOCHOICES){
                $select=(int)$cell->select;
                if(($select&Table::F_TSUMOAGARI)!==0){$actions[]=$this->post(Table::S_TSUMO_AGARI);continue;}
                as_ok($this->hand!==[],'empty human hand gmode='.$this->gmode);
                $pai=(int)end($this->hand);$reach=0;
                if(($select&Table::F_REACH)!==0&&isset($cell->ptn0->sute_pai)){$pai=(int)$cell->ptn0->sute_pai;$reach=1;}
                $actions[]=$this->post(Table::S_SUTE_PAI,$pai,$reach,$reach===0?1:0);
            }elseif($kind===Table::K_SUTECHOICES){
                $actions[]=$this->post((((int)$cell->select&Table::F_RON)!==0)?Table::S_RON_AGARI:Table::S_NAKINASHI);
            }elseif($kind===Table::K_KYOKUEND){
                if((int)$cell->end_stat===1)$this->done=true;else $actions[]=$this->post(Table::S_NEXT_KYOKU_READY);
            }
        }
        return $actions;
    }

    private function removePai(int $pai):void
    {$k=array_search($pai,$this->hand,true);if($k!==false)array_splice($this->hand,(int)$k,1);}

    /** @return array<string,string> */
    private function post(int $kind,int $pai=0,int $reach=0,int $tsumogiri=0):array
    {
        return ['pcuid'=>$this->pcuid,'kind'=>(string)$kind,'pindex'=>(string)$this->pindex,'pai'=>(string)$pai,'tepai_id'=>'0','tepai_id2'=>'0','reach'=>(string)$reach,'tsumogiri'=>(string)$tsumogiri,'next_sno'=>(string)$this->nextSno];
    }
}

$db=new Database('sqlite::memory:');$d=new Dispatcher($db);
$seedRaw=trim((string)(getenv('SOAK_SEED_BASE')?:''));
$seedBase=$seedRaw!==''?intval($seedRaw,0):0x534F0000;
$modesRaw=trim((string)(getenv('SOAK_MODES')?:''));
if($modesRaw==='')$modes=range(1,23);
else{
    $modes=[];
    foreach(explode(',',$modesRaw) as $part){$g=(int)trim($part);if($g>=1&&$g<=23&&!in_array($g,$modes,true))$modes[]=$g;}
    as_ok($modes!==[],'SOAK_MODES selected no valid modes');
}
$totalLoops=0;$totalKyoku=0;
foreach($modes as $gmode){
    $pcuid='SOAK-'.dechex($seedBase).'-'.$gmode;
    $r=(new AllModesClient($d,$db,$pcuid,$gmode,$seedBase))->run();
    $totalLoops+=$r['loops'];$totalKyoku+=$r['kyoku'];
    echo 'seed=0x'.dechex($seedBase).' gmode='.$gmode.' kyoku='.$r['kyoku'].' loops='.$r['loops'].' players='.$r['players']."\n";
}
echo 'match soak OK modes='.count($modes).' seed=0x'.dechex($seedBase).' kyoku='.$totalKyoku.' loops='.$totalLoops."\n";
