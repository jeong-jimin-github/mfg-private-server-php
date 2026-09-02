<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Protocol/EamuseProtocol.php';

use Mfg\Protocol\EamuseProtocol;

$info = '1-01234567-89ab';
$xml = '<?xml version="1.0" encoding="UTF-8"?><call model="VFG:J:A:A:2025122300"><services method="get"/></call>';

$wire = EamuseProtocol::encodeTransport($xml, $info, 'lz77');
$back = EamuseProtocol::decodeTransport($wire, $info, 'lz77');
if ($back !== $xml) {
    fwrite(STDERR, "RC4/LZ77 round-trip failed\n");
    exit(1);
}

if (EamuseProtocol::parseEamuseInfo($info) !== $info) {
    fwrite(STDERR, "X-Eamuse-Info parse failed\n");
    exit(1);
}

if (EamuseProtocol::parseEamuseInfo('bad') !== null) {
    fwrite(STDERR, "invalid header accepted\n");
    exit(1);
}

echo "protocol transport ok\n";
