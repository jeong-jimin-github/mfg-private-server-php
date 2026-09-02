from pathlib import Path

p=Path('src/Aog/Dispatcher.php')
s=p.read_text(encoding='utf-8')
old="    private function endGame(array $form): string{$pcuid=(string)($form['pcuid']??'GUEST');$m=$this->db->getMatch($pcuid)??[];$m['state']='game_end';if(isset($m['table'])&&is_array($m['table'])){$m['table']['state']='game_end';$m['table']['finished']=true;}$this->db->saveMatch($pcuid,$m);return $this->xml('<mgresult><result>0</result></mgresult>');}"
new="""    private function endGame(array $form): string
    {
        $pcuid=(string)($form['pcuid']??'GUEST');$m=$this->db->getMatch($pcuid)??[];$gmode=(int)($m['gmode']??1);if(!isset(self::GMODE_TAKU[$gmode]))$gmode=1;$taku=self::GMODE_TAKU[$gmode];$seats=(int)($m['seats']??self::SEATS[$taku]);$table=is_array($m['table']??null)?$m['table']:null;
        if($table!==null){$table['state']='game_end';$table['finished']=true;$scores=array_values(array_map('intval',$table['scores']??[]));$kyoku=(int)($table['kyoku_index']??0);}else{$scores=array_fill(0,$seats,self::START_SCORE[$taku]);$kyoku=0;}
        while(count($scores)<$seats)$scores[]=self::START_SCORE[$taku];$oya=$seats>0?$kyoku%$seats:0;$order=range(0,max(0,$seats-1));usort($order,static function(int $a,int $b)use($scores,$oya,$seats):int{$cmp=$scores[$b]<=>$scores[$a];if($cmp!==0)return $cmp;$wa=($a-$oya+$seats)%$seats;$wb=($b-$oya+$seats)%$seats;return $wa<=>$wb;});$ranks=array_fill(0,4,0);foreach($order as $rank=>$seat)$ranks[$seat]=$rank;$umaTable=[4=>[20000,10000,-10000,-20000],3=>[20000,0,-20000],2=>[10000,-10000]];$uma=$umaTable[$seats]??array_fill(0,$seats,0);
        $body='<mgresult><gmode>'.$gmode.'</gmode><taku_class>1</taku_class><continue_state>0</continue_state><continue_fee>0</continue_fee>';for($i=0;$i<$seats;$i++){$rank=(int)$ranks[$i];$body.='<player_'.$i.'><rank>'.$rank.'</rank><score>'.(int)$scores[$i].'</score><uma>'.(int)($uma[$rank]??0).'</uma></player_'.$i.'>';}$body.='</mgresult>';
        $m['state']='game_end';if($table!==null)$m['table']=$table;$this->db->saveMatch($pcuid,$m);return $this->xml($body);
    }
"""
if old not in s: raise SystemExit('endGame anchor missing')
p.write_text(s.replace(old,new,1),encoding='utf-8')
