<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Mfg\\')) return;
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use Mfg\Protocol\KBinXml;

function kb_ok(bool $v,string $message):void
{
    if(!$v) throw new RuntimeException($message);
}

function kb_doc(string $xml):DOMDocument
{
    $doc=new DOMDocument();
    kb_ok($doc->loadXML($xml),'decoded XML invalid');
    return $doc;
}

$xml = '<?xml version="1.0" encoding="UTF-8"?><call model="VFG:J:A:A:2025122300" srcid="00010203040506070809"><services method="get"><string __type="str">hello</string><num __type="s32">12345</num><small __type="u8">7</small><globalip __type="ip4">127.0.0.1</globalip><iplist __type="ip4" __count="2">192.168.0.10 10.20.30.40</iplist></services></call>';

foreach ([true, false] as $compressed) {
    $bin = KBinXml::encode($xml, 'UTF-8', $compressed);
    kb_ok(KBinXml::isBinary($bin),'not detected as kbin');
    $decoded = KBinXml::decode($bin);
    kb_ok($decoded['encoding']==='UTF-8','UTF-8 metadata mismatch');
    kb_ok($decoded['compressed'] === $compressed,'compression flag mismatch');
    $doc = kb_doc($decoded['xml']);
    $root = $doc->documentElement;
    kb_ok($root !== null && $root->tagName === 'call','root mismatch');
    kb_ok($root->getAttribute('model') === 'VFG:J:A:A:2025122300','attribute mismatch');
    $services = $root->getElementsByTagName('services')->item(0);
    kb_ok($services instanceof DOMElement && $services->getAttribute('method') === 'get','service method mismatch');
    kb_ok($root->getElementsByTagName('string')->item(0)?->textContent === 'hello','string mismatch');
    kb_ok($root->getElementsByTagName('num')->item(0)?->textContent === '12345','s32 mismatch');
    kb_ok($root->getElementsByTagName('small')->item(0)?->textContent === '7','u8 mismatch');
    $ip=$root->getElementsByTagName('globalip')->item(0);
    kb_ok($ip instanceof DOMElement && $ip->getAttribute('__type')==='ip4','ip4 scalar type');
    kb_ok($ip->textContent==='127.0.0.1','ip4 scalar value');
    $ips=$root->getElementsByTagName('iplist')->item(0);
    kb_ok($ips instanceof DOMElement && $ips->getAttribute('__type')==='ip4','ip4 array type');
    kb_ok($ips->getAttribute('__count')==='2','ip4 array count');
    kb_ok($ips->textContent==='192.168.0.10 10.20.30.40','ip4 array value');
}

// Reproduce the real facility ordering that exposed a decoder cursor bug:
// variable string -> packed u8 -> variable string -> packed u8 -> ip4.  The
// byte/word cursors must advance past each standalone variable-length block or
// globalip is decoded from an older four-byte packing slot.
$facilityLike='<?xml version="1.0" encoding="UTF-8"?><response><facility><location><type __type="u8">0</type><countryjname __type="str">日本</countryjname><accuracy __type="u8">0</accuracy></location><line><id __type="str">0</id><class __type="u8">1</class></line><portfw><globalip __type="ip4">127.0.0.1</globalip><globalport __type="u16">18080</globalport><privateport __type="u16">18080</privateport></portfw></facility></response>';
$facilityDecoded=KBinXml::decode(KBinXml::encode($facilityLike,'UTF-8',true));
$facilityDoc=kb_doc($facilityDecoded['xml']);
kb_ok($facilityDoc->getElementsByTagName('class')->item(0)?->textContent==='1','facility-like packed u8 alignment');
kb_ok($facilityDoc->getElementsByTagName('globalip')->item(0)?->textContent==='127.0.0.1','facility-like ip4 alignment');
kb_ok($facilityDoc->getElementsByTagName('globalport')->item(0)?->textContent==='18080','facility-like u16 alignment');

// Real cabinets use legacy Japanese encodings too. Verify that both string
// payloads and attribute payloads survive the binary format in both node-name
// modes, while the decoder reports the original kbin encoding metadata.
$jpXml='<?xml version="1.0" encoding="UTF-8"?><call model="VFG:J:A:A:2025122300"><message method="get" area="東京都"><title __type="str">麻雀ファイトガール</title><notice __type="str">接続テスト成功</notice></message></call>';
foreach(['CP932','EUC-JP'] as $encoding){
    foreach([true,false] as $compressed){
        $bin=KBinXml::encode($jpXml,$encoding,$compressed);
        $decoded=KBinXml::decode($bin);
        kb_ok($decoded['encoding']===$encoding,$encoding.' metadata mismatch');
        kb_ok($decoded['compressed']===$compressed,$encoding.' compression mismatch');
        $doc=kb_doc($decoded['xml']);
        $msg=$doc->getElementsByTagName('message')->item(0);
        kb_ok($msg instanceof DOMElement,$encoding.' message missing');
        kb_ok($msg->getAttribute('area')==='東京都',$encoding.' Japanese attribute mismatch');
        kb_ok($doc->getElementsByTagName('title')->item(0)?->textContent==='麻雀ファイトガール',$encoding.' Japanese title mismatch');
        kb_ok($doc->getElementsByTagName('notice')->item(0)?->textContent==='接続テスト成功',$encoding.' Japanese notice mismatch');
    }
}

echo "kbin UTF-8/CP932/EUC-JP + IPv4/alignment round-trip OK\n";
