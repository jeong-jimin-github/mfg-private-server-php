<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $p='Mfg\\';if(!str_starts_with($class,$p))return;$r=substr($class,strlen($p));require __DIR__.'/../src/'.str_replace('\\','/',$r).'.php';
});

use Mfg\Eamuse\Dispatcher;
use Mfg\Storage\Database;

function eb_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$db=new Database('sqlite::memory:');
$d=new Dispatcher($db,'http://127.0.0.1:8080');
$model='VFG:J:A:A:2025122300';

$xml=$d->dispatch($model,'facility','get',new SimpleXMLElement('<call/>'));
$r=new SimpleXMLElement($xml);$f=$r->facility;
eb_ok((string)$f['status']==='0','facility status');
eb_ok((string)$f->location->id==='VFG00001'&&(string)$f->location->id['__type']==='str','location id');
eb_ok((string)$f->location->country==='JP'&&(string)$f->location->region==='13','country/region');
eb_ok((string)$f->location->type==='0'&&(string)$f->location->type['__type']==='u8','location type');
eb_ok((string)$f->location->countryname==='Japan'&&(string)$f->location->countryjname==='日本','country names');
eb_ok((string)$f->location->regionname==='Tokyo'&&(string)$f->location->regionjname==='東京都','region names');
eb_ok((string)$f->location->customercode==='VFG'&&(string)$f->location->companycode==='00','facility codes');
eb_ok((string)$f->line->id==='0'&&(string)$f->line->class==='1','line');
eb_ok((string)$f->portfw->globalip==='127.0.0.1'&&(string)$f->portfw->globalip['__type']==='ip4','global ip');
eb_ok((string)$f->portfw->globalport==='8080'&&(string)$f->portfw->privateport==='8080','ports');
eb_ok((string)$f->public->flag==='1'&&(string)$f->public->name==='LOCAL TEST','public');
eb_ok((string)$f->share->eacoin->supplylimit==='100000','eacoin supply');
foreach(['eapass','arcadefan','konaminetdx','konamiid','eagate'] as $tag)eb_ok((string)$f->share->url->{$tag}==='http://127.0.0.1:8080','url '.$tag);

$xml=$d->dispatch($model,'vfgac','service_list',new SimpleXMLElement('<call/>'));
$r=new SimpleXMLElement($xml);$v=$r->vfgac;
eb_ok((string)$v['status']==='0','vfgac status');
eb_ok((string)$v->service_url==='http://127.0.0.1:8080/aog'&&(string)$v->service_url['__type']==='str','nested service url');
eb_ok(count($v->services->item)===2,'nested services');
eb_ok((string)$v->services->item[0]['service']==='front'&&(string)$v->services->item[1]['service']==='game','nested service names');
eb_ok((string)$r->service_url==='http://127.0.0.1:8080/aog','root service url duplicate');
eb_ok(count($r->services->item)===2,'root services duplicate');

echo "eamuse bootstrap parity OK\n";
