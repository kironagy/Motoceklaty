<?php

namespace Tests\Feature\Agent;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiProviderException;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Agent\Runtime\AgentRunner;
use App\Exceptions\TransientAiFailure;
use App\Models\Brand;
use App\Models\ApplicationData;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\RequirementField;
use App\Models\Handoff;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agent.runtime.max_model_calls' => 5,
            'agent.runtime.max_tool_calls' => 10,
            'agent.runtime.wall_clock_seconds' => 30,
            'agent.fallback.message' => 'حصل عطل بسيط، هرد عليك بس هحول المحادثة لموظف.',
            'agent.handoff.max_failed_turns' => 2,
        ]);
    }

    private function conversation(): WhatsappConversation
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $conversation->update(['customer_id' => $customer->id]);

        return $conversation;
    }

    private function turnFor(WhatsappConversation $conversation): object
    {
        $id = DB::table('whatsapp_message_jobs')->insertGetId([
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
            'whatsapp_conversation_id' => $conversation->id,
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('whatsapp_message_jobs')->find($id);
    }

    private function fake(): FakeAiProvider
    {
        $fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $fake);

        return $fake;
    }

    private function response(array $toolCalls = [], array $textParts = []): AiResponse
    {
        return new AiResponse($textParts, $toolCalls, 'STOP', ['input_tokens' => 10, 'output_tokens' => 5], 'gemini-test', null, 12);
    }

    public function test_single_tool_call_then_reply(): void
    {
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['أهلًا بيك!']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['أهلًا بيك!'], $result['messages']);
    }

    public function test_chained_tool_calls_across_model_calls(): void
    {
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $machine = Machine::create(['name' => 'بوكسر 150', 'brand_id' => $brand->id, 'cash_price' => 50000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);

        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'get_motorcycle_details', 'args' => ['motorcycle_ids' => [$machine->id]]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['سعرها 50000 كاش']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['سعرها 50000 كاش'], $result['messages']);
        $this->assertCount(2, $fake->requests());
    }

    public function test_parallel_tool_calls_in_one_response(): void
    {
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $machine = Machine::create(['name' => 'بوكسر 150', 'brand_id' => $brand->id, 'cash_price' => 50000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);

        $fake = $this->fake();
        $fake->queue($this->response([
            ['id' => 't1', 'name' => 'get_motorcycle_details', 'args' => ['motorcycle_ids' => [$machine->id]]],
            ['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['سعرها 50000 كاش']]],
        ]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['سعرها 50000 كاش'], $result['messages']);
        $this->assertCount(1, $fake->requests());
    }

    public function test_a_price_seen_only_in_the_catalog_index_needs_a_lookup_this_turn(): void
    {
        // Principle 7: the catalog index resolves names; the price quoted to
        // the customer must come from a tool result in the same turn. "Lifan
        // 150 بـ 53,000" was quoted from the index with no lookup at all.
        config(['agent.guard.number_min_value' => 1000]);

        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $machine = Machine::create(['name' => 'بوكسر 150', 'brand_id' => $brand->id, 'cash_price' => 45000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);

        $fake = $this->fake();
        $fake->queue($this->response([
            ['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['بوكسر 150 سعرها كاش 45,000 جنيه']]],
        ]));
        $fake->queue($this->response([
            ['id' => 't2', 'name' => 'get_motorcycle_details', 'args' => ['motorcycle_ids' => [$machine->id]]],
        ]));
        $fake->queue($this->response([
            ['id' => 't3', 'name' => 'send_reply', 'args' => ['messages' => ['بوكسر 150 سعرها كاش 45,000 جنيه']]],
        ]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['بوكسر 150 سعرها كاش 45,000 جنيه'], $result['messages']);
        $this->assertSame('UNVERIFIED_NUMBER', \App\Models\AiTrace::first()->guard_events[0]['code']);
    }

    public function test_a_conditional_clause_is_not_a_claim_of_saving(): void
    {
        // Real conversation 115: "لو غيرت رأيك ... أنا موجود" was blocked as
        // "claimed a save" four times and the customer got the outage notice.
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['ولا يهمك، براحتك خالص. لو غيرت رأيك أنا موجود.']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['ولا يهمك، براحتك خالص. لو غيرت رأيك أنا موجود.'], $result['messages']);
    }

    public function test_claiming_the_application_was_sent_without_a_submission_is_blocked(): void
    {
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['تمام، أكدت إرسال الطلب للمراجعة.']]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['لسه محتاجين نكمل بيانات الطلب قبل ما نبعته.']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['لسه محتاجين نكمل بيانات الطلب قبل ما نبعته.'], $result['messages']);
        $this->assertSame('SUBMISSION_CLAIMED_NOT_DONE', \App\Models\AiTrace::first()->guard_events[0]['code']);
    }

    public function test_offering_photos_is_not_claiming_they_were_sent(): void
    {
        // Live regression: "تحب أبعتلك صورها؟" was blocked twice.
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['موجودة. تحب أبعتلك صورها؟']]]]));

        $this->assertSame(['موجودة. تحب أبعتلك صورها؟'], app(AgentRunner::class)->run($this->turnFor($conversation))['messages']);
    }

    public function test_saying_photos_were_sent_without_sending_them_is_blocked(): void
    {
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['تمام، بعتلك الصور.']]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['تحب أبعتلك الصور؟']]]]));

        $this->assertSame(['تحب أبعتلك الصور؟'], app(AgentRunner::class)->run($this->turnFor($conversation))['messages']);
    }

    public function test_a_claim_guard_does_not_force_an_unrelated_tool_call(): void
    {
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['تمام سجلت كل حاجة']]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['تمام، قولي محتاج إيه تاني']]]]));

        app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame('auto', $fake->lastRequest()->toolMode);
    }

    public function test_a_single_word_internal_key_is_not_sent(): void
    {
        $conversation = $this->conversation();
        RequirementField::create(['key' => 'work_type', 'label' => 'نوع الشغل', 'data_type' => 'enum', 'enum_options' => ['craftsman', 'delivery_app'], 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);

        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['هعتبرك صاحب مهنة (craftsman)']]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['تمام يا أسطى']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['تمام يا أسطى'], $result['messages']);
    }

    public function test_an_unsourced_specification_is_blocked_even_below_the_price_threshold(): void
    {
        // "الدايو 2 بتعمل 40-45 كيلو في اللتر" - no tool said so.
        config(['agent.guard.number_min_value' => 1000]);
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['دي بتعمل 40-45 كيلو في اللتر']]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['هي اقتصادية، تحب أبعتلك تفاصيلها؟']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['هي اقتصادية، تحب أبعتلك تفاصيلها؟'], $result['messages']);
    }

    public function test_a_last_try_without_the_number_keeps_the_customer_with_the_bot(): void
    {
        // Owner: the bot closes ~90% itself - two unsourced numbers no longer hand off.
        config(['agent.handoff.waiting_message' => 'وصلتني رسالتك، زميلي هيرد عليك.']);
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['السعر 99999 جنيه']]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['برضو 88888 جنيه']]]]));
        $fake->queue($this->response([['id' => 't3', 'name' => 'send_reply', 'args' => ['messages' => ['تحب أقولك سعرها بالظبط لو قلتلي أنهي موديل؟']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['تحب أقولك سعرها بالظبط لو قلتلي أنهي موديل؟'], $result['messages']);
        $this->assertNotSame('awaiting_agent', $conversation->fresh()->status);
    }

    public function test_unresolvable_guards_hand_off_with_the_waiting_message_not_the_outage_notice(): void
    {
        config(['agent.handoff.waiting_message' => 'وصلتني رسالتك، زميلي هيرد عليك.']);
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['السعر 99999 جنيه']]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['برضو 88888 جنيه']]]]));
        $fake->queue($this->response([['id' => 't3', 'name' => 'send_reply', 'args' => ['messages' => ['خلاص 77777 جنيه']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['وصلتني رسالتك، زميلي هيرد عليك.'], $result['messages']);
        $this->assertSame('awaiting_agent', $conversation->fresh()->status);
        $this->assertSame(1, Handoff::where('conversation_id', $conversation->id)->count());
    }

    public function test_a_crashing_turn_does_not_leave_its_trace_running(): void
    {
        $conversation = $this->conversation();
        $this->app->instance(AiProvider::class, new class implements AiProvider {
            public function chat(\App\Agent\Providers\AiRequest $request): AiResponse
            {
                throw new \RuntimeException('boom');
            }
        });

        try {
            app(AgentRunner::class)->run($this->turnFor($conversation));
        } catch (\Throwable) {
        }

        $this->assertSame('error', \App\Models\AiTrace::first()->status);
    }

    public function test_limit_reached_forces_send_reply(): void
    {
        config(['agent.runtime.max_model_calls' => 1]);
        $conversation = $this->conversation();

        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'get_business_knowledge', 'args' => ['keys' => ['x']]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['تمام كده']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['تمام كده'], $result['messages']);
        $lastRequest = $fake->lastRequest();
        $this->assertSame('any', $lastRequest->toolMode);
        $this->assertSame(['send_reply'], $lastRequest->allowedTools);
    }

    public function test_unverified_number_is_corrected_on_second_attempt(): void
    {
        $conversation = $this->conversation();
        $fake = $this->fake();
        // 99999 appears nowhere in tool results, L4 or customer messages.
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['السعر 99999 جنيه']]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['تمام، هبعتلك التفاصيل بعدين']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['تمام، هبعتلك التفاصيل بعدين'], $result['messages']);
    }

    public function test_a_persistent_number_violation_falls_back_and_hands_off(): void
    {
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['السعر 99999 جنيه']]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['برضو 88888 جنيه']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['حصل عطل بسيط، هرد عليك بس هحول المحادثة لموظف.'], $result['messages']);
        // max_failed_turns=2, but this is only the 1st failure - no handoff yet.
        $this->assertSame(0, Handoff::where('conversation_id', $conversation->id)->count());
    }

    public function test_duplicate_reply_is_rejected_once(): void
    {
        $conversation = $this->conversation();
        WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing',
            'sender_type' => 'bot', 'type' => 'text', 'text' => 'تمام يا فندم',
        ]);

        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['تمام يا فندم']]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['تمام، حاضر']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['تمام، حاضر'], $result['messages']);
    }

    public function test_retryable_provider_failure_throws_transient_exception(): void
    {
        $conversation = $this->conversation();
        $this->app->instance(AiProvider::class, new class implements AiProvider {
            public function chat(\App\Agent\Providers\AiRequest $request): AiResponse
            {
                throw new AiProviderException('rate limited', retryable: true);
            }
        });

        $this->expectException(TransientAiFailure::class);
        app(AgentRunner::class)->run($this->turnFor($conversation));
    }

    public function test_non_retryable_provider_failure_sends_fallback_and_hands_off_after_threshold(): void
    {
        $conversation = $this->conversation();
        $this->app->instance(AiProvider::class, new class implements AiProvider {
            public function chat(\App\Agent\Providers\AiRequest $request): AiResponse
            {
                throw new AiProviderException('invalid request', retryable: false);
            }
        });

        app(AgentRunner::class)->run($this->turnFor($conversation));
        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['حصل عطل بسيط، هرد عليك بس هحول المحادثة لموظف.'], $result['messages']);
        // 2nd consecutive failure hits max_failed_turns=2 -> handed off.
        $this->assertSame(1, Handoff::where('conversation_id', $conversation->id)->count());
    }

    public function test_trace_is_recorded_with_status_and_tokens(): void
    {
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['تمام']]]]));

        $turn = $this->turnFor($conversation);
        app(AgentRunner::class)->run($turn);

        $trace = \App\Models\AiTrace::where('turn_id', $turn->id)->first();
        $this->assertSame('done', $trace->status);
        $this->assertSame(5, $trace->output_tokens);
        $this->assertSame('v2.2.0', $trace->prompt_version);
    }

    public function test_tools_called_together_after_start_application_see_the_new_application(): void
    {
        $conversation = $this->conversation();
        CustomerType::create(['key' => 'employee', 'label' => 'موظف', 'is_active' => true]);
        RequirementField::create(['key' => 'phone', 'label' => 'تليفون', 'data_type' => 'phone', 'scope' => 'application', 'is_active' => true]);
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'انا موظف ورقمي 01012345678']);

        $fake = $this->fake();
        $fake->queue($this->response([
            ['id' => 't1', 'name' => 'start_application', 'args' => ['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف']],
            ['id' => 't2', 'name' => 'record_customer_data', 'args' => ['fields' => [['key' => 'phone', 'value' => '01012345678']]]],
            ['id' => 't3', 'name' => 'send_reply', 'args' => ['messages' => ['تمام سجلت رقمك']]],
        ]));

        app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame('01012345678', ApplicationData::where('field_key', 'phone')->first()?->value);
    }

    public function test_an_empty_reply_is_not_sent(): void
    {
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['']]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['وصلتني بياناتك']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertSame(['وصلتني بياناتك'], $result['messages']);
    }

    public function test_offering_a_handoff_is_not_treated_as_promising_one(): void
    {
        $conversation = $this->conversation();
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['لو حابب أحولك لزميل من المعرض، تحب أعمل كده؟']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertCount(1, $fake->requests());
        $this->assertSame('open', $conversation->refresh()->status);
        $this->assertNotEmpty($result['messages']);
    }

    public function test_a_busy_notice_row_does_not_supersede_the_turn_it_apologised_for(): void
    {
        $conversation = $this->conversation();
        $stuck = $this->turnFor($conversation);
        DB::table('whatsapp_message_jobs')->where('id', $stuck->id)->update(['status' => 'pending', 'attempts' => 4]);
        $notice = $this->turnFor($conversation);
        DB::table('whatsapp_message_jobs')->where('id', $notice->id)->update(['status' => 'done']);
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'system', 'type' => 'text', 'text' => 'busy', 'turn_id' => $notice->id]);

        $command = app(\App\Console\Commands\ProcessWhatsappMessageJobs::class);
        $method = new \ReflectionMethod($command, 'supersedeIfStale');

        $this->assertFalse($method->invoke($command, DB::table('whatsapp_message_jobs')->find($stuck->id)));

        $customerTurn = $this->turnFor($conversation);
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'hi', 'turn_id' => $customerTurn->id]);

        $this->assertTrue($method->invoke($command, DB::table('whatsapp_message_jobs')->find($stuck->id)));
    }
}
