<?php

declare(strict_types=1);

namespace Mfg\Aog;

use Mfg\Storage\Database;

final class FeatureDispatcher
{
    private const DOJO_SLOTS = 4;
    private const DOJO_STOCK_MAX = 3;
    private const DOJO_LESSON_SECONDS = 300;

    public function __construct(private Database $db) {}

    public function dispatch(string $name,array $form): ?string
    {
        $misc=(new MiscDispatcher($this->db))->dispatch($name,$form);
        if($misc!==null)return $misc;
        return match($name) {
            'dojo_get_status'=>$this->dojoGetStatus($form),
            'dojo_set_slot'=>$this->dojoSetSlot($form),
            'dojo_gain_soul'=>$this->dojoGainSoul($form),
            'gacha_info'=>$this->gachaInfo(),
            'req_draw_gacha'=>$this->reqDrawGacha($form),
            'get_gacha_result'=>$this->getGachaResult($form),
            'gacha_log'=>$this->logOnly($form,'gacha'),
            'music_gacha_play_reserve'=>$this->musicReserve($form),
            'music_gacha_play'=>$this->musicPlay($form),
            default=>null,
        };
    }

    private function dojoGetStatus(array $form): string
    {
        [$refid,$p]=$this->profile($form);$payload=$p['payload'];$slots=$this->dojoSlots($payload);
        foreach($slots as &$slot)$this->refreshSlot($slot);unset($slot);
        $payload['dojo']=$slots;$this->db->saveProfilePayload($refid,$payload);
        $body='';foreach($slots as $i=>$slot)$body.=$this->dojoSlotXml($i,$slot);
        return $this->xml('<dojo><slot_nr>'.self::DOJO_SLOTS.'</slot_nr>'.$body.'</dojo>');
    }

    private function dojoSetSlot(array $form): string
    {
        [$refid,$p]=$this->profile($form);$payload=$p['payload'];$slots=$this->dojoSlots($payload);
        $id=max(0,min(self::DOJO_SLOTS-1,(int)($form['slot_id']??0)));$chara=(string)($form['set_character']??'OID_CHARACTER_1');$now=time();
        $slots[$id]=['available'=>true,'chara'=>$chara,'start'=>$now,'next'=>$now+self::DOJO_LESSON_SECONDS,'stock'=>0];
        $payload['dojo']=$slots;$this->db->saveProfilePayload($refid,$payload);
        return $this->xml('<dojo><slot_id>'.$id.'</slot_id><updated>1</updated>'.$this->dojoSlotXml($id,$slots[$id]).'</dojo>');
    }

    private function dojoGainSoul(array $form): string
    {
        [$refid,$p]=$this->profile($form);$payload=$p['payload'];$slots=$this->dojoSlots($payload);
        $id=max(0,min(self::DOJO_SLOTS-1,(int)($form['slot_id']??0)));$this->refreshSlot($slots[$id]);$got=(int)$slots[$id]['stock'];$now=time();
        $slots[$id]['stock']=0;$slots[$id]['start']=$now;$slots[$id]['next']=$now+self::DOJO_LESSON_SECONDS;
        $payload['dojo']=$slots;$this->db->saveProfilePayload($refid,$payload);
        return $this->xml('<dojo><slot_id>'.$id.'</slot_id><get_nr>'.$got.'</get_nr>'.$this->dojoSlotXml($id,$slots[$id]).'</dojo>');
    }

    private function gachaInfo(): string
    {
        $body='<gacha_schedule>';
        foreach(GachaPools::advertisedSeries() as $sid=>$entry) {
            $id=(int)$sid;$label=(string)($entry['name']??('Series'.$id));$stype=(string)($entry['type']??'Normal');$ticket=$id===1?1:0;
            $body.='<info><id>'.$id.'</id><label>'.$this->x($label).'</label><ticket_nr>'.$ticket.'</ticket_nr><now_active>1</now_active><series_type>'.$this->x($stype).'</series_type><items>';
            foreach(GachaPools::poolForSeries($id) as $oid)$body.='<item>'.$this->x($oid).'</item>';
            $body.='</items><pickup_charas>';
            foreach(GachaPools::pickupCharas($id) as $chara)$body.='<chara>'.$this->x($chara).'</chara>';
            $body.='</pickup_charas><custom_pickup_items>';
            foreach(GachaPools::customPickupItems($id) as $oid)$body.='<item>'.$this->x($oid).'</item>';
            $body.='</custom_pickup_items><exchange_items></exchange_items><start_date>2020/01/01 00:00:00</start_date><end_date>2099/12/31 23:59:59</end_date></info>';
        }
        return $this->xml($body.'</gacha_schedule>');
    }

    private function reqDrawGacha(array $form): string
    {
        $pcuid=(string)($form['pcuid']??'GUEST');
        $times=(int)($form['times']??1);if($times===0)$times=1;
        $token=bin2hex(random_bytes(8));
        $this->db->setKv('gacha',$pcuid,[
            'id'=>$token,
            'gacha'=>(string)($form['gacha_name']??''),
            'times'=>$times,
            'time'=>time(),
        ]);
        return $this->xml('<transaction_info><transaction_id>'.$token.'</transaction_id></transaction_info>');
    }

    private function getGachaResult(array $form): string
    {
        $times=(int)($form['times']??1);if($times===0)$times=1;$count=max(1,$times);
        $body='<lottery_result>';
        for($i=0;$i<$count;$i++){
            $body.='<data><character_id>0</character_id><unique_id>'.substr(bin2hex(random_bytes(8)),0,12).'</unique_id></data>';
        }
        return $this->xml($body.'</lottery_result><gift><acquired>0</acquired><prev>0</prev><after>0</after></gift>');
    }

    private function musicReserve(array $form): string
    {
        $series=(int)($form['gacha_id']??$form['series_id']??0);
        $last=(int)$this->db->getKv('music_gacha_meta','seq',1000);
        $req=max(1001,$last+1);
        $this->db->setKv('music_gacha_meta','seq',$req);
        $this->db->setKv('music_gacha',(string)$req,['series'=>$series,'time'=>time()]);
        return $this->xml('<gacha_reserve><is_success>1</is_success><request_id>'.$req.'</request_id></gacha_reserve>');
    }

    private function musicPlay(array $form): string
    {
        $req=(int)($form['request_id']??0);
        $row=$req>0?$this->db->getKv('music_gacha',(string)$req,null):null;
        $series=is_array($row)?(int)($row['series']??0):0;
        if($req>0)$this->db->deleteKv('music_gacha',(string)$req);

        $entry=GachaPools::seriesEntry($series);
        $pool=(($entry['type']??'')==='Music')?GachaPools::poolForSeries($series):[];
        if(!$pool)$pool=GachaPools::poolForSeries(91);
        if(!$pool)$pool=['OID_ReachBgm148'];
        $oid=$pool[random_int(0,count($pool)-1)];
        error_log('[MFG][music-gacha] series='.$series.' request='.$req.' -> '.$oid);
        return $this->xml('<gacha_result><is_success>1</is_success><gain_items><item>'.$this->x($oid).'</item></gain_items><gift>2</gift><fight_spirits></fight_spirits></gacha_result>');
    }

    /** @param array<string,mixed> $form */
    private function logOnly(array $form,string $label): string
    {
        TelemetryLog::write($form,$label);
        return $this->xml();
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function profile(array $form): array
    {
        $pcuid=(string)($form['pcuid']??'');$s=$pcuid!==''?$this->db->getSession($pcuid):null;$refid=(string)($s['refid']??'GUEST');return [$refid,$this->db->ensureProfile($refid,$refid==='GUEST'?'GUEST':'PLAYER')];
    }
    /** @param array<string,mixed> $payload @return array<int,array<string,mixed>> */
    private function dojoSlots(array $payload): array
    {
        $slots=is_array($payload['dojo']??null)?$payload['dojo']:[];while(count($slots)<self::DOJO_SLOTS)$slots[]=['available'=>false,'chara'=>'','start'=>0,'next'=>0,'stock'=>0];return array_slice($slots,0,self::DOJO_SLOTS);
    }
    /** @param array<string,mixed> $slot */
    private function refreshSlot(array &$slot): void
    {
        if(empty($slot['available']))return;$now=time();$next=(int)($slot['next']??0);$stock=(int)($slot['stock']??0);while($stock<self::DOJO_STOCK_MAX&&$next>0&&$now>=$next){$stock++;$next+=self::DOJO_LESSON_SECONDS;}$slot['stock']=$stock;$slot['next']=$next;
    }
    /** @param array<string,mixed> $slot */
    private function dojoSlotXml(int $i,array $slot): string
    {
        if(empty($slot['available']))return '<slot idx="'.$i.'"><available>0</available></slot>';$stock=(int)($slot['stock']??0);return '<slot idx="'.$i.'"><available>1</available><character_obj>'.$this->x((string)($slot['chara']??'OID_CHARACTER_1')).'</character_obj><start_time>'.(int)($slot['start']??time()).'</start_time><next_time>'.(int)($slot['next']??time()).'</next_time><has_next>'.($stock<self::DOJO_STOCK_MAX?1:0).'</has_next><reserve_souls>'.$stock.'</reserve_souls><all_souls>'.self::DOJO_STOCK_MAX.'</all_souls></slot>';
    }
    private function xml(string $inner=''): string{return '<?xml version="1.0" encoding="UTF-8"?><root><serv_st><code>0</code></serv_st>'.$inner.'</root>';}
    private function x(string $s): string{return htmlspecialchars($s,ENT_QUOTES|ENT_XML1,'UTF-8');}
}
