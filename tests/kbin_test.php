<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Mfg\\')) return;
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use Mfg\Protocol\KBinXml;

$xml = '<?xml version="1.0" encoding="UTF-8"?><call model="VFG:J:A:A:2025122300" srcid="00010203040506070809"><services method="get"><string __type="str">hello</string><num __type="s32">12345</num><small __type="u8">7</small></services></call>';

foreach ([true, false] as $compressed) {
    $bin = KBinXml::encode($xml, 'UTF-8', $compressed);
    if (!KBinXml::isBinary($bin)) throw new RuntimeException('not detected as kbin');
    $decoded = KBinXml::decode($bin);
    if ($decoded['compressed'] !== $compressed) throw new RuntimeException('compression flag mismatch');
    $doc = new DOMDocument();
    if (!$doc->loadXML($decoded['xml'])) throw new RuntimeException('decoded XML invalid');
    $root = $doc->documentElement;
    if (!$root || $root->tagName !== 'call') throw new RuntimeException('root mismatch');
    if ($root->getAttribute('model') !== 'VFG:J:A:A:2025122300') throw new RuntimeException('attribute mismatch');
    $services = $root->getElementsByTagName('services')->item(0);
    if (!$services || $services->getAttribute('method') !== 'get') throw new RuntimeException('service method mismatch');
    if ($root->getElementsByTagName('string')->item(0)?->textContent !== 'hello') throw new RuntimeException('string mismatch');
    if ($root->getElementsByTagName('num')->item(0)?->textContent !== '12345') throw new RuntimeException('s32 mismatch');
    if ($root->getElementsByTagName('small')->item(0)?->textContent !== '7') throw new RuntimeException('u8 mismatch');
}

echo "kbin round-trip OK\n";
