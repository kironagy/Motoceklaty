<?php

namespace Tests\Unit\Agent\Runtime;

use App\Agent\Runtime\TalabatWorkContext;
use PHPUnit\Framework\TestCase;

class TalabatWorkContextTest extends TestCase
{
    public function test_work_phrases_are_not_application_followups(): void
    {
        foreach (['شغال في طلبات','شغال طلبات','صاحب كافيه وشغل في شركة طلبات','كنت شغال ف طلبات','هشتغل في طلبات','انا كابتن talabat'] as $text) {
            $this->assertTrue((new TalabatWorkContext)->isWorkStatement($text),$text);
        }
    }

    public function test_application_questions_are_not_employment(): void
    {
        foreach (['طلبي وصل فين','طلبات التقسيط ايه','ايه المطلوب في الطلبات','شغال على طلبات التقسيط'] as $text) {
            $this->assertFalse((new TalabatWorkContext)->isWorkStatement($text),$text);
        }
    }
}
