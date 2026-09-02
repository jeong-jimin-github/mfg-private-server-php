<?php

declare(strict_types=1);

namespace Mfg\Aog;

use Mfg\Storage\Database;

final class Dispatcher
{
    private const GAME_MODES = [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23];
    private const GMODE_TAKU = [1=>0,2=>1,3=>2,4=>3,5=>0,6=>0,7=>2,8=>0,9=>0,10=>2,11=>0,12=>2,13=>0,14=>2,15=>0,16=>0,17=>2,18=>2,19=>2,20=>0,21=>2,22=>0,23=>2];
    private const SEATS = [0=>4,1=>4,2=>3,3=>2];
    private const START_SCORE = [0=>25000,1=>25000,2=>35000,3=>50000];

    public function __construct(private Database $db) {}

    public function dispatch(string $name, array $form): string
    {
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
            default => $this->xml(),
        };
    }

    private function appliBoot(): string
    {
        return $this->xml('<server_setting><mask_ac_link_scene>0</mask_ac_link_scene><enable_player_name_entry>1</enable_player_name_entry><enable_card_entry>1</enable_card_entry></server_setting>');
    }

    private function appliInfo(): string
    {
        $events = [
            'SpiritGymBonusEvent','ConstancyFireReach','ConstancyFireReachAppearance','ConstancyAccelDora','ConstancyAccelDoraSchedule','ConstancyMentanpin','ConstancyMentanpinSchedule','StickerEditNotice','DecorationSticker','ChaosUsable','ClearAppearance','IyoAppearance','GrimAroeAppearance','CocoaAppearance','DiaAppearance','DoubrielAppearance','IppatsuAppearance','ShiroeAppearance','ShioriAppearance','PineAppearance','ZoudaiAppearance','CinderellaMitsubaAppearance','PremiumStartEnable','RevengeContinueEnable','EnableOdekake','ItemGainLogEnable','PrivateMatchingDisplay','PrivateMatchingEnable','FavoBonusEvent','FanBonusEvent','ReachSongVoiceGacha','FireReach2','ComebackTakuEvent','KirisameTakuEvent'
        ];
        $list = [];
        foreach ($events as $name) {
            $list[] = ['name'=>$name,'active'=>true,'begin'=>'2020/01/01 00:00:00','end'=>'2099/12/31 23:59:59','param'=>$name === 'SpiritGymBonusEvent' ? 'OID=OID_DOJO_BONUS_3X' : ''];
        }
        $eventsJson = json_encode(['list'=>$list], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{"list":[]}';
        $proJson = json_encode(['now_pro_stats'=>false,'ProStayDatas'=>[]], JSON_UNESCAPED_SLASHES) ?: '{}';
        return $this->xml('<expire_seconds>3600</expire_seconds>' . $this->infoData('events',$eventsJson) . $this->infoData('pro_stats',$proJson));
    }

    private function login(array $form): string
    {
        $guest = ((string)($form['guest'] ?? '')) === '1';
        $userId = (string)($form['user_id'] ?? $form['dataid'] ?? 'GUEST');
        if ($userId === '') $userId = 'GUEST';
        $profile = $this->db->ensureProfile($userId, $guest ? 'GUEST' : 'PLAYER');
        $session = bin2hex(random_bytes(16));
        $this->db->saveSession($session, $userId, ['is_guest'=>$guest]);
        if (!$guest && ($profile['name'] ?? '') === 'GUEST') {
            $payload = $profile['payload'];
            $payload['is_guest'] = false;
            $this->db->saveProfilePayload($userId, $payload);
        }
        return $this->xml('<auth><session_id>' . $this->x($session) . '</session_id></auth>');
    }

    private function createPlayer(array $form): string
    {
        $name = trim((string)($form['name'] ?? 'PLAYER')) ?: 'PLAYER';
        $userId = trim((string)($form['user_id'] ?? ''));
        if ($userId === '') $userId = strtoupper(substr(bin2hex(random_bytes(8)),0,16));
        $p = $this->db->ensureProfile($userId, $name);
        $payload = $p['payload'];
        $payload['name'] = $name;
        $payload['created'] = true;
        $this->db->saveProfilePayload($userId, $payload);
        return $this->xml();
    }

    private function getMenuData(array $form): string
    {
        $name = 'GUEST';
        $mid = 1;
        $pcuid = (string)($form['pcuid'] ?? '');
        $session = $pcuid !== '' ? $this->db->getSession($pcuid) : null;
        if ($session) {
            $p = $this->db->getProfile((string)$session['refid']);
            if ($p) {
                $name = (string)($p['payload']['name'] ?? $p['name'] ?? $name);
                $mid = (int)($p['player_id'] ?? 1);
            }
        }
        return $this->xml('<menudata><mpdata><mid>' . $mid . '</mid><name>' . $this->x($name) . '</name></mpdata>' . $this->playmodeXml() . $this->battleItemXml() . '</menudata>');
    }

    private function clientStateRead(array $form): string
    {
        $mid = (int)($form['mid'] ?? 0);
        $one = (string)($form['one_kind'] ?? '');
        $p = $mid ? $this->db->getProfileByPlayerId($mid) : null;
        if (!$p && ($form['pcuid'] ?? '') !== '') {
            $s = $this->db->getSession((string)$form['pcuid']);
            if ($s) $p = $this->db->getProfile((string)$s['refid']);
        }
        $states = $p['payload']['states'] ?? [];
        $chunks = '';
        foreach ($states as $kind => $payload) {
            if ($one !== '' && $kind !== $one) continue;
            if (!is_string($payload)) $payload = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '';
            $chunks .= '<state kind="' . $this->x((string)$kind) . '"><data>' . base64_encode($payload) . '</data></state>';
        }
        return $this->xml($chunks);
    }

    private function clientStateWrite(array $form): string
    {
        $mid = (int)($form['mid'] ?? 0);
        $kind = (string)($form['kind'] ?? 'unknown');
        $data = (string)($form['data'] ?? '');
        $p = $mid ? $this->db->getProfileByPlayerId($mid) : null;
        if (!$p && ($form['pcuid'] ?? '') !== '') {
            $s = $this->db->getSession((string)$form['pcuid']);
            if ($s) $p = $this->db->getProfile((string)$s['refid']);
        }
        if ($p) {
            $decoded = base64_decode(urldecode($data), true);
            $payload = $p['payload'];
            $payload['states'][$kind] = $decoded === false ? $data : $decoded;
            $this->db->saveProfilePayload((string)$p['refid'], $payload);
        }
        return $this->xml();
    }

    private function playmodeXml(): string
    {
        $s = '<playmode_list>';
        foreach (self::GAME_MODES as $gmode) {
            $taku = self::GMODE_TAKU[$gmode];
            $s .= '<mode><gmode>' . $gmode . '</gmode><taku_class>1</taku_class><payment_mode>0</payment_mode><table_type>0</table_type><pmax>' . self::SEATS[$taku] . '</pmax><tenbo>' . self::START_SCORE[$taku] . '</tenbo><state>1</state><rate>0</rate><superior_border>0</superior_border></mode>';
        }
        return $s . '</playmode_list>';
    }

    private function battleItemXml(): string
    {
        $s = '<battle_item_settings><basic_settings/><playmode_settings>';
        foreach (self::GAME_MODES as $gmode) $s .= '<setting gmode="' . $gmode . '" taku_class="1"/>';
        return $s . '</playmode_settings></battle_item_settings>';
    }

    private function infoData(string $kind, string $payload): string
    {
        return '<info_data kind="' . $this->x($kind) . '">' . base64_encode($payload) . '</info_data>';
    }

    private function xml(string $inner = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><response>' . $inner . '</response>';
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES|ENT_XML1, 'UTF-8');
    }
}
