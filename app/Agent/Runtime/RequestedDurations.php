<?php

namespace App\Agent\Runtime;

use App\Support\ArabicTextNormalizer;

class RequestedDurations
{
    public function months(string $text): array
    {
        $text = ArabicTextNormalizer::normalize($text);
        $months=[];
        foreach (['/سنه\s*و\s*نص|18\s*شهر/u'=>18,'/سنتين\s*و\s*نص|30\s*شهر/u'=>30,'/سنتين|24\s*شهر/u'=>24,'/(?:3|ثلاث|تلات)\s*سنين|36\s*شهر/u'=>36] as $pattern=>$duration) {
            if (preg_match($pattern,$text)) { $months[]=$duration; $text=preg_replace($pattern,' ',$text); }
        }
        if(preg_match('/سنه|12\s*شهر/u',$text))$months[]=12;
        sort($months);
        return array_values(array_unique($months));
    }
}
