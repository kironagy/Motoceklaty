<?php

namespace Tests\Unit;

use App\Services\AiReplyPhraser;
use PHPUnit\Framework\TestCase;

/**
 * The guard is the whole point of plan task 2.4: the model may reword a
 * money reply, but it may never change what the numbers say. These cover
 * rejectionReason() directly - no Gemini call involved.
 */
class AiReplyPhraserTest extends TestCase
{
    private AiReplyPhraser $phraser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phraser = new AiReplyPhraser();
    }

    public function test_accepts_a_reword_that_keeps_every_number(): void
    {
        $this->assertNull($this->phraser->rejectionReason(
            'قسطها هيبقى 3,200 جنيه في الشهر على 12 شهر يا فندم.',
            'القسط الشهري 3,200 جنيه لمدة 12 شهر.'
        ));
    }

    public function test_accepts_arabic_indic_digits_as_the_same_number(): void
    {
        $this->assertNull($this->phraser->rejectionReason(
            'القسط ٣٢٠٠ جنيه على ١٢ شهر.',
            'القسط 3200 جنيه لمدة 12 شهر.'
        ));
    }

    public function test_rejects_an_invented_number(): void
    {
        $reason = $this->phraser->rejectionReason(
            'القسط 3,200 جنيه على 12 شهر، والمقدم 5000 جنيه.',
            'القسط الشهري 3,200 جنيه لمدة 12 شهر.'
        );

        $this->assertNotNull($reason);
        $this->assertStringStartsWith('invented_numbers', $reason);
    }

    public function test_rejects_a_dropped_number(): void
    {
        $reason = $this->phraser->rejectionReason(
            'القسط الشهري حلو جدًا على 12 شهر يا فندم.',
            'القسط الشهري 3,200 جنيه لمدة 12 شهر.'
        );

        $this->assertNotNull($reason);
        $this->assertStringStartsWith('dropped_numbers', $reason);
    }

    public function test_does_not_confuse_thousand_separators_with_digits(): void
    {
        $this->assertNull($this->phraser->rejectionReason(
            'سعرها كاش 39500 جنيه.',
            'سعرها كاش 39,500 جنيه.'
        ));
    }

    public function test_rejects_a_melted_bullet_list(): void
    {
        $deterministic = "الأسعار كاش:\n- دايو ٤: 39,500 جنيه\n- دايو ٤ اصلي: 46,000 جنيه";

        $reason = $this->phraser->rejectionReason(
            'دايو ٤ بـ 39,500 جنيه ودايو ٤ اصلي بـ 46,000 جنيه.',
            $deterministic
        );

        $this->assertNotNull($reason);
        $this->assertStringStartsWith('dropped_fragment', $reason);
    }

    public function test_rejects_an_empty_reply(): void
    {
        $this->assertSame('empty', $this->phraser->rejectionReason('   ', 'القسط 3200 جنيه.'));
    }

    public function test_rejects_a_must_keep_fragment_that_disappeared(): void
    {
        $reason = $this->phraser->rejectionReason(
            'المكنة دي سعرها كاش 39,500 جنيه.',
            'هوجن جامبو سعرها كاش 39,500 جنيه.',
            ['must_keep' => ['هوجن جامبو']]
        );

        $this->assertSame('dropped_fragment:هوجن جامبو', $reason);
    }

    /**
     * Machine names carry Arabic-Indic digits ("دايو ٤"), so the digit in
     * the name is guarded exactly like a price: dropping it or attaching it
     * to the wrong model is a real error, not a wording choice.
     */
    public function test_a_digit_inside_a_machine_name_is_guarded_too(): void
    {
        $reason = $this->phraser->rejectionReason(
            'دايو سعرها كاش 39,500 جنيه.',
            'دايو ٤ سعرها كاش 39,500 جنيه.'
        );

        $this->assertSame('dropped_numbers:4', $reason);
    }
}
