<?php

namespace App\Agent\Runtime;

use App\Support\ArabicTextNormalizer;

class TalabatWorkContext
{
    public function isWorkStatement(string $text): bool
    {
        $text = ArabicTextNormalizer::normalize($text);
        if (preg_match('/طلبات\s*(?:تقسيط|التقسيط|تقديم|التقديم)/u',$text)) return false;
        return (bool) preg_match('/(?:شغال|بشتغل|شغل|كابتن|مندوب|موظف|توصيل|هشتغل).{0,35}(?<!\p{L})(?:طلبات|talabat)(?!\p{L})/iu',$text);
    }
}
