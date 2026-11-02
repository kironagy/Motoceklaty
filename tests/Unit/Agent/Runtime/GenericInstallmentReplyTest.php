<?php

namespace Tests\Unit\Agent\Runtime;

use App\Agent\Runtime\GenericInstallmentReply;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GenericInstallmentReplyTest extends TestCase
{
    #[DataProvider('bareQuestions')]
    public function test_bare_installment_question_asks_for_the_model(string $question): void
    {
        $this->assertSame(
            ['messages' => ['القسط بيتحسب على حسب الموديل. بتسأل على قسط أنهي موديل؟']],
            (new GenericInstallmentReply)->for($question),
        );
    }

    public function test_richer_request_continues_to_the_agent(): void
    {
        $reply = new GenericInstallmentReply;

        $this->assertNull($reply->for('عايز قسط من غير مقدم'));
        $this->assertNull($reply->for('عايز موتوسيكل للدليفري قسط'));
        $this->assertNull($reply->for('هوجن 4 قسط كام'));
    }

    public static function bareQuestions(): array
    {
        return [
            ['عايز اعرف تفاصيل القسط'],
            ['تفاصيل التقسيط'],
            ['نظام القسط ايه؟'],
        ];
    }
}
