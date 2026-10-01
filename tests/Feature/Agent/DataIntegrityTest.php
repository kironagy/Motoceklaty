<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\RecordCustomerDataTool;
use App\Agent\Tools\StartApplicationTool;
use App\Agent\Tools\ToolContext;
use App\Agent\Tools\UpdateApplicationSelectionTool;
use App\Domain\Applications\CustomerDataService;
use App\Domain\Applications\SnapshotService;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerAttribute;
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
use Tests\TestCase;

/**
 * Adversarial regressions from the live QA: data the customer never said,
 * customer types nobody stated, names shortened by OCR, one customer's ID on
 * another customer's application, and selection arguments that were silently
 * dropped.
 */
class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappConversation $conversation;

    private Customer $customer;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();

        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $this->conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $this->customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $this->conversation->update(['customer_id' => $this->customer->id]);

        $type = CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر', 'is_active' => true]);
        CustomerType::create(['key' => 'employee', 'label' => 'موظف', 'is_active' => true]);

        RequirementField::create(['key' => 'full_name', 'label' => 'الاسم', 'data_type' => 'person_name', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        RequirementField::create(['key' => 'address', 'label' => 'العنوان', 'data_type' => 'address', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        RequirementField::create(['key' => 'national_id', 'label' => 'الرقم القومي', 'data_type' => 'national_id', 'scope' => 'customer', 'is_sensitive' => true, 'is_active' => true]);
        RequirementField::create(['key' => 'work_type', 'label' => 'نوع الشغل', 'data_type' => 'enum', 'enum_options' => ['delivery_app', 'craftsman'], 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);

        $this->application = Application::create([
            'customer_id' => $this->customer->id, 'origin_conversation_id' => $this->conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting',
        ]);
    }

    private function say(string $text): WhatsappMessage
    {
        return WhatsappMessage::create([
            'whatsapp_conversation_id' => $this->conversation->id, 'direction' => 'incoming',
            'sender_type' => 'customer', 'type' => 'text', 'text' => $text,
        ]);
    }

    private function document(): ApplicationDocument
    {
        $message = WhatsappMessage::create(['whatsapp_conversation_id' => $this->conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image']);
        $media = \App\Models\MessageMedia::create(['message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg', 'disk' => 'local', 'path' => 'x.jpg', 'size' => 1, 'sha256' => uniqid()]);

        return ApplicationDocument::create(['application_id' => $this->application->id, 'media_id' => $media->id, 'party' => 'applicant', 'status' => 'accepted', 'detected_type_key' => 'national_id_front', 'attempts' => 1]);
    }

    private function ctx(?int $applicationId = null): ToolContext
    {
        $trace = AiTrace::create(['conversation_id' => $this->conversation->id, 'turn_id' => 1, 'status' => 'running']);

        return new ToolContext($this->customer->id, $this->conversation->id, $applicationId ?? $this->application->id, 1, $trace->id, new TurnResultBuilder());
    }

    public function test_a_value_read_from_an_image_is_not_accepted_as_customer_stated(): void
    {
        // Persona C: the ID card's address was saved as what he "said".
        $this->say('دي البطاقة والرخصة');

        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [
            ['key' => 'address', 'value' => '1006 زهراء مدينة نصر، القاهرة'],
        ]], $this->ctx());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('NOT_STATED_BY_CUSTOMER', $result->error['detail']);
        $this->assertSame(0, ApplicationData::count());
    }

    public function test_a_value_the_customer_wrote_is_saved_with_its_real_evidence(): void
    {
        $this->say('مساء الخير');
        $said = $this->say('انا ساكن في الخصوص شارع السعاده عقار 3');

        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [
            ['key' => 'address', 'value' => 'الخصوص، شارع السعادة، عقار 3'],
        ]], $this->ctx());

        $this->assertTrue($result->ok);
        $this->assertSame($said->id, ApplicationData::where('field_key', 'address')->value('evidence_message_id'));
    }

    public function test_a_national_id_typed_by_the_model_from_a_photo_is_rejected(): void
    {
        $this->say('ودي البطاقة');

        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [
            ['key' => 'national_id', 'value' => '30411262102496'],
        ]], $this->ctx());

        $this->assertFalse($result->ok);
        $this->assertSame(0, CustomerAttribute::count());
    }

    public function test_an_enum_needs_the_customers_own_words(): void
    {
        $this->say('انا شغال على اوبر وديدي بقالي سنتين');

        $noQuote = app(RecordCustomerDataTool::class)->execute(['fields' => [['key' => 'work_type', 'value' => 'delivery_app']]], $this->ctx());
        $this->assertFalse($noQuote->ok);

        $inventedQuote = app(RecordCustomerDataTool::class)->execute(['fields' => [['key' => 'work_type', 'value' => 'craftsman', 'quote' => 'انا نجار']]], $this->ctx());
        $this->assertFalse($inventedQuote->ok);

        $quoted = app(RecordCustomerDataTool::class)->execute(['fields' => [['key' => 'work_type', 'value' => 'delivery_app', 'quote' => 'شغال على اوبر']]], $this->ctx());
        $this->assertTrue($quoted->ok);
    }

    public function test_an_application_cannot_be_opened_with_a_customer_type_nobody_stated(): void
    {
        // Persona G: opened as "employee" while the reply asked if he was one.
        $this->say('طب لا خليها تقسيط بس المقدم 30 الف');

        $result = app(StartApplicationTool::class)->execute(['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف'], $this->ctx(0));

        $this->assertSame('CUSTOMER_TYPE_NOT_STATED', $result->error['code']);
    }

    public function test_working_in_a_workshop_is_not_owning_it(): void
    {
        // Opened as صاحب ورشة: asked for shop photos he does not have.
        CustomerType::create(['key' => 'business_owner', 'label' => 'صاحب نشاط', 'is_active' => true]);
        $this->say('انا شغال في ورشة ميكانيكا');

        $worker = app(StartApplicationTool::class)->execute(['customer_type' => 'business_owner', 'customer_type_quote' => 'شغال في ورشة ميكانيكا'], $this->ctx(0));
        $this->assertSame('OWNERSHIP_NOT_STATED', $worker->error['code']);

        $this->say('لا انا صاحب الورشة');
        $owner = app(StartApplicationTool::class)->execute(['customer_type' => 'business_owner', 'customer_type_quote' => 'انا صاحب الورشة'], $this->ctx(0));
        $this->assertTrue($owner->ok);
    }

    public function test_the_home_address_is_not_saved_as_the_work_address(): void
    {
        RequirementField::create(['key' => 'work_address', 'label' => 'عنوان الشغل', 'data_type' => 'address', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        $this->say('انا ساكن في الخصوص شارع السعاده عقار 3');

        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [
            ['key' => 'address', 'value' => 'الخصوص، شارع السعادة، عقار 3'],
            ['key' => 'work_address', 'value' => 'الخصوص، شارع السعادة، عقار 3'],
        ]], $this->ctx());

        $this->assertSame(['address'], $result->data['saved']);
        $this->assertSame('SAME_AS_RESIDENCE', $result->data['rejected'][0]['code']);

        // he says so himself: then it is his work address too
        $this->say('الورشة تحت البيت نفس العنوان');
        $same = app(RecordCustomerDataTool::class)->execute(['fields' => [
            ['key' => 'work_address', 'value' => 'الخصوص، شارع السعادة، عقار 3'],
        ]], $this->ctx());
        $this->assertTrue($same->ok);
    }

    public function test_the_kind_of_place_is_not_a_building_number_or_a_landmark(): void
    {
        // Request 4278: "شغل في ورشه" became work_building_no=ورشة and
        // work_landmark=ورشة, and part of his home address the work address.
        RequirementField::create(['key' => 'work_address', 'label' => 'عنوان الشغل', 'data_type' => 'address', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        RequirementField::create(['key' => 'work_building_no', 'label' => 'رقم العقار', 'data_type' => 'string', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        RequirementField::create(['key' => 'work_landmark', 'label' => 'علامة', 'data_type' => 'string', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        $this->say('شغل في ورشه');
        $this->say('القاهرالعنوان عزبه النخل شارع الشيخ منصور شارع حاج عبد اللطيف رابع بيت على الشمال');

        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [
            ['key' => 'address', 'value' => 'القاهرة، عزبة النخل، شارع الشيخ منصور، شارع حاج عبد اللطيف'],
            ['key' => 'work_address', 'value' => 'القاهرة، عزبة النخل، شارع الشيخ منصور'],
            ['key' => 'work_building_no', 'value' => 'ورشة'],
            ['key' => 'work_landmark', 'value' => 'ورشة'],
        ]], $this->ctx());

        $this->assertSame(['address'], $result->data['saved']);
        $this->assertEqualsCanonicalizing(['SAME_AS_RESIDENCE', 'NOT_A_BUILDING_NUMBER', 'NOT_A_LANDMARK'], array_column($result->data['rejected'], 'code'));
    }

    public function test_owning_the_flat_is_not_its_number(): void
    {
        // "الدور الثاني والشقه ملك" - the apartment number became "شقة ملك".
        RequirementField::create(['key' => 'address_apartment', 'label' => 'رقم الشقة', 'data_type' => 'string', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        RequirementField::create(['key' => 'address_floor', 'label' => 'الدور', 'data_type' => 'string', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        $this->say('رابع بيت على الشمال الدور الثاني والشقه ملك');

        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [
            ['key' => 'address_floor', 'value' => 'الدور الثاني'],
            ['key' => 'address_apartment', 'value' => 'الشقه ملك'],
        ]], $this->ctx());

        $this->assertSame(['address_floor'], $result->data['saved']);
        $this->assertSame('NOT_AN_APARTMENT_NUMBER', $result->data['rejected'][0]['code']);
    }

    public function test_the_flat_number_is_not_the_floor_and_a_named_building_is_a_building(): void
    {
        // Request 4276: "عماره 2 شقه 41" - 41 became the floor.
        foreach (['address_floor' => 'الدور', 'address_apartment' => 'الشقة', 'address_building_no' => 'العقار'] as $key => $label) {
            RequirementField::create(['key' => $key, 'label' => $label, 'data_type' => 'string', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        }
        $this->say('مدينه بدر الحي التاني عماره 2 شقه 41');
        $this->say('عمارة السندباد الدور الخامس');

        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [
            ['key' => 'address_floor', 'value' => '41'],
            ['key' => 'address_apartment', 'value' => '41'],
            ['key' => 'address_building_no', 'value' => 'عمارة السندباد'],
        ]], $this->ctx());

        $this->assertSame(['address_apartment', 'address_building_no'], $result->data['saved']);
        $this->assertSame('NOT_A_FLOOR', $result->data['rejected'][0]['code']);
    }

    public function test_an_insured_employee_is_not_switched_to_self_employed(): void
    {
        // Request 4272: "متأمن عليا بس في فترة تقييم" was moved to عامل حر.
        $this->say('متأمن عليا بس انا حاليا في فتره تقييم من الشركه ف مش هعرف اطلع مفردات مرتب');

        $switch = app(UpdateApplicationSelectionTool::class)->execute(['customer_type' => 'self_employed', 'customer_type_quote' => 'مش هعرف اطلع مفردات مرتب'], $this->ctx());
        $this->assertSame('INSURED_IS_EMPLOYEE', $switch->error['code']);
    }

    public function test_an_employee_who_is_not_insured_may_apply_as_self_employed(): void
    {
        // the owner's one exception
        $this->say('انا موظف بس مش متأمن عليا ومعنديش مفردات');

        $this->assertFalse(StartApplicationTool::saidInsured($this->conversation->id));
        $switch = app(UpdateApplicationSelectionTool::class)->execute(['customer_type' => 'self_employed', 'customer_type_quote' => 'مش متأمن عليا'], $this->ctx());
        $this->assertTrue($switch->ok);
    }

    private function refusedWorkRule(): void
    {
        \App\Models\EligibilityRule::create(['rule_type' => 'excluded_occupation', 'is_active' => true, 'params' => [
            'words' => 'حكومي، حكومه، امين شرطه، ظابط، محامي، محاماه',
            'message' => 'للأسف جهات التمويل مش بتقبل الشغل الحكومي ولا المحاماة، فالطلب هيترفض.',
        ]]);
    }

    public function test_refused_work_is_named_plainly_and_no_application_is_opened(): void
    {
        // Lesson 33: a police sergeant got "بتتحفظ... لو حابب نجرب".
        $this->refusedWorkRule();
        $policy = app(\App\Domain\Applications\OccupationPolicy::class);

        $this->assertNotNull($policy->rejection('انا أمين شرطة'));
        $this->assertNotNull($policy->rejection('شغال في المحاماة'));
        $this->assertNotNull($policy->rejection('موظف حكومة'));
        $this->assertNull($policy->rejection('الفا شركه خاصه مش حكوميه بس موظف و متأمن عليا'));
        $this->assertNull($policy->rejection('انا شغال كهربائي'));

        $this->say('انا أمين شرطة وعايز اقسط');
        $result = app(StartApplicationTool::class)->execute(['customer_type' => 'employee', 'customer_type_quote' => 'انا أمين شرطة'], $this->ctx(0));

        $this->assertSame('OCCUPATION_NOT_ACCEPTED', $result->error['code']);
        $this->assertStringContainsString('هيترفض', $result->error['detail']);
    }

    public function test_a_softened_refusal_is_not_sent(): void
    {
        $guard = app(\App\Agent\Runtime\ReplyGuard::class);
        $outcomes = [['name' => 'start_application', 'ok' => false, 'data' => ['code' => 'OCCUPATION_NOT_ACCEPTED']]];

        $this->assertSame('OCCUPATION_SOFTENED', $guard->check(['messages' => ['مهنة أمين الشرطة من المهن اللي جهات التمويل بتتحفظ عليها، لو حابب نجرب ونقدم مفيش مشكلة.']], $this->conversation, '', [], $outcomes));
        $this->assertNull($guard->check(['messages' => ['للأسف جهات التمويل مش بتقبل الشغل الحكومي، فالطلب هيترفض. لو حابب تشتري كاش أنا معاك.']], $this->conversation, '', [], $outcomes));
    }

    public function test_an_ocr_name_missing_the_first_name_does_not_replace_the_full_name(): void
    {
        $this->say('انا سلام ناصر درويش عبدالمحسن');
        app(RecordCustomerDataTool::class)->execute(['fields' => [['key' => 'full_name', 'value' => 'سلام ناصر درويش عبدالمحسن']]], $this->ctx());

        $document = $this->document();
        app(CustomerDataService::class)->recordFromDocument($this->customer, $this->application, ['full_name' => 'ناصر درويش عبد المحسن'], $document->id);

        $row = ApplicationData::where('field_key', 'full_name')->first();
        $this->assertSame('سلام ناصر درويش عبدالمحسن', $row->value);
        $this->assertSame($document->id, $row->document_id);
    }

    public function test_the_customer_can_complete_a_name_the_document_shortened(): void
    {
        $document = $this->document();
        app(CustomerDataService::class)->recordFromDocument($this->customer, $this->application, ['full_name' => 'محمد علي احمد'], $document->id);
        $this->say('اسمي علي محمد علي احمد');

        $completed = app(RecordCustomerDataTool::class)->execute(['fields' => [['key' => 'full_name', 'value' => 'علي محمد علي احمد']]], $this->ctx());
        $this->assertTrue($completed->ok);
        $this->assertSame('علي محمد علي احمد', ApplicationData::where('field_key', 'full_name')->value('value'));

        // A different name is still a conflict, not an overwrite.
        $this->say('لا اسمي كريم سعيد');
        $different = app(RecordCustomerDataTool::class)->execute(['fields' => [['key' => 'full_name', 'value' => 'كريم سعيد']]], $this->ctx());
        $this->assertFalse($different->ok);
        $this->assertSame('علي محمد علي احمد', ApplicationData::where('field_key', 'full_name')->value('value'));
    }

    /**
     * Conversation 708: three simulator runs used the same test national ID,
     * and the third "customer" was handed to staff as an identity conflict.
     * Simulator and teach-mode customers are not people - they never clash
     * with anyone, and a real customer never clashes with them.
     */
    public function test_a_simulator_customer_never_counts_as_another_holder_of_the_identity(): void
    {
        $simulated = app(\App\Domain\Simulation\ConversationSimulator::class)->start('sim')->customer;
        CustomerAttribute::create(['customer_id' => $simulated->id, 'field_key' => 'national_id', 'value' => '30411262102496', 'source' => 'customer_stated', 'status' => 'valid']);
        CustomerAttribute::create(['customer_id' => $this->customer->id, 'field_key' => 'national_id', 'value' => '30411262102496', 'source' => 'document', 'status' => 'valid']);

        $snapshot = app(SnapshotService::class)->for($this->application);

        $this->assertNotContains(['key' => 'national_id', 'code' => 'IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER'], $snapshot['fields']['invalid']);
    }

    public function test_an_identity_already_held_by_another_customer_blocks_submission(): void
    {
        $other = Customer::create(['whatsapp_bot_id' => $this->customer->whatsapp_bot_id, 'jid' => '2012@s.whatsapp.net', 'phone' => '2012']);
        CustomerAttribute::create(['customer_id' => $other->id, 'field_key' => 'national_id', 'value' => '30411262102496', 'source' => 'document', 'status' => 'valid']);
        CustomerAttribute::create(['customer_id' => $this->customer->id, 'field_key' => 'national_id', 'value' => '30411262102496', 'source' => 'document', 'status' => 'valid']);

        $snapshot = app(SnapshotService::class)->for($this->application);

        $this->assertFalse($snapshot['can_submit']);
        $this->assertContains(['key' => 'national_id', 'code' => 'IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER'], $snapshot['fields']['invalid']);
        // The value itself is never stored in clear.
        $this->assertNotSame('30411262102496', CustomerAttribute::first()->getRawOriginal('lookup_hash'));
    }

    private function machineWithPlans(float $cash, array $systemIds): Machine
    {
        $brand = Brand::firstOrCreate(['name' => 'Bajaj'], ['image' => 'b.jpg']);

        return Machine::create([
            'name' => 'M'.uniqid(), 'brand_id' => $brand->id, 'cash_price' => $cash, 'installment_price' => $cash + 5000,
            'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal', 'installment_systems' => $systemIds,
        ]);
    }

    public function test_months_alone_keep_the_chosen_system_and_without_one_pick_the_best(): void
    {
        $aman = InstallmentSystem::create(['name' => 'امان', 'pricing_mode' => 'standard', 'administrative_fees' => 7, 'plans' => [['months' => 12, 'interest' => 20], ['months' => 24, 'interest' => 40]]]);
        $machine = $this->machineWithPlans(40000, [$aman->id]);
        $this->application->update(['machine_id' => $machine->id]);

        // The customer never picks a finance company: the best one for him is chosen.
        $noSystem = app(UpdateApplicationSelectionTool::class)->execute(['months' => 24, 'down_payment' => 5000], $this->ctx());
        $this->assertTrue($noSystem->ok);
        $this->assertSame('امان', $noSystem->data['selected_plan']['system']);
        $this->assertEquals(5000, $this->application->fresh()->down_payment);

        $this->application->update(['installment_plan_id' => InstallmentPlan::where('installment_system_id', $aman->id)->where('months', 12)->value('id')]);
        $monthsOnly = app(UpdateApplicationSelectionTool::class)->execute(['months' => 24], $this->ctx());
        $this->assertTrue($monthsOnly->ok);
        $this->assertSame(24, $monthsOnly->data['selected_plan']['months']);
    }

    public function test_a_motorcycle_change_clears_a_plan_and_down_payment_that_no_longer_fit(): void
    {
        $aman = InstallmentSystem::create(['name' => 'امان', 'pricing_mode' => 'standard', 'administrative_fees' => 7, 'plans' => [['months' => 12, 'interest' => 20]]]);
        $other = InstallmentSystem::create(['name' => 'حالا', 'pricing_mode' => 'standard', 'administrative_fees' => 0, 'plans' => [['months' => 12, 'interest' => 30]]]);
        $expensive = $this->machineWithPlans(80000, [$aman->id]);
        $cheap = $this->machineWithPlans(30000, [$other->id]);
        $this->application->update([
            'machine_id' => $expensive->id,
            'installment_plan_id' => InstallmentPlan::where('installment_system_id', $aman->id)->value('id'),
            'down_payment' => 50000,
        ]);

        $result = app(UpdateApplicationSelectionTool::class)->execute(['motorcycle_id' => $cheap->id], $this->ctx());

        $this->assertTrue($result->ok);
        $this->assertEqualsCanonicalizing(['plan', 'down_payment'], array_column(array_filter($result->data['invalidated'], fn ($i) => $i['kind'] === 'selection'), 'key'));
        $fresh = $this->application->fresh();
        $this->assertNull($fresh->installment_plan_id);
        $this->assertNull($fresh->down_payment);
    }

    public function test_a_down_payment_above_the_price_is_refused(): void
    {
        $aman = InstallmentSystem::create(['name' => 'امان', 'pricing_mode' => 'standard', 'administrative_fees' => 7, 'plans' => [['months' => 12, 'interest' => 20]]]);
        $machine = $this->machineWithPlans(40000, [$aman->id]);
        $this->application->update(['machine_id' => $machine->id]);

        $result = app(UpdateApplicationSelectionTool::class)->execute(['down_payment' => 45000], $this->ctx());

        $this->assertSame('DOWN_PAYMENT_TOO_HIGH', $result->error['code']);
        $this->assertNull($this->application->fresh()->down_payment);
    }

    /** Conversation 731: "لا يوجد رقم" saved as "مفيش رقم" was refused and asked again and again. */
    public function test_there_is_no_number_is_an_answer_in_any_wording(): void
    {
        RequirementField::create(['key' => 'work_building_no', 'label' => 'رقم عقار الشغل', 'data_type' => 'string', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        RequirementField::create(['key' => 'address_floor', 'label' => 'الدور', 'data_type' => 'string', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        $this->say('مش موجود والله رقم عقار للمدرسه');
        $this->say('لا يوجد رقم');

        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [['key' => 'work_building_no', 'value' => 'مفيش رقم']]], $this->ctx());

        $this->assertTrue($result->ok);
        $this->assertSame('لا يوجد', ApplicationData::where('field_key', 'work_building_no')->value('value'));

        // "الأخير" is a floor
        $this->say('الدور الأخير');
        $this->assertTrue(app(RecordCustomerDataTool::class)->execute(['fields' => [['key' => 'address_floor', 'value' => 'الأخير']]], $this->ctx())->ok);
    }

    public function test_none_is_not_accepted_when_he_never_said_it(): void
    {
        RequirementField::create(['key' => 'address_landmark', 'label' => 'علامة مميزة', 'data_type' => 'string', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        $this->say('ساكن في شبرا');

        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [['key' => 'address_landmark', 'value' => 'غير مذكور']]], $this->ctx());

        $this->assertFalse($result->ok);
        $this->assertSame(0, ApplicationData::where('field_key', 'address_landmark')->count());
    }
}
