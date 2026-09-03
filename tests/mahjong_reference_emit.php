<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'Mfg\\'))return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use Mfg\Mahjong\Mahjong;

/** @return list<int> */
function mr_hand(int $taku,int $index):array{
    $live=Mahjong::liveKinds($taku);$counts=array_fill(0,34,0);$hand=[];
    $state=(0x1234567 + $taku*0x2345 + $index*0x10101) & 0x7fffffff;
    while(count($hand)<13){
        $state=(int)((1103515245*$state+12345)&0x7fffffff);
        $tile=$live[$state%count($live)];
        if($counts[$tile]>=4)continue;
        $counts[$tile]++;$hand[]=$tile;
    }
    sort($hand);return $hand;
}

$out=['taku'=>[],'samples'=>[],'tile_roundtrip'=>[]];
for($taku=0;$taku<=3;$taku++){
    $live=Mahjong::liveKinds($taku);$dora=[];
    foreach($live as $idx)$dora[(string)$idx]=Mahjong::doraFromIndicator($idx,$taku);
    $out['taku'][(string)$taku]=[
        'seats'=>Mahjong::SEATS_OF[$taku],
        'kyoku'=>Mahjong::KYOKU_COUNT[$taku],
        'start_score'=>Mahjong::START_SCORE[$taku],
        'live'=>$live,
        'dora'=>$dora,
    ];
    for($i=0;$i<64;$i++){
        $hand=mr_hand($taku,$i);$counts=Mahjong::countsOf($hand);
        $out['samples'][]=[
            'taku'=>$taku,
            'hand'=>$hand,
            'shanten'=>Mahjong::shanten($counts,0,$taku),
            'waits'=>Mahjong::waitsOf($counts,0,$taku),
            'improves'=>Mahjong::ukeire($counts,0,$taku),
        ];
    }
}
for($idx=0;$idx<34;$idx++){
    $pai=Mahjong::idxToPai($idx);
    $out['tile_roundtrip'][]=[$idx,$pai,Mahjong::paiToIdx($pai),Mahjong::paiToIdx($pai+64)];
}

echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
