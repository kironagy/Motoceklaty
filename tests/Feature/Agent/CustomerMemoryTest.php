<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\SendReplyTool;
use App\Agent\Tools\StartApplicationTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Memory\CustomerMemory;
use App\Models\AiTrace;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\EligibilityRule;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ENHANCE-Ai §7-11, §20, §25-26: structured memory, provenance, intent stages, isolation. */
class CustomerMemoryTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappBot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $this->bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
    }

    /** @return array{0: WhatsappConversation, 1: Customer} */
    private function customer(string $phone = '2011'): array
    {
        $customer = Customer::create(['whatsapp_bot_id' => $this->bot->id, 'jid' => "{$phone}@s.whatsapp.net", 'phone' => $phone]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $this->bot->id, 'phone' => $phone, 'status' => 'open', 'customer_id' => $customer->id]);

        return [$conversation, $customer];
    }

    private function says(WhatsappConversation $conversation, string $text, ?int $turnId = null): WhatsappMessage
    {
        return WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'whatsapp_bot_id' => $this->bot->id,
            'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => $text, 'turn_id' => $turnId,
        ]);
    }

    private function botSays(WhatsappConversation $conversation, string $text): WhatsappMessage
    {
        return WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'whatsapp_bot_id' => $this->bot->id,
            'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'text', 'text' => $text,
        ]);
    }

    private function machine(string $name, int $price = 40000): Machine
    {
        $brand = Brand::firstOrCreate(['name' => 'Hojan'], ['image' => 'b.jpg']);

        return Machine::create(['name' => $name, 'brand_id' => $brand->id, 'cash_price' => $price, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
    }

    private function memory(): CustomerMemory
    {
        return app(CustomerMemory::class);
    }

    private function ctx(WhatsappConversation $conversation, Customer $customer, int $turnId = 1): ToolContext
    {
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => $turnId, 'status' => 'running']);

        return new ToolContext($customer->id, $conversation->id, null, $turnId, $trace->id, new TurnResultBuilder());
    }

    public function test_a_fact_in_his_own_words_is_a_statement_and_a_guess_is_not(): void
    {
        [$conversation, $customer] = $this->customer();
        $this->says($conversation, 'انا مدرس في مدرسة اعدادي');

        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['facts' => [
            ['key' => 'job', 'value' => 'مدرس', 'quote' => 'انا مدرس'],
            ['key' => 'governorate', 'value' => 'الجيزة', 'quote' => 'ساكن في الجيزة'],
        ]]);

        $facts = $this->memory()->get($customer->id)['facts'];
        $this->assertSame('customer_statement', $facts['job']['source']);
        $this->assertSame('ai_inference', $facts['governorate']['source']);

        $prompt = $this->memory()->forPrompt($customer->id);
        $this->assertStringContainsString('قاله بنفسه: الشغل: مدرس', $prompt);
        $this->assertStringContainsString('استنتاج مش مؤكد', $prompt);
        $this->assertStringContainsString('ما تسألوش تاني عن: الشغل', $prompt);
    }

    public function test_a_new_statement_replaces_the_old_one_and_keeps_it_as_history(): void
    {
        [$conversation, $customer] = $this->customer();
        $this->says($conversation, 'انا مدرس');
        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['facts' => [['key' => 'job', 'value' => 'مدرس', 'quote' => 'انا مدرس']]]);

        $this->says($conversation, 'لا انا سبت التدريس وبشتغل سواق دلوقتي');
        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['facts' => [['key' => 'job', 'value' => 'سواق', 'quote' => 'بشتغل سواق دلوقتي']]]);

        $job = $this->memory()->get($customer->id)['facts']['job'];
        $this->assertSame('سواق', $job['value']);
        $this->assertSame('مدرس', $job['history'][0]['value']);
        $this->assertStringContainsString('كان "مدرس" وبقى "سواق"', $this->memory()->forPrompt($customer->id));
    }

    public function test_a_guess_never_replaces_what_he_said(): void
    {
        [$conversation, $customer] = $this->customer();
        $this->says($conversation, 'انا مدرس');
        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['facts' => [['key' => 'job', 'value' => 'مدرس', 'quote' => 'انا مدرس']]]);

        $result = $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['facts' => [['key' => 'job', 'value' => 'محاسب', 'quote' => 'شكلك محاسب']]]);

        $this->assertSame('WEAKER_THAN_STATED', $result['rejected'][0]['code']);
        $this->assertSame('مدرس', $this->memory()->get($customer->id)['facts']['job']['value']);
    }

    public function test_asking_a_price_is_not_choosing_the_motorcycle(): void
    {
        [$conversation, $customer] = $this->customer();
        $vlr = $this->machine('فيجوري VLR 150');
        $this->says($conversation, 'VLR 150 بكام؟');

        // the price lookup alone
        $this->memory()->recordToolEvent($customer->id, 'get_installment_offer', ['motorcycle_id' => $vlr->id], ['ok' => true, 'data' => []]);
        $this->assertSame('asked_about', $this->memory()->get($customer->id)['motorcycles']["m{$vlr->id}"]['stage']);

        // the model claims "selected" with no words of his saying so
        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['motorcycles' => [['id' => $vlr->id, 'stage' => 'selected', 'quote' => 'خلاص هاخدها']]]);
        $this->assertSame('interested', $this->memory()->get($customer->id)['motorcycles']["m{$vlr->id}"]['stage']);

        // he really decides
        $this->says($conversation, 'خلاص هقدم على VLR 150');
        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['motorcycles' => [['id' => $vlr->id, 'stage' => 'selected', 'quote' => 'خلاص هقدم على VLR 150']]]);
        $this->assertSame('selected', $this->memory()->get($customer->id)['motorcycles']["m{$vlr->id}"]['stage']);

        // a later price question about it does not pull the choice down - neither the tool nor his words
        $this->memory()->recordToolEvent($customer->id, 'get_installment_offer', ['motorcycle_id' => $vlr->id], ['ok' => true, 'data' => []]);
        $this->says($conversation, 'VLR 150 بكام تاني؟');
        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['motorcycles' => [['id' => $vlr->id, 'stage' => 'asked_about', 'quote' => 'VLR 150 بكام تاني']]]);
        $this->assertSame('selected', $this->memory()->get($customer->id)['motorcycles']["m{$vlr->id}"]['stage']);
    }

    public function test_only_one_motorcycle_is_selected_and_changing_mind_works(): void
    {
        [$conversation, $customer] = $this->customer();
        $a = $this->machine('هوجن 4');
        $b = $this->machine('بوكسر 150', 58000);

        $this->memory()->recordToolEvent($customer->id, 'start_application', ['motorcycle_id' => $a->id], ['ok' => true, 'data' => []]);
        $this->says($conversation, 'لا مش عايز الهوجن خلاص هاخد البوكسر');
        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['motorcycles' => [
            ['id' => $a->id, 'stage' => 'rejected', 'quote' => 'مش عايز الهوجن'],
            ['id' => $b->id, 'stage' => 'selected', 'quote' => 'هاخد البوكسر'],
        ]]);

        $bikes = $this->memory()->get($customer->id)['motorcycles'];
        $this->assertSame('rejected', $bikes["m{$a->id}"]['stage']);
        $this->assertSame('selected', $bikes["m{$b->id}"]['stage']);

        $prompt = $this->memory()->forPrompt($customer->id);
        $this->assertStringContainsString('اختاره: بوكسر 150', $prompt);
        $this->assertStringContainsString('مش عايزه: هوجن 4', $prompt);
        $this->assertStringContainsString('سؤال عن سعر مش معناه إنه اختار', $prompt);
    }

    public function test_rejecting_needs_his_words(): void
    {
        [$conversation, $customer] = $this->customer();
        $a = $this->machine('هوجن 4');

        $result = $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['motorcycles' => [['id' => $a->id, 'stage' => 'rejected']]]);

        $this->assertSame('QUOTE_REQUIRED', $result['rejected'][0]['code']);
        $this->assertSame([], $this->memory()->get($customer->id)['motorcycles']);
    }

    public function test_one_customer_never_leaks_into_another(): void
    {
        [$convA, $customerA] = $this->customer('2011');
        [$convB, $customerB] = $this->customer('2012');
        $this->says($convA, 'انا اسمي احمد وشغال نجار');

        // B's turn tries to use A's words
        $this->memory()->applyModelUpdate($customerB->id, $convB->id, ['facts' => [['key' => 'name', 'value' => 'احمد', 'quote' => 'انا اسمي احمد']]]);
        $this->memory()->applyModelUpdate($customerA->id, $convA->id, ['facts' => [['key' => 'job', 'value' => 'نجار', 'quote' => 'شغال نجار']]]);

        $this->assertSame('ai_inference', $this->memory()->get($customerB->id)['facts']['name']['source']);
        $this->assertArrayNotHasKey('job', $this->memory()->get($customerB->id)['facts']);
        $this->assertArrayNotHasKey('name', $this->memory()->get($customerA->id)['facts']);
        $this->assertStringNotContainsString('نجار', (string) $this->memory()->forPrompt($customerB->id));
    }

    public function test_invalid_keys_and_stages_are_dropped(): void
    {
        [$conversation, $customer] = $this->customer();
        $this->says($conversation, 'عندي 250 سنة');

        $result = $this->memory()->applyModelUpdate($customer->id, $conversation->id, [
            'facts' => [['key' => 'password', 'value' => 'x', 'quote' => 'x'], ['key' => 'age', 'value' => '250', 'quote' => 'عندي 250 سنة']],
            'motorcycles' => [['id' => 999999, 'stage' => 'interested'], ['name' => 'X', 'stage' => 'bought']],
        ]);

        $this->assertSame(['INVALID_FACT', 'INVALID_AGE', 'UNKNOWN_MOTORCYCLE', 'INVALID_STAGE'], array_column($result['rejected'], 'code'));
        $this->assertNull($this->memory()->forPrompt($customer->id));
    }

    public function test_send_reply_saves_memory_with_the_reply(): void
    {
        [$conversation, $customer] = $this->customer();
        $this->says($conversation, 'انا اسمي كريم وعندي 26 سنة', 1);

        $result = (new SendReplyTool())->execute([
            'messages' => ['تمام يا كريم'],
            'memory' => ['facts' => [['key' => 'name', 'value' => 'كريم', 'quote' => 'اسمي كريم'], ['key' => 'age', 'value' => '26', 'quote' => 'عندي 26 سنة']], 'topic' => 'بيسأل عن التقسيط'],
        ], $this->ctx($conversation, $customer));

        $this->assertTrue($result->ok);
        $memory = $this->memory()->get($customer->id);
        $this->assertSame('كريم', $memory['facts']['name']['value']);
        $this->assertSame(26, $this->memory()->statedAge($customer->id));
        $this->assertSame('بيسأل عن التقسيط', $memory['conversation']['topic']);
    }

    public function test_an_age_must_be_in_his_words_to_count(): void
    {
        [$conversation, $customer] = $this->customer();
        $this->says($conversation, 'انا عندي 26 سنة');

        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['facts' => [['key' => 'age', 'value' => '19', 'quote' => 'انا عندي 26 سنة']]]);

        $this->assertNull($this->memory()->statedAge($customer->id));
    }

    public function test_no_reply_only_for_a_closing_word(): void
    {
        [$conversation, $customer] = $this->customer();
        $this->botSays($conversation, 'العفو يا غالي، تنورنا في أي وقت.');
        $this->says($conversation, '🤍', 1);

        $silent = (new SendReplyTool())->execute(['messages' => [], 'no_reply' => true], $this->ctx($conversation, $customer, 1));
        $this->assertTrue($silent->ok);

        $this->says($conversation, 'طب الهوجن بكام؟', 2);
        $refused = (new SendReplyTool())->execute(['messages' => [], 'no_reply' => true], $this->ctx($conversation, $customer, 2));
        $this->assertFalse($refused->ok);
        $this->assertSame('REPLY_REQUIRED', $refused->error['code']);
    }

    public function test_no_reply_refused_when_our_last_message_asked_him_something(): void
    {
        [$conversation, $customer] = $this->customer();
        $this->botSays($conversation, 'تحب تقسطها على سنة ولا سنتين؟');
        $this->says($conversation, 'تمام', 1);

        $refused = (new SendReplyTool())->execute(['messages' => [], 'no_reply' => true], $this->ctx($conversation, $customer, 1));
        $this->assertFalse($refused->ok);
    }

    public function test_a_stated_age_under_the_minimum_stops_an_application(): void
    {
        [$conversation, $customer] = $this->customer();
        EligibilityRule::create(['customer_type_id' => null, 'rule_type' => 'age_range', 'params' => ['min' => 21, 'max' => 60], 'is_active' => true]);
        CustomerType::firstOrCreate(['key' => 'self_employed'], ['label' => 'عامل حر', 'is_active' => true]);
        $this->says($conversation, 'شغال صنايعي ميكانيكي');
        $this->says($conversation, 'سني 18');

        $result = app(StartApplicationTool::class)->execute(['customer_type' => 'self_employed', 'customer_type_quote' => 'شغال صنايعي ميكانيكي'], $this->ctx($conversation, $customer));

        $this->assertFalse($result->ok);
        $this->assertSame('AGE_BELOW_MINIMUM_STATED', $result->error['code']);
        $this->assertSame(0, \App\Models\Application::count());

        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['facts' => [['key' => 'age', 'value' => '18', 'quote' => 'سني 18']]]);
        $this->assertStringContainsString('أقل من 21', $this->memory()->forPrompt($customer->id));
    }

    public function test_a_returning_customer_is_flagged_without_a_new_greeting(): void
    {
        [$conversation, $customer] = $this->customer();
        $old = $this->says($conversation, 'الهوجن بكام');
        $old->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->says($conversation, 'السلام عليكم انا رجعت');
        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['topic' => 'سعر الهوجن']);

        $this->assertStringContainsString('راجع بعد 3 أيام', (string) $this->memory()->forPrompt($customer->id, $conversation->id));
    }

    public function test_a_conversation_that_carried_on_after_the_return_is_not_a_return_again(): void
    {
        [$conversation, $customer] = $this->customer();
        $this->says($conversation, 'الهوجن بكام')->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->says($conversation, 'السلام عليكم انا رجعت')->forceFill(['created_at' => now()->subMinutes(40)])->save();
        $this->says($conversation, 'طب على سنتين');
        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['topic' => 'سعر الهوجن']);

        $this->assertStringNotContainsString('راجع بعد', (string) $this->memory()->forPrompt($customer->id, $conversation->id));
    }

    public function test_an_app_profile_needs_no_app_name_when_he_said_which_app(): void
    {
        // server: 7 of 8 profile screenshots refused MISSING_DATA app_name (conversation 830)
        [$conversation, $customer] = $this->customer();
        $this->says($conversation, 'انا شغال على ديدي');
        $this->memory()->applyModelUpdate($customer->id, $conversation->id, ['facts' => [['key' => 'workplace', 'value' => 'ديدي', 'quote' => 'شغال على ديدي']]]);
        $type = CustomerType::firstOrCreate(['key' => 'self_employed'], ['label' => 'عامل حر', 'is_active' => true]);
        $application = \App\Models\Application::create(['customer_id' => $customer->id, 'customer_type_id' => $type->id, 'origin_conversation_id' => $conversation->id, 'status' => 'collecting']);
        $this->app->instance(\App\Domain\Applications\WorkClassification::class, \Mockery::mock(\App\Domain\Applications\WorkClassification::class, ['reading' => null]));

        $method = new \ReflectionMethod(\App\Domain\Documents\DocumentPipeline::class, 'knownAppName');

        $this->assertSame('ديدي', $method->invoke(app(\App\Domain\Documents\DocumentPipeline::class), $application));
    }

    public function test_a_reply_in_parts_goes_out_as_one_message(): void
    {
        // owner 2026-10-04: "بيرد عليا برسالتين وتلاتة"
        [$conversation, $customer] = $this->customer();
        $this->says($conversation, 'عندك حاجه في حدود ٤٠ الف ؟', 1);
        $ctx = $this->ctx($conversation, $customer);

        (new SendReplyTool())->execute(['messages' => ['في حدود ٤٠ ألف عندنا اختيارين:', "• هوجن ٤ فرز تاني ٤٠,٠٠٠\n• دايو ٤ ٣٩,٥٠٠", 'تحب تقسيط ولا كاش؟']], $ctx);

        $this->assertSame(["في حدود ٤٠ ألف عندنا اختيارين:\n• هوجن ٤ فرز تاني ٤٠,٠٠٠\n• دايو ٤ ٣٩,٥٠٠\n\nتحب تقسيط ولا كاش؟"], $ctx->outbound->toArray()['messages']);
        $this->assertCount(2, SendReplyTool::asOneMessage([str_repeat('أ', 900), str_repeat('ب', 900)]));
    }
}
