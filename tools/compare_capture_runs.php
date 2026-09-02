<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Debug\CaptureComparator;

$args=$argv;array_shift($args);$strict=false;
$args=array_values(array_filter($args,static function(string $arg)use(&$strict):bool{if($arg==='--values'){$strict=true;return false;}return true;}));
if(count($args)!==2){
    fwrite(STDERR,"Usage: php tools/compare_capture_runs.php [--values] <python-run-dir> <php-run-dir>\n");
    exit(2);
}
$result=(new CaptureComparator())->compare($args[0],$args[1],$strict);
echo 'reference responses: '.$result['reference_files']."\n";
echo 'candidate responses: '.$result['candidate_files']."\n";
echo 'paired responses:    '.$result['compared']."\n";
if(!$result['differences']){echo "capture structures match\n";exit(0);}
echo 'differences: '.count($result['differences'])."\n";
foreach($result['differences'] as $line)echo '- '.$line."\n";
exit(1);
