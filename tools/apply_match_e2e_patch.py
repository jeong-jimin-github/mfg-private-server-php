from pathlib import Path
import re

p=Path('src/Aog/Dispatcher.php')
s=p.read_text(encoding='utf-8')
pattern=r"    private function endGame\(array \$form\): string\{.*?\}\n    private function endShow"
replacement=r'''    private function endGame(array $form): string
    {
        $pcuid=(string)($form['pcuid']??'GUEST');$m=$this->db->getMatch($pcuid)??[];
        $gmode=(int)($m['gmode']??1);$taku=(int)($m['taku']??(self::GMODE_TAKU[$gmode]??0));$seats=(int)($m['seats']??self::SEATS[$taku]);
        $table=is_array($m['table']??null)?$m['table']:null;
        if($table!==null){$table['state']='game_end';$table['finished']=true;$m['table']=$table;}
        $m['state']='game_end';$this->db->saveMatch($pcuid,$m);
        $scores=is_array($table['scores']??null)?array_map('intval',$table['scores']):array_fill(0,4,0);
        if($table===null)for($i=0;$i<$seats;$i++)$scores[$i]=self::START_SCORE[$taku];
        $order=range(0,$seats-1);usort($order,fn($a,$b)=>($scores[$b]<=>$scores[$a])?:($a<=>$b));$ranks=[];foreach($order as $rank=>$seat)$ranks[$seat]=$rank;
        $umaTable=[4=>[20000,10000,-10000,-20000],3=>[20000,0,-20000],2=>[10000,-10000]];$uma=$umaTable[$seats]??array_fill(0,$seats,0);
        $body='<mgresult><gmode>'.$gmode.'</gmode><taku_class>1</taku_class><continue_state>0</continue_state><continue_fee>0</continue_fee>';
        for($i=0;$i<$seats;$i++){$rank=(int)($ranks[$i]??$i);$body.='<player_'.$i.'><rank>'.$rank.'</rank><score>'.(int)($scores[$i]??0).'</score><uma>'.(int)($uma[$rank]??0).'</uma></player_'.$i.'>';}
        return $this->xml($body.'</mgresult>');
    }
    private function endShow'''
ns,n=re.subn(pattern,replacement,s,count=1,flags=re.S)
if n!=1: raise SystemExit(f'endGame replacement count={n}')
p.write_text(ns,encoding='utf-8')

Path('tests/match_e2e_test.php').write_text(r'''<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Aog\Dispatcher;
use Mfg\Storage\Database;
use Mfg\Mahjong\Table;

const APP='VFG:J:A:A:2025122300';
function me_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
/** @return list<array{0:int,1:int,2:SimpleXMLElement}> */
function me_cells(SimpleXMLElement $root):array{
    $info=$root->xpath('./game/taikyoku/cell_info');if(!$info||!isset($info[0])||(string)$info[0]['available']==='0')return [];$ci=$info[0];$sno=$ci->cell_sno;if(!isset($sno[0]))return [];$start=(int)$sno['start'];$count=(int)$sno['count'];$out=[];for($i=0;$i<$count;$i++){$name='cell_data_'.($start+$i);if(isset($ci->{$name}[0])){$e=$ci->{$name}[0];$out[]=[$start+$i,(int)$e['kind'],$e];}}return $out;
}

final class MatchClient{
    public int $tid=1,$nextSno=0,$pindex=0,$kyokuStarts=0;public bool $done=false;/** @var list<int> */ public array $hand=[];/** @var array<int,int> */ public array $counts=[];
    public function __construct(private Dispatcher $d,public string $pcuid,public int $gmode){}
    public function entry():void{$r=new SimpleXMLElement($this->d->dispatch('entry_game',['pcuid'=>$this->pcuid,'gmode'=>(string)$this->gmode]));$e=$r->entry;me_ok(isset($e[0]),'entry missing');$this->tid=(int)$e->tid;$this->pindex=(int)$e->pindex;$this->nextSno=(int)$e->next_sno;}
    private function must(array $rest):string{return implode('/',array_merge([APP,$this->pcuid,(string)$this->tid,(string)$this->pindex,'1'],array_map('strval',$rest)));}
    public function gget(bool $ready=true):SimpleXMLElement{return new SimpleXMLElement($this->d->dispatch('gget',['pcuid'=>$this->pcuid,'ready'=>$ready?'1':'0','must'=>$this->must([$this->nextSno])]));}
    public function gpost(int $kind,int $pai=0,int $tepai=0,int $tepai2=0,int $reach=0,int $tsumogiri=0):SimpleXMLElement{$must=$this->must([$this->nextSno,$kind,0,0,$pai,$tepai,$tepai2,$reach,$tsumogiri,0]);return new SimpleXMLElement($this->d->dispatch('gpost',['pcuid'=>$this->pcuid,'must'=>$must]));}
    public function consume(SimpleXMLElement $root):bool{$acted=false;foreach(me_cells($root) as [$sno,$kind,$e]){$this->nextSno=$sno+1;$this->counts[$kind]=($this->counts[$kind]??0)+1;if($kind===Table::K_KYOKUSTART){$this->kyokuStarts++;$tag='player_info'.$this->pindex;$p=$e->{$tag};$txt=trim((string)$p->tepai);$this->hand=$txt===''?[]:array_map('intval',preg_split('/\s+/',$txt)?:[]);}elseif($kind===Table::K_TSUMO&&(int)$e->pindex===$this->pindex){$this->hand[]=(int)$e->pai;}elseif($kind===Table::K_SUTEHAI&&(int)$e->pindex===$this->pindex){$pai=(int)$e->pai;$k=array_search($pai,$this->hand,true);if($k!==false){unset($this->hand[$k]);$this->hand=array_values($this->hand);}}elseif($kind===Table::K_KYOKUEND){if((int)$e->end_stat===1){$this->done=true;}else{$this->consume($this->gpost(Table::S_NEXT_KYOKU_READY));}$acted=true;}elseif($kind===Table::K_TSUMOCHOICES){$this->onTsumoChoices($e);$acted=true;}elseif($kind===Table::K_SUTECHOICES){$select=(int)$e->select;$this->consume($this->gpost(($select&Table::F_RON)?Table::S_RON_AGARI:Table::S_NAKINASHI));$acted=true;}}return $acted;}
    private function onTsumoChoices(SimpleXMLElement $e):void{$sel=(int)$e->select;if($sel&Table::F_TSUMOAGARI){$this->consume($this->gpost(Table::S_TSUMO_AGARI));return;}me_ok($this->hand!==[],'empty hand at tsumo choice');$reach=0;$pai=$this->hand[count($this->hand)-1];if(($sel&Table::F_REACH)&&isset($e->ptn0)){$pai=(int)$e->ptn0->sute_pai;$reach=1;}$this->consume($this->gpost(Table::S_SUTE_PAI,$pai,0,0,$reach,$reach?0:1));}
}

function runMode(int $gmode,string $label):void{$db=new Database('sqlite::memory:');$d=new Dispatcher($db);$c=new MatchClient($d,'TEST-'.$gmode,$gmode);$c->entry();$m=$c->gget(false);me_ok(isset($m->game->mwait),'matching mwait missing '.$label);$guard=0;while(!$c->done&&$guard<4000){$guard++;$r=$c->gget(true);$cells=me_cells($r);$acted=$c->consume($r);if(!$acted&&$cells===[])break;}me_ok($c->done,$label.' never reached final KYOKUEND after '.$guard.' loops');me_ok($c->kyokuStarts>=1,$label.' no kyoku');$res=new SimpleXMLElement($d->dispatch('end_game',['pcuid'=>$c->pcuid]));$mg=$res->mgresult;me_ok(isset($mg[0]),$label.' no mgresult');$expected=[4=>2,3=>3,1=>4,2=>4,6=>4,8=>4,20=>4][$gmode]??4;$players=0;foreach($mg->children() as $ch)if(str_starts_with($ch->getName(),'player_')){$players++;me_ok(isset($ch->rank)&&isset($ch->score)&&isset($ch->uma),$label.' incomplete result row');}me_ok($players===$expected,$label.' result seat count');echo $label.' loops='.$guard.' kyoku='.$c->kyokuStarts." OK\n";}

foreach([[4,'nima'],[3,'sanma'],[1,'tonpu'],[2,'hanchan'],[6,'firereach'],[8,'acceldora'],[20,'bomb']] as [$mode,$label])runMode($mode,$label);
echo "match E2E soak OK\n";
''',encoding='utf-8')
