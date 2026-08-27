<?php

namespace Tests\Unit;

use App\Services\ApplicantNameValidator;
use PHPUnit\Framework\TestCase;

/**
 * Second layer under the fix in ApplicationStateService::fieldsToAsk().
 *
 * Even with the turn held on the rejected field, extraction is an LLM
 * call and can still come back empty for a bare name - it did exactly
 * that in conversation 254 when the conversation history pointed at a
 * different question. When the previous turn's open question WAS the
 * full name, a short text answer is the answer to it; reading it
 * deterministically means the customer never has to send the same name
 * a third time because a model call shrugged.
 *
 * This is a structural read only. Whether the text is really a person's
 * name is still validate()'s job.
 */
class ApplicantNameRecoveryTest extends TestCase
{
    private function validator(): ApplicantNameValidator
    {
        return new ApplicantNameValidator();
    }

    public function test_a_bare_name_answer_is_recovered(): void
    {
        $this->assertSame(
            'احمد سيد احمد عبداللاه',
            $this->validator()->recoverNameAnswer('احمد سيد احمد عبداللاه')
        );
    }

    public function test_lead_in_words_are_stripped_on_recovery(): void
    {
        $this->assertSame(
            'بدر احمد سيد احمد عبداللاه',
            $this->validator()->recoverNameAnswer('اسم بدر احمد سيد احمد عبداللاه')
        );
    }

    public function test_a_message_with_digits_is_not_a_name_answer(): void
    {
        $this->assertNull($this->validator()->recoverNameAnswer('29001011234567'));
        $this->assertNull($this->validator()->recoverNameAnswer('01200268302'));
    }

    public function test_a_question_is_not_a_name_answer(): void
    {
        $this->assertNull($this->validator()->recoverNameAnswer('ليه محتاج الاسم؟'));
        $this->assertNull($this->validator()->recoverNameAnswer('؟؟'));
    }

    public function test_a_long_sentence_is_not_a_name_answer(): void
    {
        $this->assertNull($this->validator()->recoverNameAnswer(
            'انا عايز اعرف المكنة دي متوفرة عندكم ولا لأ وكمان عايز اعرف القسط بيبدأ من كام'
        ));
    }

    public function test_empty_text_is_not_a_name_answer(): void
    {
        $this->assertNull($this->validator()->recoverNameAnswer('   '));
    }
}
