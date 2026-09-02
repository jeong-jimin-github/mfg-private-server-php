from pathlib import Path

p = Path('src/Aog/Dispatcher.php')
s = p.read_text(encoding='utf-8')

anchor = "    private const START_SCORE = [0=>25000,1=>25000,2=>35000,3=>50000];\n"
insert = anchor + """    private const BASE_EVENTS = [
        'SpiritGymBonusEvent','ConstancyFireReach','ConstancyFireReachAppearance',
        'ConstancyAccelDora','ConstancyAccelDoraSchedule','ConstancyMentanpin',
        'ConstancyMentanpinSchedule','StickerEditNotice','DecorationSticker',
        'ChaosUsable','ClearAppearance','IyoAppearance','GrimAroeAppearance',
        'CocoaAppearance','DiaAppearance','DoubrielAppearance','IppatsuAppearance',
        'ShiroeAppearance','ShioriAppearance','PineAppearance','ZoudaiAppearance',
        'CinderellaMitsubaAppearance','PremiumStartEnable','RevengeContinueEnable',
        'EnableOdekake','ItemGainLogEnable','PrivateMatchingDisplay',
        'PrivateMatchingEnable','FavoBonusEvent','FanBonusEvent','ReachSongVoiceGacha',
    ];
    private const EVENT_TAKU_SETS = [
        'off' => [],
        'min' => ['FireReach2','ComebackTakuEvent','KirisameTakuEvent'],
        'all' => [
            'BlowAwaySanma','FireReach2','Competition7','Competition8',
            'AotenjoEvent2','ComebackTakuEvent','KirisameTakuEvent',
            'MeldBonusTakuEvent2','Competition6','ReversalTakuEvent',
            'BombTakuEvent','AllGreenTaku',
        ],
    ];
    private const EVENT_TAKU_PANELS = [
        'BlowAwaySanma'=>1,'FireReach2'=>1,'Competition7'=>0,'Competition8'=>0,
        'AotenjoEvent2'=>1,'ComebackTakuEvent'=>1,'KirisameTakuEvent'=>1,
        'MeldBonusTakuEvent2'=>2,'Competition6'=>0,'ReversalTakuEvent'=>1,
        'BombTakuEvent'=>2,'AllGreenTaku'=>2,
    ];
    private const EVENT_TAKU_PANEL_SLOTS = 3;
"""
if anchor not in s:
    raise SystemExit('constant anchor missing')
s = s.replace(anchor, insert, 1)

old = "        $events=['SpiritGymBonusEvent','ConstancyFireReach','ConstancyFireReachAppearance','ConstancyAccelDora','ConstancyAccelDoraSchedule','ConstancyMentanpin','ConstancyMentanpinSchedule','StickerEditNotice','DecorationSticker','ChaosUsable','ClearAppearance','IyoAppearance','GrimAroeAppearance','CocoaAppearance','DiaAppearance','DoubrielAppearance','IppatsuAppearance','ShiroeAppearance','ShioriAppearance','PineAppearance','ZoudaiAppearance','CinderellaMitsubaAppearance','PremiumStartEnable','RevengeContinueEnable','EnableOdekake','ItemGainLogEnable','PrivateMatchingDisplay','PrivateMatchingEnable','FavoBonusEvent','FanBonusEvent','ReachSongVoiceGacha','FireReach2','ComebackTakuEvent','KirisameTakuEvent'];$list=[];\n"
new = "        $events=self::BASE_EVENTS;foreach($this->eventTakuFlags() as $flag)$events[]=$flag;$list=[];\n"
if old not in s:
    raise SystemExit('appliInfo event list anchor missing')
s = s.replace(old, new, 1)

method_anchor = "    private function infoData(string $kind,string $payload): string"
methods = """    /** @return list<string> */
    private function eventTakuFlags(): array
    {
        $asked=strtolower(trim((string)(getenv('VFG_EVENT_TAKU')?:'min')));
        $name=array_key_exists($asked,self::EVENT_TAKU_SETS)?$asked:'min';
        if($asked!==''&&$asked!==$name)error_log('[MFG] unknown VFG_EVENT_TAKU='.$asked.'; using min');
        $flags=self::EVENT_TAKU_SETS[$name];$panels=0;
        foreach($flags as $flag)$panels+=self::EVENT_TAKU_PANELS[$flag]??1;
        if($panels>self::EVENT_TAKU_PANEL_SLOTS)error_log('[MFG] VFG_EVENT_TAKU='.$name.' advertises '.$panels.' event panels into '.self::EVENT_TAKU_PANEL_SLOTS.' slots');
        return $flags;
    }

"""
if method_anchor not in s:
    raise SystemExit('method insertion anchor missing')
s = s.replace(method_anchor, methods + method_anchor, 1)
p.write_text(s, encoding='utf-8')

t = Path('tests/event_flags_test.php')
t.write_text(r'''<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Aog\Dispatcher;
use Mfg\Storage\Database;

function ef_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function ef_events(Dispatcher $d,string $mode):array{
    putenv('VFG_EVENT_TAKU='.$mode);
    $root=new SimpleXMLElement($d->dispatch('appli_info',[]));
    $rows=[];
    foreach($root->info_data as $n){
        if((string)$n['kind']!=='events')continue;
        $raw=base64_decode((string)$n,true);ef_ok($raw!==false,'events base64');
        $j=json_decode($raw,true);ef_ok(is_array($j),'events json');$rows=$j['list']??[];
    }
    ef_ok($rows!==[],'events missing');return $rows;
}
function ef_names(array $rows):array{return array_values(array_map(static fn($r)=>(string)$r['name'],$rows));}

$d=new Dispatcher(new Database('sqlite::memory:'));
$tableFlags=['BlowAwaySanma','FireReach2','Competition7','Competition8','AotenjoEvent2','ComebackTakuEvent','KirisameTakuEvent','MeldBonusTakuEvent2','Competition6','ReversalTakuEvent','BombTakuEvent','AllGreenTaku'];
$panels=['BlowAwaySanma'=>1,'FireReach2'=>1,'Competition7'=>0,'Competition8'=>0,'AotenjoEvent2'=>1,'ComebackTakuEvent'=>1,'KirisameTakuEvent'=>1,'MeldBonusTakuEvent2'=>2,'Competition6'=>0,'ReversalTakuEvent'=>1,'BombTakuEvent'=>2,'AllGreenTaku'=>2];

$off=ef_events($d,'off');$offNames=ef_names($off);
ef_ok(in_array('SpiritGymBonusEvent',$offNames,true),'base event missing');
foreach($tableFlags as $f)ef_ok(!in_array($f,$offNames,true),'off advertised '.$f);
foreach($off as $r){ef_ok(($r['active']??false)===true,'inactive base event');$p=(string)($r['param']??'');if($p!=='')ef_ok(str_contains($p,'='),'param not key=value');}

$min=ef_names(ef_events($d,'min'));
$minFlags=array_values(array_intersect($tableFlags,$min));sort($minFlags);
$expected=['ComebackTakuEvent','FireReach2','KirisameTakuEvent'];sort($expected);
ef_ok($minFlags===$expected,'min event table set mismatch');
$minPanels=array_sum(array_map(static fn($f)=>$panels[$f]??1,$minFlags));ef_ok($minPanels===3,'min panel budget');

$all=ef_names(ef_events($d,'all'));
foreach($tableFlags as $f)ef_ok(in_array($f,$all,true),'all missing '.$f);
$allPanels=array_sum(array_map(static fn($f)=>$panels[$f]??1,$tableFlags));ef_ok($allPanels===12,'all panel count must reproduce known overflow');

$bad=ef_names(ef_events($d,'not-a-mode'));
foreach($expected as $f)ef_ok(in_array($f,$bad,true),'invalid mode did not fall back to min: '.$f);
putenv('VFG_EVENT_TAKU');

echo "event table flags OK\n";
''', encoding='utf-8')
