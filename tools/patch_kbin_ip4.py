from pathlib import Path

p = Path('src/Protocol/KBinXml.php')
s = p.read_text(encoding='utf-8')

old = '''            if ($isArray) {
                $raw = substr($input, $dataPos, $total * $fmt['size']);
                $dataPos += strlen($raw);
                $dataPos = self::align4($dataPos);
            } else {
                $raw = self::grabAligned($input, $dataPos, $bytePos, $wordPos, $fmt['size'], $total);
            }
'''
new = '''            if ($isArray) {
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
'''

count = s.count(old)
if count != 1:
    raise SystemExit(f'alignment patch anchor count={count}')

p.write_text(s.replace(old, new), encoding='utf-8')
print('patched KBin variable-data alignment cursors')
