<?php

declare(strict_types=1);

namespace Mfg\Mahjong;

/**
 * Persistent core of the VFG table state machine.
 *
 * State is a plain array so every PHP-FPM request can reload/save it through
 * SQLite. This first stage implements KYOKUSTART, TSUMO, TSUMOCHOICES,
 * SUTEHAI and CPU turns; calls/win scoring are layered on top of the same state.
 */
final class Table
{
    public const K_TSUMO = 1;
    public const K_SUTEHAI = 2;
    public const K_RYUKYOKU = 5;
    public const K_TSUMOCHOICES = 15;
    public const K_KYOKUSTART = 17;
    public const K_KYOKUEND = 23;
    public const K_SCORERANK = 24;

    public const S_SUTE_PAI = 2;
    public const S_TSUMO_AGARI = 3;
    public const S_RON_AGARI = 4;
    public const S_NAKINASHI = 11;
    public const S_NEXT_KYOKU_READY = 15;

    public const F_TSUMOAGARI = 0x40;
    public const F_REACH = 0x200;
    public const F_SUTE = 0x400;

    /** @param array<string,mixed> $s */
    public function __construct(private array $s) {}

    /** @return array<string,mixed> */
    public static function create(int $taku, int $human=0, ?int $seed=null): array
    {
        $seats = Mahjong::SEATS_OF[$taku];
        $scores = [0,0,0,0];
        for($i=0;$i<$seats;$i++) $scores[$i]=Mahjong::START_SCORE[$taku];
        return [
            'taku'=>$taku,'seats'=>$seats,'human'=>$human%$seats,'seed'=>$seed ?? random_int(1,PHP_INT_MAX),
            'scores'=>$scores,'kyoku_index'=>0,'honba'=>0,'kyotaku'=>0,'cells'=>[],'state'=>'init','finished'=>false,
            'hands'=>[[],[],[],[]],'discards'=>[[],[],[],[]],'wall'=>[],'rinshan'=>[],'dora_ind'=>[],'ura_ind'=>[],
            'turn'=>0,'drawn'=>[null,null,null,null],'discard_count'=>0,'pending_choices'=>null,
        ];
    }

    /** @return array<string,mixed> */
    public function state(): array { return $this->s; }

    public function startKyoku(): void
    {
        $taku=(int)$this->s['taku']; $seats=(int)$this->s['seats'];
        $tiles=Mahjong::buildWall($taku,(int)$this->s['seed'] + (int)$this->s['kyoku_index']);
        $dead=array_slice($tiles,-14); $live=array_slice($tiles,0,-14);
        $this->s['rinshan']=array_slice($dead,0,4);
        $this->s['dora_ind']=array_slice($dead,4,5);
        $this->s['ura_ind']=array_slice($dead,9,5);
        $this->s['hands']=[[],[],[],[]]; $this->s['discards']=[[],[],[],[]]; $this->s['drawn']=[null,null,null,null];
        for($seat=0;$seat<$seats;$seat++) {
            $this->s['hands'][$seat]=array_slice($live,0,13); sort($this->s['hands'][$seat]); $live=array_slice($live,13);
        }
        $this->s['wall']=array_values($live); $this->s['state']='discard'; $this->s['discard_count']=0; $this->s['pending_choices']=null;
        $oya=$this->oya(); $ba=$this->ba(); $kyoku=$this->kyoku();
        $inner='<chicya>0</chicya><oya>'.$oya.'</oya>'.$this->ints('sai',[random_int(1,6),random_int(1,6)])
            .'<ba>'.$ba.'</ba><kyoku>'.$kyoku.'</kyoku><all_last>'.($this->isAllLast()?1:0).'</all_last><honba>'.(int)$this->s['honba'].'</honba><rencyan>0</rencyan><kyoutaku>'.(int)$this->s['kyotaku'].'</kyoutaku>'
            .'<nokori>'.count($live).'</nokori><dora_open>1</dora_open>'.$this->ints('dora',$this->pais($this->s['dora_ind']))
            .$this->ints('ura_dora',$this->pais($this->s['ura_ind'])).'<yama_cnt>'.count($live).'</yama_cnt>'.$this->ints('yama',$this->pais($live)).$this->ints('rinshan',$this->pais($this->s['rinshan']));
        $ranks=$this->ranks();
        for($i=0;$i<4;$i++) {
            if($i<$seats){$tepai=$this->pais($this->s['hands'][$i]);$score=(int)$this->s['scores'][$i];$rank=$ranks[$i];$jikaze=($i-$oya+$seats)%$seats;}
            else {$tepai=array_fill(0,13,1);$score=0;$rank=$i;$jikaze=$i;}
            $inner.='<player_info'.$i.'><jikaze>'.$jikaze.'</jikaze>'.$this->ints('tepai',$tepai).'<score>'.$score.'</score><rank>'.$rank.'</rank></player_info'.$i.'>';
        }
        $this->cell(self::K_KYOKUSTART,$inner);
        $this->beginTurn($oya);
    }

    public function flushPending(): void
    {
        $p=$this->s['pending_choices']; if(!is_array($p))return; $this->s['pending_choices']=null;
        $seat=(int)$p['seat']; $flags=(int)$p['flags'];
        $this->cell(self::K_TSUMOCHOICES,'<select>'.$flags.'</select><ptn_num>0</ptn_num>',[$seat]);
    }

    public function onCommand(int $kind,int $pindex,int $pai,int $reach=0,int $tsumogiri=0): void
    {
        if($this->s['finished'])return;
        if($kind===self::S_SUTE_PAI) {
            $idx=Mahjong::paiToIdx($pai);
            if($idx<0 || !in_array($idx,$this->s['hands'][$pindex]??[],true)) $idx=(int)($this->s['drawn'][$pindex] ?? ($this->s['hands'][$pindex][count($this->s['hands'][$pindex])-1] ?? -1));
            if($idx>=0)$this->discard($pindex,$idx,$reach!==0,$tsumogiri!==0);
            return;
        }
        if($kind===self::S_NEXT_KYOKU_READY && $this->s['state']==='kyoku_end') {
            $this->s['kyoku_index']++;
            if($this->isPastEnd()){$this->s['finished']=true;$this->s['state']='game_end';return;}
            $this->startKyoku();
        }
    }

    public function cellsFrom(int $start): string
    {
        if($start<0)$start=0; $cells=$this->s['cells'];
        if($start>=count($cells))return '<taikyoku><cell_info available="0" /></taikyoku>';
        $chunk=array_slice($cells,$start);
        return '<taikyoku><cell_info available="1"><cell_sno start="'.$start.'" count="'.count($chunk).'"></cell_sno>'.implode('',$chunk).'</cell_info></taikyoku>';
    }

    private function beginTurn(int $seat): void
    {
        if(empty($this->s['wall'])){$this->ryuukyoku();return;}
        $tile=array_shift($this->s['wall']); $this->s['hands'][$seat][]=$tile; sort($this->s['hands'][$seat]); $this->s['drawn'][$seat]=$tile; $this->s['turn']=$seat;
        $this->cell(self::K_TSUMO,'<pindex>'.$seat.'</pindex><pai>'.Mahjong::idxToPai($tile).'</pai>');
        if($seat===(int)$this->s['human']) {
            $flags=self::F_SUTE; $counts=Mahjong::countsOf($this->s['hands'][$seat]); if(Mahjong::isAgari($counts,0,(int)$this->s['taku']))$flags|=self::F_TSUMOAGARI;
            if((int)$this->s['scores'][$seat]>=1000 && count($this->s['wall'])>=4 && $this->canReach($seat))$flags|=self::F_REACH;
            $this->s['pending_choices']=['seat'=>$seat,'flags'=>$flags];
        } else {
            $this->cpuTurn($seat);
        }
    }

    private function cpuTurn(int $seat): void
    {
        $hand=$this->s['hands'][$seat]; $best=null; $bestSh=99;
        $seen=[];
        foreach($hand as $tile){if(isset($seen[$tile]))continue;$seen[$tile]=true;$tmp=$hand;$k=array_search($tile,$tmp,true);unset($tmp[$k]);$tmp=array_values($tmp);$sh=Mahjong::shanten(Mahjong::countsOf($tmp),0,(int)$this->s['taku']);if($sh<$bestSh){$bestSh=$sh;$best=$tile;}}
        $tile=$best ?? (int)end($hand); $this->discard($seat,$tile,false,$tile===($this->s['drawn'][$seat]??null));
    }

    private function discard(int $seat,int $tile,bool $reach,bool $tsumogiri): void
    {
        $k=array_search($tile,$this->s['hands'][$seat],true); if($k===false)return; unset($this->s['hands'][$seat][$k]); $this->s['hands'][$seat]=array_values($this->s['hands'][$seat]); sort($this->s['hands'][$seat]);
        $this->s['discards'][$seat][]=$tile; $this->s['drawn'][$seat]=null; $this->s['discard_count']++;
        if($reach && (int)$this->s['scores'][$seat]>=1000){$this->s['scores'][$seat]-=1000;$this->s['kyotaku']++;}
        $stat=($reach?1:0)|($tsumogiri?2:0); $this->cell(self::K_SUTEHAI,'<pindex>'.$seat.'</pindex><pai>'.Mahjong::idxToPai($tile).'</pai><stat>'.$stat.'</stat>');
        if($reach)$this->scoreRankCell();
        $this->beginTurn(($seat+1)%(int)$this->s['seats']);
    }

    private function canReach(int $seat): bool
    {
        $hand=$this->s['hands'][$seat]; $seen=[];
        foreach($hand as $tile){if(isset($seen[$tile]))continue;$seen[$tile]=true;$tmp=$hand;$k=array_search($tile,$tmp,true);unset($tmp[$k]);$tmp=array_values($tmp);if(Mahjong::shanten(Mahjong::countsOf($tmp),0,(int)$this->s['taku'])===0)return true;}
        return false;
    }

    private function ryuukyoku(): void
    {
        $this->s['state']='kyoku_end';
        $this->cell(self::K_RYUKYOKU,'<reason>0</reason><honba>'.(int)$this->s['honba'].'</honba><kyoutaku>'.(int)$this->s['kyotaku'].'</kyoutaku>');
        $this->cell(self::K_KYOKUEND,'<result>0</result>');
    }

    private function scoreRankCell(): void
    {
        $r=$this->ranks();$inner='<kyoutaku>'.(int)$this->s['kyotaku'].'</kyoutaku>';
        for($i=0;$i<4;$i++)$inner.='<riti_after'.$i.'><score>'.(int)$this->s['scores'][$i].'</score><rank>'.$r[$i].'</rank></riti_after'.$i.'>';
        $this->cell(self::K_SCORERANK,$inner);
    }

    /** @param list<int>|null $pis */
    private function cell(int $kind,string $inner,?array $pis=null): void
    {
        $seq=count($this->s['cells']);$targets=$pis??range(0,(int)$this->s['seats']-1);$flags='';
        for($i=0;$i<4;$i++)$flags.=' pi'.$i.'="'.(in_array($i,$targets,true)?1:0).'"';
        $this->s['cells'][]='<cell_data_'.$seq.' kind="'.$kind.'"'.$flags.'>'.$inner.'</cell_data_'.$seq.'>';
    }

    /** @return list<int> */
    private function ranks(): array
    {
        $seats=(int)$this->s['seats'];$order=range(0,$seats-1);usort($order,fn($a,$b)=>($this->s['scores'][$b]<=>$this->s['scores'][$a])?:($a<=>$b));$r=[0,1,2,3];foreach($order as $rank=>$seat)$r[$seat]=$rank;return $r;
    }
    private function oya(): int{return (int)$this->s['kyoku_index']%(int)$this->s['seats'];}
    private function ba(): int{return (int)$this->s['kyoku_index']<(int)$this->s['seats']?0:1;}
    private function kyoku(): int{return (int)$this->s['kyoku_index']%(int)$this->s['seats'];}
    private function isAllLast(): bool{return (int)$this->s['kyoku_index']>=Mahjong::KYOKU_COUNT[(int)$this->s['taku']]-1;}
    private function isPastEnd(): bool{return (int)$this->s['kyoku_index']>=Mahjong::KYOKU_COUNT[(int)$this->s['taku']];}
    /** @param list<int> $v */ private function pais(array $v):array{return array_map([Mahjong::class,'idxToPai'],$v);}
    /** @param list<int> $v */ private function ints(string $tag,array $v):string{$v=$v?:[0];return '<'.$tag.' __count="'.count($v).'">'.implode(' ',array_map('intval',$v)).'</'.$tag.'>';}
}
