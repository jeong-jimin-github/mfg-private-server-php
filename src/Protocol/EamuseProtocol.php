<?php

declare(strict_types=1);

namespace Mfg\Protocol;

final class EamuseProtocol
{
    private const EAMUSE_KEY_HEX = '69d74627d985ee2187161570d08d93b12455035b6df0d8205df5';

    public static function parseEamuseInfo(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }
        $header = trim($header);
        return preg_match('/^1-[0-9a-fA-F]{8}-[0-9a-fA-F]{4}$/', $header) === 1 ? $header : null;
    }

    public static function rc4KeyFromInfo(string $info): string
    {
        $parts = explode('-', $info);
        if (count($parts) !== 3 || $parts[0] !== '1') {
            throw new \InvalidArgumentException('Invalid X-Eamuse-Info header');
        }
        $material = hex2bin($parts[1] . $parts[2] . self::EAMUSE_KEY_HEX);
        if ($material === false) {
            throw new \RuntimeException('Failed to build RC4 key material');
        }
        return md5($material, true);
    }

    public static function crypt(string $data, ?string $info): string
    {
        if ($info === null || $info === '') {
            return $data;
        }
        $key = self::rc4KeyFromInfo($info);
        $s = range(0, 255);
        $j = 0;
        $keyLen = strlen($key);
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $keyLen])) & 0xff;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $i = 0;
        $j = 0;
        $out = '';
        $n = strlen($data);
        for ($k = 0; $k < $n; $k++) {
            $i = ($i + 1) & 0xff;
            $j = ($j + $s[$i]) & 0xff;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$k]) ^ $s[($s[$i] + $s[$j]) & 0xff]);
        }
        return $out;
    }

    public static function lz77Decompress(string $data): string
    {
        $out = '';
        $i = 0;
        $n = strlen($data);
        while ($i < $n) {
            $flags = ord($data[$i++]);
            for ($bit = 0; $bit < 8; $bit++) {
                if ($i >= $n) {
                    return $out;
                }
                if (($flags & (1 << $bit)) !== 0) {
                    $out .= $data[$i++];
                    continue;
                }
                if ($i + 1 >= $n) {
                    return $out;
                }
                $hi = ord($data[$i++]);
                $lo = ord($data[$i++]);
                $offset = ($hi << 4) | ($lo >> 4);
                $length = ($lo & 0x0f) + 3;
                if ($offset === 0) {
                    return $out;
                }
                $start = strlen($out) - $offset;
                if ($start < 0) {
                    $pad = min(-$start, $length);
                    $out .= str_repeat("\0", $pad);
                    $length -= $pad;
                    $start = 0;
                }
                for ($x = 0; $x < $length; $x++) {
                    $out .= $out[$start++];
                }
            }
        }
        return $out;
    }

    public static function lz77CompressStore(string $data): string
    {
        $out = '';
        $i = 0;
        $n = strlen($data);
        while ($i < $n) {
            $chunk = substr($data, $i, 8);
            $flags = 0;
            $len = strlen($chunk);
            for ($k = 0; $k < $len; $k++) {
                $flags |= 1 << $k;
            }
            $out .= chr($flags) . $chunk;
            $i += 8;
        }
        return $out . "\0\0\0";
    }

    public static function decodeTransport(string $wireBody, ?string $eamuseInfo, ?string $compress): string
    {
        $body = self::crypt($wireBody, $eamuseInfo);
        if (strtolower((string)$compress) === 'lz77') {
            $body = self::lz77Decompress($body);
        }
        return $body;
    }

    public static function encodeTransport(string $payload, ?string $eamuseInfo, ?string $compress): string
    {
        if (strtolower((string)$compress) === 'lz77') {
            $payload = self::lz77CompressStore($payload);
        }
        return self::crypt($payload, $eamuseInfo);
    }
}
