<?php

declare(strict_types=1);

namespace Mfg\Mahjong;

final class Table
{
    public const K_TSUMO=1,K_SUTEHAI=2,K_TSUMOAGARI=3,K_RON=4,K_RYUKYOKU=5,K_PON=6,K_CHI=7,K_ANKAN=8,K_MINKAN=9,K_KAKAN=10,K_TSUMOCHOICES=15,K_SUTECHOICES=16,K_KYOKUSTART=17,K_KYOKUEND=23,K_SCORERANK=24;
    public const S_SUTE_PAI=2,S_TSUMO_AGARI=3,S_RON_AGARI=4,S_PON=5,S_CHI=6,S_ANKAN=7,S_MINKAN=8,S_KAKAN=9,S_KYUSYUKYUHAI=10,S_NAKINASHI=11,S_NEXT_KYOKU_READY=15;
    public const F_PON=0x2,F_CHI=0x4,F_KAN=0x8,F_TSUMOAGARI=0x40,F_RON=0x80,F_KYUSYU=0x100,F_REACH=0x200,F_SUTE=0x400;

    /** @param array<string,mixed> $s */
    public function __construct(private array $s){$this->normalize();}

    /** @return array<string,mixed> */
    public static function create(int $taku,int $human=0,?int $seed=null):array
    {
        $seats=Mahjong::SEATS_OF[$taku];$scores=[0,0,0,0];
        for($i=0;$i<$seats;$i++)$scores[$i]=Mahjong::START_SCORE[$taku];
        return [
            'taku'=>$taku,'seats'=>$seats,'human'=>$human%$seats,'seed'=>$seed??random_int(1,PHP_INT_MAX),
            'scores'=>$scores,'kyoku_index'=>0,'honba'=>0,'kyotaku'=>0,'cells'=>[],'state'=>'init','finished'=>false,
            'hands'=>[[],[],[],[]],'melds'=>[[],[],[],[]],'discards'=>[[],[],[],[]],'discard_log'=>[],'riichi_at'=>[-1,-1,-1,-1],
            'wall'=>[],'rinshan'=>[],'dora_ind'=>[],'ura_ind'=>[],'dora_open'=>1,'kan_count'=>0,'turn'=>0,'drawn'=>[null,null,null,null],
            'discard_count'=>0,'riichi'=>[false,false,false,false],'double_riichi'=>[false,false,false,false],
            'ippatsu'=>[false,false,false,false],'furiten'=>[false,false,false,false],'temp_furiten'=>[false,false,false,false],
            'pending_choices'=>null,'call_ctx'=>null,'last_draw_rinshan'=>false,'any_call'=>false,'advance_kyoku'=>true,
        ];
    }

    /** @return array<string,mixed> */
    public function state():array{return $this->s;}

    private function normalize():void
    {
        $defaults=[
            'melds'=>[[],[],[],[]],'riichi'=>[false,false,false,false],'double_riichi'=>[false,false,false,false],
            'ippatsu'=>[false,false,false,false],'furiten'=>[false,false,false,false],'temp_furiten'=>[false,false,false,false],
            'discard_log'=>[],'riichi_at'=>[-1,-1,-1,-1],'call_ctx'=>null,'dora_open'=>1,'kan_count'=>0,
            'last_draw_rinshan'=>false,'any_call'=>false,'advance_kyoku'=>true,
        ];
        foreach($defaults as $k=>$v)if(!array_key_exists($k,$this->s))$this->s[$k]=$v;
    }

    public function startKyoku():void
    {
        $taku=(int)$this->s['taku'];$seats=(int)$this->s['seats'];
        $tiles=Mahjong::buildWall($taku,(int)$this->s['seed']+(int)$this->s['kyoku_index']);
        $dead=array_slice($tiles,-14);$live=array_slice($tiles,0,-14);
        $this->s['rinshan']=array_slice($dead,0,4);$this->s['dora_ind']=array_slice($dead,4,5);$this->s['ura_ind']=array_slice($dead,9,5);
        $this->s['dora_open']=1;$this->s['kan_count']=0;$this->s['hands']=[[],[],[],[]];$this->s['melds']=[[],[],[],[]];
        $this->s['discards']=[[],[],[],[]];$this->s['discard_log']=[];$this->s['riichi_at']=[-1,-1,-1,-1];
        $this->s['drawn']=[null,null,null,null];$this->s['riichi']=[false,false,false,false];$this->s['double_riichi']=[false,false,false,false];
        $this->s['ippatsu']=[false,false,false,false];$this->s['furiten']=[false,false,false,false];$this->s['temp_furiten']=[false,false,false,false];
        $this->s['discard_count']=0;$this->s['pending_choices']=null;$this->s['call_ctx']=null;$this->s['last_draw_rinshan']=false;
        $this->s['any_call']=false;$this->s['advance_kyoku']=true;
        for($seat=0;$seat<$seats;$seat++){$this->s['hands'][$seat]=array_slice($live,0,13);sort($this->s['hands'][$seat]);$live=array_slice($live,13);}
        $this->s['wall']=array_values($live);$this->s['state']='discard';
        $oya=$this->oya();
        $inner='<chicya>0</chicya><oya>'.$oya.'</oya>'.$this->ints('sai',[random_int(1,6),random_int(1,6)])
            .'<ba>'.$this->ba().'</ba><kyoku>'.$this->kyoku().'</kyoku><all_last>'.($this->isAllLast()?1:0).'</all_last>'
            .'<honba>'.(int)$this->s['honba'].'</honba><rencyan>0</rencyan><kyoutaku>'.(int)$this->s['kyotaku'].'</kyoutaku>'
            .'<nokori>'.count($live).'</nokori><dora_open>1</dora_open>'.$this->ints('dora',$this->pais($this->s['dora_ind']))
            .$this->ints('ura_dora',$this->pais($this->s['ura_ind'])).'<yama_cnt>'.count($live).'</yama_cnt>'
            .$this->ints('yama',$this->pais($live)).$this->ints('rinshan',$this->pais($this->s['rinshan']));
        $ranks=$this->ranks();
        for($i=0;$i<4;$i++){
            if($i<$seats){$tepai=$this->pais($this->s['hands'][$i]);$score=(int)$this->s['scores'][$i];$rank=$ranks[$i];$jikaze=($i-$oya+$seats)%$seats;}
            else{$tepai=array_fill(0,13,1);$score=0;$rank=$i;$jikaze=$i;}
            $inner.='<player_info'.$i.'><jikaze>'.$jikaze.'</jikaze>'.$this->ints('tepai',$tepai).'<score>'.$score.'</score><rank>'.$rank.'</rank></player_info'.$i.'>';
        }
        $this->cell(self::K_KYOKUSTART,$inner);$this->beginTurn($oya);
    }

    public function flushPending():void
    {
        $p=$this->s['pending_choices'];if(!is_array($p))return;$this->s['pending_choices']=null;
        $seat=(int)$p['seat'];$flags=(int)$p['flags'];$patterns=is_array($p['patterns']??null)?$p['patterns']:[];
        $inner='<select>'.$flags.'</select><ptn_num>'.count($patterns).'</ptn_num>';
        $visible=$this->visibleCounts($seat);
        foreach($patterns as $i=>$pattern){
            $sute=(int)$pattern[0];$waits=array_values(array_map('intval',$pattern[1]??[]));$stat=[];
            foreach($waits as $w)$stat[]=(($visible[$w]??0)>=4)?2:0;
            $inner.='<ptn'.$i.'><sute_pai>'.Mahjong::idxToPai($sute).'</sute_pai><machi_num>'.count($waits).'</machi_num>'
                .$this->ints('machi_pai',$this->pais($waits)).$this->ints('stat',$stat).'</ptn'.$i.'>';
        }
        $kans=is_array($p['kans']??null)?$p['kans']:[];
        if($kans){$kanPai=[];$kanType=[];foreach($kans as $k){$kanPai[]=Mahjong::idxToPai((int)$k[0]);$kanType[]=(int)$k[1];}$inner.=$this->ints('kan_pai',$kanPai).$this->ints('kan_type',$kanType);}
        $this->cell(self::K_TSUMOCHOICES,$inner,[$seat]);
    }

    public function onCommand(int $kind,int $pindex,int $pai,int $reach=0,int $tsumogiri=0,int $tepaiId=0,int $tepaiId2=0):void
    {
        if(!empty($this->s['finished']))return;$seat=(int)$this->s['human'];
        if($kind===self::S_SUTE_PAI){$idx=Mahjong::paiToIdx($pai);if($idx<0||!in_array($idx,$this->s['hands'][$pindex]??[],true))$idx=(int)($this->s['drawn'][$pindex]??($this->s['hands'][$pindex][count($this->s['hands'][$pindex])-1]??-1));if($idx>=0)$this->discard($pindex,$idx,$reach!==0,$tsumogiri!==0);return;}
        if($kind===self::S_TSUMO_AGARI){$this->applyTsumo($seat);return;}
        if($kind===self::S_RON_AGARI){$ctx=$this->s['call_ctx'];if(is_array($ctx)){$from=(int)$ctx['discarder'];$tile=(int)$ctx['tile'];$chankan=!empty($ctx['chankan']);$winners=$this->ronCandidates($from,$tile,$chankan,true);if(in_array($seat,$winners,true))$this->applyRonMany($winners,$from,$tile,$chankan);}return;}
        if($kind===self::S_KYUSYUKYUHAI){$this->s['pending_choices']=null;if($this->kyuushuOk($seat))$this->ryuukyoku(true);return;}
        if($kind===self::S_NAKINASHI){$ctx=$this->s['call_ctx'];$this->s['call_ctx']=null;$this->s['state']='discard';if(is_array($ctx)){$this->s['temp_furiten'][$seat]=!empty($ctx['ron']);$from=(int)$ctx['discarder'];$tile=(int)$ctx['tile'];if(!empty($ctx['chankan'])){$cpu=$this->ronCandidates($from,$tile,true,false);if($cpu)$this->applyRonMany($cpu,$from,$tile,true);else$this->beginTurn($from,true);}else$this->cpuCalls($from,$tile);}return;}
        if(in_array($kind,[self::S_PON,self::S_CHI,self::S_MINKAN],true)){$ctx=$this->s['call_ctx'];if(!is_array($ctx))return;$from=(int)$ctx['discarder'];$tile=(int)$ctx['tile'];$this->s['call_ctx']=null;if($kind===self::S_PON)$this->applyPon($seat,$from,$tile);elseif($kind===self::S_CHI)$this->applyChi($seat,$from,$tile,$tepaiId,$tepaiId2);else$this->applyMinkan($seat,$from,$tile);return;}
        if($kind===self::S_ANKAN){$idx=Mahjong::paiToIdx($pai);if($idx>=0)$this->applyAnkan($seat,$idx);return;}
        if($kind===self::S_KAKAN){$idx=Mahjong::paiToIdx($pai);if($idx>=0)$this->applyKakan($seat,$idx);return;}
        if($kind===self::S_NEXT_KYOKU_READY&&$this->s['state']==='kyoku_end'){if(!empty($this->s['advance_kyoku']))$this->s['kyoku_index']++;if($this->isPastEnd()){$this->s['finished']=true;$this->s['state']='game_end';return;}$this->startKyoku();}
    }

    public function cellsFrom(int $start):string
    {
        if($start<0)$start=0;$cells=$this->s['cells'];if($start>=count($cells))return '<taikyoku><cell_info available="0" /></taikyoku>';
        $chunk=array_slice($cells,$start);return '<taikyoku><cell_info available="1"><cell_sno start="'.$start.'" count="'.count($chunk).'"></cell_sno>'.implode('',$chunk).'</cell_info></taikyoku>';
    }

    private function beginTurn(int $seat,bool $rinshan=false):void
    {
        if(in_array($this->s['state'],['kyoku_end','game_end'],true))return;
        if($rinshan){if((int)$this->s['kan_count']<1||empty($this->s['rinshan'])||empty($this->s['wall'])){$this->ryuukyoku();return;}$tile=$this->s['rinshan'][(int)$this->s['kan_count']-1]??null;array_pop($this->s['wall']);}
        else{$tile=array_shift($this->s['wall']);}
        if($tile===null){$this->ryuukyoku();return;}
        $this->s['hands'][$seat][]=$tile;sort($this->s['hands'][$seat]);$this->s['drawn'][$seat]=$tile;$this->s['turn']=$seat;$this->s['last_draw_rinshan']=$rinshan;$this->s['temp_furiten'][$seat]=false;
        $this->cell(self::K_TSUMO,'<pindex>'.$seat.'</pindex><pai>'.Mahjong::idxToPai($tile).'</pai>');if($seat===(int)$this->s['human'])$this->offerTsumoChoices($seat);else$this->cpuTurn($seat);
    }

    private function offerTsumoChoices(int $seat):void
    {
        $flags=self::F_SUTE;$patterns=[];if($this->winResult($seat,(int)$this->s['drawn'][$seat],true)!==null)$flags|=self::F_TSUMOAGARI;
        if(empty($this->s['riichi'][$seat])&&count($this->s['melds'][$seat])===0&&(int)$this->s['scores'][$seat]>=1000&&count($this->s['wall'])>=4){$patterns=$this->tenpaiPatterns($seat);if($patterns)$flags|=self::F_REACH;}
        $kans=$this->ankanOptions($seat);if($kans)$flags|=self::F_KAN;if($this->kyuushuOk($seat))$flags|=self::F_KYUSYU;
        $this->s['pending_choices']=['seat'=>$seat,'flags'=>$flags,'patterns'=>$patterns,'kans'=>$kans];$this->s['state']='discard';
    }

    private function cpuTurn(int $seat):void
    {
        $drawn=$this->s['drawn'][$seat]??null;if($drawn!==null&&$this->winResult($seat,(int)$drawn,true)!==null){$this->applyTsumo($seat);return;}
        foreach($this->ankanOptions($seat) as [$tile,$type]){if(!CpuAi::wantsKan($this->s,$seat,(int)$tile,(int)$type))continue;if((int)$type===1)$this->applyAnkan($seat,(int)$tile);else$this->applyKakan($seat,(int)$tile);return;}
        [$tile,$declare]=CpuAi::chooseDiscard($this->s,$seat);$this->discard($seat,$tile,$declare,$tile===($drawn??null));
    }

    private function discard(int $seat,int $tile,bool $reach,bool $tsumogiri):void
    {
        $k=array_search($tile,$this->s['hands'][$seat],true);if($k===false)return;unset($this->s['hands'][$seat][$k]);$this->s['hands'][$seat]=array_values($this->s['hands'][$seat]);sort($this->s['hands'][$seat]);
        $this->s['discards'][$seat][]=$tile;$this->s['discard_log'][]=[$seat,$tile];$this->s['drawn'][$seat]=null;$this->s['discard_count']++;
        if($reach&&!$this->s['riichi'][$seat]&&(int)$this->s['scores'][$seat]>=1000){$this->s['scores'][$seat]-=1000;$this->s['kyotaku']++;$this->s['riichi'][$seat]=true;$this->s['ippatsu'][$seat]=true;$this->s['riichi_at'][$seat]=count($this->s['discard_log']);$this->s['double_riichi'][$seat]=count($this->s['discards'][$seat])===1&&!$this->s['any_call'];}
        elseif($this->s['riichi'][$seat])$this->s['ippatsu'][$seat]=false;
        $stat=($reach?1:0)|($tsumogiri?2:0);$this->cell(self::K_SUTEHAI,'<pindex>'.$seat.'</pindex><pai>'.Mahjong::idxToPai($tile).'</pai><stat>'.$stat.'</stat>');if($reach)$this->scoreRankCell();$this->updateFuriten($seat);$this->afterDiscard($seat,$tile);
    }

    private function afterDiscard(int $discarder,int $tile):void
    {
        $h=(int)$this->s['human'];if($h!==$discarder){$ron=$this->winResult($h,$tile,false)!==null;$pon=empty($this->s['riichi'][$h])&&$this->countTile($h,$tile)>=2;$chi=empty($this->s['riichi'][$h])&&$h===$this->nextSeat($discarder)&&!empty($this->chiOptions($h,$tile));$kan=empty($this->s['riichi'][$h])&&$this->countTile($h,$tile)>=3&&(int)$this->s['kan_count']<4&&!empty($this->s['wall']);if($ron||$pon||$chi||$kan){$this->offerSuteChoices($discarder,$tile,$ron,$pon,$chi,$kan);return;}}$this->cpuCalls($discarder,$tile);
    }

    private function offerSuteChoices(int $discarder,int $tile,bool $ron,bool $pon,bool $chi,bool $kan,bool $chankan=false):void
    {
        $flags=0;$naki=0;if($ron)$flags|=self::F_RON;if($pon){$flags|=self::F_PON;$naki|=self::F_PON;}if($chi){$flags|=self::F_CHI;$naki|=self::F_CHI;}if($kan){$flags|=self::F_KAN;$naki|=self::F_KAN;}
        $inner='<select>'.$flags.'</select><naki>'.$naki.'</naki><pindex>'.$discarder.'</pindex><sute_pai>'.Mahjong::idxToPai($tile).'</sute_pai>';
        if($chi){$flat=[];foreach(array_slice($this->chiOptions((int)$this->s['human'],$tile),0,6) as $o)foreach($o as $x)$flat[]=Mahjong::idxToPai($x);$inner.=$this->ints('chi_pai',$flat);}if($pon)$inner.=$this->ints('pon_pai',[Mahjong::idxToPai($tile),Mahjong::idxToPai($tile)]);if($kan)$inner.=$this->ints('kan_pai',[Mahjong::idxToPai($tile)]).$this->ints('kan_type',[2]);
        $this->cell(self::K_SUTECHOICES,$inner,[(int)$this->s['human']]);$this->s['call_ctx']=['discarder'=>$discarder,'tile'=>$tile,'ron'=>$ron,'chankan'=>$chankan];$this->s['state']='call';
    }

    /** @return list<int> */
    private function ronCandidates(int $discarder,int $tile,bool $chankan=false,bool $includeHuman=true):array
    {
        $out=[];$seats=(int)$this->s['seats'];$human=(int)$this->s['human'];for($i=1;$i<$seats;$i++){$s=($discarder+$i)%$seats;if(!$includeHuman&&$s===$human)continue;if($this->winResult($s,$tile,false,$chankan)!==null)$out[]=$s;}return $out;
    }

    private function cpuCalls(int $discarder,int $tile):void
    {
        $winners=$this->ronCandidates($discarder,$tile,false,false);if($winners){$this->applyRonMany($winners,$discarder,$tile);return;}
        $seats=(int)$this->s['seats'];for($i=1;$i<$seats;$i++){$s=($discarder+$i)%$seats;if($s===(int)$this->s['human']||!empty($this->s['riichi'][$s]))continue;if($this->countTile($s,$tile)>=3&&(int)$this->s['kan_count']<4&&!empty($this->s['wall'])&&CpuAi::wantsPon($this->s,$s,$tile)){$this->applyMinkan($s,$discarder,$tile);return;}if($this->countTile($s,$tile)>=2&&CpuAi::wantsPon($this->s,$s,$tile)){$this->applyPon($s,$discarder,$tile);return;}}
        $next=$this->nextSeat($discarder);if($next!==(int)$this->s['human']&&empty($this->s['riichi'][$next])){$pick=CpuAi::pickChi($this->s,$next,$tile,$this->chiOptions($next,$tile));if($pick!==null){$this->applyChiByTiles($next,$discarder,$tile,$pick);return;}}$this->beginTurn($next);
    }

    private function applyPon(int $seat,int $from,int $tile):void
    {for($i=0;$i<2;$i++)$this->removeTile($seat,$tile);$this->s['melds'][$seat][]=['kind'=>'pon','tiles'=>[$tile,$tile,$tile],'called'=>$tile,'from_seat'=>$from];$this->s['any_call']=true;$this->breakIppatsu();$this->cell(self::K_PON,'<pindex>'.$seat.'</pindex><sute_pindex>'.$from.'</sute_pindex><pai>'.Mahjong::idxToPai($tile).'</pai>'.$this->ints('pon_pai',[Mahjong::idxToPai($tile),Mahjong::idxToPai($tile)]));$this->s['turn']=$seat;$this->s['drawn'][$seat]=null;if($seat===(int)$this->s['human'])$this->offerTsumoChoices($seat);else$this->cpuDiscardAfterCall($seat);}

    private function applyChi(int $seat,int $from,int $tile,int $a=0,int $b=0):void
    {$opts=$this->chiOptions($seat,$tile);if(!$opts){$this->beginTurn($this->nextSeat($from));return;}$own=$opts[0];if($a||$b){$ia=Mahjong::paiToIdx($a);$ib=Mahjong::paiToIdx($b);foreach($opts as $o)if(in_array($ia,$o,true)&&in_array($ib,$o,true)){$own=$o;break;}}$this->applyChiByTiles($seat,$from,$tile,$own);}

    /** @param list<int> $own */
    private function applyChiByTiles(int $seat,int $from,int $tile,array $own):void
    {foreach($own as $t)$this->removeTile($seat,(int)$t);$tiles=array_merge([$tile],$own);sort($tiles);$this->s['melds'][$seat][]=['kind'=>'chi','tiles'=>$tiles,'called'=>$tile,'from_seat'=>$from];$this->s['any_call']=true;$this->breakIppatsu();$this->cell(self::K_CHI,'<pindex>'.$seat.'</pindex><sute_pindex>'.$from.'</sute_pindex><pai>'.Mahjong::idxToPai($tile).'</pai>'.$this->ints('chi_pai',$this->pais($own)));$this->s['turn']=$seat;$this->s['drawn'][$seat]=null;if($seat===(int)$this->s['human'])$this->offerTsumoChoices($seat);else$this->cpuDiscardAfterCall($seat);}

    private function applyMinkan(int $seat,int $from,int $tile):void
    {for($i=0;$i<3;$i++)$this->removeTile($seat,$tile);$this->s['melds'][$seat][]=['kind'=>'minkan','tiles'=>[$tile,$tile,$tile,$tile],'called'=>$tile,'from_seat'=>$from];$this->s['any_call']=true;$this->breakIppatsu();$this->s['kan_count']++;$this->s['dora_open']=min(5,(int)$this->s['dora_open']+1);$this->cell(self::K_MINKAN,'<pindex>'.$seat.'</pindex><sute_pindex>'.$from.'</sute_pindex><pai>'.Mahjong::idxToPai($tile).'</pai>');$this->beginTurn($seat,true);}

    private function applyAnkan(int $seat,int $tile):void
    {if($this->countTile($seat,$tile)<4)return;for($i=0;$i<4;$i++)$this->removeTile($seat,$tile);$this->s['melds'][$seat][]=['kind'=>'ankan','tiles'=>[$tile,$tile,$tile,$tile],'called'=>$tile,'from_seat'=>$seat];$this->s['any_call']=true;$this->breakIppatsu();$this->s['kan_count']++;$this->s['dora_open']=min(5,(int)$this->s['dora_open']+1);$this->cell(self::K_ANKAN,'<pindex>'.$seat.'</pindex><pai>'.Mahjong::idxToPai($tile).'</pai>');$this->beginTurn($seat,true);}

    private function applyKakan(int $seat,int $tile):void
    {
        if($this->countTile($seat,$tile)<1)return;foreach($this->s['melds'][$seat] as &$m){if(($m['kind']??'')!=='pon'||($m['tiles'][0]??-1)!==$tile)continue;$this->removeTile($seat,$tile);$m['kind']='kakan';$m['tiles']=[$tile,$tile,$tile,$tile];$this->s['any_call']=true;$this->breakIppatsu();$this->s['kan_count']++;$this->s['dora_open']=min(5,(int)$this->s['dora_open']+1);$this->cell(self::K_KAKAN,'<pindex>'.$seat.'</pindex><pai>'.Mahjong::idxToPai($tile).'</pai>');unset($m);$winners=$this->ronCandidates($seat,$tile,true,true);if($winners){if(in_array((int)$this->s['human'],$winners,true)){$this->offerSuteChoices($seat,$tile,true,false,false,false,true);return;}$this->applyRonMany($winners,$seat,$tile,true);return;}$this->beginTurn($seat,true);return;}unset($m);
    }

    private function applyTsumo(int $seat):void
    {
        $tile=$this->s['drawn'][$seat];if($tile===null)return;$res=$this->winResult($seat,(int)$tile,true);if($res===null)return;$before=array_values(array_pad(array_map('intval',$this->s['scores']),4,0));$yaku=[0,0,0,0];$kyo=[0,0,0,0];$fu=[0,0,0,0];$pay=ScoreMath::payments((int)$this->s['taku'],(int)$res['rank'],(int)$res['fu'],$seat===$this->oya(),true);$gain=0;
        for($s=0;$s<(int)$this->s['seats'];$s++){if($s===$seat)continue;$amt=$seat===$this->oya()?$pay['ko']:($s===$this->oya()?$pay['oya']:$pay['ko']);$yaku[$s]=-(int)$amt;$fu[$s]=-100*(int)$this->s['honba'];$gain+=(int)$amt;}
        $yaku[$seat]=$gain;$fu[$seat]=100*(int)$this->s['honba']*((int)$this->s['seats']-1);$kyo[$seat]=1000*(int)$this->s['kyotaku'];for($i=0;$i<4;$i++)$this->s['scores'][$i]=$before[$i]+$yaku[$i]+$kyo[$i]+$fu[$i];$this->s['kyotaku']=0;
        $inner='<pindex>'.$seat.'</pindex><dora_open>'.(int)$this->s['dora_open'].'</dora_open>'.$this->ints('dora',$this->pais($this->s['dora_ind'])).$this->ints('ura_dora',$this->pais($this->s['ura_ind'])).ResultXml::yaku('yaku',$res,(int)$tile,$this->s['hands'][$seat]).ResultXml::calcScores($before,$yaku,$kyo,$fu);$this->cell(self::K_TSUMOAGARI,$inner);$this->finishKyoku([$seat],false);
    }

    private function applyRon(int $seat,int $from,int $tile,bool $chankan=false):void
    {$this->applyRonMany([$seat],$from,$tile,$chankan);}

    /** @param list<int> $winners */
    private function applyRonMany(array $winners,int $from,int $tile,bool $chankan=false):void
    {
        $results=[];$valid=[];foreach($winners as $seat){$seat=(int)$seat;$res=$this->winResult($seat,$tile,false,$chankan);if($res===null)continue;$results[$seat]=$res;$valid[]=$seat;}if(!$valid)return;
        $before=array_values(array_pad(array_map('intval',$this->s['scores']),4,0));$yaku=[0,0,0,0];$kyo=[0,0,0,0];$fu=[0,0,0,0];$first=true;
        foreach($valid as $seat){$res=$results[$seat];$pay=ScoreMath::payments((int)$this->s['taku'],(int)$res['rank'],(int)$res['fu'],$seat===$this->oya(),false);$total=(int)$pay['total'];$yaku[$seat]+=$total;$yaku[$from]-=$total;$fu[$seat]+=300*(int)$this->s['honba'];$fu[$from]-=300*(int)$this->s['honba'];if($first){$kyo[$seat]+=1000*(int)$this->s['kyotaku'];$first=false;}}
        for($i=0;$i<4;$i++)$this->s['scores'][$i]=$before[$i]+$yaku[$i]+$kyo[$i]+$fu[$i];$this->s['kyotaku']=0;
        $inner='<furikomi_pindex>'.$from.'</furikomi_pindex>'.$this->ints('ron_flg',array_map(fn($i)=>in_array($i,$valid,true)?1:0,range(0,3))).'<dora_open>'.(int)$this->s['dora_open'].'</dora_open>'.$this->ints('dora',$this->pais($this->s['dora_ind'])).$this->ints('ura_dora',$this->pais($this->s['ura_ind']));
        for($i=0;$i<4;$i++){if(isset($results[$i])){$hand=$this->s['hands'][$i];$hand[]=$tile;sort($hand);$inner.=ResultXml::yaku('yaku'.$i,$results[$i],$tile,$hand);}else$inner.=ResultXml::yaku('yaku'.$i,null,$tile,array_fill(0,13,0));}
        $inner.=ResultXml::calcScores($before,$yaku,$kyo,$fu);$this->cell(self::K_RON,$inner);$this->finishKyoku($valid,false);
    }

    /** @return array<string,mixed>|null */
    private function winResult(int $seat,int $tile,bool $tsumo,bool $chankan=false):?array
    {
        $hand=$this->s['hands'][$seat];if(!$tsumo)$hand[]=$tile;if(count($hand)%3!==2)return null;if(!$tsumo&&(!empty($this->s['furiten'][$seat])||!empty($this->s['temp_furiten'][$seat])))return null;
        $base=HandEvaluator::evaluate($hand,$this->s['melds'][$seat],$tile,$tsumo,$this->seatWind($seat),$this->ba(),!empty($this->s['riichi'][$seat]),!empty($this->s['double_riichi'][$seat]),!empty($this->s['ippatsu'][$seat]),array_slice($this->s['dora_ind'],0,(int)$this->s['dora_open']),array_slice($this->s['ura_ind'],0,(int)$this->s['dora_open']),(int)$this->s['taku']);if($base===null)return null;
        $haitei=$tsumo&&empty($this->s['wall'])&&!$this->s['last_draw_rinshan'];$houtei=!$tsumo&&empty($this->s['wall']);$tenho=$tsumo&&$seat===$this->oya()&&(int)$this->s['discard_count']===0&&!$this->s['any_call'];$chiho=$tsumo&&$seat!==$this->oya()&&count($this->s['discards'][$seat])===0&&!$this->s['any_call']&&(int)$this->s['discard_count']<(int)$this->s['seats'];$extra=SituationalYaku::evaluate($haitei,$houtei,$tsumo&&!empty($this->s['last_draw_rinshan']),$chankan,$tenho,$chiho);return SituationalYaku::merge($base,$extra);
    }

    /** @param list<int> $winners */
    private function finishKyoku(array $winners,bool $draw,array $tenpai=[],bool $abortive=false):void
    {$next=RoundSettlement::nextState($this->oya(),$winners,$tenpai,$draw,$abortive,(int)$this->s['honba'],(int)$this->s['kyoku_index'],Mahjong::KYOKU_COUNT[(int)$this->s['taku']],$this->s['scores'],(int)$this->s['seats']);$this->s['honba']=$next['honba'];$this->s['advance_kyoku']=$next['advance'];$this->scoreRankCell();$this->cell(self::K_KYOKUEND,'<end_stat>'.($next['game_over']?1:0).'</end_stat>');$this->s['pending_choices']=null;$this->s['call_ctx']=null;if($next['game_over']){$this->s['state']='game_end';$this->s['finished']=true;}else$this->s['state']='kyoku_end';}

    private function ryuukyoku(bool $abortive=false):void
    {$before=array_values(array_pad(array_map('intval',$this->s['scores']),4,0));if($abortive){$status=['tenpai'=>[],'waits'=>[]];$settled=['scores'=>$before,'deltas'=>[0,0,0,0]];}else{$status=RoundSettlement::tenpaiStatus($this->s['hands'],$this->s['melds'],(int)$this->s['seats'],(int)$this->s['taku']);$settled=RoundSettlement::applyExhaustiveDraw($before,(int)$this->s['seats'],$status['tenpai']);}$this->s['scores']=$settled['scores'];$inner='<reason>'.($abortive?1:0).'</reason>';for($i=0;$i<4;$i++){$is=in_array($i,$status['tenpai'],true);$waits=$is?($status['waits'][$i]??[]):[0];$inner.='<ryukyoku_status'.$i.'><end_stat>'.($is?1:0).'</end_stat>'.$this->ints('machi_pai',$this->pais($waits)).'</ryukyoku_status'.$i.'>';}$inner.=ResultXml::calcScores($before,$settled['deltas'],[0,0,0,0],[0,0,0,0]);$this->cell(self::K_RYUKYOKU,$inner);$this->finishKyoku([],true,$status['tenpai'],$abortive);}

    /** @return list<array{0:int,1:list<int>}> */
    private function tenpaiPatterns(int $seat):array
    {$hand=$this->s['hands'][$seat];$opened=count($this->s['melds'][$seat]);$out=[];$seen=[];foreach($hand as $tile){$tile=(int)$tile;if(isset($seen[$tile]))continue;$seen[$tile]=true;$rest=$hand;$k=array_search($tile,$rest,true);if($k===false)continue;unset($rest[$k]);$rest=array_values($rest);$c=Mahjong::countsOf($rest);if(Mahjong::shanten($c,$opened,(int)$this->s['taku'])!==0)continue;$waits=Mahjong::waitsOf($c,$opened,(int)$this->s['taku']);if($waits)$out[]=[$tile,array_values($waits)];}return $out;}

    /** @return list<int> */
    private function visibleCounts(int $seat):array
    {$c=array_fill(0,34,0);foreach($this->s['hands'][$seat] as $t)$c[(int)$t]++;for($s=0;$s<(int)$this->s['seats'];$s++){foreach($this->s['discards'][$s] as $t)$c[(int)$t]++;foreach($this->s['melds'][$s] as $m)foreach(($m['tiles']??[]) as $t)$c[(int)$t]++;}foreach(array_slice($this->s['dora_ind'],0,(int)$this->s['dora_open']) as $t)$c[(int)$t]++;return array_map(fn($n)=>min(4,(int)$n),$c);}

    /** @return list<array{0:int,1:int}> */
    private function ankanOptions(int $seat):array
    {
        if(empty($this->s['wall'])||(int)$this->s['kan_count']>=4)return [];$c=Mahjong::countsOf($this->s['hands'][$seat]);$opened=count($this->s['melds'][$seat]);$out=[];
        for($t=0;$t<34;$t++){if(($c[$t]??0)!==4)continue;if(!empty($this->s['riichi'][$seat])){if(($this->s['drawn'][$seat]??null)!==$t)continue;$before=$this->s['hands'][$seat];$k=array_search($t,$before,true);if($k!==false)unset($before[$k]);$before=array_values($before);$beforeWaits=Mahjong::waitsOf(Mahjong::countsOf($before),$opened,(int)$this->s['taku']);sort($beforeWaits);$after=array_values(array_filter($this->s['hands'][$seat],fn($x)=>(int)$x!==$t));$afterWaits=Mahjong::waitsOf(Mahjong::countsOf($after),$opened+1,(int)$this->s['taku']);sort($afterWaits);if(!$afterWaits||$beforeWaits!==$afterWaits)continue;}$out[]=[$t,1];}
        if(empty($this->s['riichi'][$seat]))foreach($this->s['melds'][$seat] as $m){$tile=(int)($m['tiles'][0]??-1);if(($m['kind']??'')==='pon'&&$tile>=0&&($c[$tile]??0)>=1)$out[]=[$tile,3];}return $out;
    }

    private function cpuDiscardAfterCall(int $seat):void{[$tile,]=CpuAi::chooseDiscard($this->s,$seat);$this->discard($seat,$tile,false,false);}
    private function breakIppatsu():void{for($s=0;$s<(int)$this->s['seats'];$s++)$this->s['ippatsu'][$s]=false;}
    private function countTile(int $seat,int $tile):int{return array_count_values($this->s['hands'][$seat])[$tile]??0;}
    private function removeTile(int $seat,int $tile):void{$k=array_search($tile,$this->s['hands'][$seat],true);if($k!==false){unset($this->s['hands'][$seat][$k]);$this->s['hands'][$seat]=array_values($this->s['hands'][$seat]);sort($this->s['hands'][$seat]);}}

    /** @return list<list<int>> */
    private function chiOptions(int $seat,int $tile):array
    {if(Mahjong::isHonor($tile))return [];$c=Mahjong::countsOf($this->s['hands'][$seat]);$n=$tile%9;$out=[];if($n>=2&&($c[$tile-2]??0)&&($c[$tile-1]??0))$out[]=[$tile-2,$tile-1];if($n>=1&&$n<=7&&($c[$tile-1]??0)&&($c[$tile+1]??0))$out[]=[$tile-1,$tile+1];if($n<=6&&($c[$tile+1]??0)&&($c[$tile+2]??0))$out[]=[$tile+1,$tile+2];return $out;}
    private function updateFuriten(int $seat):void{$c=Mahjong::countsOf($this->s['hands'][$seat]);if(array_sum($c)%3!==1)return;$w=Mahjong::waitsOf($c,count($this->s['melds'][$seat]),(int)$this->s['taku']);$this->s['furiten'][$seat]=count(array_intersect($w,$this->s['discards'][$seat]))>0;}
    private function canReach(int $seat):bool{return !empty($this->tenpaiPatterns($seat));}
    private function kyuushuOk(int $seat):bool{if(!empty($this->s['any_call'])||!empty($this->s['discards'][$seat])||(int)$this->s['discard_count']>=(int)$this->s['seats'])return false;$kinds=[];foreach($this->s['hands'][$seat] as $tile)if(Mahjong::isYaochu((int)$tile))$kinds[(int)$tile]=true;return count($kinds)>=9;}
    private function scoreRankCell():void{$r=$this->ranks();$inner='<kyoutaku>'.(int)$this->s['kyotaku'].'</kyoutaku>';for($i=0;$i<4;$i++)$inner.='<riti_after'.$i.'><score>'.(int)$this->s['scores'][$i].'</score><rank>'.$r[$i].'</rank></riti_after'.$i.'>';$this->cell(self::K_SCORERANK,$inner);}
    /** @param list<int>|null $pis */
    private function cell(int $kind,string $inner,?array $pis=null):void{$seq=count($this->s['cells']);$targets=$pis??range(0,(int)$this->s['seats']-1);$flags='';for($i=0;$i<4;$i++)$flags.=' pi'.$i.'="'.(in_array($i,$targets,true)?1:0).'"';$this->s['cells'][]='<cell_data_'.$seq.' kind="'.$kind.'"'.$flags.'>'.$inner.'</cell_data_'.$seq.'>';}
    /** @return list<int> */
    private function ranks():array{$seats=(int)$this->s['seats'];$order=range(0,$seats-1);usort($order,fn($a,$b)=>($this->s['scores'][$b]<=>$this->s['scores'][$a])?:($a<=>$b));$r=[0,1,2,3];foreach($order as $rank=>$seat)$r[$seat]=$rank;return $r;}
    private function nextSeat(int $seat):int{return ($seat+1)%(int)$this->s['seats'];}
    private function oya():int{return (int)$this->s['kyoku_index']%(int)$this->s['seats'];}
    private function seatWind(int $seat):int{return ($seat-$this->oya()+(int)$this->s['seats'])%(int)$this->s['seats'];}
    private function ba():int{return (int)$this->s['kyoku_index']<(int)$this->s['seats']?0:1;}
    private function kyoku():int{return (int)$this->s['kyoku_index']%(int)$this->s['seats'];}
    private function isAllLast():bool{return (int)$this->s['kyoku_index']>=Mahjong::KYOKU_COUNT[(int)$this->s['taku']]-1;}
    private function isPastEnd():bool{return (int)$this->s['kyoku_index']>=Mahjong::KYOKU_COUNT[(int)$this->s['taku']];}
    /** @param list<int> $v */
    private function pais(array $v):array{return array_map([Mahjong::class,'idxToPai'],$v);}
    /** @param list<int> $v */
    private function ints(string $tag,array $v):string{$v=$v?:[0];return '<'.$tag.' __count="'.count($v).'">'.implode(' ',array_map('intval',$v)).'</'.$tag.'>';}
}
