<?php

declare(strict_types=1);

namespace Mfg\Aog;

use Mfg\Storage\Database;

final class MiscDispatcher
{
    private const CPU_STAMP_REPLIES = [
        'TableSticker001','TableSticker002','TableSticker003','TableSticker004',
    ];

    public function __construct(private Database $db) {}

    public function dispatch(string $name,array $form): ?string
    {
        return match($name) {
            'gchat' => $this->gchat($form),
            'gget_stamp_info' => $this->ggetStampInfo($form),
            'player_record','get_record' => $this->xml('<player_record></player_record>'),
            'get_haifu_list' => $this->xml('<haifu_list></haifu_list>'),
            'get_haifu_data' => $this->xml(),
            'get_jongstone_info' => $this->xml('<jongstone_info><free_point>0</free_point><record_point>0</record_point></jongstone_info>'),
            'get_mg' => $this->xml('<mg_info><mg>0</mg><additional_mg>0</additional_mg></mg_info>'),
            'mission_date' => $this->xml($this->infoData('missions','{"list":[]}')),
            'present_done' => $this->presentDone($form),
            'competition_entry' => $this->xml('<competition><entry_result>1</entry_result></competition>'),
            'chk_tabooword' => $this->xml('<taboo_chk><result>0</result></taboo_chk>'),
            'item_gain_log','item_consume_log','notice_done','important_notice_done',
            'set_favorite_character','odekake_done','coop_done','eashop_done' => $this->xml(),
            'reconnect' => $this->reconnect($form),
            default => null,
        };
    }

    public function stampXml(string $tag,int $tid,int $since=0): string
    {
        return (new StampStore($this->db))->xml($tag,$tid,$since);
    }

    private function reconnect(array $form): string
    {
        $pcuid=(string)($form['pcuid']??'GUEST');
        $m=$this->db->getMatch($pcuid);
        if(!$m) return $this->xml('<entry><gserv_id>1</gserv_id><tid>1</tid><pindex>0</pindex><next_sno>0</next_sno><gmode>1</gmode></entry>');
        return $this->xml('<entry><gserv_id>1</gserv_id><tid>'.(int)($m['tid']??1).'</tid><pindex>'.(int)($m['pindex']??0).'</pindex><next_sno>'.(int)($m['next_sno']??0).'</next_sno><last_cyoukou_num>3</last_cyoukou_num><cyoukou_num>3</cyoukou_num><ste_oya1_limit_time>15000</ste_oya1_limit_time><ste_limit_time>10000</ste_limit_time><ste_reechi1_limit_time>15000</ste_reechi1_limit_time><naki_limit_time>8000</naki_limit_time><agari_limit_time>10000</agari_limit_time><naki_choice_limit_time>8000</naki_choice_limit_time><reechi_choice_limit_time>8000</reechi_choice_limit_time><last_cyoukou_limit_time>30000</last_cyoukou_limit_time><last_time>30000</last_time><gserv_url>/aog/</gserv_url><pay_mode>0</pay_mode><gmode>'.(int)($m['gmode']??1).'</gmode></entry>');
    }

    private function gchat(array $form): string
    {
        $tid=max(1,(int)($form['tid']??1));
        $contents=(string)($form['contents']??'');
        if($contents!=='') {
            $pindex=(int)($form['pindex']??0);
            (new StampStore($this->db))->post($tid,(int)($form['mid']??0),$pindex,(string)($form['name']??''),$contents,(string)($form['param']??''));
            $this->maybeCpuStamp($tid,$pindex,$form);
        }
        return $this->xml($this->stampXml('chat',$tid,0));
    }

    private function ggetStampInfo(array $form): string
    {
        $must=$this->must($form);$tid=(int)($must[2]??$form['tid']??1);$pindex=(int)($must[3]??$form['pindex']??0);$mid=(int)($must[4]??$form['mid']??0);
        $info=explode(',',(string)($form['stamp_info']??''));$since=(isset($info[1])&&$info[1]!=='')?(int)$info[1]:0;
        if(isset($info[2])&&$info[2]!==''){
            (new StampStore($this->db))->post($tid,$mid,$pindex,(string)($info[3]??''),(string)$info[2],'');
            $this->maybeCpuStamp($tid,$pindex,$form);
        }
        return $this->xml($this->stampXml('stamp_info',$tid,$since));
    }

    /** @param array<string,mixed> $form */
    private function maybeCpuStamp(int $tid,int $humanPindex,array $form): void
    {
        // Python reference: random.random() > 0.55 returns, so CPUs answer 55%
        // of the time. If no match can be resolved it also defaults to yonma.
        if(random_int(1,100)>55)return;
        $seats=4;$pcuid=(string)($form['pcuid']??'');
        if($pcuid!==''){
            $match=$this->db->getMatch($pcuid);
            if(is_array($match))$seats=max(1,(int)($match['seats']??4));
        }
        if($seats<2)return;
        $seat=($humanPindex+random_int(1,$seats-1))%$seats;
        $reply=self::CPU_STAMP_REPLIES[random_int(0,count(self::CPU_STAMP_REPLIES)-1)];
        (new StampStore($this->db))->post($tid,0,$seat,'CPU',$reply,'');
    }

    private function presentDone(array $form): string
    {
        $ids=array_filter(array_map('trim',explode(',',(string)($form['done_ids']??''))),fn($v)=>$v!=='');$body='<present>';
        foreach($ids as $id)$body.='<data><id>'.$this->x($id).'</id><success>1</success><content></content><amount>0</amount></data>';
        return $this->xml($body.'</present>');
    }

    /** @return list<string> */
    private function must(array $form):array {$raw=(string)($form['must']??'');if($raw==='')return [];$parts=preg_split('#[/,]#',$raw);return array_map('trim',$parts===false?[]:$parts);}
    private function infoData(string $kind,string $payload):string {return '<info_data kind="'.$this->x($kind).'">'.base64_encode($payload).'</info_data>';}
    private function xml(string $inner=''):string {return '<?xml version="1.0" encoding="UTF-8"?><root><serv_st><code>0</code></serv_st>'.$inner.'</root>';}
    private function x(string $s):string {return htmlspecialchars($s,ENT_QUOTES|ENT_XML1,'UTF-8');}
}
