<?php

namespace Tests\Unit;

use App\Agent\Tools\StartApplicationTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TalksAboutWorkTest extends TestCase
{
    public static function work(): array
    {
        return [
            ['انا صاحب مطعم'], ['عندي كافيه'], ['عندي سوبر ماركت'], ['انا سباك'], ['نجار'],
            ['بشتغل نقاش'], ['عندي فرن عيش'], ['صاحب مكتب عقارات'], ['انا مبيض محارة'], ['بقال'],
            ['عندي عربية فول'], ['شغال في مطعم'], ['موظف في شركة'], ['على المعاش'], ['سواق ميكروباص'],
            // refused live 2026-10-01: not in any list
            ['مدير يا فندم'], ['معاه طلباط'], ['انا شغال شيف'], ['كوافير و ماسك ادراة الصالون'], ['ممثله في مسرح'],
        ];
    }

    #[DataProvider('work')]
    public function test_recognises_a_stated_occupation(string $quote): void
    {
        $this->assertTrue(StartApplicationTool::talksAboutWork($quote), $quote);
    }

    public static function notWork(): array
    {
        return [['عايز اقسط'], ['طب لا خليها تقسيط بس المقدم 30 الف'], ['تمام يلا'], ['ماشي'], ['عايز اقدم'],
            ['هوجن فرز تاني'], ['الفيجوري ال 250 على سنتين'], ['حبيبي'], ['طيب خلاص']];
    }

    #[DataProvider('notWork')]
    public function test_an_intent_alone_is_not_an_occupation(string $quote): void
    {
        $this->assertFalse(StartApplicationTool::talksAboutWork($quote), $quote);
    }
}
