<?php

declare(strict_types=1);

namespace Mfg\Aog;

use Mfg\Storage\Database;

final class StampStore
{
    public function __construct(private Database $db) {}

    public function post(int $tid,int $mid,int $pindex,string $name,string $contents,string $param=''): void
    {
        if($contents==='')return;
        $seq=(int)$this->db->getKv('stamp_meta','seq',0)+1;
        $this->db->setKv('stamp_meta','seq',$seq);
        $rows=$this->db->getKv('stamps',(string)$tid,[]);if(!is_array($rows))$rows=[];
        $rows[]=[
            'idx'=>$seq,
            'mid'=>$mid,
            'pindex'=>$pindex,
            'time'=>(int)floor(microtime(true)*1000),
            'name'=>$name,
            'contents'=>$contents,
            'param'=>$param,
        ];
        if(count($rows)>40)$rows=array_slice($rows,-40);
        $this->db->setKv('stamps',(string)$tid,$rows);
    }

    public function xml(string $tag,int $tid,int $since=0): string
    {
        $rows=$this->db->getKv('stamps',(string)$tid,[]);if(!is_array($rows))$rows=[];
        $body='';
        foreach($rows as $e){
            if((int)($e['idx']??0)<=$since)continue;
            $body.='<d idx="'.(int)($e['idx']??0).'" mid="'.(int)($e['mid']??0).'" pindex="'.(int)($e['pindex']??0).'" time="'.(int)($e['time']??0).'">'
                .'<name>'.$this->x((string)($e['name']??'')).'</name>'
                .'<contents>'.$this->x((string)($e['contents']??'')).'</contents>'
                .'<param>'.$this->x((string)($e['param']??'')).'</param></d>';
        }
        return '<'.$tag.'>'.$body.'</'.$tag.'>';
    }

    private function x(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_XML1,'UTF-8');}
}
