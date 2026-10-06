<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Context\ContextBuilder;
use App\Agent\Context\TokenEstimator;
use App\Agent\Providers\AiResponse;
use App\Jobs\SummarizeConversation;
use App\Models\AiTrace;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Rebuild CTX-006 (history window by real tokens, newest first) and CTX-010 (structured summary). */
class HistoryWindowTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private function longConversation(int $count)
    {
        $conversation = $this->conversation();

        foreach (range(1, $count) as $i) {
            WhatsappMessage::create([
                'whatsapp_bot_id' => $conversation->whatsapp_bot_id, 'whatsapp_conversation_id' => $conversation->id,
                'direction' => $i % 2 ? 'incoming' : 'outgoing', 'sender_type' => $i % 2 ? 'customer' : 'bot', 'type' => 'text',
                'text' => "رسالة رقم {$i} عن الموتوسيكل والقسط والمقدم", 'wa_message_id' => uniqid('wa'),
            ]);
        }

        return $conversation;
    }

    public function test_a_long_conversation_keeps_the_newest_messages_within_the_token_budget(): void
    {
        Queue::fake();
        config(['agent.context.recent_messages_tokens' => 300, 'agent.summary.trigger_tokens' => 200, 'agent.summary.trigger_messages' => null]);
        $conversation = $this->longConversation(200);
        $turn = $this->turnFor($conversation, 'طيب');

        $request = app(ContextBuilder::class)->build($turn);

        $history = array_slice($request->contents, 0, -1);
        $texts = array_map(fn ($c) => $c['parts'][0]['text'], $history);
        $tokens = array_sum(array_map(fn ($t) => TokenEstimator::estimate($t), $texts));

        $this->assertLessThan(200, count($history));
        $this->assertLessThanOrEqual(300, $tokens);
        // newest first: the window ends with the last message before this turn, in order
        $this->assertSame('رسالة رقم 200 عن الموتوسيكل والقسط والمقدم', end($texts));
        $numbers = array_map(fn ($t) => (int) preg_replace('/\D/', '', $t), $texts);
        $this->assertSame(range(201 - count($history), 200), $numbers);
        $this->assertTrue(AiTrace::sole()->context_manifest['l7_trimmed']);

        // what fell out (well over 200 tokens) goes to the summary
        Queue::assertPushed(SummarizeConversation::class, fn ($job) => $job->upToMessageId === WhatsappMessage::where('text', $texts[0])->value('id'));
    }

    public function test_a_small_backlog_does_not_trigger_the_summary(): void
    {
        Queue::fake();
        config(['agent.context.recent_messages_tokens' => 300, 'agent.summary.trigger_tokens' => 100000, 'agent.summary.trigger_messages' => null]);
        $conversation = $this->longConversation(60);

        app(ContextBuilder::class)->build($this->turnFor($conversation, 'طيب'));

        Queue::assertNotPushed(SummarizeConversation::class);
    }

    public function test_the_window_has_a_default_budget(): void
    {
        $this->assertSame(4000, (int) config('agent.context.recent_messages_tokens'));
    }

    public function test_the_summary_is_facts_decisions_and_what_is_still_open(): void
    {
        $conversation = $this->longConversation(6);
        $fake = $this->fakeAi();
        $fake->queue(new AiResponse([json_encode([
            'facts' => ['شغال سواق في شركة نقل', 'من الجيزة'],
            'decisions' => ['اختار الهوجن 4 على سنة'],
            'still_open' => ['هيبعت ضهر البطاقة'],
        ], JSON_UNESCAPED_UNICODE)], [], 'STOP', [], 'gemini-test', null, 1));

        (new SummarizeConversation($conversation->id, WhatsappMessage::max('id') + 1))->handle($fake);

        $this->assertSame('object', $fake->requests()[0]->responseSchema['type']);
        $this->assertSame(['شغال سواق في شركة نقل', 'من الجيزة'], SummarizeConversation::decode($conversation->refresh()->summary)['facts']);

        $system = app(ContextBuilder::class)->build($this->turnFor($conversation, 'تمام'))->system;
        $this->assertStringContainsString("حقايق عنه:\n- شغال سواق في شركة نقل\n- من الجيزة", $system);
        $this->assertStringContainsString("اتفقنا على:\n- اختار الهوجن 4 على سنة", $system);
        $this->assertStringContainsString("لسه مفتوح:\n- هيبعت ضهر البطاقة", $system);
    }

    public function test_an_empty_or_prose_answer_keeps_the_old_summary(): void
    {
        $conversation = $this->longConversation(4);
        $conversation->update(['summary' => 'ملخص قديم نثر', 'summary_until_message_id' => 1]);
        $fake = $this->fakeAi();
        $fake->queue(new AiResponse(['العميل بيسأل عن الأسعار.'], [], 'STOP', [], 'gemini-test', null, 1));

        (new SummarizeConversation($conversation->id, WhatsappMessage::max('id') + 1))->handle($fake);

        $this->assertSame('ملخص قديم نثر', $conversation->refresh()->summary);
        // a prose summary from before still reaches the model as it is
        $this->assertStringContainsString("## ملخص المحادثة السابقة\nملخص قديم نثر", app(ContextBuilder::class)->build($this->turnFor($conversation, 'تمام'))->system);
    }
}
