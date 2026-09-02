from pathlib import Path
import re

p = Path('src/Aog/Dispatcher.php')
s = p.read_text(encoding='utf-8')

old_boot = "    private function appliBoot(): string{return $this->xml('<server_setting><mask_ac_link_scene>0</mask_ac_link_scene><enable_player_name_entry>1</enable_player_name_entry><enable_card_entry>1</enable_card_entry></server_setting>');}"
new_boot = "    private function appliBoot(): string{return $this->xml('<server_setting><mask_ac_link_scene>0</mask_ac_link_scene><reviewed_version>false</reviewed_version></server_setting><boot_mes><status>0</status><moserv_url>'.$this->x(rtrim($this->baseUrl(),'/').'/aog').'</moserv_url><message>0</message></boot_mes>');}"
if old_boot not in s:
    raise SystemExit('appliBoot anchor missing')
s = s.replace(old_boot, new_boot, 1)

old_entry = "'name'=>(string)($profile['payload']['name']??$profile['name']??'ゲスト'),'state'=>'matching'"
new_entry = "'name'=>(string)($profile['payload']['name']??$profile['name']??'ゲスト'),'profile_payload'=>(array)($profile['payload']??[]),'state'=>'matching'"
if old_entry not in s:
    raise SystemExit('entryGame profile anchor missing')
s = s.replace(old_entry, new_entry, 1)

start = s.index('    private function matchingXml(array $match): string')
end = s.index('    private function endGame(array $form): string', start)
matching = '''    private function matchingXml(array $match): string
    {
        $seats=(int)($match['seats']??2);$cpuN=max(0,$seats-1);$human=(int)($match['pindex']??0);$mid=(int)($match['mid']??1);$name=(string)($match['name']??'ゲスト');$payload=is_array($match['profile_payload']??null)?$match['profile_payload']:[];
        $states='';foreach(['player_game','customize_item'] as $kind){$raw=$payload['states'][$kind]??null;if($raw===null||$raw==='')continue;if(!is_string($raw))$raw=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'';$states.='<state kind="'.$this->x($kind).'"><data>'.$this->x(base64_encode($raw)).'</data></state>';}
        $players='<player_0 ptype="1"><zaseki>0</zaseki><cpu_level>0</cpu_level>'.($states!==''?'<client_states>'.$states.'</client_states>':'').'</player_0>';
        $used=1;$pg=$payload['states']['player_game']??'';if(is_string($pg)&&$pg!==''){$j=json_decode($pg,true);if(is_array($j))$used=(int)($j['SelectChara']??0)+1;}
        $cpuChara=1;for($i=1;$i<$seats;$i++){$cpuChara++;if($cpuChara===$used)$cpuChara++;if($cpuChara>19)$cpuChara=$used!==1?1:2;$players.='<player_'.$i.' ptype="3"><cpu_level>1</cpu_level><zaseki>'.$i.'</zaseki><cpu_name>OID_CHARACTER_'.$cpuChara.'</cpu_name></player_'.$i.'>';}
        return '<mwait><status>1</status><pnum>1</pnum><cpu_num>'.$cpuN.'</cpu_num><pindex>'.$human.'</pindex><epdata_0><name>'.$this->x($name).'</name><mid>'.$mid.'</mid></epdata_0><mend>'.$players.'</mend></mwait>';
    }
'''
s = s[:start] + matching + s[end:]

old_must = "private function must(array $form): array{$raw=(string)($form['must']??'');return $raw===''?[]:array_map('trim',explode(',',$raw));}"
new_must = "private function must(array $form): array{$raw=(string)($form['must']??'');if($raw==='')return [];$parts=preg_split('#[/,]#',$raw);return array_map('trim',$parts===false?[]:$parts);}"
if old_must not in s:
    raise SystemExit('Dispatcher must anchor missing')
s = s.replace(old_must, new_must, 1)
p.write_text(s, encoding='utf-8')

p = Path('src/Aog/MiscDispatcher.php')
s = p.read_text(encoding='utf-8')
old_must = "private function must(array $form):array {$raw=(string)($form['must']??'');return $raw===''?[]:array_map('trim',explode(',',$raw));}"
new_must = "private function must(array $form):array {$raw=(string)($form['must']??'');if($raw==='')return [];$parts=preg_split('#[/,]#',$raw);return array_map('trim',$parts===false?[]:$parts);}"
if old_must not in s:
    raise SystemExit('Misc must anchor missing')
p.write_text(s.replace(old_must, new_must, 1), encoding='utf-8')
