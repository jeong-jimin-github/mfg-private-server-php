<?php

declare(strict_types=1);

namespace Mfg\Aog;

use Mfg\Storage\Database;
use Mfg\Mahjong\Table;

final class Dispatcher
{
    private const GAME_MODES = [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23];
    private const GMODE_TAKU = [1=>0,2=>1,3=>2,4=>3,5=>0,6=>0,7=>2,8=>0,9=>0,10=>2,11=>0,12=>2,13=>0,14=>2,15=>0,16=>0,17=>2,18=>2,19=>2,20=>0,21=>2,22=>0,23=>2];
    private const SEATS = [0=>4,1=>4,2=>3,3=>2];
    private const START_SCORE = [0=>25000,1=>25000,2=>35000,3=>50000];

    public function __construct(private Database $db) {}

    public function dispatch(string $name, array $form): string
    {
        $feature=(new FeatureDispatcher($this->db))->dispatch($name,$form);if($feature!==null)return $feature;
        return match ($name) {
            'appli_boot' => $this->appliBoot(),
            'appli_info' => $this->appliInfo(),
            'login' => $this->login($form),
            'logout' => $this->xml(),
            'create_player' => $this->createPlayer($form),
            'get_menudata' => $this->getMenuData($form),
            'keep_alive' => $this->xml(),
            'client_state_read' => $this->clientStateRead($form),
            'client_state_write' => $this->clientStateWrite($form),
            'entry_game' => $this->entryGame($form),
            'gget' => $this->gget($form),
            'gpost' => $this->gpost($form),
            'end_game', 'kiken_game' => $this->endGame($form),
            'end_show' => $this->endShow($form),
            'chk_tabooword' => $this->xml('<result>0</result>'),
            'mission_date', 'present_done', 'competition_entry', 'item_gain_log', 'item_consume_log', 'notice_done', 'set_favorite_character', 'odekake_done', 'player_record', 'get_haifu_list', 'get_jongstone_info', 'get_mg' => $this->xml(),
            default => $this->xml(),
        };
    }

    private function appliBoot(): string{return $this->xml('<server_setting><mask_ac_link_scene>0</mask_ac_link_scene><enable_player_name_entry>1</enable_player_name_entry><enable_card_entry>1</enable_card_entry></server_setting>');}
    private function appliInfo(): string
    {
        $events=['SpiritGymBonusEvent','ConstancyFireReach','ConstancyFireReachAppearance','ConstancyAccelDora','ConstancyAccelDoraSchedule','ConstancyMentanpin','ConstancyMentanpinSchedule','StickerEditNotice','DecorationSticker','ChaosUsable','ClearAppearance','IyoAppearance','GrimAroeAppearance','CocoaAppearance','DiaAppearance','DoubrielAppearance','IppatsuAppearance','ShiroeAppearance','ShioriAppearance','PineAppearance','ZoudaiAppearance','CinderellaMitsubaAppearance','PremiumStartEnable','RevengeContinueEnable','EnableOdekake','ItemGainLogEnable','PrivateMatchingDisplay','PrivateMatchingEnable','FavoBonusEvent','FanBonusEvent','ReachSongVoiceGacha','FireReach2','ComebackTakuEvent','KirisameTakuEvent'];$list=[];
        foreach($events as $name)$list[]=['name'=>$name,'active'=>true,'begin'=>'2020/01/01 00:00:00','end'=>'2099/12/31 23:59:59','param'=>$name==='SpiritGymBonusEvent'?'OID=OID_DOJO_BONUS_3X':''];
        $eventsJson=json_encode(['list'=>$list],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{"list":[]}';$proJson=json_encode(['now_pro_stats'=>false,'ProStayDatas'=>[]],JSON_UNESCAPED_SLASHES)?:'{}';
        return $this->xml('<expire_seconds>3600</expire_seconds>'.$this->infoData('events',$eventsJson).$this->infoData('pro_stats',$proJson));
    }
    private function login(array $form): string
    {$guest=((string)($form['guest']??''))==='1';$userId=(string)($form['user_id']??$form['dataid']??'GUEST');if($userId==='')$userId='GUEST';$this->db->ensureProfile($userId,$guest?'GUEST':'PLAYER');$session=bin2hex(random_bytes(16));$this->db->saveSession($session,$userId,['is_guest'=>$guest]);return $this->xml('<auth><session_id>'.$this->x($session).'</session_id></auth>');}
    private function createPlayer(array $form): string
    {$name=trim((string)($form['name']??'PLAYER'))?:'PLAYER';$userId=trim((string)($form['user_id']??''));if($userId==='')$userId=strtoupper(substr(bin2hex(random_bytes(8)),0,16));$p=$this->db->ensureProfile($userId,$name);$payload=$p['payload'];$payload['name']=$name;$payload['created']=true;$this->db->saveProfilePayload($userId,$payload);return $this->xml();}
    private function getMenuData(array $form): string
    {$name='GUEST';$mid=1;$pcuid=(string)($form['pcuid']??'');$session=$pcuid!==''?$this->db->getSession($pcuid):null;if($session){$p=$this->db->getProfile((string)$session['refid']);if($p){$name=(string)($p['payload']['name']??$p['name']??$name);$mid=(int)($p['player_id']??1);}}return $this->xml('<menudata><mpdata><mid>'.$mid.'</mid><name>'.$this->x($name).'</name></mpdata>'.$this->playmodeXml().$this->battleItemXml().'</menudata>');}
    private function clientStateRead(array $form): string
    {$mid=(int)($form['mid']??0);$one=(string)($form['one_kind']??'');$p=$mid?$this->db->getProfileByPlayerId($mid):null;if(!$p&&($form['pcuid']??'')!==''){$s=$this->db->getSession((string)$form['pcuid']);if($s)$p=$this->db->getProfile((string)$s['refid']);}$states=$p['payload']['states']??[];$chunks='';foreach($states as $kind=>$payload){if($one!==''&&$kind!==$one)continue;if(!is_string($payload))$payload=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'';$chunks.='<state kind="'.$this->x((string)$kind).'"><data>'.base64_encode($payload).'</data></state>';}return $this->xml($chunks);}
    private function clientStateWrite(array $form): string
    {$mid=(int)($form['mid']??0);$kind=(string)($form['kind']??'unknown');$data=(string)($form['data']??'');$p=$mid?$this->db->getProfileByPlayerId($mid):null;if(!$p&&($form['pcuid']??'')!==''){$s=$this->db->getSession((string)$form['pcuid']);if($s)$p=$this->db->getProfile((string)$s['refid']);}if($p){$decoded=base64_decode(urldecode($data),true);$payload=$p['payload'];$payload['states'][$kind]=$decoded===false?$data:$decoded;$this->db->saveProfilePayload((string)$p['refid'],$payload);}return $this->xml();}

    private function entryGame(array $form): string
    {$gmode=(int)($form['gmode']??1);if(!isset(self::GMODE_TAKU[$gmode]))$gmode=1;$pcuid=(string)($form['pcuid']??'GUEST');$old=$this->db->getMatch($pcuid);$tid=(int)($old['tid']??0)+1;$session=$this->db->getSession($pcuid);$profile=$session?$this->db->getProfile((string)$session['refid']):null;$taku=self::GMODE_TAKU[$gmode];$match=['gmode'=>$gmode,'taku'=>$taku,'seats'=>self::SEATS[$taku],'tid'=>$tid,'pindex'=>0,'mid'=>(int)($profile['player_id']??1),'name'=>(string)($profile['payload']['name']??$profile['name']??'ゲスト'),'state'=>'matching','next_sno'=>0,'table'=>Table::create($taku,0)];$this->db->saveMatch($pcuid,$match);$url=rtrim($this->baseUrl(),'/').'/aog/';return $this->xml('<entry><gserv_id>1</gserv_id><tid>'.$tid.'</tid><pindex>0</pindex><next_sno>0</next_sno><last_cyoukou_num>3</last_cyoukou_num><cyoukou_num>3</cyoukou_num><ste_oya1_limit_time>15000</ste_oya1_limit_time><ste_limit_time>10000</ste_limit_time><ste_reechi1_limit_time>15000</ste_reechi1_limit_time><naki_limit_time>8000</naki_limit_time><agari_limit_time>10000</agari_limit_time><naki_choice_limit_time>8000</naki_choice_limit_time><reechi_choice_limit_time>8000</reechi_choice_limit_time><last_cyoukou_limit_time>30000</last_cyoukou_limit_time><last_time>30000</last_time><gserv_url>'.$this->x($url).'</gserv_url><pay_mode>0</pay_mode><gmode>'.$gmode.'</gmode></entry>');}
    private function gget(array $form): string
    {$pcuid=(string)($form['pcuid']??'GUEST');$match=$this->db->getMatch($pcuid)??['gmode'=>1,'taku'=>0,'seats'=>4,'tid'=>1,'pindex'=>0,'mid'=>1,'name'=>'ゲスト','state'=>'matching','table'=>Table::create(0,0)];$must=$this->must($form);$ready=((string)($form['ready']??''))==='1';$nextSno=(int)($form['next_sno']??($must[5]??0));$table=new Table(is_array($match['table']??null)?$match['table']:Table::create((int)$match['taku'],0));if($ready&&(($match['state']??'')==='matching')){$table->startKyoku();$match['state']='playing';}if($ready)$table->flushPending();$match['table']=$table->state();$this->db->saveMatch($pcuid,$match);return $this->xml('<game><all_ready>'.($ready?1:0).'</all_ready>'.$this->matchingXml($match).($ready?$table->cellsFrom($nextSno):'').'</game>');}
    private function gpost(array $form): string
    {$pcuid=(string)($form['pcuid']??'GUEST');$match=$this->db->getMatch($pcuid);if(!$match)return $this->xml('<game><taikyoku><cell_info available="0" /></taikyoku></game>');$must=$this->must($form);$kind=(int)($form['kind']??($must[6]??0));$pindex=(int)($form['pindex']??($must[3]??0));$pai=(int)($form['pai']??($must[9]??0));$tepaiId=(int)($form['tepai_id']??($must[10]??0));$tepaiId2=(int)($form['tepai_id2']??($must[11]??0));$reach=(int)($form['reach']??($must[12]??0));$tsumogiri=(int)($form['tsumogiri']??($must[13]??0));$table=new Table(is_array($match['table']??null)?$match['table']:Table::create((int)$match['taku'],0));$before=count($table->state()['cells']??[]);$table->onCommand($kind,$pindex,$pai,$reach,$tsumogiri,$tepaiId,$tepaiId2);$table->flushPending();$match['table']=$table->state();$this->db->saveMatch($pcuid,$match);return $this->xml('<game>'.$table->cellsFrom($before).'</game>');}
    private function matchingXml(array $match): string
    {$seats=(int)($match['seats']??4);$human=(int)($match['pindex']??0);$mid=(int)($match['mid']??1);$name=(string)($match['name']??'ゲスト');$s='<mwait><tid>'.(int)($match['tid']??1).'</tid><pmax>'.$seats.'</pmax>';for($i=0;$i<4;$i++){if($i===$human)$s.='<player_'.$i.' ptype="1"><mid>'.$mid.'</mid><name>'.$this->x($name).'</name><zaseki>'.$i.'</zaseki><cpu_level>0</cpu_level></player_'.$i.'>';elseif($i<$seats)$s.='<player_'.$i.' ptype="3"><mid>0</mid><name>CPU</name><zaseki>'.$i.'</zaseki><cpu_level>1</cpu_level><character_obj>OID_CHARACTER_'.($i+1).'</character_obj></player_'.$i.'>';}return $s.'</mwait>';}
    private function endGame(array $form): string{$pcuid=(string)($form['pcuid']??'GUEST');$m=$this->db->getMatch($pcuid)??[];$m['state']='game_end';if(isset($m['table'])&&is_array($m['table'])){$m['table']['state']='game_end';$m['table']['finished']=true;}$this->db->saveMatch($pcuid,$m);return $this->xml('<mgresult><result>0</result></mgresult>');}
    private function endShow(array $form): string{$voltage=(int)($form['voltage']??0);$contribute=(int)($form['contribute_percent']??100);$bonus=(int)($form['bonus']??0);return $this->xml('<showresult><voltage>'.$voltage.'</voltage><contribute_percent>'.$contribute.'</contribute_percent><card_effect_percent>0</card_effect_percent><item_effect_percent>0</item_effect_percent><bonus>'.$bonus.'</bonus><get_point>'.max(0,intdiv($voltage,10)).'</get_point></showresult>');}
    private function playmodeXml(): string{$s='<playmode_list>';foreach(self::GAME_MODES as $gmode){$taku=self::GMODE_TAKU[$gmode];$s.='<mode><gmode>'.$gmode.'</gmode><taku_class>1</taku_class><payment_mode>0</payment_mode><table_type>0</table_type><pmax>'.self::SEATS[$taku].'</pmax><tenbo>'.self::START_SCORE[$taku].'</tenbo><state>1</state><rate>0</rate><superior_border>0</superior_border></mode>';}return $s.'</playmode_list>';}
    private function battleItemXml(): string{$s='<battle_item_settings><basic_settings/><playmode_settings>';foreach(self::GAME_MODES as $gmode)$s.='<setting gmode="'.$gmode.'" taku_class="1"/>';return $s.'</playmode_settings></battle_item_settings>';}
    /** @return list<string> */ private function must(array $form): array{$raw=(string)($form['must']??'');return $raw===''?[]:array_map('trim',explode(',',$raw));}
    private function infoData(string $kind,string $payload): string{return '<info_data kind="'.$this->x($kind).'">'.base64_encode($payload).'</info_data>';}
    private function xml(string $inner=''): string{return '<?xml version="1.0" encoding="UTF-8"?><response>'.$inner.'</response>';}
    private function baseUrl(): string{$https=($_SERVER['HTTPS']??'')!==''&&($_SERVER['HTTPS']??'')!=='off';return ($https?'https':'http').'://'.($_SERVER['HTTP_HOST']??'127.0.0.1');}
    private function x(string $s): string{return htmlspecialchars($s,ENT_QUOTES|ENT_XML1,'UTF-8');}
}
