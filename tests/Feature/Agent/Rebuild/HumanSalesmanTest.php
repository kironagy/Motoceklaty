<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Context\ContextBuilder;
use App\Agent\Runtime\AgentRunner;
use App\Agent\Tools\SearchMotorcyclesTool;
use App\Agent\Tools\ToolContext;
use App\Agent\Runtime\TurnResultBuilder;
use App\Models\AiTrace;
use App\Models\Brand;
use App\Models\Machine;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner 2026-10-06: every version of a model he names, every question in
 * his message answered, no tool going round, no canned line twice.
 */
class HumanSalesmanTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeLimits();
    }

    private function hogan3Versions(): array
    {
        $brand = Brand::create(['name' => 'Haojiang', 'image' => 'b.jpg']);
        $base = ['brand_id' => $brand->id, 'installment_price' => 0, 'cc' => 150, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal'];

        return [
            Machine::create($base + ['name' => 'هوجن 3 استراد 75%', 'aliases' => ['هوجن 3'], 'cash_price' => 90000]),
            Machine::create($base + ['name' => 'هوجن 3 استراد بالكامل', 'aliases' => ['هوجن 3'], 'cash_price' => 98000]),
        ];
    }

    public function test_a_model_with_several_versions_lists_every_version_with_its_price(): void
    {
        [$partly, $fully] = $this->hogan3Versions();
        $conversation = $this->conversation();
        $ctx = new ToolContext($conversation->customer_id, $conversation->id, null, 1, 1, new TurnResultBuilder());

        $result = app(SearchMotorcyclesTool::class)->execute(['name_query' => 'هوجن 3'], $ctx);

        $this->assertTrue($result->ok);
        $this->assertEqualsCanonicalizing([$partly->id, $fully->id], array_column($result->data['versions'], 'id'));
        $this->assertEqualsCanonicalizing([90000, 98000], array_map('floatval', array_column($result->data['versions'], 'cash_price')));
    }

    public function test_a_bare_brand_is_not_a_list_of_versions(): void
    {
        $this->hogan3Versions();
        $conversation = $this->conversation();
        $ctx = new ToolContext($conversation->customer_id, $conversation->id, null, 1, 1, new TurnResultBuilder());

        $result = app(SearchMotorcyclesTool::class)->execute(['name_query' => 'Haojiang'], $ctx);

        $this->assertArrayNotHasKey('versions', $result->data);
    }

    public function test_the_same_lookup_twice_makes_the_next_step_a_reply(): void
    {
        $conversation = $this->conversation();
        $fake = $this->fakeAi();
        $search = fn () => $this->reply([['id' => uniqid('c'), 'name' => 'search_motorcycles', 'args' => ['name_query' => 'هوجن 3']]]);
        $fake->queue($search());
        $fake->queue($search());
        $fake->queue($this->sendReply('قولي تقصد أنهي موديل بالظبط؟'));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation, 'هوجن 3'));

        $this->assertSame(['قولي تقصد أنهي موديل بالظبط؟'], $result['messages']);
        $this->assertSame(['send_reply'], $fake->requests()[2]->allowedTools);
        $this->assertContains('LOOP_BROKEN', array_column(AiTrace::sole()->guard_events, 'code'));
    }

    public function test_a_message_with_several_questions_is_marked_to_answer_them_all(): void
    {
        $conversation = $this->conversation();
        $turn = $this->turnFor($conversation, 'بكام الهوجن 3 وفين الفرع؟');

        $texts = collect(app(ContextBuilder::class)->build($turn)->contents)
            ->flatMap(fn ($c) => collect($c['parts'])->where('type', 'text')->pluck('text'))->implode("\n");

        $this->assertStringContainsString('أكتر من سؤال', $texts);
    }

    public function test_a_single_question_gets_no_note(): void
    {
        $conversation = $this->conversation();
        $turn = $this->turnFor($conversation, 'بكام الهوجن 3؟');

        $texts = collect(app(ContextBuilder::class)->build($turn)->contents)
            ->flatMap(fn ($c) => collect($c['parts'])->where('type', 'text')->pluck('text'))->implode("\n");

        $this->assertStringNotContainsString('أكتر من سؤال', $texts);
    }

    public function test_the_keep_going_line_is_never_the_one_he_just_got(): void
    {
        config(['agent.handoff.waiting_message' => 'وصلتني رسالتك، زميلي هيرد عليك.', 'agent.guard.number_min_value' => 1000]);
        $conversation = $this->conversation();
        WhatsappMessage::create([
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id, 'whatsapp_conversation_id' => $conversation->id,
            'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'text', 'text' => 'معلش وضّحلي قصدك أكتر؟',
        ]);
        $fake = $this->fakeAi();
        $fake->queue($this->sendReply('السعر 99999 جنيه'));
        $fake->queue($this->sendReply('السعر 88888 جنيه'));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation, 'بكام'));

        $this->assertNotSame(['معلش وضّحلي قصدك أكتر؟'], $result['messages']);
        $this->assertStringNotContainsString('88888', implode(' ', $result['messages']));
    }
}
