<?php

declare(strict_types=1);

namespace Mfg\Aog;

use Mfg\Storage\Database;

final class FeatureDispatcher
{
    private const DOJO_SLOTS = 4;
    private const DOJO_STOCK_MAX = 3;
    private const DOJO_LESSON_SECONDS = 300;

    private const GACHA_SERIES = [
        [0,'Normal',0,'Normal'],[1,'NormalTicket',1,'Normal'],[25,'UnlockClear',0,'Unlock'],
        [44,'UnlockIyo',0,'Unlock'],[56,'UnlockGrimAroe',0,'Unlock'],[63,'UnlockCocoa',0,'Unlock'],
        [74,'UnlockDia',0,'Unlock'],[101,'UnlockDoubriel',0,'Unlock'],[125,'UnlockIppatsu',0,'Unlock'],
        [135,'UnlockShiroe',0,'Unlock'],[91,'MusicHiyori',0,'Music'],[92,'MusicSen',0,'Music'],
        [107,'MusicYao',0,'Music'],[114,'MusicTenshi',0,'Music'],[132,'MusicMusashi',0,'Music'],
        [133,'LimitedCharaReturns',0,'Limited'],[124,'PickupIppatsu',0,'Pickup'],
        [128,'PickupMizugiReturns4',0,'Pickup'],[129,'PickupUniformDia',0,'Pickup'],
        [130,'PickupYukataToytoy2',0,'Pickup'],[131,'PickupUniformDoubriel',0,'Pickup'],
        [134,'PickupShiroe',0,'Pickup'],[136,'PickupXmasGrimAroe',0,'Pickup'],
        [137,'PickupMarchingIchiko',0,'Pickup'],[138,'PickupKimonoClear',0,'Pickup'],
        [139,'PickupBomberPine2',0,'Pickup'],[140,'PickupLillyIppatsu',0,'Pickup'],
    ];

    private const SAFE_POOL = [
        'OID_01NakiN01','OID_02NakiN01','OID_03NakiN01','OID_04NakiN01',
        'OID_01AgariR01','OID_02AgariR01','OID_03ReachR01','OID_04ReachR01',
        'OID_01AgariSR01','OID_02AgariSR01','OID_03AgariSR01','OID_04AgariSR01',
        'OID_01AgariUR01','OID_02AgariUR01','OID_03AgariUR01','OID_04AgariUR01',
    ];

    private const MUSIC_POOL = [
        91=>['OID_ReachBgm148','OID_ReachBgm149','OID_ReachBgm150','OID_ReachBgm151'],
        92=>['OID_ReachBgm152','OID_ReachBgm153','OID_ReachBgm154','OID_ReachBgm155'],
        107=>['OID_ReachBgm160','OID_ReachBgm161','OID_ReachBgm162','OID_ReachBgm163'],
        114=>['OID_ReachBgm164','OID_ReachBgm165','OID_ReachBgm166','OID_ReachBgm167'],
        132=>['OID_ReachBgm172','OID_ReachBgm173','OID_ReachBgm174','OID_ReachBgm175'],
    ];

    private const UNLOCKS = [
        25=>'OID_12AgariSR01',44=>'OID_13AgariSR01',56=>'OID_14AgariSR01',63=>'OID_15AgariSR01',
        74=>'OID_16AgariSR01',101=>'OID_17AgariSR01',125=>'OID_18AgariSR01',135=>'OID_19AgariSR01',
    ];

    private const PICKUP_CHARA = [
        124=>'Chara18',129=>'Chara16',131=>'Chara17',134=>'Chara19',136=>'Chara14',137=>'Chara01',138=>'Chara12',139=>'Chara01',140=>'Chara18',
    ];

    public function __construct(private Database $db) {}

    public function dispatch(string $name,array $form): ?string
    {
        return match($name) {
            'dojo_get_status'=>$this->dojoGetStatus($form),
            'dojo_set_slot'=>$this->dojoSetSlot($form),
            'dojo_gain_soul'=>$this->dojoGainSoul($form),
            'gacha_info'=>$this->gachaInfo(),
            'req_draw_gacha'=>$this->reqDrawGacha($form),
            'get_gacha_result'=>$this->getGachaResult($form),
            'gacha_log'=>$this->xml(),
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
        foreach(self::GACHA_SERIES as [$id,$label,$ticket,$stype]) {
            $pool=self::MUSIC_POOL[$id]??self::SAFE_POOL;
            $body.='<info><id>'.$id.'</id><label>'.$this->x($label).'</label><ticket_nr>'.$ticket.'</ticket_nr><now_active>1</now_active><series_type>'.$this->x($stype).'</series_type><items>';
            foreach($pool as $oid)$body.='<item>'.$this->x($oid).'</item>';
            $body.='</items><pickup_charas>';
            if(isset(self::PICKUP_CHARA[$id]))$body.='<chara>'.$this->x(self::PICKUP_CHARA[$id]).'</chara>';
            $body.='</pickup_charas><custom_pickup_items>';
            if(isset(self::UNLOCKS[$id]))$body.='<item>'.$this->x(self::UNLOCKS[$id]).'</item>';
            $body.='</custom_pickup_items></info>';
        }
        return $this->xml($body.'</gacha_schedule>');
    }

    private function reqDrawGacha(array $form): string
    {
        $pcuid=(string)($form['pcuid']??'GUEST');$series=(int)($form['series_id']??$form['gacha_id']??0);$count=max(1,min(10,(int)($form['count']??1)));$pool=self::MUSIC_POOL[$series]??self::SAFE_POOL;$draw=[];
        for($i=0;$i<$count;$i++)$draw[]=$pool[random_int(0,count($pool)-1)];
        if(!isset(self::MUSIC_POOL[$series])&&isset(self::UNLOCKS[$series])&&random_int(1,100)<=10)$draw[0]=self::UNLOCKS[$series];
        $token=bin2hex(random_bytes(8));$this->db->setKv('gacha',$pcuid,['token'=>$token,'series'=>$series,'items'=>$draw,'time'=>time()]);
        return $this->xml('<gacha><result>0</result><reserve_id>'.$token.'</reserve_id></gacha>');
    }

    private function getGachaResult(array $form): string
    {
        $pcuid=(string)($form['pcuid']??'GUEST');$row=$this->db->getKv('gacha',$pcuid,['items'=>[]]);$items=is_array($row['items']??null)?$row['items']:[];$body='<gacha_result><result>0</result><items>';
        foreach($items as $oid)$body.='<item>'.$this->x((string)$oid).'</item>';
        return $this->xml($body.'</items></gacha_result>');
    }

    private function musicReserve(array $form): string
    {
        $pcuid=(string)($form['pcuid']??'GUEST');$series=(int)($form['series_id']??91);$this->db->setKv('music_gacha',$pcuid,['reserved'=>true,'series'=>$series,'time'=>time()]);return $this->xml('<music_gacha><result>0</result></music_gacha>');
    }
    private function musicPlay(array $form): string
    {
        $pcuid=(string)($form['pcuid']??'GUEST');$row=$this->db->getKv('music_gacha',$pcuid,['series'=>91]);$series=(int)($row['series']??91);$pool=self::MUSIC_POOL[$series]??self::MUSIC_POOL[91];$oid=$pool[random_int(0,count($pool)-1)];$this->db->deleteKv('music_gacha',$pcuid);return $this->xml('<music_gacha><result>0</result><gain_items><item>'.$this->x($oid).'</item></gain_items></music_gacha>');
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
    private function xml(string $inner=''): string{return '<?xml version="1.0" encoding="UTF-8"?><response>'.$inner.'</response>';}
    private function x(string $s): string{return htmlspecialchars($s,ENT_QUOTES|ENT_XML1,'UTF-8');}
}
