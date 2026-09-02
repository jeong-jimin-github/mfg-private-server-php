<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Mfg\\')) return;
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use Mfg\Mahjong\SituationalYaku;

$r = SituationalYaku::evaluate(haitei: true);
if ($r['han'] !== 1 || !in_array('Haitei', $r['yaku'], true)) throw new RuntimeException('haitei');

$r = SituationalYaku::evaluate(houtei: true, chankan: true);
if ($r['han'] !== 2 || !in_array('Hotei', $r['yaku'], true) || !in_array('Chankan', $r['yaku'], true)) throw new RuntimeException('houtei/chankan');

$r = SituationalYaku::evaluate(rinshan: true);
if ($r['han'] !== 1 || !in_array('Rinsyan', $r['yaku'], true)) throw new RuntimeException('rinshan');

$r = SituationalYaku::evaluate(tenho: true, chiho: true);
if ($r['yakuman'] !== 1 || $r['yaku'] !== ['Tenho']) throw new RuntimeException('tenho precedence');

$base = ['han'=>3,'fu'=>30,'rank'=>3,'dora'=>1,'yaku'=>['Richi'],'yakuman'=>0];
$m = SituationalYaku::merge($base, SituationalYaku::evaluate(rinshan: true));
if ($m['han'] !== 4 || !in_array('Rinsyan', $m['yaku'], true)) throw new RuntimeException('merge');

echo "situational yaku OK\n";
