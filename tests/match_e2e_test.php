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

function me_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function me_xml(string $xml):SimpleXMLElement{return new SimpleXMLElement($xml);}
function me_ints(?SimpleXMLElement $n):array{
    if($n===null)return [];$s=trim((string)$n);if($s==='')return [];
    return array_values(array_map('intval',preg_split('/\s+/', $s)?:[]));
}

final class MatchClient
{
    private int $tid=1;
    private int $pindex=0;
    private int $nextSno=0;
    private array $hand=[];
    private int $kyokuStarts=0;
    private bool $done=false;
    private array $counts=[];

    public function __construct(
        private Dispatcher $d,
        private Database $db,
        private string $pcuid,
        private int $gmode,
    ){}

    public function run():array
    {
        $entry=me_xml($this->d->dispatch('entry_game',['pcuid'=>$this->pcuid,'gmode'=>(string)$this->gmode]));
        me_ok(isset($entry->entry),'entry_game returned no entry');
        $this->tid=(int)$entry->entry->tid;$this->pindex=(int)$entry->entry->pindex;$this->nextSno=(int)$entry->entry->next_sno;

        // Keep the complete handler path, but make the wall deterministic for CI.
        $match=$this->db->getMatch($this->pcuid);me_ok(is_array($match),'match not persisted');
        me_ok(is_array($match['table']??null),'table state not persisted');
        $match['table']['seed']=0x4D460000+$this->gmode;
        $this->db->saveMatch($this->pcuid,$match);

        $poll=me_xml($this->d->dispatch('gget',['pcuid'=>$this->pcuid,'ready'=>'0','next_sno'=>(string)$this->nextSno]));
        me_ok(isset($poll->game->mwait),'matching poll returned no mwait');

        $queue=[me_xml($this->d->dispatch('gget',['pcuid'=>$this->pcuid,'ready'=>'1','next_sno'=>(string)$this->nextSno]))];
        $guard=0;$idle=0;
        while(!$this->done && $guard<4000){
            $guard++;
            if(!$queue){
                $root=me_xml($this->d->dispatch('gget',['pcuid'=>$this->pcuid,'ready'=>'1','next_sno'=>(string)$this->nextSno]));
                if(!$this->hasCells($root)){if(++$idle>=5)throw new RuntimeException('match stalled gmode='.$this->gmode.' sno='.$this->nextSno);continue;}
                $idle=0;$queue[]=$root;
            }
            $root=array_shift($queue);$actions=$this->consume($root);
            foreach($actions as $a)$queue[]=me_xml($this->d->dispatch('gpost',$a));
        }
        me_ok($this->done,'gmode '.$this->gmode.' never reached final KYOKUEND');
        me_ok($this->kyokuStarts>=1,'no KYOKUSTART');

        $end=me_xml($this->d->dispatch('end_game',['pcuid'=>$this->pcuid]));
        me_ok(isset($end->mgresult),'end_game returned no mgresult');
        $players=0;foreach($end->mgresult->children() as $name=>$n)if(str_starts_with((string)$name,'player_'))$players++;
        $match=$this->db->getMatch($this->pcuid);$seats=(int)($match['seats']??0);
        me_ok($players===$seats,'mgresult player count mismatch: '.$players.' != '.$seats.' (gmode '.$this->gmode.')');
        return ['gmode'=>$this->gmode,'kyoku'=>$this->kyokuStarts,'loops'=>$guard,'players'=>$players,'counts'=>$this->counts];
    }

    private function hasCells(SimpleXMLElement $root):bool
    {return isset($root->game->taikyoku->cell_info) && (string)$root->game->taikyoku->cell_info['available']==='1';}

    /** @return list<array<string,string>> */
    private function consume(SimpleXMLElement $root):array
    {
        $info=$root->game->taikyoku->cell_info??null;if(!$info instanceof SimpleXMLElement || (string)$info['available']!=='1')return [];
        $sno=$info->cell_sno??null;if($sno instanceof SimpleXMLElement){$start=(int)$sno['start'];$count=(int)$sno['count'];$this->nextSno=max($this->nextSno,$start+$count);}
        $actions=[];
        foreach($info->children() as $tag=>$cell){
            if(!str_starts_with((string)$tag,'cell_data_'))continue;
            $kind=(int)$cell['kind'];$this->counts[$kind]=($this->counts[$kind]??0)+1;
            if($kind===Table::K_KYOKUSTART){
                $this->kyokuStarts++;$pn='player_info'.$this->pindex;$p=$cell->{$pn};
                me_ok($p instanceof SimpleXMLElement,'KYOKUSTART player_info missing');$this->hand=me_ints($p->tepai??null);
            }elseif($kind===Table::K_TSUMO){
                if((int)$cell->pindex===$this->pindex)$this->hand[]=(int)$cell->pai;
            }elseif($kind===Table::K_SUTEHAI){
                if((int)$cell->pindex===$this->pindex)$this->removePai((int)$cell->pai);
            }elseif($kind===Table::K_TSUMOCHOICES){
                $sel=(int)$cell->select;
                if(($sel&Table::F_TSUMOAGARI)!==0){$actions[]=$this->post(Table::S_TSUMO_AGARI);continue;}
                me_ok($this->hand!==[],'TSUMOCHOICES with empty hand');$pai=(int)end($this->hand);$reach=0;
                if(($sel&Table::F_REACH)!==0 && isset($cell->ptn0->sute_pai)){$pai=(int)$cell->ptn0->sute_pai;$reach=1;}
                $actions[]=$this->post(Table::S_SUTE_PAI,$pai,$reach,$reach===0?1:0);
            }elseif($kind===Table::K_SUTECHOICES){
                $sel=(int)$cell->select;$actions[]=$this->post(($sel&Table::F_RON)!==0?Table::S_RON_AGARI:Table::S_NAKINASHI);
            }elseif($kind===Table::K_KYOKUEND){
                if((int)$cell->end_stat===1){$this->done=true;}
                else{$actions[]=$this->post(Table::S_NEXT_KYOKU_READY);}
            }
        }
        return $actions;
    }

    private function removePai(int $pai):void
    {$k=array_search($pai,$this->hand,true);if($k!==false){array_splice($this->hand,(int)$k,1);}}

    /** @return array<string,string> */
    private function post(int $kind,int $pai=0,int $reach=0,int $tsumogiri=0):array
    {
        return ['pcuid'=>$this->pcuid,'kind'=>(string)$kind,'pindex'=>(string)$this->pindex,'pai'=>(string)$pai,'tepai_id'=>'0','tepai_id2'=>'0','reach'=>(string)$reach,'tsumogiri'=>(string)$tsumogiri,'next_sno'=>(string)$this->nextSno];
    }
}

$db=new Database('sqlite::memory:');$d=new Dispatcher($db);
$modes=[4=>'nima',3=>'sanma',1=>'tonpu',2=>'hanchan',6=>'firereach',8=>'acceldora',20=>'bomb'];
foreach($modes as $gmode=>$label){
    $r=(new MatchClient($d,$db,'E2E-'.$gmode,$gmode))->run();
    echo $label.' kyoku='.$r['kyoku'].' loops='.$r['loops'].' players='.$r['players']."\n";
}
echo "full match E2E OK\n";
