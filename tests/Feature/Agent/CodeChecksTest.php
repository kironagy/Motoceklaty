<?php

namespace Tests\Feature\Agent;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Agent\Runtime\AgentRunner;
use App\Agent\Runtime\ReplyGuard;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationRequirement;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\RequirementField;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-10-04: 21 of the reviewer's 40 redos were facts the code can check
 * exactly - each check here has its wrong case and a right case like it.
 */
class CodeChecksTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappConversation $conversation;

    private Customer $customer;

    private CustomerType $type;

    private ReplyGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.guard.number_min_value' => 1000]);

        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $this->conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $this->customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $this->conversation->update(['customer_id' => $this->customer->id]);
        $this->type = CustomerType::create(['key' => 'employee', 'label' => 'موظف', 'legacy_work_status' => 'employee']);
        $this->guard = app(ReplyGuard::class);
    }

    private function check(string $reply, array $outcomes = [], array $contents = [], string $system = ''): ?string
    {
        return $this->guard->check(['messages' => [$reply]], $this->conversation, $system, $contents, $outcomes);
    }

    private function machine(string $brand, string $name, array $aliases = []): Machine
    {
        return Machine::create([
            'name' => $name, 'brand_id' => Brand::firstOrCreate(['name' => $brand], ['image' => 'b.jpg'])->id, 'aliases' => $aliases ?: null,
            'cash_price' => 50000, 'installment_price' => 55000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal',
        ]);
    }

    private function application(array $attributes = []): Application
    {
        return Application::create($attributes + [
            'customer_id' => $this->customer->id, 'origin_conversation_id' => $this->conversation->id,
            'customer_type_id' => $this->type->id, 'status' => 'collecting',
        ]);
    }

    private function message(string $direction, string $text): void
    {
        WhatsappMessage::create([
            'whatsapp_conversation_id' => $this->conversation->id, 'direction' => $direction,
            'sender_type' => $direction === 'incoming' ? 'customer' : 'bot', 'type' => 'text', 'text' => $text,
        ]);
    }

    // A1

    public function test_photos_are_claimed_only_for_the_model_that_was_sent(): void
    {
        $this->machine('كيواي', 'Rk200 R', ['ار كي 200']);
        $lifan = $this->machine('Scooters', 'Lifan 150', ['ليفان 150', 'لايفان 150']);
        $sent = [['name' => 'send_motorcycle_images', 'ok' => true, 'data' => ['motorcycle' => 'Scooters Lifan 150', 'motorcycle_id' => $lifan->id, 'queued_count' => 2]],
            ['name' => 'send_motorcycle_images', 'ok' => false, 'data' => ['code' => 'MOTORCYCLE_DIFFERS_FROM_LAST_QUOTED']]];

        $this->assertNull($this->check('تمام يا باشا، بعتلك صور الليفان.', $sent));
        $this->assertSame('IMAGES_CLAIMED_FOR_UNSENT_MODEL', $this->check('تمام يا باشا، بعتلك صور الكيواي والليفان.', $sent));
        $this->assertStringContainsString('Lifan 150', $this->guard->lastDetail());
        // an offer of the other one's photos is no claim
        $this->assertNull($this->check('بعتلك صور الليفان. تحب أبعتلك صور الكيواي كمان؟', $sent));
    }

    // A2

    public function test_i_opened_your_application_is_only_said_in_the_turn_that_opened_it(): void
    {
        $this->assertSame('APPLICATION_CLAIMED_NOT_OPENED', $this->check('تمام، بدأتلك الطلب، ابعتلي وش البطاقة.'));

        $this->application();
        $stillOpen = [['name' => 'start_application', 'ok' => true, 'data' => ['application_id' => 1, 'created' => false]]];
        $this->assertSame('APPLICATION_ALREADY_OPEN_CLAIMED', $this->check('تمام، فتحتلك الطلب، ابعتلي وش البطاقة.', $stillOpen));
        $this->assertSame('APPLICATION_ALREADY_OPEN_CLAIMED', $this->check('تمام يا فندم، هابدأ معاكي الطلب دلوقتي.'));

        $opened = [['name' => 'start_application', 'ok' => true, 'data' => ['application_id' => 1, 'created' => true]]];
        $this->assertNull($this->check('تمام، فتحتلك الطلب، ابعتلي وش البطاقة.', $opened));
        $this->assertNull($this->check('طلبك مفتوح وفاضل البطاقة.'));
        $this->assertNull($this->check('لو تحب أفتحلك الطلب دلوقتي؟'));
    }

    // A3

    public function test_the_application_is_said_to_be_on_what_it_is_really_set_to(): void
    {
        $keeway = $this->machine('كيواي', 'Rk200 R');
        $this->machine('Scooters', 'Lifan 150', ['ليفان 150']);
        // the Latin "Keeway" is in the real catalog on this one only
        $this->machine('Scooters', 'Keeway keet 150', ['كيواي كيت 150']);
        $system = InstallmentSystem::create(['name' => 'S', 'pricing_mode' => 'standard', 'plans' => [['months' => 12, 'interest' => 20]], 'administrative_fees' => 7]);
        $plan = InstallmentPlan::where('installment_system_id', $system->id)->first();
        $this->application(['machine_id' => $keeway->id, 'installment_plan_id' => $plan->id]);
        $opened = [['name' => 'start_application', 'ok' => true, 'data' => ['application_id' => 1, 'created' => true]]];

        $this->assertSame('SELECTION_CLAIMED_NOT_SET', $this->check('تمام، فتحتلك الطلب على LIFAN على سنة.', $opened));
        $this->assertStringContainsString('12 months', $this->guard->lastDetail());
        $this->assertSame('SELECTION_CLAIMED_NOT_SET', $this->check('تمام، طلبك على الكيواي على سنتين.'));
        $this->assertNull($this->check('تمام، طلبك على Keeway سنة.'));
        $this->assertNull($this->check('تمام، طلبك على الكيواي 12 شهر.', [['name' => 'update_application_selection', 'ok' => true, 'data' => ['months' => 12]]]));
    }

    // A4

    public function test_photos_arrived_is_not_said_when_reading_them_failed(): void
    {
        $failed = [['name' => 'process_document', 'ok' => false, 'data' => ['code' => 'MEDIA_NOT_FOUND']]];
        $accepted = [['name' => 'process_document', 'ok' => true, 'data' => ['results' => [['accepted' => true, 'applied_fields' => ['full_name']]]]]];

        $this->assertSame('DOCUMENT_CLAIMED_NOT_ACCEPTED', $this->check('تمام وصلت الصور، ابعت الضهر.', $failed));
        $this->assertSame('DOCUMENT_CLAIMED_NOT_ACCEPTED', $this->check('تمام يا باشا، البطاقة تمام.', $failed));
        $this->assertNull($this->check('تمام وصلت الصور، ابعت الضهر.', $accepted));
        $this->assertNull($this->check('ابعت صورة البطاقة وتكون واضحة.', $failed));
    }

    // A5

    public function test_done_is_not_said_while_the_application_still_misses_something(): void
    {
        RequirementField::create(['key' => 'work_landmark', 'label' => 'علامة مميزة جنب الشغل', 'data_type' => 'text', 'scope' => 'application', 'is_active' => true]);
        ApplicationRequirement::create(['customer_type_id' => $this->type->id, 'requirement_type' => 'field',
            'requirement_field_id' => RequirementField::where('key', 'work_landmark')->value('id'), 'is_required' => true]);
        $application = $this->application();

        $this->assertSame('COMPLETION_OVERCLAIMED', $this->check('تمام كده كملنا التسجيل.'));
        $this->assertStringContainsString('علامة مميزة', $this->guard->lastDetail());
        $this->assertNull($this->check('تمام، فاضل بس علامة مميزة جنب الشغل.'));
        $this->assertNull($this->check('الطلب مش جاهز لسه.'));

        ApplicationData::create(['application_id' => $application->id, 'party' => 'applicant', 'field_key' => 'work_landmark', 'value' => 'جنب المسجد', 'source' => 'customer_stated', 'status' => 'valid']);
        // the data is complete even with the motorcycle still to choose
        $this->assertNull($this->check('تمام كده كملنا التسجيل.'));
    }

    // B1

    public function test_a_price_from_our_own_earlier_message_needs_a_lookup_now(): void
    {
        $contents = [['role' => 'model', 'parts' => [['type' => 'text', 'text' => 'سعرها كاش 50,000 جنيه.']]],
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'تمام وبالتقسيط؟']]]];

        $this->assertSame('UNVERIFIED_NUMBER', $this->check('الكاش 50,000 جنيه.', [], $contents));
        $this->assertNull($this->check('الكاش 50,000 جنيه.', [['name' => 'get_motorcycle_details', 'ok' => true, 'data' => ['cash_price' => 50000]]], $contents));
        $this->assertNull($this->check('عندنا 3 موديلات في الفئة دي.', [], $contents));
        // his own figure is still his
        $mine = [['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'معايا 40,000 مقدم']]]];
        $this->assertNull($this->check('تمام، الـ 40,000 مقدم نحسب عليهم.', [], $mine));
    }

    // B2

    public function test_a_model_is_named_only_after_something_looked_it_up(): void
    {
        $this->machine('باجاج', 'بوكسر ١٥٠', ['Boxer 150', 'بوكسر']);

        $this->assertSame('MODEL_NOT_LOOKED_UP', $this->check('عندنا كمان باجاج بوكسر 150 لو تحب.'));
        $this->assertNull($this->check('عندنا كمان باجاج بوكسر 150 لو تحب.', [['name' => 'search_motorcycles', 'ok' => true, 'data' => ['results' => [['name' => 'بوكسر ١٥٠']]]]]));

        $this->message('incoming', 'عايز بوكسر');
        $this->assertNull($this->check('البوكسر 150 من أحسن المكن عندنا.'));
    }

    // B3

    public function test_a_warranty_period_or_an_approval_time_needs_a_source(): void
    {
        $this->assertSame('UNRECORDED_PROMISE', $this->check('الضمان مكتوب على الورقة 6 شهور.'));
        $this->assertNotNull($this->check('الموافقة بتاخد 24-72 ساعة.'));
        $this->assertNotNull($this->check('الموافقة 24-72 ساعة.'));
        $this->assertNull($this->check('الضمان بيوضحه الزميل في الفرع.'));
        $this->assertNull($this->check('الضمان 6 شهور على الموتور.', [['name' => 'get_motorcycle_details', 'ok' => true, 'data' => ['warranty' => 'ضمان 6 شهور على الموتور']]]));
    }

    // C

    // A0 + the order: code checks, then the reviewer

    private function turnWith(string $text): object
    {
        $id = DB::table('whatsapp_message_jobs')->insertGetId([
            'whatsapp_bot_id' => $this->conversation->whatsapp_bot_id, 'whatsapp_conversation_id' => $this->conversation->id,
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        WhatsappMessage::create(['whatsapp_conversation_id' => $this->conversation->id, 'turn_id' => $id, 'direction' => 'incoming',
            'sender_type' => 'customer', 'type' => 'text', 'text' => $text]);

        return DB::table('whatsapp_message_jobs')->find($id);
    }

    private function fake(): FakeAiProvider
    {
        config(['agent.runtime.max_model_calls' => 6, 'agent.runtime.max_tool_calls' => 10, 'agent.runtime.wall_clock_seconds' => 30]);
        $fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $fake);

        return $fake;
    }

    private function reply(string $text, string $id): AiResponse
    {
        return new AiResponse([], [['id' => $id, 'name' => 'send_reply', 'args' => ['messages' => [$text]]]], 'STOP', ['input_tokens' => 10, 'output_tokens' => 5], 'gpt-test', null, 5);
    }

    private function jsonAnswer(array $data): AiResponse
    {
        return new AiResponse([json_encode($data, JSON_UNESCAPED_UNICODE)], [], 'STOP', ['input_tokens' => 10, 'output_tokens' => 5], 'gpt-test', null, 5);
    }

    private function fix(string $reply, array $outcomes = []): array
    {
        return $this->guard->autofix(['messages' => [$reply]], $this->conversation, $outcomes)['messages'];
    }

    public function test_the_reviewer_is_not_called_when_he_asked_nothing(): void
    {
        config(['agent.understanding.enabled' => false, 'agent.reviewer.enabled' => true]);
        $fake = $this->fake();
        $fake->queue($this->reply('تمام يا باشا، ابعت ضهر البطاقة.', 't1'));

        app(AgentRunner::class)->run($this->turnWith('تمام'));

        $this->assertCount(1, $fake->requests());
    }

    public function test_a_work_address_on_his_own_street_is_saved_when_he_names_the_workplace(): void
    {
        foreach (['address' => 'عنوان السكن', 'work_address' => 'عنوان الشغل'] as $key => $label) {
            RequirementField::create(['key' => $key, 'label' => $label, 'data_type' => 'address', 'scope' => 'application', 'is_active' => true]);
        }
        $application = $this->application();
        $this->message('incoming', 'ساكن القليوبيه الخصوص شارع السعاده متفرع من شارع الصرف');
        $data = app(\App\Domain\Applications\CustomerDataService::class);
        $data->record($this->customer, $application, [['key' => 'address', 'value' => 'القليوبيه الخصوص شارع السعاده متفرع من شارع الصرف']], $this->conversation->id);

        $this->message('incoming', 'القهوة القليوبيه الخصوص شارع السعاده متفرع من شارع الصرف');
        // the model says it: he works on his own street (no phrase list guesses it)
        $result = $data->record($this->customer, $application, [['key' => 'work_address', 'value' => 'القليوبيه الخصوص شارع السعاده متفرع من شارع الصرف', 'same_as_home' => true]], $this->conversation->id);

        $this->assertSame(['work_address'], $result['saved'], json_encode($result));
    }
}
