<?php

namespace Tests\Unit;

use App\Services\ApplicantNameValidator;
use App\Services\GeminiClient;
use PHPUnit\Framework\TestCase;

/**
 * "الاسم بالكامل" used to be satisfied by any non-empty string, so a
 * two-word answer closed the field and the request reached staff with a
 * name that could not be matched against the ID card.
 *
 * The structural layer tested here is deliberately the one that answers
 * the common case, without a model call: the model is only consulted once
 * a name is already long enough to be a full name.
 */
class ApplicantNameValidatorTest extends TestCase
{
    /**
     * A stub standing in for the plausibility model, so these tests never
     * touch the network. `$isName` is what the model would answer.
     */
    private function validator(bool $isName = true, ?int &$calls = null): ApplicantNameValidator
    {
        $calls = 0;

        $gemini = new class($isName, $calls) extends GeminiClient {
            public function __construct(private bool $isName, private int &$calls)
            {
            }

            public function generateText(string $prompt, ?string $preferredModelCode = 'gemini-3.1-flash-lite', array $options = []): array
            {
                $this->calls++;

                return [
                    'ok' => true,
                    'reply' => json_encode([
                        'is_name' => $this->isName,
                        'question' => $this->isName ? null : 'ابعتلي اسم حضرتك زي ما هو في البطاقة.',
                    ], JSON_UNESCAPED_UNICODE),
                ];
            }
        };

        return new ApplicantNameValidator($gemini);
    }

    public function test_triple_name_is_accepted(): void
    {
        $result = $this->validator()->validate('كيرلس ناجي فهيم');

        $this->assertSame('ok', $result['status']);
        $this->assertNull($result['message']);
    }

    public function test_two_part_name_is_incomplete(): void
    {
        $result = $this->validator()->validate('أحمد محمد');

        $this->assertSame('incomplete', $result['status']);
        $this->assertSame('too_few_parts', $result['reason']);
        $this->assertNotNull($result['message']);
    }

    public function test_single_name_is_incomplete(): void
    {
        $this->assertSame('incomplete', $this->validator()->validate('كيرلس')['status']);
    }

    /**
     * A structurally short name is settled without spending a model call -
     * the model cannot change the answer, since "أحمد محمد" is a real name
     * that simply is not the full one.
     */
    public function test_short_name_does_not_call_the_model(): void
    {
        $validator = $this->validator(true, $calls);
        $validator->validate('أحمد محمد');

        $this->assertSame(0, $calls);
    }

    public function test_lead_in_words_are_stripped_before_counting_parts(): void
    {
        // "اسمي" must not count as a name part, otherwise this two-part
        // name would pass as a triple one.
        $result = $this->validator()->validate('اسمي أحمد محمد');

        $this->assertSame('incomplete', $result['status']);
        $this->assertSame('أحمد محمد', $result['name']);
    }

    /**
     * "عبد الرحمن" is one name part, not two - counting the particle
     * separately would let a two-part name through as a triple one.
     */
    public function test_compound_first_name_counts_as_one_part(): void
    {
        $result = $this->validator()->validate('عبد الرحمن محمد');

        $this->assertSame('incomplete', $result['status']);
        $this->assertCount(2, $result['parts']);
    }

    public function test_compound_name_with_third_part_is_accepted(): void
    {
        $result = $this->validator()->validate('عبد الرحمن محمد السيد');

        $this->assertSame('ok', $result['status']);
    }

    public function test_name_containing_digits_is_invalid(): void
    {
        $result = $this->validator()->validate('احمد 123 محمد');

        $this->assertSame('invalid', $result['status']);
        $this->assertSame('contains_digits', $result['reason']);
    }

    public function test_model_rejection_marks_the_name_invalid(): void
    {
        $result = $this->validator(false)->validate('سسسس ككككك ممممم');

        $this->assertSame('invalid', $result['status']);
        $this->assertSame('not_a_name', $result['reason']);
        $this->assertNotNull($result['message']);
    }

    /**
     * A model outage must never read as "your name is rejected".
     */
    public function test_model_failure_falls_back_to_accepting_the_name(): void
    {
        $gemini = new class extends GeminiClient {
            public function __construct()
            {
            }

            public function generateText(string $prompt, ?string $preferredModelCode = 'gemini-3.1-flash-lite', array $options = []): array
            {
                return ['ok' => false];
            }
        };

        $result = (new ApplicantNameValidator($gemini))->validate('محمد أحمد إبراهيم');

        $this->assertSame('ok', $result['status']);
    }
}
