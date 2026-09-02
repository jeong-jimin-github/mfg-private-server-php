<?php

declare(strict_types=1);

namespace Mfg\Mahjong;

/** MFG.Types.Yaku bit layout used by the Unity client. */
final class YakuBits
{
    /** @var array<string,int> */
    private const INDEX = [
        'Tenho'=>0,'Chiho'=>1,'Renho'=>2,'Tyuren9'=>3,'TyurenTanki'=>4,
        'Chinroto'=>5,'Tsuiso'=>6,'Ryuiso'=>7,'Kokushi13'=>8,'KokushiTanki'=>9,
        'Sukantsu'=>10,'Suanko'=>11,'SuankoTanki'=>12,'Daisushi'=>13,'Syosushi'=>14,
        'Daisangen'=>15,'Daisyarin'=>16,'Surenko'=>17,'Parenchan'=>18,'Kazoeyakuman'=>19,
        'Chiniso'=>20,'Honroto'=>21,'Syosangen'=>22,'Nagashimangan'=>23,'Shisanputo'=>24,
        'Junchan'=>25,'Ryanpeko'=>26,'Honiso'=>27,'DoubleRichi'=>28,'Sankantsu'=>29,
        'Sananko'=>30,'Toitoiho'=>31,'Chitoitsu'=>32,'Sansyokudoko'=>33,
        'Sansyokudojun'=>34,'Chanta'=>35,'Ikkitsukan'=>36,'Sanrenko'=>37,
        'Haitei'=>38,'Hotei'=>39,'Chankan'=>40,'Rinsyan'=>41,'Ipeiko'=>42,
        'Tanyao'=>43,'Pinfu'=>44,'Richi'=>45,'Ippatsu'=>46,'Menzen'=>47,
        'Haku'=>48,'Hatsu'=>49,'Tyun'=>50,'Bakaze'=>51,'Jikaze'=>52,'Dora'=>53,
        'ChinisoNaki'=>54,'JunchanNaki'=>55,'HonisoNaki'=>56,
        'SansyokudojunNaki'=>57,'ChantaNaki'=>58,'IkkitsukanNaki'=>59,
    ];

    /** @param list<string> $yaku */
    public static function mask(array $yaku,int $dora=0): int
    {
        $bits=0;
        foreach($yaku as $name){
            $i=self::INDEX[$name]??null;
            if($i!==null)$bits|=(1<<$i);
        }
        if($dora>0)$bits|=(1<<self::INDEX['Dora']);
        return $bits;
    }

    /** @param list<string> $yaku @return array{low:int,high:int} */
    public static function words(array $yaku,int $dora=0):array
    {
        $bits=self::mask($yaku,$dora);
        return ['low'=>$bits & 0xFFFFFFFF,'high'=>($bits >> 32) & 0xFFFFFFFF];
    }
}
