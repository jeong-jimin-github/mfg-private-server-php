<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Mfg\\')) return;
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use Mfg\Mahjong\Mahjong as M;
use Mfg\Mahjong\Table;

$t = new Table(Table::create(M::TONPU, 0, 12345));
$t->startKyoku();
$t->flushPending();
$xml = $t->cellsFrom(0);
if (!str_contains($xml, 'kind="17"')) throw new RuntimeException('KYOKUSTART missing');
if (!str_contains($xml, 'kind="1"')) throw new RuntimeException('TSUMO missing');
if (!str_contains($xml, 'kind="15"')) throw new RuntimeException('TSUMOCHOICES missing');

$state = $t->state();
$drawn = $state['drawn'][0];
if (!is_int($drawn)) throw new RuntimeException('human draw missing');
$start = count($state['cells']);
$t->onCommand(Table::S_SUTE_PAI, 0, M::idxToPai($drawn), 0, 1);
$t->flushPending();
$delta = $t->cellsFrom($start);
if (!str_contains($delta, 'kind="2"')) throw new RuntimeException('discard missing');
if (!str_contains($delta, 'kind="1"')) throw new RuntimeException('CPU/human follow-up draw missing');

echo "match stream OK\n";
