<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Mfg\\')) return;
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use Mfg\Mahjong\Mahjong as M;
use Mfg\Mahjong\ScoreMath as S;

if (S::baseScore(5,30)!==2000) throw new RuntimeException('mangan base');
if (S::hanRank(13,30,0)!==9) throw new RuntimeException('kazoe rank');
$p=S::payments(M::TONPU,5,30,false,false);
if ($p['total']!==8000) throw new RuntimeException('ko mangan ron');
$p=S::payments(M::TONPU,5,30,true,false);
if ($p['total']!==12000) throw new RuntimeException('oya mangan ron');
$p=S::payments(M::SANMA,5,30,false,true);
if ($p['total']<=0) throw new RuntimeException('sanma tsumo');

echo "score math OK\n";
