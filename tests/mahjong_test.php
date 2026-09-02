<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Mfg\\')) return;
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use Mfg\Mahjong\Mahjong as M;

$tiles = [0,1,2, 9,10,11, 18,19,20, 27,27,27, 28,28];
if (!M::isAgari(M::countsOf($tiles))) throw new RuntimeException('standard agari failed');

$chiitoi = [0,0, 1,1, 9,9, 10,10, 18,18, 19,19, 27,27];
if (!M::isAgari(M::countsOf($chiitoi))) throw new RuntimeException('chiitoi agari failed');

$kokushi = M::yaochuIdx();
$kokushi[] = $kokushi[0];
if (!M::isAgari(M::countsOf($kokushi))) throw new RuntimeException('kokushi agari failed');

if (count(M::buildWall(M::SANMA, 1)) !== 108) throw new RuntimeException('sanma wall size');
if (count(M::buildWall(M::NIMA, 1)) !== 72) throw new RuntimeException('nima wall size');
if (M::doraFromIndicator(M::HON + 1, M::NIMA) !== M::HON) throw new RuntimeException('nima wind dora cycle');

echo "mahjong core OK\n";
