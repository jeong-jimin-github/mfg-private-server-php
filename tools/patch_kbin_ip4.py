from pathlib import Path

p = Path('src/Protocol/KBinXml.php')
s = p.read_text(encoding='utf-8')

old_decode = '''            if ($type === 10) {
                $el->setAttribute('__size', (string)strlen($raw));
                $el->nodeValue = bin2hex($raw);
            } elseif ($type === 11) {
                $raw = rtrim($raw, "\\0");
                $el->nodeValue = self::toUtf8($raw, $encoding);
            } else {
                $values = self::unpackValues($raw, $fmt, $total);
                $el->nodeValue = implode(' ', array_map(static fn($v) => is_float($v) ? number_format($v, 6, '.', '') : (string)$v, $values));
            }
'''
new_decode = '''            if ($type === 10) {
                $el->setAttribute('__size', (string)strlen($raw));
                $el->nodeValue = bin2hex($raw);
            } elseif ($type === 11) {
                $raw = rtrim($raw, "\\0");
                $el->nodeValue = self::toUtf8($raw, $encoding);
            } elseif ($type === 12) {
                $ips = [];
                for ($i=0; $i<$total; $i++) {
                    $chunk = substr($raw, $i*4, 4);
                    $ip = strlen($chunk) === 4 ? @inet_ntop($chunk) : false;
                    if ($ip === false) throw new \\RuntimeException('Invalid kbin ip4 payload');
                    $ips[] = $ip;
                }
                $el->nodeValue = implode(' ', $ips);
            } else {
                $values = self::unpackValues($raw, $fmt, $total);
                $el->nodeValue = implode(' ', array_map(static fn($v) => is_float($v) ? number_format($v, 6, '.', '') : (string)$v, $values));
            }
'''

old_encode = '''            if ($id === 10) $raw = hex2bin(preg_replace('/\\s+/', '', $text) ?? '') ?: '';
            elseif ($id === 11) $raw = self::fromUtf8($text, $encoding) . "\\0";
            else {
                $tokens = preg_split('/\\s+/', trim($text)) ?: [];
                if ($tokens === ['']) $tokens = [];
                $raw = self::packValues($tokens, $fmt);
            }
'''
new_encode = '''            if ($id === 10) $raw = hex2bin(preg_replace('/\\s+/', '', $text) ?? '') ?: '';
            elseif ($id === 11) $raw = self::fromUtf8($text, $encoding) . "\\0";
            elseif ($id === 12) {
                $tokens = preg_split('/\\s+/', trim($text)) ?: [];
                if ($tokens === ['']) $tokens = [];
                $raw = '';
                foreach ($tokens as $token) {
                    $packed = @inet_pton($token);
                    if ($packed === false || strlen($packed) !== 4) throw new \\RuntimeException('Invalid IPv4 address: ' . $token);
                    $raw .= $packed;
                }
            } else {
                $tokens = preg_split('/\\s+/', trim($text)) ?: [];
                if ($tokens === ['']) $tokens = [];
                $raw = self::packValues($tokens, $fmt);
            }
'''

if s.count(old_decode) != 1:
    raise SystemExit(f'decode patch anchor count={s.count(old_decode)}')
if s.count(old_encode) != 1:
    raise SystemExit(f'encode patch anchor count={s.count(old_encode)}')

s = s.replace(old_decode, new_decode).replace(old_encode, new_encode)
p.write_text(s, encoding='utf-8')
print('patched KBin ip4 encode/decode')
