<?php

namespace Tests\Feature\Agent;

use App\Agent\Context\ContextBuilder;
use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Jobs\SummarizeConversation;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\Brand;
use App\Models\BusinessMemory;
use App\Models\Customer;
use App\Models\CustomerAttribute;
use App\Models\CustomerType;
use App\Models\Machine;
use App\Models\RequirementField;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function conversationWithCustomer(): array
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $conversation->update(['customer_id' => $customer->id]);

        return [$conversation, $customer];
    }

    private function turnFor(WhatsappConversation $conversation): object
    {
        $id = DB::table('whatsapp_message_jobs')->insertGetId([
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
            'whatsapp_conversation_id' => $conversation->id,
            'status' => 'processing',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('whatsapp_message_jobs')->find($id);
    }

    public function test_minimal_conversation_has_only_l0_and_l4(): void
    {
        [$conversation] = $this->conversationWithCustomer();
        $turn = $this->turnFor($conversation);

        $request = app(ContextBuilder::class)->build($turn);

        $this->assertStringContainsString('فريق المبيعات في معرض موتوسيكلات', $request->system);
        $this->assertStringContainsString('## الحالة الحالية', $request->system);
        $this->assertStringNotContainsString('فهرس الكتالوج', $request->system);
        $this->assertStringNotContainsString('إرشادات ثابتة', $request->system);
        $this->assertStringNotContainsString('## ملخص المحادثة السابقة', $request->system);
    }

    public function test_all_layers_present_with_a_full_fixture(): void
    {
        [$conversation, $customer] = $this->conversationWithCustomer();

        BusinessMemory::create(['key' => 'pinned1', 'title' => 'قاعدة مثبتة', 'content' => 'محتوى مثبت', 'is_pinned' => true, 'is_active' => true]);
        BusinessMemory::create(['key' => 'note1', 'title' => 'ملاحظة عامة', 'content' => 'تفاصيل الملاحظة', 'is_pinned' => false, 'is_active' => true]);

        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        Machine::create(['name' => 'بوكسر 150', 'brand_id' => $brand->id, 'cash_price' => 50000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);

        $type = CustomerType::create(['key' => 'employee', 'label' => 'Employee']);
        BusinessMemory::create([
            'key' => 'stage1', 'title' => 'إرشاد مرحلة التحصيل', 'content' => 'اطلب البطاقة الأول',
            'is_pinned' => false, 'is_active' => true, 'scope_customer_types' => ['employee'],
        ]);

        $application = Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting',
        ]);

        $conversation->update(['summary' => 'العميل بيسأل عن موتوسيكلات اقتصادية.']);

        $turn = $this->turnFor($conversation);

        $request = app(ContextBuilder::class)->build($turn);

        $this->assertStringContainsString('قاعدة مثبتة', $request->system);
        $this->assertStringContainsString('note1: ملاحظة عامة', $request->system);
        $this->assertStringContainsString('بوكسر 150', $request->system);
        $this->assertStringContainsString((string) $application->id, $request->system);
        $this->assertStringContainsString('إرشاد مرحلة التحصيل', $request->system);
        $this->assertStringContainsString('ملخص المحادثة السابقة', $request->system);
    }

    public function test_stage_scoped_memory_requires_matching_status_and_an_active_application(): void
    {
        [$conversation, $customer] = $this->conversationWithCustomer();
        $type = CustomerType::create(['key' => 'employee', 'label' => 'Employee']);
        $otherType = CustomerType::create(['key' => 'self_employed', 'label' => 'Self employed']);

        BusinessMemory::create([
            'key' => 'stage1', 'title' => 'إرشاد الموظف', 'content' => 'محتوى-إرشاد-صاحب-النشاط-الحر',
            'is_pinned' => false, 'is_active' => true, 'scope_customer_types' => [$otherType->key],
        ]);

        // L2's index always lists every active memory's title (by design) -
        // only L5 gates the full content on a matching, active application.
        // No active application at all -> L5 never shown.
        $turnWithoutApp = $this->turnFor($conversation);
        $requestWithoutApp = app(ContextBuilder::class)->build($turnWithoutApp);
        $this->assertStringNotContainsString('محتوى-إرشاد-صاحب-النشاط-الحر', $requestWithoutApp->system);

        // Active application, but customer_type doesn't match the memory's scope.
        Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting',
        ]);
        $turnWrongType = $this->turnFor($conversation);
        $requestWrongType = app(ContextBuilder::class)->build($turnWrongType);
        $this->assertStringNotContainsString('محتوى-إرشاد-صاحب-النشاط-الحر', $requestWrongType->system);
    }

    public function test_sensitive_value_never_appears_in_l4(): void
    {
        [$conversation, $customer] = $this->conversationWithCustomer();
        RequirementField::create(['key' => 'national_id', 'label' => 'ID', 'data_type' => 'national_id', 'scope' => 'customer', 'is_sensitive' => true, 'is_active' => true]);
        CustomerAttribute::create(['customer_id' => $customer->id, 'field_key' => 'national_id', 'value' => '29001010112345', 'source' => 'customer_stated', 'status' => 'valid']);

        $turn = $this->turnFor($conversation);
        $request = app(ContextBuilder::class)->build($turn);

        $this->assertStringNotContainsString('29001010112345', $request->system);
        $this->assertStringContainsString('national_id', $request->system);
    }

    public function test_recent_messages_budget_trims_the_oldest_first(): void
    {
        [$conversation] = $this->conversationWithCustomer();
        config(['agent.context.recent_messages_tokens' => 5]);

        foreach (range(1, 5) as $i) {
            WhatsappMessage::create([
                'whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming',
                'sender_type' => 'customer', 'type' => 'text', 'text' => "رسالة رقم {$i} هنا عشان تاخد توكنز كفاية",
            ]);
        }

        $turn = $this->turnFor($conversation);
        $request = app(ContextBuilder::class)->build($turn);

        $manifest = AiTrace::where('turn_id', $turn->id)->value('context_manifest');

        $this->assertTrue($manifest['l7_trimmed']);
        $this->assertLessThan(5, $manifest['l7_count']);
        // The most recent message must be the one kept, never dropped.
        $contents = $request->contents;
        $lastContent = end($contents);
        $this->assertStringContainsString('رسالة رقم 5', $lastContent['parts'][0]['text']);
    }

    public function test_quoted_message_and_its_stored_focus_appear_in_l8(): void
    {
        [$conversation] = $this->conversationWithCustomer();

        $botMessage = WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing',
            'sender_type' => 'bot', 'type' => 'text', 'text' => 'دي بوكسر 150 سعرها 50000',
            'metadata' => ['focus_motorcycle_ids' => [12]],
        ]);

        $turn = $this->turnFor($conversation);

        $customerMessage = WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming',
            'sender_type' => 'customer', 'type' => 'text', 'text' => 'طب دي فيها ألوان؟',
            'quoted_message_id' => $botMessage->id, 'turn_id' => $turn->id,
        ]);

        $request = app(ContextBuilder::class)->build($turn);

        $contents = $request->contents;
        $l8 = end($contents);
        $texts = implode(' | ', array_column($l8['parts'], 'text'));

        $this->assertStringContainsString('بوكسر 150', $texts);
        $this->assertStringContainsString('12', $texts);
        $this->assertStringContainsString('طب دي فيها ألوان؟', $texts);
    }

    public function test_customer_type_keys_are_listed_so_the_model_never_has_to_guess_them(): void
    {
        // Regression: start_application etc. take an exact customer_type
        // key, but nothing told the model what the valid keys were - it
        // guessed invented strings ("موظف قطاع خاص", "freelance"), all
        // rejected with UNKNOWN_CUSTOMER_TYPE.
        [$conversation] = $this->conversationWithCustomer();
        CustomerType::create(['key' => 'employee', 'label' => 'موظف متأمن عليه', 'is_active' => true, 'sort' => 1]);
        CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر', 'is_active' => true, 'sort' => 2]);
        CustomerType::create(['key' => 'retired_inactive', 'label' => 'نوع غير مفعل', 'is_active' => false]);

        $turn = $this->turnFor($conversation);
        $request = app(ContextBuilder::class)->build($turn);

        $this->assertStringContainsString('employee: موظف متأمن عليه', $request->system);
        $this->assertStringContainsString('self_employed: عامل حر', $request->system);
        $this->assertStringNotContainsString('retired_inactive', $request->system);
    }

    public function test_an_attached_images_media_id_is_visible_as_text_in_l8(): void
    {
        // Regression: process_document/identify_motorcycle_from_image both
        // require a media_id argument, but the model was only ever shown
        // the raw image bytes - with no id anywhere in text, it had no way
        // to reference the image it just received in a tool call.
        \Illuminate\Support\Facades\Storage::fake('local');
        [$conversation] = $this->conversationWithCustomer();
        $turn = $this->turnFor($conversation);

        $message = WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming',
            'sender_type' => 'customer', 'type' => 'image', 'turn_id' => $turn->id,
        ]);
        \Illuminate\Support\Facades\Storage::disk('local')->put('fake.jpg', 'fake-bytes');
        $media = \App\Models\MessageMedia::create([
            'message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg',
            'disk' => 'local', 'path' => 'fake.jpg', 'size' => 10,
        ]);

        $request = app(ContextBuilder::class)->build($turn);

        $contents = $request->contents;
        $l8 = end($contents);
        $texts = implode(' | ', array_column(array_filter($l8['parts'], fn ($p) => $p['type'] === 'text'), 'text'));

        $this->assertStringContainsString((string) $media->id, $texts);
    }

    public function test_an_earlier_image_nobody_processed_is_listed_with_its_media_id(): void
    {
        // Persona C's license and persona D's pension statement were sent in
        // earlier messages; the model could not reach them and asked again.
        \Illuminate\Support\Facades\Storage::fake('local');
        [$conversation] = $this->conversationWithCustomer();
        $earlier = WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image', 'text' => 'دي الرخصة']);
        $media = \App\Models\MessageMedia::create(['message_id' => $earlier->id, 'media_type' => 'image', 'mime' => 'image/jpeg', 'disk' => 'local', 'path' => 'l.jpg', 'size' => 10]);
        $turn = $this->turnFor($conversation);
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'بعتلك الرخصة فوق', 'turn_id' => $turn->id]);

        $request = app(ContextBuilder::class)->build($turn);

        $this->assertStringContainsString('"unprocessed_media"', $request->system);
        $this->assertStringContainsString('"media_id": '.$media->id, $request->system);
        $history = json_encode($request->contents, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('media_id: '.$media->id, $history);
    }

    public function test_the_manifest_is_recorded_on_the_turns_trace(): void
    {
        [$conversation] = $this->conversationWithCustomer();
        $turn = $this->turnFor($conversation);

        app(ContextBuilder::class)->build($turn);

        $trace = AiTrace::where('conversation_id', $conversation->id)->where('turn_id', $turn->id)->first();

        $this->assertNotNull($trace);
        $this->assertSame('v2.1.0', $trace->prompt_version);
        $this->assertIsArray($trace->context_manifest);
        $this->assertArrayHasKey('total_input_tokens_estimate', $trace->context_manifest);
    }

    public function test_the_stable_prefix_is_byte_identical_across_two_turns(): void
    {
        [$conversation] = $this->conversationWithCustomer();
        BusinessMemory::create(['key' => 'note1', 'title' => 'ثابتة', 'content' => 'محتوى', 'is_pinned' => false, 'is_active' => true]);

        $turn1 = $this->turnFor($conversation);
        $request1 = app(ContextBuilder::class)->build($turn1);

        $turn2 = $this->turnFor($conversation);
        $request2 = app(ContextBuilder::class)->build($turn2);

        $marker = '## الحالة الحالية';
        $prefix1 = substr($request1->system, 0, strpos($request1->system, $marker));
        $prefix2 = substr($request2->system, 0, strpos($request2->system, $marker));

        $this->assertSame($prefix1, $prefix2);
    }

    public function test_summarizer_updates_summary_and_a_failure_keeps_the_old_one(): void
    {
        [$conversation] = $this->conversationWithCustomer();

        $messages = collect(range(1, 3))->map(fn ($i) => WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming',
            'sender_type' => 'customer', 'type' => 'text', 'text' => "رسالة {$i}",
        ]));

        $fake = new FakeAiProvider();
        $fake->queue(new AiResponse(textParts: ['العميل بيسأل عن الأسعار.'], toolCalls: [], finishReason: 'STOP', usage: [], model: 'gemini-test', keyId: null, latencyMs: 1));
        $this->app->instance(AiProvider::class, $fake);

        (new SummarizeConversation($conversation->id, $messages->last()->id + 1))->handle($fake);

        $conversation->refresh();
        $this->assertSame('العميل بيسأل عن الأسعار.', $conversation->summary);
        $this->assertSame($messages->last()->id, $conversation->summary_until_message_id);

        // A second batch, but the provider fails this time - old summary must survive.
        $newMessage = WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming',
            'sender_type' => 'customer', 'type' => 'text', 'text' => 'رسالة 4',
        ]);

        $failingFake = new FakeAiProvider(); // no responses queued -> throws
        (new SummarizeConversation($conversation->id, $newMessage->id + 1))->handle($failingFake);

        $conversation->refresh();
        $this->assertSame('العميل بيسأل عن الأسعار.', $conversation->summary);
        $this->assertSame($messages->last()->id, $conversation->summary_until_message_id);
    }
}
