<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Runtime\AgentRunner;
use App\Models\AiTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rebuild step 1: the AI writes the reply, PHP checks only facts and
 * actions. One repair at most; the AI's words are never cut or rewritten.
 */
class ReplyLoopTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeLimits();
        config(['agent.guard.number_min_value' => 1000]);
    }

    public function test_wording_is_the_ai_s_choice_and_is_sent_as_written(): void
    {
        $conversation = $this->conversation();
        // formal words, a repeated question, English - style, not truth
        $reply = 'حقك عليا يا باشا، هل تحب أقولك الـ options اللي عندنا؟';
        $fake = $this->fakeAi();
        $fake->queue($this->sendReply($reply));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation, 'ممكن تساعدني'));

        $this->assertSame([$reply], $result['messages']);
        $this->assertCount(1, $fake->requests());
        $this->assertSame([], AiTrace::sole()->guard_events);
    }

    public function test_a_false_fact_gets_one_repair_with_a_short_reason(): void
    {
        $conversation = $this->conversation();
        $fake = $this->fakeAi();
        $fake->queue($this->sendReply('السعر 99999 جنيه'));
        $fake->queue($this->sendReply('قولي الموديل اللي عايزه وأقولك سعره'));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation, 'بكام'));

        $this->assertSame(['قولي الموديل اللي عايزه وأقولك سعره'], $result['messages']);
        $feedback = collect($fake->requests()[1]->contents)->last(fn ($c) => ($c['role'] ?? null) === 'tool')['parts'][0]['result']['error'];
        $this->assertSame('UNVERIFIED_NUMBER', $feedback['code']);
        $this->assertLessThan(160, mb_strlen($feedback['detail']));
    }

    public function test_a_second_false_fact_is_never_cut_and_never_argued_with(): void
    {
        $conversation = $this->conversation();
        $fake = $this->fakeAi();
        $fake->queue($this->sendReply('السعر 99999 جنيه. تحب تقسط؟'));
        $fake->queue($this->sendReply('السعر 88888 جنيه. تحب تقسط؟'));
        $fake->queue($this->sendReply('مش هيتنادى'));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation, 'بكام'));

        $this->assertCount(2, $fake->requests());
        $this->assertNotSame(['تحب تقسط؟'], $result['messages'], 'no sentence of the AI is deleted');
        $this->assertStringNotContainsString('88888', implode(' ', $result['messages']));
        $this->assertSame('fallback', AiTrace::sole()->status);
    }

    public function test_only_technical_cleanup_touches_the_text(): void
    {
        $conversation = $this->conversation();
        $this->fakeAi()->queue($this->sendReply('**تمام** يا باشا 👍 — نكمل؟'));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation, 'تمام'));

        $this->assertSame(['تمام يا باشا، نكمل؟'], $result['messages']);
    }
}
