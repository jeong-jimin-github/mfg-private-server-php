<?php

declare(strict_types=1);

namespace Mfg\Protocol;

use DOMDocument;
use DOMElement;

/**
 * Konami binary XML (kbin) codec used by AVS/e-Amusement.
 *
 * This is a clean PHP implementation of the public kbin format used by the
 * Python kbinxml project. It intentionally has no Python/runtime dependency so
 * it can run on ordinary PHP-FPM/shared hosting.
 */
final class KBinXml
{
    private const SIGNATURE = 0xA0;
    private const SIG_COMPRESSED = 0x42;
    private const SIG_UNCOMPRESSED = 0x45;
    private const NODE_END = 190;
    private const END_SECTION = 191;
    private const ATTR = 46;
    private const ARRAY_FLAG = 0x40;
    private const CHARMAP = '0123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz';

    /** @var array<int,array{name:string,size:int,signed?:bool,float?:bool,count:int}> */
    private const FORMATS = [
        1=>['name'=>'void','size'=>0,'count'=>0],
        2=>['name'=>'s8','size'=>1,'signed'=>true,'count'=>1],
        3=>['name'=>'u8','size'=>1,'count'=>1],
        4=>['name'=>'s16','size'=>2,'signed'=>true,'count'=>1],
        5=>['name'=>'u16','size'=>2,'count'=>1],
        6=>['name'=>'s32','size'=>4,'signed'=>true,'count'=>1],
        7=>['name'=>'u32','size'=>4,'count'=>1],
        8=>['name'=>'s64','size'=>8,'signed'=>true,'count'=>1],
        9=>['name'=>'u64','size'=>8,'count'=>1],
        10=>['name'=>'bin','size'=>1,'count'=>-1],
        11=>['name'=>'str','size'=>1,'count'=>-1],
        12=>['name'=>'ip4','size'=>4,'count'=>1],
        13=>['name'=>'time','size'=>4,'count'=>1],
        14=>['name'=>'float','size'=>4,'float'=>true,'count'=>1],
        15=>['name'=>'double','size'=>8,'float'=>true,'count'=>1],
        16=>['name'=>'2s8','size'=>1,'signed'=>true,'count'=>2],
        17=>['name'=>'2u8','size'=>1,'count'=>2],
        18=>['name'=>'2s16','size'=>2,'signed'=>true,'count'=>2],
        19=>['name'=>'2u16','size'=>2,'count'=>2],
        20=>['name'=>'2s32','size'=>4,'signed'=>true,'count'=>2],
        21=>['name'=>'2u32','size'=>4,'count'=>2],
        22=>['name'=>'2s64','size'=>8,'signed'=>true,'count'=>2],
        23=>['name'=>'2u64','size'=>8,'count'=>2],
        24=>['name'=>'2f','size'=>4,'float'=>true,'count'=>2],
        25=>['name'=>'2d','size'=>8,'float'=>true,'count'=>2],
        26=>['name'=>'3s8','size'=>1,'signed'=>true,'count'=>3],
        27=>['name'=>'3u8','size'=>1,'count'=>3],
        28=>['name'=>'3s16','size'=>2,'signed'=>true,'count'=>3],
        29=>['name'=>'3u16','size'=>2,'count'=>3],
        30=>['name'=>'3s32','size'=>4,'signed'=>true,'count'=>3],
        31=>['name'=>'3u32','size'=>4,'count'=>3],
        32=>['name'=>'3s64','size'=>8,'signed'=>true,'count'=>3],
        33=>['name'=>'3u64','size'=>8,'count'=>3],
        34=>['name'=>'3f','size'=>4,'float'=>true,'count'=>3],
        35=>['name'=>'3d','size'=>8,'float'=>true,'count'=>3],
        36=>['name'=>'4s8','size'=>1,'signed'=>true,'count'=>4],
        37=>['name'=>'4u8','size'=>1,'count'=>4],
        38=>['name'=>'4s16','size'=>2,'signed'=>true,'count'=>4],
        39=>['name'=>'4u16','size'=>2,'count'=>4],
        40=>['name'=>'4s32','size'=>4,'signed'=>true,'count'=>4],
        41=>['name'=>'4u32','size'=>4,'count'=>4],
        42=>['name'=>'4s64','size'=>8,'signed'=>true,'count'=>4],
        43=>['name'=>'4u64','size'=>8,'count'=>4],
        44=>['name'=>'4f','size'=>4,'float'=>true,'count'=>4],
        45=>['name'=>'4d','size'=>8,'float'=>true,'count'=>4],
        48=>['name'=>'vs8','size'=>1,'signed'=>true,'count'=>16],
        49=>['name'=>'vu8','size'=>1,'count'=>16],
        50=>['name'=>'vs16','size'=>2,'signed'=>true,'count'=>8],
        51=>['name'=>'vu16','size'=>2,'count'=>8],
        52=>['name'=>'bool','size'=>1,'signed'=>true,'count'=>1],
        53=>['name'=>'2b','size'=>1,'signed'=>true,'count'=>2],
        54=>['name'=>'3b','size'=>1,'signed'=>true,'count'=>3],
        55=>['name'=>'4b','size'=>1,'signed'=>true,'count'=>4],
        56=>['name'=>'vb','size'=>1,'signed'=>true,'count'=>16],
    ];

    /** @var array<string,int>|null */
    private static ?array $typeIds = null;

    public static function isBinary(string $data): bool
    {
        return strlen($data) >= 2 && ord($data[0]) === self::SIGNATURE
            && in_array(ord($data[1]), [self::SIG_COMPRESSED,self::SIG_UNCOMPRESSED], true);
    }

    /** @return array{xml:string,encoding:string,compressed:bool} */
    public static function decode(string $input): array
    {
        if (!self::isBinary($input) || strlen($input) < 12) {
            throw new \InvalidArgumentException('Not kbin XML');
        }
        $compressed = ord($input[1]) === self::SIG_COMPRESSED;
        $encKey = ord($input[2]);
        if (ord($input[3]) !== (0xFF ^ $encKey)) {
            throw new \RuntimeException('Invalid kbin encoding guard');
        }
        $encoding = self::encodingName($encKey);
        $nodeLength = self::u32(substr($input, 4, 4));
        $nodeStart = 8;
        $dataStart = $nodeStart + $nodeLength;
        if ($dataStart + 4 > strlen($input)) {
            throw new \RuntimeException('Truncated kbin');
        }
        $nodePos = $nodeStart;
        $nodeEnd = $dataStart;
        $dataPos = $dataStart + 4;
        $bytePos = $dataStart;
        $wordPos = $dataStart;

        $dom = new DOMDocument('1.0', 'UTF-8');
        $stack = [];
        $current = null;
        $root = null;

        while ($nodePos < $nodeEnd) {
            while ($nodePos < $nodeEnd && ord($input[$nodePos]) === 0) $nodePos++;
            if ($nodePos >= $nodeEnd) break;
            $rawType = ord($input[$nodePos++]);
            $isArray = ($rawType & self::ARRAY_FLAG) !== 0;
            $type = $rawType & ~self::ARRAY_FLAG;
            if ($type === self::NODE_END) {
                $current = array_pop($stack) ?: null;
                continue;
            }
            if ($type === self::END_SECTION) break;

            $name = $compressed
                ? self::unpackSixBit($input, $nodePos)
                : self::unpackName($input, $nodePos, $encoding);

            if ($type === self::ATTR) {
                if (!$current instanceof DOMElement) throw new \RuntimeException('Attribute without node');
                $value = self::grabAutoString($input, $dataPos, $encoding);
                $current->setAttribute($name, $value);
                continue;
            }
            if (!isset(self::FORMATS[$type])) throw new \RuntimeException('Unsupported kbin node type ' . $type);

            $el = $dom->createElement(self::safeXmlName($name));
            if ($current instanceof DOMElement) $current->appendChild($el);
            else { $dom->appendChild($el); $root = $el; }
            $stack[] = $current;
            $current = $el;
            if ($type === 1) continue;

            $fmt = self::FORMATS[$type];
            $el->setAttribute('__type', $fmt['name']);
            $varCount = $fmt['count'];
            $arrayCount = 1;
            if ($varCount === -1) {
                $byteLen = self::readU32($input, $dataPos);
                $varCount = $byteLen;
                $isArray = true;
            } elseif ($isArray) {
                $byteLen = self::readU32($input, $dataPos);
                $unit = $fmt['size'] * $varCount;
                $arrayCount = $unit > 0 ? intdiv($byteLen, $unit) : 0;
                $el->setAttribute('__count', (string)$arrayCount);
            }
            $total = $arrayCount * $varCount;
            if ($type === 10 || $type === 11) $total = $varCount;

            if ($isArray) {
                $raw = substr($input, $dataPos, $total * $fmt['size']);
                $dataPos += strlen($raw);
                $dataPos = self::align4($dataPos);
                // Variable-length/array payloads are emitted as standalone
                // aligned blocks by encodeElement(), which also advances the
                // byte/word packing cursors to the end of that block. Mirror
                // that here or a following u8/u16 scalar can be read from an
                // older reserved packing slot instead of the current dataPos.
                $bytePos = max($bytePos, $dataPos);
                $wordPos = max($wordPos, $dataPos);
            } else {
                $raw = self::grabAligned($input, $dataPos, $bytePos, $wordPos, $fmt['size'], $total);
            }

            if ($type === 10) {
                $el->setAttribute('__size', (string)strlen($raw));
                $el->nodeValue = bin2hex($raw);
            } elseif ($type === 11) {
                $raw = rtrim($raw, "\0");
                $el->nodeValue = self::toUtf8($raw, $encoding);
            } elseif ($type === 12) {
                $ips = [];
                for ($i=0; $i<$total; $i++) {
                    $chunk = substr($raw, $i*4, 4);
                    $ip = strlen($chunk) === 4 ? @inet_ntop($chunk) : false;
                    if ($ip === false) throw new \RuntimeException('Invalid kbin ip4 payload');
                    $ips[] = $ip;
                }
                $el->nodeValue = implode(' ', $ips);
            } else {
                $values = self::unpackValues($raw, $fmt, $total);
                $el->nodeValue = implode(' ', array_map(static fn($v) => is_float($v) ? number_format($v, 6, '.', '') : (string)$v, $values));
            }
        }

        if (!$root instanceof DOMElement) throw new \RuntimeException('Empty kbin document');
        $xml = $dom->saveXML();
        return ['xml'=>$xml === false ? '' : $xml,'encoding'=>$encoding,'compressed'=>$compressed];
    }

    public static function encode(string $xml, string $encoding = 'UTF-8', bool $compressed = true): string
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!@$dom->loadXML($xml, LIBXML_NONET)) throw new \InvalidArgumentException('Invalid XML');
        $root = $dom->documentElement;
        if (!$root) throw new \InvalidArgumentException('Missing XML root');

        $node = '';
        $data = '';
        $bytePos = 0;
        $wordPos = 0;
        self::encodeElement($root, $node, $data, $bytePos, $wordPos, $encoding, $compressed);
        $node .= chr(self::END_SECTION | self::ARRAY_FLAG);
        while ((strlen($node) % 4) !== 0) $node .= "\0";
        $encKey = self::encodingKey($encoding);
        $header = chr(self::SIGNATURE) . chr($compressed ? self::SIG_COMPRESSED : self::SIG_UNCOMPRESSED)
            . chr($encKey) . chr(0xFF ^ $encKey) . pack('N', strlen($node));
        return $header . $node . pack('N', strlen($data)) . $data;
    }

    private static function encodeElement(DOMElement $el, string &$node, string &$data, int &$bytePos, int &$wordPos, string $encoding, bool $compressed): void
    {
        $typeName = $el->getAttribute('__type');
        if ($typeName === '') $typeName = trim($el->textContent) !== '' && !$el->firstElementChild ? 'str' : 'void';
        $id = self::typeIds()[$typeName] ?? null;
        if ($id === null) throw new \RuntimeException('Unsupported kbin type ' . $typeName);
        $countAttr = $el->getAttribute('__count');
        $isArray = $countAttr !== '';
        $node .= chr($id | ($isArray ? self::ARRAY_FLAG : 0));
        self::packName($el->tagName, $node, $encoding, $compressed);

        if ($id !== 1) {
            $fmt = self::FORMATS[$id];
            $text = $el->textContent ?? '';
            if ($id === 10) $raw = hex2bin(preg_replace('/\s+/', '', $text) ?? '') ?: '';
            elseif ($id === 11) $raw = self::fromUtf8($text, $encoding) . "\0";
            elseif ($id === 12) {
                $tokens = preg_split('/\s+/', trim($text)) ?: [];
                if ($tokens === ['']) $tokens = [];
                $raw = '';
                foreach ($tokens as $token) {
                    $packed = @inet_pton($token);
                    if ($packed === false || strlen($packed) !== 4) throw new \RuntimeException('Invalid IPv4 address: ' . $token);
                    $raw .= $packed;
                }
            } else {
                $tokens = preg_split('/\s+/', trim($text)) ?: [];
                if ($tokens === ['']) $tokens = [];
                $raw = self::packValues($tokens, $fmt);
            }

            if ($isArray || $fmt['count'] === -1) {
                $data .= pack('N', strlen($raw)) . $raw;
                while ((strlen($data) % 4) !== 0) $data .= "\0";
                $bytePos = max($bytePos, strlen($data));
                $wordPos = max($wordPos, strlen($data));
            } else {
                self::appendAligned($data, $raw, $fmt['size'] * $fmt['count'], $bytePos, $wordPos);
            }
        }

        $attrs = [];
        foreach ($el->attributes as $attr) {
            if (in_array($attr->name, ['__type','__size','__count'], true)) continue;
            $attrs[$attr->name] = $attr->value;
        }
        ksort($attrs, SORT_STRING);
        foreach ($attrs as $name => $value) {
            $raw = self::fromUtf8($value, $encoding) . "\0";
            $data .= pack('N', strlen($raw)) . $raw;
            while ((strlen($data) % 4) !== 0) $data .= "\0";
            $bytePos = max($bytePos, strlen($data));
            $wordPos = max($wordPos, strlen($data));
            $node .= chr(self::ATTR);
            self::packName($name, $node, $encoding, $compressed);
        }

        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement) self::encodeElement($child, $node, $data, $bytePos, $wordPos, $encoding, $compressed);
        }
        $node .= chr(self::NODE_END | self::ARRAY_FLAG);
    }

    private static function grabAligned(string $input, int &$dataPos, int &$bytePos, int &$wordPos, int $size, int $count): string
    {
        $bytes = $size * $count;
        if (($bytePos % 4) === 0) $bytePos = $dataPos;
        if (($wordPos % 4) === 0) $wordPos = $dataPos;
        if ($bytes === 1) {
            $raw = substr($input, $bytePos, 1); $bytePos += 1;
        } elseif ($bytes === 2) {
            $raw = substr($input, $wordPos, 2); $wordPos += 2;
        } else {
            $raw = substr($input, $dataPos, $bytes); $dataPos += $bytes; $dataPos = self::align4($dataPos);
        }
        $trail = max($bytePos, $wordPos);
        if ($dataPos < $trail) $dataPos = self::align4($trail);
        return $raw;
    }

    private static function appendAligned(string &$data, string $raw, int $bytes, int &$bytePos, int &$wordPos): void
    {
        if (($bytePos % 4) === 0) $bytePos = strlen($data);
        if (($wordPos % 4) === 0) $wordPos = strlen($data);
        if ($bytes === 1) {
            if (($bytePos % 4) === 0) $data .= "\0\0\0\0";
            self::writeAt($data, $bytePos, $raw); $bytePos++;
        } elseif ($bytes === 2) {
            if (($wordPos % 4) === 0) $data .= "\0\0\0\0";
            self::writeAt($data, $wordPos, $raw); $wordPos += 2;
        } else {
            $data .= $raw;
            while ((strlen($data) % 4) !== 0) $data .= "\0";
        }
    }

    private static function packName(string $name, string &$out, string $encoding, bool $compressed): void
    {
        if ($compressed) { self::packSixBit($name, $out); return; }
        $raw = self::fromUtf8($name, $encoding);
        $out .= chr((strlen($raw) - 1) | 0x40) . $raw;
    }

    private static function unpackName(string $input, int &$pos, string $encoding): string
    {
        $len = (ord($input[$pos++]) & ~0x40) + 1;
        $raw = substr($input, $pos, $len); $pos += $len;
        return self::toUtf8($raw, $encoding);
    }

    private static function packSixBit(string $name, string &$out): void
    {
        $len = strlen($name);
        if ($len > 255) throw new \RuntimeException('kbin name too long');
        $out .= chr($len);
        $acc = 0; $bits = 0;
        for ($i=0; $i<$len; $i++) {
            $v = strpos(self::CHARMAP, $name[$i]);
            if ($v === false) throw new \RuntimeException('Name cannot be six-bit encoded: ' . $name);
            $acc = ($acc << 6) | $v; $bits += 6;
            while ($bits >= 8) { $bits -= 8; $out .= chr(($acc >> $bits) & 0xFF); }
        }
        if ($bits > 0) $out .= chr(($acc << (8-$bits)) & 0xFF);
    }

    private static function unpackSixBit(string $input, int &$pos): string
    {
        $len = ord($input[$pos++]);
        $need = intdiv($len * 6 + 7, 8);
        $raw = substr($input, $pos, $need); $pos += $need;
        $out = ''; $acc = 0; $bits = 0; $idx = 0;
        while (strlen($out) < $len) {
            while ($bits < 6 && $idx < strlen($raw)) { $acc = ($acc << 8) | ord($raw[$idx++]); $bits += 8; }
            $bits -= 6;
            $out .= self::CHARMAP[($acc >> $bits) & 0x3F];
        }
        return $out;
    }

    private static function grabAutoString(string $input, int &$pos, string $encoding): string
    {
        $len = self::readU32($input, $pos);
        $raw = substr($input, $pos, $len); $pos += $len; $pos = self::align4($pos);
        return self::toUtf8(rtrim($raw, "\0"), $encoding);
    }

    /** @return list<int|float|string> */
    private static function unpackValues(string $raw, array $fmt, int $count): array
    {
        $out = []; $size = $fmt['size'];
        for ($i=0; $i<$count; $i++) {
            $chunk = substr($raw, $i*$size, $size);
            if (($fmt['float'] ?? false) === true) {
                $u = unpack($size === 4 ? 'G' : 'E', $chunk); $out[] = (float)$u[1]; continue;
            }
            if ($size === 1) $v = ord($chunk[0] ?? "\0");
            elseif ($size === 2) $v = unpack('n', $chunk)[1];
            elseif ($size === 4) $v = self::u32($chunk);
            else $v = self::u64String($chunk);
            if (($fmt['signed'] ?? false) === true && is_int($v)) {
                $bits = $size*8; if ($bits < PHP_INT_SIZE*8 && $v >= (1 << ($bits-1))) $v -= (1 << $bits);
            }
            $out[] = $v;
        }
        return $out;
    }

    private static function packValues(array $tokens, array $fmt): string
    {
        $out=''; $size=$fmt['size'];
        foreach ($tokens as $token) {
            if (($fmt['float'] ?? false) === true) { $out .= pack($size===4?'G':'E', (float)$token); continue; }
            $v = (int)$token;
            if ($size===1) $out .= chr($v & 0xFF);
            elseif ($size===2) $out .= pack('n', $v & 0xFFFF);
            elseif ($size===4) $out .= pack('N', $v & 0xFFFFFFFF);
            else $out .= self::packU64($token);
        }
        return $out;
    }

    private static function typeIds(): array
    {
        if (self::$typeIds !== null) return self::$typeIds;
        $m=[]; foreach (self::FORMATS as $id=>$fmt) $m[$fmt['name']]=$id;
        $m += ['string'=>11,'binary'=>10,'b'=>52,'f'=>14,'d'=>15,'vs64'=>22,'vu64'=>23,'vs32'=>40,'vu32'=>41,'vf'=>44,'vd'=>25];
        return self::$typeIds=$m;
    }

    private static function encodingName(int $key): string
    {
        return match($key) {0x00,0x80=>'CP932',0x20=>'ASCII',0x40=>'ISO-8859-1',0x60=>'EUC-JP',0xA0=>'UTF-8',default=>throw new \RuntimeException('Unknown kbin encoding')};
    }
    private static function encodingKey(string $encoding): int
    {
        $e=strtoupper(str_replace('_','-',$encoding));
        return match($e) {'CP932','SJIS','SHIFT-JIS'=>0x80,'ASCII'=>0x20,'ISO-8859-1','LATIN1'=>0x40,'EUC-JP'=>0x60,'UTF-8','UTF8'=>0xA0,default=>0xA0};
    }
    private static function toUtf8(string $raw,string $encoding): string
    { return strtoupper($encoding)==='UTF-8' ? $raw : (function_exists('mb_convert_encoding') ? mb_convert_encoding($raw,'UTF-8',$encoding) : $raw); }
    private static function fromUtf8(string $s,string $encoding): string
    { return strtoupper($encoding)==='UTF-8' ? $s : (function_exists('mb_convert_encoding') ? mb_convert_encoding($s,$encoding,'UTF-8') : $s); }
    private static function safeXmlName(string $name): string
    { return preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]*$/u',$name) ? $name : '_' . preg_replace('/[^A-Za-z0-9_.:-]/u','_',$name); }
    private static function align4(int $v): int { return ($v+3)&~3; }
    private static function readU32(string $s,int &$pos): int { $v=self::u32(substr($s,$pos,4)); $pos+=4; return $v; }
    private static function u32(string $s): int { $u=unpack('N',str_pad($s,4,"\0")); return (int)$u[1]; }
    private static function writeAt(string &$s,int $off,string $raw): void { for($i=0;$i<strlen($raw);$i++) $s[$off+$i]=$raw[$i]; }
    private static function u64String(string $s): string
    { $u=unpack('Nhi/Nlo',str_pad($s,8,"\0")); return sprintf('%.0f', $u['hi']*4294967296.0+$u['lo']); }
    private static function packU64(string|int $v): string
    { $f=(float)$v; $hi=(int)floor($f/4294967296.0); $lo=(int)fmod($f,4294967296.0); return pack('NN',$hi,$lo); }
}
