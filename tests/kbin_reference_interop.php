<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Protocol\KBinXml;

function ki_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

function ki_fixture():string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<call model="VFG:J:A:A:2025122300" srcid="00010203040506070809">'
        .'<interop method="roundtrip" area="東京都">'
        .'<title __type="str">麻雀ファイトガール</title>'
        .'<small __type="u8">7</small>'
        .'<signed __type="s64">-9223372036854775808</signed>'
        .'<unsigned __type="u64">18446744073709551615</unsigned>'
        .'<pair __type="2s64">-9007199254740993 9007199254740993</pair>'
        .'<globalip __type="ip4">127.0.0.1</globalip>'
        .'</interop></call>';
}

function ki_assert_xml(string $xml):void
{
    $doc=new DOMDocument();
    ki_ok(@$doc->loadXML($xml,LIBXML_NONET),'interop XML invalid');
    $root=$doc->documentElement;ki_ok($root instanceof DOMElement&&$root->tagName==='call','root');
    ki_ok($root->getAttribute('model')==='VFG:J:A:A:2025122300','model attr');
    $interop=$doc->getElementsByTagName('interop')->item(0);ki_ok($interop instanceof DOMElement,'interop node');
    ki_ok($interop->getAttribute('method')==='roundtrip','method attr');
    ki_ok($interop->getAttribute('area')==='東京都','Japanese attr');
    ki_ok($doc->getElementsByTagName('title')->item(0)?->textContent==='麻雀ファイトガール','Japanese string');
    ki_ok($doc->getElementsByTagName('small')->item(0)?->textContent==='7','u8');
    ki_ok($doc->getElementsByTagName('signed')->item(0)?->textContent==='-9223372036854775808','s64');
    ki_ok($doc->getElementsByTagName('unsigned')->item(0)?->textContent==='18446744073709551615','u64');
    ki_ok($doc->getElementsByTagName('pair')->item(0)?->textContent==='-9007199254740993 9007199254740993','2s64');
    ki_ok($doc->getElementsByTagName('globalip')->item(0)?->textContent==='127.0.0.1','ip4');
}

$mode=$argv[1]??'';$path=$argv[2]??'';
ki_ok($path!=='','usage: php kbin_reference_interop.php emit|check FILE');
if($mode==='emit'){
    $bin=KBinXml::encode(ki_fixture(),'UTF-8',true);
    ki_ok(file_put_contents($path,$bin)!==false,'cannot write PHP fixture');
    $meta=KBinXml::decode($bin);ki_ok($meta['encoding']==='UTF-8'&&$meta['compressed']===true,'PHP fixture metadata');
    ki_assert_xml($meta['xml']);
    echo "PHP KBin fixture emitted\n";
    exit(0);
}
if($mode==='check'){
    $bin=file_get_contents($path);ki_ok($bin!==false,'cannot read Python fixture');
    $meta=KBinXml::decode($bin);ki_ok($meta['encoding']==='UTF-8','Python fixture encoding');
    ki_ok($meta['compressed']===true,'Python fixture compression');
    ki_assert_xml($meta['xml']);
    echo "Python kbinxml fixture decoded by PHP\n";
    exit(0);
}
throw new RuntimeException('unknown mode '.$mode);
