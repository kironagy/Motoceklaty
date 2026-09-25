<?php

namespace Tests\Unit;

use App\Support\ArabicTextNormalizer;
use PHPUnit\Framework\TestCase;

class ArabicTextNormalizerTest extends TestCase
{
    public function test_tatweel_in_a_catalog_name_does_not_stop_it_matching(): void
    {
        // the catalog stores "فيجــوري 3" / "بلسـر ١٨٠"; customers type them plainly
        $this->assertSame(ArabicTextNormalizer::normalize('فيجوري 3'), ArabicTextNormalizer::normalize('فيجــوري 3'));
        $this->assertSame(ArabicTextNormalizer::normalize('بلسر 180'), ArabicTextNormalizer::normalize('بلسـر ١٨٠'));
    }

    public function test_diacritics_are_ignored(): void
    {
        $this->assertSame(ArabicTextNormalizer::normalize('هوجن'), ArabicTextNormalizer::normalize('هُوجَن'));
    }
}
