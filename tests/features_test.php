<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Mfg\\')) return;
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use Mfg\Aog\FeatureDispatcher;
use Mfg\Storage\Database;

$tmp = sys_get_temp_dir() . '/mfg-php-feature-' . bin2hex(random_bytes(4)) . '.sqlite';
$db = new Database($tmp);
$db->ensureProfile('TESTREFID','PLAYER');
$db->saveSession('TESTSESSION','TESTREFID');
$f = new FeatureDispatcher($db);

$xml = $f->dispatch('gacha_info', []);
$doc = new DOMDocument();
if (!$xml || !$doc->loadXML($xml)) throw new RuntimeException('gacha_info invalid XML');
$infos = $doc->getElementsByTagName('info');
if ($infos->length < 1) throw new RuntimeException('no gacha series');
foreach ($infos as $info) {
    $items = $info->getElementsByTagName('items')->item(0)?->getElementsByTagName('item');
    if (!$items || $items->length < 1) throw new RuntimeException('empty gacha pool');
    $type = $info->getElementsByTagName('series_type')->item(0)?->textContent ?? '';
    if ($type === 'Pickup') {
        $charas = $info->getElementsByTagName('pickup_charas')->item(0)?->getElementsByTagName('chara');
        $custom = $info->getElementsByTagName('custom_pickup_items')->item(0)?->getElementsByTagName('item');
        if ((!$charas || $charas->length === 0) && (!$custom || $custom->length === 0)) {
            throw new RuntimeException('pickup banner without preview target');
        }
    }
}

$set = $f->dispatch('dojo_set_slot', ['pcuid'=>'TESTSESSION','slot_id'=>'0','set_character'=>'OID_CHARACTER_1']);
if (!$set || !str_contains($set,'<updated>1</updated>')) throw new RuntimeException('dojo set failed');
$status = $f->dispatch('dojo_get_status', ['pcuid'=>'TESTSESSION']);
if (!$status || !str_contains($status,'<available>1</available>')) throw new RuntimeException('dojo state not persistent');

@unlink($tmp);
echo "features OK\n";
