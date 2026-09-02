<?php

declare(strict_types=1);

namespace Mfg\Debug;

use DOMDocument;
use DOMElement;
use DOMNode;

final class CaptureComparator
{
    /**
     * @return array{reference_files:int,candidate_files:int,compared:int,differences:list<string>}
     */
    public function compare(string $referenceDir,string $candidateDir,bool $compareValues=false): array
    {
        $ref=$this->collectXml($referenceDir.DIRECTORY_SEPARATOR.'responses');
        $cand=$this->collectXml($candidateDir.DIRECTORY_SEPARATOR.'responses');
        $diff=[];$compared=0;
        $names=array_values(array_unique(array_merge(array_keys($ref),array_keys($cand))));sort($names,SORT_STRING);
        foreach($names as $name){
            $a=$ref[$name]??[];$b=$cand[$name]??[];
            if(count($a)!==count($b))$diff[]=$name.': response count '.count($a).' != '.count($b);
            $n=min(count($a),count($b));
            for($i=0;$i<$n;$i++){
                $compared++;
                try{$sa=$this->xmlSignature($a[$i],$compareValues);$sb=$this->xmlSignature($b[$i],$compareValues);}
                catch(\Throwable $e){$diff[]=$name.' #'.($i+1).': XML parse error '.$e->getMessage();continue;}
                foreach($this->arrayDiff($sa,$sb) as $line)$diff[]=$name.' #'.($i+1).': '.$line;
            }
        }

        $refMeta=$this->collectJson($referenceDir.DIRECTORY_SEPARATOR.'transport');
        $candMeta=$this->collectJson($candidateDir.DIRECTORY_SEPARATOR.'transport');
        $metaNames=array_values(array_unique(array_merge(array_keys($refMeta),array_keys($candMeta))));sort($metaNames,SORT_STRING);
        foreach($metaNames as $name){
            $a=$refMeta[$name]??[];$b=$candMeta[$name]??[];
            if(count($a)!==count($b))$diff[]='transport '.$name.': metadata count '.count($a).' != '.count($b);
            $n=min(count($a),count($b));
            for($i=0;$i<$n;$i++){
                $ma=$this->transportMeta($a[$i]);$mb=$this->transportMeta($b[$i]);
                if($ma!==$mb)$diff[]='transport '.$name.' #'.($i+1).': '.json_encode($ma,JSON_UNESCAPED_SLASHES).' != '.json_encode($mb,JSON_UNESCAPED_SLASHES);
            }
        }

        return [
            'reference_files'=>array_sum(array_map('count',$ref)),
            'candidate_files'=>array_sum(array_map('count',$cand)),
            'compared'=>$compared,
            'differences'=>$diff,
        ];
    }

    /** @return array<string,list<string>> */
    private function collectXml(string $dir): array
    {
        $out=[];if(!is_dir($dir))return $out;
        $files=glob($dir.DIRECTORY_SEPARATOR.'*.xml')?:[];sort($files,SORT_STRING);
        foreach($files as $file){$name=preg_replace('/^\d+_/','',basename($file,'.xml'))??basename($file,'.xml');$out[$name][]=$file;}
        return $out;
    }

    /** @return array<string,list<string>> */
    private function collectJson(string $dir): array
    {
        $out=[];if(!is_dir($dir))return $out;
        $files=glob($dir.DIRECTORY_SEPARATOR.'*.json')?:[];sort($files,SORT_STRING);
        foreach($files as $file){$name=preg_replace('/^\d+_/','',basename($file,'.json'))??basename($file,'.json');$out[$name][]=$file;}
        return $out;
    }

    /** @return list<string> */
    private function xmlSignature(string $file,bool $compareValues): array
    {
        $doc=new DOMDocument();$old=libxml_use_internal_errors(true);
        try{if(!$doc->load($file,LIBXML_NONET|LIBXML_NOBLANKS))throw new \RuntimeException('invalid XML '.basename($file));}
        finally{libxml_clear_errors();libxml_use_internal_errors($old);}
        if(!$doc->documentElement)throw new \RuntimeException('missing document element '.basename($file));
        $out=[];$this->walk($doc->documentElement,'',$out,$compareValues);return $out;
    }

    /** @param list<string> $out */
    private function walk(DOMElement $el,string $parent,array &$out,bool $compareValues): void
    {
        $index=1;
        for($p=$el->previousSibling;$p;$p=$p->previousSibling)if($p instanceof DOMElement&&$p->tagName===$el->tagName)$index++;
        $path=$parent.'/'.$el->tagName.'['.$index.']';
        $attrs=[];
        foreach($el->attributes as $attr){$value=$attr->name==='__type'||$compareValues?'='.$attr->value:'';$attrs[]=$attr->name.$value;}
        sort($attrs,SORT_STRING);
        $text='';
        foreach($el->childNodes as $child)if($child->nodeType===DOMNode::TEXT_NODE)$text.=trim($child->nodeValue??'');
        $row=$path.' attrs{'.implode(',',$attrs).'}';
        if($text!=='')$row.=$compareValues?' text='.json_encode($text,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):' text=#';
        $out[]=$row;
        foreach($el->childNodes as $child)if($child instanceof DOMElement)$this->walk($child,$path,$out,$compareValues);
    }

    /** @param list<string> $a @param list<string> $b @return list<string> */
    private function arrayDiff(array $a,array $b): array
    {
        if($a===$b)return [];$out=[];$n=max(count($a),count($b));
        for($i=0;$i<$n;$i++){
            $x=$a[$i]??'<missing>';$y=$b[$i]??'<missing>';
            if($x!==$y)$out[]='signature line '.($i+1).' '.$x.' != '.$y;
            if(count($out)>=30){$out[]='more differences omitted';break;}
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function transportMeta(string $file): array
    {
        $raw=file_get_contents($file);$j=$raw===false?null:json_decode($raw,true);if(!is_array($j))return ['invalid'=>true];
        $keys=['x_compress','used_kbin','kbin_encoding','kbin_compressed','module','method'];$out=[];
        foreach($keys as $key)if(array_key_exists($key,$j))$out[$key]=$j[$key];
        return $out;
    }
}
