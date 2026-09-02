<?php

declare(strict_types=1);

namespace Mfg\Debug;

/**
 * Optional request/response capture writer for real arcade-client comparison.
 *
 * Disabled unless VFG_CAPTURE_DIR points at a writable run directory. The
 * directory is deliberately supplied by the operator instead of being created
 * implicitly under the web root, so production shared hosting stays capture-
 * free by default and each test session can have an explicit immutable folder.
 */
final class CaptureStore
{
    private ?string $dir;

    public function __construct(?string $dir=null)
    {
        $value=$dir;
        if($value===null){$env=getenv('VFG_CAPTURE_DIR');$value=$env===false?'':$env;}
        $value=trim((string)$value);
        $this->dir=$value!==''?rtrim($value,"/\\"):null;
    }

    public function enabled(): bool{return $this->dir!==null;}

    public function saveText(string $kind,string $name,string $body,string $ext='xml'): ?string
    {
        if($this->dir===null)return null;
        $folder=$this->dir.DIRECTORY_SEPARATOR.$this->safe($kind);
        $this->ensureDir($folder);
        $seq=$this->nextSeq();
        $path=$folder.DIRECTORY_SEPARATOR.sprintf('%04d_%s.%s',$seq,$this->safe($name),$this->safeExt($ext));
        if(@file_put_contents($path,$body,LOCK_EX)===false)throw new \RuntimeException('Cannot write capture: '.$path);
        return $path;
    }

    public function saveBinary(string $kind,string $name,string $body,string $ext='bin'): ?string
    {
        return $this->saveText($kind,$name,$body,$ext);
    }

    /** @param array<string,mixed> $meta */
    public function saveJson(string $kind,string $name,array $meta): ?string
    {
        return $this->saveText($kind,$name,json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).'\n','json');
    }

    private function nextSeq(): int
    {
        if($this->dir===null)return 0;
        $this->ensureDir($this->dir);
        $path=$this->dir.DIRECTORY_SEPARATOR.'.seq';
        $fh=@fopen($path,'c+');
        if($fh===false)throw new \RuntimeException('Cannot open capture sequence: '.$path);
        try{
            if(!flock($fh,LOCK_EX))throw new \RuntimeException('Cannot lock capture sequence: '.$path);
            rewind($fh);$raw=stream_get_contents($fh);$seq=max(0,(int)trim((string)$raw))+1;
            rewind($fh);ftruncate($fh,0);fwrite($fh,(string)$seq);fflush($fh);flock($fh,LOCK_UN);
            return $seq;
        }finally{fclose($fh);}
    }

    private function ensureDir(string $dir): void
    {
        if(is_dir($dir))return;
        if(!@mkdir($dir,0775,true)&&!is_dir($dir))throw new \RuntimeException('Cannot create capture directory: '.$dir);
    }

    private function safe(string $name): string
    {
        $name=preg_replace('/[^A-Za-z0-9_.-]+/','_',$name)??'';
        $name=trim($name,'._-');return $name!==''?$name:'unknown';
    }

    private function safeExt(string $ext): string
    {
        $ext=preg_replace('/[^A-Za-z0-9]+/','',$ext)??'';return $ext!==''?$ext:'dat';
    }
}
