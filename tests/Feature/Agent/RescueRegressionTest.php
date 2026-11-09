<?php

namespace Tests\Feature\Agent;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Agent\Runtime\AgentRunner;
use App\Agent\Runtime\ConversationIntent;
use App\Agent\Runtime\ReplyGuard;
use App\Agent\Tools\GetInstallmentOfferTool;
use App\Agent\Tools\ToolContext;
use App\Agent\Runtime\TurnResultBuilder;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rescue 2026-11-08: what the critic and the real-model simulation found -
 * repeated greetings, links nobody asked for, "سجلت" on the form, asking a
 * known answer again, and a model "pick" that was only a word like "عايز".
 */
class RescueRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agent.runtime.max_model_calls' => 5,
            'agent.runtime.max_tool_calls' => 10,
            'agent.runtime.wall_clock_seconds' => 30,
            'agent.fallback.message' => 'معلش حصلت مشكلة، ابعتلي تاني.',
            'agent.applications.mode' => 'form_links',
        ]);
    }

    private function conversation(): WhatsappConversation
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'r'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $conversation->update(['customer_id' => $customer->id]);

        return $conversation;
    }

    private function says(WhatsappConversation $conversation, string $text): object
    {
        $id = DB::table('whatsapp_message_jobs')->insertGetId([
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id, 'whatsapp_conversation_id' => $conversation->id,
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer',
            'type' => 'text', 'text' => $text, 'turn_id' => $id]);

        return DB::table('whatsapp_message_jobs')->find($id);
    }

    private function delivered(WhatsappConversation $conversation, array $messages): void
    {
        foreach ($messages as $text) {
            WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'bot',
                'type' => 'text', 'text' => $text, 'delivery_status' => 'sent']);
        }
    }

    private function fake(): FakeAiProvider
    {
        $fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $fake);

        return $fake;
    }

    private function reply(array $args): AiResponse
    {
        return new AiResponse([], [['id' => 'r'.uniqid(), 'name' => 'send_reply', 'args' => $args]], 'STOP', ['input_tokens' => 10, 'output_tokens' => 5], 'gemini-test', null, 12);
    }

    /** Conversation 231: five "السلام عليكم" got five greetings. */
    public function test_greeting_spam_gets_a_greeting_then_a_short_line_then_silence(): void
    {
        $this->fake();
        $conversation = $this->conversation();
        $replies = [];

        foreach (['السلام عليكم', 'السلام عليكم', 'السلام عليكم', 'السلام عليكم'] as $text) {
            $result = app(AgentRunner::class)->run($this->says($conversation, $text));
            $replies[] = $result['messages'] ?? [];
            $this->delivered($conversation, $result['messages'] ?? []);
        }

        $this->assertNotEmpty($replies[0]);
        $this->assertNotEmpty($replies[1]);
        $this->assertNotSame($replies[0], $replies[1]);
        $this->assertSame([], $replies[2]);
        $this->assertSame([], $replies[3]);
    }

    /** Simulation 2026-11-08: "أبعتلك رابط التقديم؟" arrived together with the link he had not asked for. */
    public function test_a_reply_that_only_mentions_the_link_does_not_send_one(): void
    {
        $fake = $this->fake();
        $conversation = $this->conversation();
        $fake->queue($this->reply(['messages' => ['لو حابب تقدّم أبعتلك رابط التقديم؟'], 'asks' => ['other']]));

        $result = app(AgentRunner::class)->run($this->says($conversation, 'تمام'));

        $this->assertSame(['لو حابب تقدم أبعتلك رابط التقديم؟'], $result['messages']);
        $this->assertSame(0, \App\Models\FormLink::count());
    }

    /** Simulation 2026-11-08: on the form nothing in the chat is saved, yet "سجلت العنوان في طلبك" went out. */
    public function test_on_the_form_a_saved_claim_is_repaired(): void
    {
        $fake = $this->fake();
        $conversation = $this->conversation();
        $fake->queue($this->reply(['messages' => ['تمام، سجلت العنوان في طلبك.']]));
        $fake->queue($this->reply(['messages' => ['العنوان ده بيتكتب في رابط التقديم، كمل منه.']]));

        $result = app(AgentRunner::class)->run($this->says($conversation, 'ساكن في الهرم 12 شارع النصر'));

        $this->assertSame(['العنوان ده بيتكتب في رابط التقديم، كمل منه.'], $result['messages']);
        $this->assertCount(2, $fake->requests());
    }

    /** "ده رابط التقديم" with no link made: he would get the words and no link. */
    public function test_claiming_a_link_that_was_not_sent_is_repaired(): void
    {
        $fake = $this->fake();
        $conversation = $this->conversation();
        $fake->queue($this->reply(['messages' => ['ده رابط التقديم، املا بياناتك عليه.']]));
        $fake->queue($this->reply(['messages' => ['قولي شغلك إيه الأول عشان أجهزلك الرابط.'], 'asks' => ['work']]));

        $result = app(AgentRunner::class)->run($this->says($conversation, 'ابعتلي الرابط'));

        $this->assertSame(['قولي شغلك إيه الأول عشان أجهزلك الرابط.'], $result['messages']);
    }

    /** Owner: never ask what he already answered - told once; asked again, he is changing it. */
    public function test_asking_a_known_model_again_is_repaired_once_and_then_trusted(): void
    {
        $fake = $this->fake();
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $boxer = Machine::create(['name' => 'بوكسر 150', 'brand_id' => $brand->id, 'cash_price' => 50000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $this->says($conversation, 'عايز اقسط بوكسر 150');
        $this->delivered($conversation, ['البوكسر 150 كاش 50,000 جنيه. تحب على كام شهر؟']);
        // known = what the AI recorded from his words, not a regex reading of them
        app(\App\Domain\Memory\CustomerMemory::class)->applyModelUpdate($conversation->customer_id, $conversation->id,
            ['motorcycles' => [['id' => $boxer->id, 'stage' => 'selected', 'quote' => 'عايز اقسط بوكسر 150']]]);

        $fake->queue($this->reply(['messages' => ['تحب أنهي موديل؟'], 'asks' => ['model']]));
        $fake->queue($this->reply(['messages' => ['تحب تغير للموديل التاني؟'], 'asks' => ['model']]));

        $result = app(AgentRunner::class)->run($this->says($conversation, 'طب والتاني؟'));

        $this->assertSame(['تحب تغير للموديل التاني؟'], $result['messages']);
        $this->assertCount(2, $fake->requests());
        $codes = array_column((array) \App\Models\AiTrace::latest('id')->value('guard_events'), 'code');
        $this->assertContains('REASK_KNOWN', $codes);
        $this->assertContains('OBSERVED:REASK_KNOWN', $codes);
    }

    public function test_a_new_applicant_makes_age_and_work_new_questions(): void
    {
        $conversation = $this->conversation();
        $conversation->update(['state' => ['work_profile' => ['work_stated' => true, 'applicant' => 'customer', 'occupation' => 'محاسب',
            'customer_type' => 'employee', 'working_now' => 'yes', 'insured' => 'yes', 'evidence' => 'محاسب']]]);
        $this->says($conversation, 'خلاص هقدم باسم اخويا');

        $known = app(ReplyGuard::class)->knownAnswers($conversation->fresh(), ['work', 'insured']);

        $this->assertSame([], $known);
    }

    /** "عايز" alone used to pass as his pick for ANY motorcycle (it is in every message). */
    public function test_his_words_must_point_at_this_motorcycle(): void
    {
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $machine = Machine::create(['name' => 'بوكسر 150', 'brand_id' => $brand->id, 'cash_price' => 46000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $this->says($conversation, 'عايز اقسط اللي بـ46 ألف');
        $ctx = new ToolContext($conversation->customer_id, $conversation->id, null, 1, 1, new TurnResultBuilder());
        $pick = new \ReflectionMethod(GetInstallmentOfferTool::class, 'hisWordsPick');

        $this->assertFalse($pick->invoke(app(GetInstallmentOfferTool::class), ['his_words' => 'عايز'], $ctx, $machine));
        $this->assertTrue($pick->invoke(app(GetInstallmentOfferTool::class), ['his_words' => 'اللي بـ46 ألف'], $ctx, $machine));
    }

    /** Simulation 2026-11-08: "هوجن 4 فرز تاني" was refused 5 times - the catalog says "هوجن ٤ استيراد فرز تاني". */
    public function test_his_short_name_for_the_model_is_his_pick(): void
    {
        $conversation = $this->conversation();
        $hogan = Brand::create(['name' => 'هوجان', 'image' => 'h.jpg']);
        $keeway = Brand::create(['name' => 'كيواي', 'image' => 'k.jpg']);
        $machine = Machine::create(['name' => 'هوجن ٤ استيراد فرز تاني', 'brand_id' => $hogan->id, 'cash_price' => 52000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $superlight = Machine::create(['name' => 'Superlight', 'brand_id' => $keeway->id, 'cash_price' => 73000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $this->says($conversation, 'هوجن 4 فرز تاني عايز اقسطها سنتين');
        $this->says($conversation, 'كيواي سوبر لايت بكام');
        $ctx = new ToolContext($conversation->customer_id, $conversation->id, null, 1, 1, new TurnResultBuilder());
        $pick = new \ReflectionMethod(GetInstallmentOfferTool::class, 'hisWordsPick');
        $tool = app(GetInstallmentOfferTool::class);

        $this->assertTrue($pick->invoke($tool, ['his_words' => 'هوجن 4 فرز تاني'], $ctx, $machine));
        $this->assertTrue($pick->invoke($tool, ['his_words' => 'كيواي سوبر لايت'], $ctx, $superlight));
        // words he never wrote, and words that do not name this motorcycle, are no pick
        $this->assertFalse($pick->invoke($tool, ['his_words' => 'هوجن ٤ استيراد فرز تاني'], $ctx, $machine));
        $this->assertFalse($pick->invoke($tool, ['his_words' => 'كيواي سوبر لايت'], $ctx, $machine));
    }

    /** Simulation 2026-11-08: "سجلنا الرقم" went out on the form - only "سجلت" was known. */
    public function test_we_saved_it_on_the_form_is_repaired(): void
    {
        $fake = $this->fake();
        $conversation = $this->conversation();
        $fake->queue($this->reply(['messages' => ['تمام يا باشا، سجلنا الرقم، وكمل باقي البيانات على الرابط.']]));
        $fake->queue($this->reply(['messages' => ['الرقم بيتكتب في رابط التقديم، كمل منه.']]));

        $result = app(AgentRunner::class)->run($this->says($conversation, 'رقمي 01098655527'));

        $this->assertSame(['الرقم بيتكتب في رابط التقديم، كمل منه.'], $result['messages']);
    }

    /** Simulation 2026-11-08: "حضرتك بتشتغل إيه؟" twice in a row. */
    public function test_a_short_reply_identical_to_the_last_one_is_rephrased(): void
    {
        $fake = $this->fake();
        $conversation = $this->conversation();
        $this->says($conversation, 'عايز اقسط');
        $this->delivered($conversation, ['حضرتك بتشتغل إيه؟']);
        $fake->queue($this->reply(['messages' => ['حضرتك بتشتغل إيه؟']]));
        $fake->queue($this->reply(['messages' => ['تمام، عشان أجهزلك التقديم قولّي شغلك إيه؟']]));

        $result = app(AgentRunner::class)->run($this->says($conversation, 'عايز اقدم'));

        $this->assertSame(['تمام، عشان أجهزلك التقديم قولي شغلك إيه؟'], $result['messages']);
    }

    /** Simulation 2026-11-08: one repair, then the false sentences were cut - "بيكون كالتالي" with no numbers. */
    public function test_the_ai_gets_a_second_rewrite_before_anything_is_cut(): void
    {
        $fake = $this->fake();
        $conversation = $this->conversation();
        $fake->queue($this->reply(['messages' => ['تمام يا باشا، سجلت عنوانك.']]));
        $fake->queue($this->reply(['messages' => ['تمام، حفظت العنوان عندي.']]));
        $fake->queue($this->reply(['messages' => ['العنوان بيتكتب في رابط التقديم، كمل منه.']]));

        $result = app(AgentRunner::class)->run($this->says($conversation, 'ساكن في الهرم'));

        $this->assertSame(['العنوان بيتكتب في رابط التقديم، كمل منه.'], $result['messages']);
        $this->assertCount(3, $fake->requests());
    }

    /** Simulation 2026-11-08: "رقمي 01098655527" was filed as his monthly income. */
    public function test_a_phone_number_is_never_remembered_as_an_amount(): void
    {
        $conversation = $this->conversation();
        $memory = app(\App\Domain\Memory\CustomerMemory::class);
        $put = new \ReflectionMethod(\App\Domain\Memory\CustomerMemory::class, 'putFact');
        $state = ['facts' => []];
        $args = [&$state, 'monthly_income', '01098655527', 'customer_statement', 'رقمي 01098655527', $conversation->id, now()->toIso8601String()];

        $this->assertNull($put->invokeArgs($memory, $args));
        $this->assertArrayNotHasKey('monthly_income', $state['facts']);
    }

    /** Simulation 2026-11-08: a link for "Keeway keet 150" she never named, then the same link again, alone. */
    public function test_the_link_carries_only_his_own_pick_and_is_not_sent_twice(): void
    {
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'كيواي', 'image' => 'k.jpg']);
        $keet = Machine::create(['name' => 'Keeway keet 150', 'brand_id' => $brand->id, 'cash_price' => 53000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $form = \App\Models\DynamicForm::create(['name' => 'طلب تقسيط', 'is_bot_default' => true, 'schema' => ['sections' => [
            ['key' => 's1', 'title' => 'بياناتك', 'items' => [
                ['type' => 'text', 'data' => ['key' => 'name', 'label' => 'الاسم', 'required' => true, 'binding' => 'applicant.name']],
                ['type' => 'radio', 'data' => ['key' => 'work', 'label' => 'شغلك', 'required' => true, 'binding' => 'work.status', 'options' => [
                    ['value' => 'employee', 'label' => 'موظف', 'customer_type_id' => \App\Models\CustomerType::create(['key' => 'employee', 'label' => 'موظف', 'is_active' => true])->id],
                ]]],
                ['type' => 'machine', 'data' => ['key' => 'machine', 'label' => 'المكنة', 'required' => true, 'binding' => 'request.machine']],
                ['type' => 'plan', 'data' => ['key' => 'plan', 'label' => 'النظام', 'required' => true, 'binding' => 'request.plan']],
            ]],
        ]]]);
        $this->assertSame([], app(\App\Domain\Forms\FormPublisher::class)->publish($form, Staff::first()->id));
        $turn = $this->says($conversation, 'تمام عايزة اقدم');
        $ctx = new ToolContext($conversation->customer_id, $conversation->id, null, $turn->id, 1, new TurnResultBuilder());
        $tool = app(\App\Agent\Tools\SendApplicationLinkTool::class);

        $result = $tool->execute(['motorcycle_id' => $keet->id, 'his_words' => 'Keeway keet 150', 'months' => 12], $ctx)->toArray();

        $this->assertTrue($result['data']['link_sent_after_your_reply']);
        $this->assertArrayHasKey('motorcycle_not_prefilled', $result['data']);
        $link = \App\Models\FormLink::sole();
        $this->assertNull($link->prefill['machine_id'] ?? null);

        $this->delivered($conversation, [$link->url()]);
        $again = $tool->execute([], $ctx)->toArray();

        $this->assertFalse($again['data']['link_sent_after_your_reply']);
        $this->assertTrue($again['data']['link_already_above']);
    }

    /** Simulation 2026-11-08: "طيب ينفع كشف حساب البنك؟" after the link got messages [] - taken as silence. */
    public function test_an_empty_reply_is_sent_back_once_before_it_counts_as_silence(): void
    {
        $fake = $this->fake();
        $conversation = $this->conversation();
        $this->says($conversation, 'عايزة اقدم');
        $this->delivered($conversation, ['http://x.test/f/abc']);
        $fake->queue($this->reply(['messages' => []]));
        $fake->queue($this->reply(['messages' => ['البديل الوحيد للمفردات هو برنت التأمينات، وبيترفع على نفس الرابط.']]));

        $result = app(AgentRunner::class)->run($this->says($conversation, 'طيب ينفع كشف حساب البنك؟'));

        $this->assertSame(['البديل الوحيد للمفردات هو برنت التأمينات، وبيترفع على نفس الرابط.'], $result['messages']);
        $this->assertCount(2, $fake->requests());
    }

    /** A reply he truly does not need ("تمام" after an answer): a second empty reply is the silence meant. */
    public function test_a_second_empty_reply_is_silence_when_silence_is_allowed(): void
    {
        $fake = $this->fake();
        $conversation = $this->conversation();
        $this->says($conversation, 'شكرا');
        $this->delivered($conversation, ['العفو يا فندم، في أي وقت.']);
        $fake->queue($this->reply(['messages' => []]));
        $fake->queue($this->reply(['messages' => []]));

        $result = app(AgentRunner::class)->run($this->says($conversation, 'تمام'));

        $this->assertSame([], $result['messages'] ?? []);
    }

    public function test_trouble_with_the_link_is_not_a_refusal_to_apply(): void
    {
        $intent = app(ConversationIntent::class);

        $this->assertFalse($intent->refusesApplication('مش عارف افتح رابط التقديم'));
        $this->assertFalse($intent->refusesApplication('مش لاقي طلبي'));
        $this->assertTrue($intent->refusesApplication('لا خلاص مش عايز اقدم دلوقتي'));
        $this->assertTrue($intent->refusesApplication('مش هقدم'));
    }

    /** Simulation 2026-11-08: a wrong rate twice ended in an unrelated line - now the live rates. */
    public function test_a_wrong_rate_twice_is_never_sent_and_never_replaced_by_the_rate_table(): void
    {
        // 2026-11-09: the rate-table replacement answered spec and photo questions with percentages.
        $system = \App\Models\InstallmentSystem::create(['name' => 'امان', 'pricing_mode' => 'standard', 'administrative_fees' => 7, 'is_active' => true,
            'plans' => [['months' => 24, 'interest' => 42]]]);
        \App\Models\InstallmentPlan::updateOrCreate(['installment_system_id' => $system->id, 'months' => 24], ['interest_percent' => 42, 'is_active' => true]);
        $fake = $this->fake();
        $conversation = $this->conversation();
        $fake->queue($this->reply(['messages' => ['على سنتين النسبة 40% مع المصاريف.']]));
        $fake->queue($this->reply(['messages' => ['على سنتين النسبة 40% برضه.']]));
        $fake->queue($this->reply(['messages' => ['على سنتين النسبة 40% تاني.']]));

        $result = app(AgentRunner::class)->run($this->says($conversation, 'الفايدة كام على سنتين؟'));

        $this->assertStringNotContainsString('40', implode(' ', $result['messages'] ?? []));
        $this->assertStringNotContainsString('النسب الحالية', implode(' ', $result['messages'] ?? []));
    }

    public function test_a_spec_percentage_is_not_a_rate(): void
    {
        \App\Models\InstallmentPlan::query()->delete();
        $conversation = $this->conversation();

        $this->assertNotSame('PERCENT_DURATION_MISMATCH', app(\App\Agent\Runtime\ReplyGuard::class)->check(
            ['messages' => ['دايو ٤ كاش ٤١٬٠٠٠ جنيه، ٧٥٪ استيراد.']], $conversation, '', [], []));
    }

    /** "عايز اقدم عليها" twice answered without the link: the link tool runs, with its rules. */
    public function test_a_link_the_model_twice_failed_to_send_is_sent_by_the_tool(): void
    {
        $fake = $this->fake();
        $conversation = $this->conversation();
        $fake->queue($this->reply(['messages' => ['ده رابط التقديم، املا عليه.']]));
        $fake->queue($this->reply(['messages' => ['ده رابط التقديم برضه.']]));
        $called = false;
        $this->app->extend(\App\Agent\Tools\ToolRegistry::class, function ($registry) use (&$called) {
            return new class($registry, $called) extends \App\Agent\Tools\ToolRegistry {
                public function __construct(private $inner, private &$called) {}
                public function declarations(): array { return $this->inner->declarations(); }
                public function execute(string $name, array $args, \App\Agent\Tools\ToolContext $ctx): array
                {
                    if ($name === 'send_application_link') { $this->called = true; return ['ok' => true, 'data' => ['link_sent_after_your_reply' => true]]; }
                    return $this->inner->execute($name, $args, $ctx);
                }
            };
        });

        $result = app(AgentRunner::class)->run($this->says($conversation, 'عايز اقدم'));

        $this->assertTrue($called);
        $this->assertSame(['ده رابط التقديم، املا بياناتك وارفع ورقك عليه، ولو وقفت في حاجة قولّي.'], $result['messages']);
    }
}
