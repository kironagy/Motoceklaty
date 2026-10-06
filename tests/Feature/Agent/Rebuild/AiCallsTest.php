<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Providers\AiRequest;
use App\Agent\Providers\GeminiProvider;
use App\Agent\Providers\OpenAiProvider;
use App\Agent\Runtime\AgentTurnProcessor;
use App\Agent\Tracing\AiCalls;
use App\Models\AiCall;
use App\Models\AiTrace;
use App\Models\AiUsageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Rebuild OBS-001 / OBS-002 / OBS-003. */
class AiCallsTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeLimits();
        config(['agent.model' => 'gemini-test', 'agent.fallback_models' => '']);
    }

    private function geminiText(string $text, int $in = 100, int $out = 7): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => $in, 'cachedContentTokenCount' => 40, 'candidatesTokenCount' => $out, 'thoughtsTokenCount' => 3, 'totalTokenCount' => $in + $out],
        ];
    }

    public function test_every_attempt_is_a_row_and_the_successful_ones_sum_to_the_usage_log(): void
    {
        $this->keyModel(name: 'A', priority: 1);
        $this->keyModel(name: 'B', priority: 2);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            return ++$calls === 1
                ? Http::response(['error' => ['message' => 'Resource has been exhausted']], 429)
                : Http::response($this->geminiText('ok'), 200);
        });

        AiCalls::within(['turn_id' => 77, 'conversation_id' => 5], function () {
            AiCalls::setTrace(9, 'v1');
            (new GeminiProvider())->chat(new AiRequest(contents: [['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'hi']]]], label: 'work'));
            $this->assertSame(2, AiCalls::countInTurn());
        });

        $rows = AiCall::orderBy('id')->get();
        $this->assertSame(['http_429', 'ok'], $rows->pluck('outcome')->all());
        $this->assertSame([1, 2], $rows->pluck('attempt')->all());
        $this->assertSame([77, 77], $rows->pluck('turn_id')->all());
        $this->assertSame([9, 9], $rows->pluck('trace_id')->all());
        $this->assertSame('work', $rows[1]->label);
        $this->assertSame(['input' => 100, 'cached' => 40, 'output' => 7, 'reasoning' => 3],
            ['input' => $rows[1]->input_tokens, 'cached' => $rows[1]->cached_tokens, 'output' => $rows[1]->output_tokens, 'reasoning' => $rows[1]->reasoning_tokens]);

        $ok = AiCall::where('outcome', 'ok');
        $this->assertSame((int) AiUsageLog::sum('input_tokens'), (int) $ok->sum('input_tokens'));
        $this->assertSame((int) AiUsageLog::sum('output_tokens'), (int) $ok->sum('output_tokens'));
        $this->assertSame(AiUsageLog::count(), $ok->count());
    }

    public function test_openai_calls_record_reasoning_tokens(): void
    {
        $this->keyModel('openai', 'gpt-5-mini');
        Http::fake(['*api.openai.com*' => Http::response([
            'choices' => [['message' => ['content' => 'تمام'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 30, 'total_tokens' => 80,
                'prompt_tokens_details' => ['cached_tokens' => 10], 'completion_tokens_details' => ['reasoning_tokens' => 20]],
        ], 200)]);

        (new OpenAiProvider())->chatWith(new AiRequest(contents: [['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'hi']]]], label: 'main'), 'gpt-5-mini');

        $row = AiCall::sole();
        $this->assertSame(['openai', 'main', 50, 10, 20], [$row->provider, $row->label, $row->input_tokens, $row->cached_tokens, $row->reasoning_tokens]);
        $this->assertNull($row->turn_id);
    }

    public function test_a_simulated_turn_has_a_row_for_every_call_and_the_trace_counts_them(): void
    {
        $this->keyModel();
        $conversation = $this->conversation();
        $turn = $this->turnFor($conversation, 'عايز موتوسيكل');
        Http::fake(['*generativelanguage.googleapis.com*' => Http::response([
            'candidates' => [['content' => ['parts' => [['functionCall' => ['id' => 'c1', 'name' => 'send_reply', 'args' => ['messages' => ['وعليكم السلام، تحت أمرك']]]]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 900, 'candidatesTokenCount' => 12, 'totalTokenCount' => 912],
        ], 200)]);

        $result = app(AgentTurnProcessor::class)->process($turn);

        $this->assertSame(['وعليكم السلام، تحت أمرك'], $result['messages']);
        $trace = AiTrace::where('turn_id', $turn->id)->sole();
        $row = AiCall::sole();
        $this->assertSame([$trace->id, (int) $turn->id, 'main', 'v1'], [$row->trace_id, $row->turn_id, $row->label, $row->runner]);
        $this->assertSame(1, $trace->context_manifest['ai_calls']['total'] ?? null);
        $this->assertSame(['main' => 1], $trace->context_manifest['ai_calls']['by_label'] ?? null);
    }

    public function test_capture_is_off_by_default_and_masks_phones_and_ids_when_on(): void
    {
        $this->keyModel();
        Http::fake(['*' => Http::response($this->geminiText('رقمك 01147709597 تمام'), 200)]);
        $request = new AiRequest(system: 'sys', contents: [['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'رقمي ٠١١٢٨٨٨٤٧١٥ وبطاقتي 29901011234567']]]]);

        (new GeminiProvider())->chat($request);
        $this->assertNull(AiCall::sole()->capture);

        config(['agent.observability.capture.enabled' => true, 'agent.observability.capture.sample_rate' => 1.0]);
        (new GeminiProvider())->chat($request);

        $captured = json_encode(AiCall::latest('id')->first()->capture, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('01147709597', $captured);
        $this->assertStringNotContainsString('29901011234567', $captured);
        $this->assertStringContainsString('[PHONE]', $captured);
        $this->assertStringContainsString('[NATIONAL_ID]', $captured);
        $this->assertStringContainsString('sys', $captured);
    }
}
