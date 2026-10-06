<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Context\TokenEstimator;
use App\Agent\Runtime\AgentRunner;
use App\Domain\Monitoring\ReplyQuality;
use App\Models\AiCall;
use App\Models\AiTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Rebuild OBS-004 / OBS-005. */
class ObservabilityTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeLimits();
    }

    public function test_a_silent_rewrite_is_logged_with_before_and_after_but_is_not_a_problem(): void
    {
        $conversation = $this->conversation();
        $this->fakeAi()->queue($this->sendReply('تمام — هبعتلك التفاصيل'));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation, 'ممكن التفاصيل'));

        $this->assertSame(['تمام، هبعتلك التفاصيل'], $result['messages']);
        $event = collect(AiTrace::sole()->guard_events)->firstWhere('code', 'REWRITE:tidy');
        $this->assertSame(['تمام — هبعتلك التفاصيل'], $event['args']['before']);
        $this->assertSame(['تمام، هبعتلك التفاصيل'], $event['args']['after']);

        $this->assertSame(1, app(ReplyQuality::class)->summary(now()->subDay())['clean']);
    }

    public function test_an_unchanged_reply_logs_no_rewrite(): void
    {
        $conversation = $this->conversation();
        $this->fakeAi()->queue($this->sendReply('تحت أمرك'));

        app(AgentRunner::class)->run($this->turnFor($conversation, 'ازيك'));

        $this->assertSame([], AiTrace::sole()->guard_events);
    }

    public function test_the_estimate_uses_the_ratio_the_provider_really_billed(): void
    {
        Cache::flush();
        $this->assertSame(4.0, TokenEstimator::charsPerToken('openai'));
        $this->assertSame(25, TokenEstimator::estimate(str_repeat('ب', 100), 'openai'));

        // 25 real calls: 2.5 Arabic characters per billed token
        foreach (range(1, 25) as $i) {
            AiCall::create(['label' => 'main', 'provider' => 'openai', 'model' => 'gpt-5-mini', 'outcome' => 'ok', 'prompt_chars' => 25000, 'input_tokens' => 10000]);
        }
        AiCall::create(['label' => 'document', 'provider' => 'openai', 'model' => 'gpt-5-mini', 'outcome' => 'ok', 'prompt_chars' => null, 'input_tokens' => 90000]);
        Cache::flush();

        $this->assertSame(2.5, TokenEstimator::charsPerToken('openai'));
        $this->assertSame(40, TokenEstimator::estimate(str_repeat('ب', 100), 'openai'));
        $this->assertSame(4.0, TokenEstimator::charsPerToken('gemini'));
    }
}
