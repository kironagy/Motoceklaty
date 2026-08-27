<?php

namespace Tests\Unit;

use App\Support\TransientAiFailurePolicy;
use PHPUnit\Framework\TestCase;

/**
 * Conversation 253: the AI call failed transiently, so the bot sent
 * "ثواني يا فندم، هراجعلك التفاصيل وأرد عليك." - and then nothing. There
 * was no follow-up behind that sentence: the queued job was marked done
 * on the strength of the placeholder, so the retry the customer was
 * promised never existed. He waited eight minutes and pinged again
 * himself.
 *
 * A transient failure is a reason to run the turn again, or to put a
 * human on it - never a reason to promise a message nobody will send.
 */
class TransientAiFailurePolicyTest extends TestCase
{
    public function test_the_first_failure_is_retried(): void
    {
        $this->assertSame(TransientAiFailurePolicy::RETRY, TransientAiFailurePolicy::actionFor(1));
    }

    public function test_a_repeated_failure_goes_to_a_human(): void
    {
        $this->assertSame(TransientAiFailurePolicy::HANDOFF, TransientAiFailurePolicy::actionFor(2));
        $this->assertSame(TransientAiFailurePolicy::HANDOFF, TransientAiFailurePolicy::actionFor(5));
    }

    public function test_a_nonsense_count_still_resolves_to_an_action(): void
    {
        $this->assertSame(TransientAiFailurePolicy::RETRY, TransientAiFailurePolicy::actionFor(0));
    }
}
