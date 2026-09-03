<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Mahjong\ScoreMath;

$out=['ranks'=>[],'payments'=>[]];
foreach([20,25,30,40,50,60,70,80,90,100,110] as $fu){
    for($han=1;$han<=15;$han++){
        foreach([0,1,2,3] as $yakuman){
            $out['ranks'][]=[
                'han'=>$han,'fu'=>$fu,'yakuman'=>$yakuman,
                'base'=>ScoreMath::baseScore($han,$fu),
                'rank'=>ScoreMath::hanRank($han,$fu,$yakuman),
            ];
        }
    }
}
foreach([20,25,30,40,50,60,70,80,90,100,110] as $fu){
    for($rank=1;$rank<=12;$rank++){
        for($taku=0;$taku<=3;$taku++){
            foreach([false,true] as $oya)foreach([false,true] as $tsumo){
                $p=ScoreMath::payments($taku,$rank,$fu,$oya,$tsumo);
                $out['payments'][]=[
                    'taku'=>$taku,'rank'=>$rank,'fu'=>$fu,
                    'oya'=>$oya,'tsumo'=>$tsumo,
                    'base'=>ScoreMath::baseScoreRank($rank,$fu),
                    'total'=>$p['total'],'ko'=>$p['ko'],'oya_payment'=>$p['oya'],
                ];
            }
        }
    }
}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
