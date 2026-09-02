<?php

declare(strict_types=1);

namespace Mfg\Mahjong;

final class ResultXml
{
    private const DUMMY_NAKI = '<naki0><type>0</type><kantype>0</kantype><pai0><pai_st>0</pai_st><pai>0</pai></pai0><pai1><pai_st>0</pai_st><pai>0</pai></pai1><pai2><pai_st>0</pai_st><pai>0</pai></pai2><pai3><pai_st>0</pai_st><pai>0</pai></pai3></naki0><naki1><type>0</type><kantype>0</kantype><pai0><pai_st>0</pai_st><pai>0</pai></pai0><pai1><pai_st>0</pai_st><pai>0</pai></pai1><pai2><pai_st>0</pai_st><pai>0</pai></pai2><pai3><pai_st>0</pai_st><pai>0</pai></pai3></naki1><naki2><type>0</type><kantype>0</kantype><pai0><pai_st>0</pai_st><pai>0</pai></pai0><pai1><pai_st>0</pai_st><pai>0</pai></pai1><pai2><pai_st>0</pai_st><pai>0</pai></pai2><pai3><pai_st>0</pai_st><pai>0</pai></pai3></naki2><naki3><type>0</type><kantype>0</kantype><pai0><pai_st>0</pai_st><pai>0</pai></pai0><pai1><pai_st>0</pai_st><pai>0</pai></pai1><pai2><pai_st>0</pai_st><pai>0</pai></pai2><pai3><pai_st>0</pai_st><pai>0</pai></pai3></naki3>';

    /** @param array<string,mixed>|null $res @param list<int> $hand */
    public static function yaku(string $tag,?array $res,int $winTile,array $hand):string
    {
        $han=$fu=$dora=$rank=0;$names=[];
        if($res!==null){$han=(int)$res['han'];$fu=(int)$res['fu'];$dora=(int)$res['dora'];$rank=(int)$res['rank'];$names=$res['yaku']??[];}
        $bits=YakuBits::words($names,$dora);
        return '<'.$tag.'>'
            .'<pai>'.Mahjong::idxToPai($winTile).'</pai>'
            .'<yaku_han>'.$rank.'</yaku_han><han_num>'.$han.'</han_num><fu_num>'.$fu.'</fu_num>'
            .'<dora_num>'.$dora.'</dora_num><bonus_han>0</bonus_han>'
            .'<yaku1>'.$bits['low'].'</yaku1><yaku2>'.$bits['high'].'</yaku2>'
            .self::ints('tepai',array_map([Mahjong::class,'idxToPai'],$hand))
            .self::DUMMY_NAKI.'</'.$tag.'>';
    }

    /** @param list<int> $before @param list<int> $yaku @param list<int> $kyotaku @param list<int> $tsumifu */
    public static function calcScores(array $before,array $yaku,array $kyotaku,array $tsumifu):string
    {
        $out='';
        for($i=0;$i<4;$i++){
            $b=(int)($before[$i]??0);$y=(int)($yaku[$i]??0);$k=(int)($kyotaku[$i]??0);$t=(int)($tsumifu[$i]??0);
            $out.='<calc_score'.$i.'><before_score>'.$b.'</before_score><yaku_score>'.$y.'</yaku_score>'
                .'<kyotaku_score>'.$k.'</kyotaku_score><tumifu_score>'.$t.'</tumifu_score>'
                .'<new_score>'.($b+$y+$k+$t).'</new_score><wherefore>0</wherefore></calc_score'.$i.'>';
        }
        return $out;
    }

    /** @param list<int> $values */
    public static function ints(string $tag,array $values):string
    {
        $values=$values?:[0];
        return '<'.$tag.' __count="'.count($values).'">'.implode(' ',array_map('intval',$values)).'</'.$tag.'>';
    }
}
