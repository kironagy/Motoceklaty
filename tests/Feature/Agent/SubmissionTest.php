<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\SubmitApplicationTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Applications\SubmissionService;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\MessageMedia;
use App\Models\ApplicationRequirement;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\InstallmentPlan;
use App\Models\InstallmentRequest;
use App\Models\LegacyStatusMapping;
use App\Models\Machine;
use App\Models\RequirementField;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function completeApplication(): array
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '01012345678']);

        $type = CustomerType::create(['key' => 'employee', 'label' => 'Employee', 'legacy_work_status' => 'employee']);

        RequirementField::create([
            'key' => 'full_name', 'label' => 'Full name', 'data_type' => 'person_name',
            'scope' => 'application', 'is_sensitive' => false, 'is_active' => true,
        ]);
        ApplicationRequirement::create([
            'customer_type_id' => $type->id, 'requirement_type' => 'field',
            'requirement_field_id' => RequirementField::where('key', 'full_name')->value('id'),
            'is_required' => true,
        ]);

        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $machine = Machine::create([
            'name' => 'M', 'brand_id' => $brand->id, 'cash_price' => 50000, 'installment_price' => 55000,
            'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal',
        ]);
        $system = \App\Models\InstallmentSystem::create([
            'name' => 'S', 'pricing_mode' => 'standard', 'plans' => [['months' => 12, 'interest' => 20]], 'administrative_fees' => 7,
        ]);
        $machine->update(['installment_systems' => [$system->id]]);
        $plan = InstallmentPlan::where('installment_system_id', $system->id)->first();

        $application = Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'machine_id' => $machine->id, 'installment_plan_id' => $plan->id,
            'down_payment' => 5000, 'status' => 'collecting',
        ]);

        ApplicationData::create([
            'application_id' => $application->id, 'party' => 'applicant', 'field_key' => 'full_name',
            'value' => 'Mohamed Ali', 'source' => 'customer_stated', 'status' => 'valid',
        ]);

        return [$application, $conversation];
    }

    private function ctx(WhatsappConversation $conversation, Application $application, int $turnId = 1): ToolContext
    {
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => $turnId, 'status' => 'running']);

        return new ToolContext($application->customer_id, $conversation->id, $application->id, $turnId, $trace->id, new TurnResultBuilder());
    }

    /** Shows the summary in turn 1 and records it as delivered to the customer. */
    private function presentSummary(WhatsappConversation $conversation, Application $application, bool $delivered = true): string
    {
        $ctx = $this->ctx($conversation, $application, 1);
        $result = app(SubmitApplicationTool::class)->execute(['confirm' => true], $ctx);

        $this->assertFalse($result->ok);
        $this->assertSame('CUSTOMER_CONFIRMATION_REQUIRED', $result->error['code']);
        $summary = end($ctx->outbound->toArray()['messages']);

        WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'turn_id' => 1, 'direction' => 'outgoing', 'sender_type' => 'bot',
            'type' => 'text', 'text' => $summary, 'delivery_status' => $delivered ? 'sent' : 'failed',
        ]);

        return $summary;
    }

    private function submitConfirmed(WhatsappConversation $conversation, Application $application): \App\Agent\Tools\ToolResult
    {
        $this->presentSummary($conversation, $application);

        return app(SubmitApplicationTool::class)->execute(['confirm' => true], $this->ctx($conversation, $application, 2));
    }

    public function test_incomplete_application_is_not_submitted(): void
    {
        [$application, $conversation] = $this->completeApplication();
        ApplicationData::where('application_id', $application->id)->delete(); // remove the required field again

        $result = app(SubmitApplicationTool::class)->execute(['confirm' => true], $this->ctx($conversation, $application));

        // Not submitted is never ok:true - a customer was told "sent"
        // on {ok:true, submitted:false} with empty reasons.
        $this->assertFalse($result->ok);
        $this->assertSame('NOT_READY', $result->error['code']);
        $this->assertStringContainsString('full_name', $result->error['detail']);
        $this->assertSame('collecting', $application->refresh()->status);
    }

    public function test_an_application_without_a_plan_is_not_ready_instead_of_crashing(): void
    {
        [$application, $conversation] = $this->completeApplication();
        $application->update(['installment_plan_id' => null]);

        $result = app(SubmitApplicationTool::class)->execute(['confirm' => true], $this->ctx($conversation, $application));

        $this->assertSame('NOT_READY', $result->error['code']);
        $this->assertStringContainsString('"key":"plan"', $result->error['detail']);
    }

    public function test_the_summary_comes_from_stored_data_and_confirmation_needs_a_later_turn(): void
    {
        [$application, $conversation] = $this->completeApplication();
        $summary = $this->presentSummary($conversation, $application);

        $this->assertStringContainsString('Mohamed Ali', $summary);
        $this->assertStringContainsString('12 شهر', $summary);

        // Same turn again: still waiting for the customer.
        $sameTurn = app(SubmitApplicationTool::class)->execute(['confirm' => true], $this->ctx($conversation, $application, 1));
        $this->assertSame('CUSTOMER_CONFIRMATION_REQUIRED', $sameTurn->error['code']);
        $this->assertSame(0, InstallmentRequest::count());
    }

    public function test_a_summary_that_never_reached_the_customer_cannot_be_confirmed(): void
    {
        [$application, $conversation] = $this->completeApplication();
        $this->presentSummary($conversation, $application, delivered: false);

        $result = app(SubmitApplicationTool::class)->execute(['confirm' => true], $this->ctx($conversation, $application, 2));

        $this->assertSame('CUSTOMER_CONFIRMATION_REQUIRED', $result->error['code']);
        $this->assertSame(0, InstallmentRequest::count());
    }

    public function test_data_changed_after_the_summary_needs_a_new_summary(): void
    {
        [$application, $conversation] = $this->completeApplication();
        $this->presentSummary($conversation, $application);
        ApplicationData::first()->update(['value' => 'Other Name']);

        $result = app(SubmitApplicationTool::class)->execute(['confirm' => true], $this->ctx($conversation, $application, 2));

        $this->assertSame('CUSTOMER_CONFIRMATION_REQUIRED', $result->error['code']);
        $this->assertSame(0, InstallmentRequest::count());
    }

    public function test_complete_application_is_submitted_and_projected(): void
    {
        [$application, $conversation] = $this->completeApplication();

        $result = $this->submitConfirmed($conversation, $application);

        $this->assertTrue($result->ok);
        $this->assertTrue($result->data['submitted']);
        $this->assertNotNull($result->data['reference']['installment_request_id']);

        $application->refresh();
        $this->assertSame('submitted', $application->status);
        $this->assertNotNull($application->installment_request_id);

        $ir = InstallmentRequest::find($application->installment_request_id);
        $this->assertSame('bot', $ir->request_type);
        $this->assertSame('employee', $ir->work_status);
        $this->assertSame('Mohamed Ali', $ir->applicant_name);
        $this->assertSame('01012345678', $ir->applicant_phone);
        $this->assertSame(12, $ir->months);
        $this->assertSame('new', $ir->status);
        $this->assertSame($application->id, $ir->application_id);
    }

    public function test_submitted_request_lands_in_the_bot_tab_with_all_customer_data(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$application, $conversation] = $this->completeApplication();

        foreach ([
            ['applicant', 'national_id', '29501151234567'],
            ['applicant', 'address', 'شارع التحرير - الدقي'],
            ['applicant', 'address_building_no', '12'],
            ['applicant', 'address_floor', '3'],
            ['applicant', 'monthly_income', '8000'],
            ['applicant', 'work_address', 'شارع الهرم'],
            ['guarantor', 'guarantor_name', 'Ahmed Hassan'],
            ['guarantor', 'guarantor_phone', '01122223333'],
            ['guarantor', 'guarantor_national_id', '30001011234567'],
        ] as [$party, $key, $value]) {
            ApplicationData::create([
                'application_id' => $application->id, 'party' => $party, 'field_key' => $key,
                'value' => $value, 'source' => 'customer_stated', 'status' => 'valid',
            ]);
        }

        Storage::disk('local')->put('whatsapp-media/id.jpg', 'id-bytes');
        Storage::disk('local')->put('whatsapp-media/slip.jpg', 'slip-bytes');
        $message = WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image',
        ]);
        foreach (['national_id_front' => 'id.jpg', 'salary_slip' => 'slip.jpg'] as $typeKey => $file) {
            $type = DocumentType::create(['key' => $typeKey, 'label' => $typeKey, 'is_active' => true]);
            $media = MessageMedia::create([
                'message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg',
                'disk' => 'local', 'path' => 'whatsapp-media/'.$file, 'size' => 5,
            ]);
            ApplicationDocument::create([
                'application_id' => $application->id, 'document_type_id' => $type->id, 'media_id' => $media->id,
                'party' => 'applicant', 'status' => 'accepted',
            ]);
        }

        $this->assertTrue($this->submitConfirmed($conversation, $application)->ok);

        $ir = InstallmentRequest::find($application->refresh()->installment_request_id);
        $this->assertSame('bot', $ir->request_type);
        $this->assertSame('29501151234567', $ir->applicant_national_id);
        $this->assertSame('1995-01-15', $ir->applicant_birthdate->toDateString());
        $this->assertTrue($ir->applicant_age_ok);  // 31 years
        $this->assertTrue($ir->guarantor_age_ok);  // 26 years
        $this->assertSame('12', $ir->applicant_building_number);
        $this->assertStringContainsString('شارع التحرير', $ir->applicant_address);
        $this->assertSame(8000, (int) $ir->salary_amount);
        // the governorate comes from the district the street is named after
        $this->assertSame('شارع الهرم - الجيزة', $ir->work_address);
        $this->assertSame('Ahmed Hassan', $ir->guarantor_name);
        $this->assertSame('01122223333', $ir->guarantor_phone);
        $this->assertSame('30001011234567', $ir->guarantor_national_id);
        $this->assertStringContainsString('بوت', $ir->notes);

        $this->assertNotNull($ir->applicant_id_image);
        Storage::disk('public')->assertExists($ir->applicant_id_image);
        $this->assertSame('id-bytes', Storage::disk('public')->get($ir->applicant_id_image));
        Storage::disk('public')->assertExists($ir->salary_slip_file);
    }

    public function test_age_ok_is_true_only_between_21_and_62(): void
    {
        [$application] = $this->completeApplication();
        $projector = app(\App\Domain\Applications\LegacyRequestProjector::class);
        $row = ApplicationData::create([
            'application_id' => $application->id, 'party' => 'applicant', 'field_key' => 'national_id',
            'value' => '', 'source' => 'customer_stated', 'status' => 'valid',
        ]);

        $nationalIdAged = function (int $years, int $extraDays = 0): string {
            $d = now()->subYears($years)->subDays($extraDays);

            return ($d->year >= 2000 ? '3' : '2').$d->format('ymd').'0101234';
        };

        foreach ([[20, false], [21, true], [62, true], [63, false]] as [$years, $expected]) {
            $row->update(['value' => $nationalIdAged($years, 1)]);
            $this->assertSame($expected, $projector->attributes($application, 'employee')['applicant_age_ok'], "age {$years}");
        }
    }

    public function test_second_submit_is_idempotent(): void
    {
        [$application, $conversation] = $this->completeApplication();
        $first = $this->submitConfirmed($conversation, $application);
        $second = app(SubmitApplicationTool::class)->execute(['confirm' => true], $this->ctx($conversation, $application, 3));

        $this->assertSame($first->data['reference'], $second->data['reference']);
        $this->assertSame(1, InstallmentRequest::count());
    }

    public function test_staff_status_change_maps_back_to_the_application(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'wa_message_id' => 'abc'], 200)]);

        [$application, $conversation] = $this->completeApplication();
        $this->submitConfirmed($conversation, $application);
        $application->refresh();

        InstallmentRequest::find($application->installment_request_id)->update(['status' => 'approved']);

        $this->assertSame('approved', $application->refresh()->status);
    }

    public function test_unmapped_legacy_status_change_does_not_alter_application_status(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'wa_message_id' => 'abc'], 200)]);

        [$application, $conversation] = $this->completeApplication();
        $this->submitConfirmed($conversation, $application);
        $application->refresh();

        LegacyStatusMapping::where('legacy_status', 'work_check')->update(['application_status' => null]);
        InstallmentRequest::find($application->installment_request_id)->update(['status' => 'work_check']);

        $this->assertSame('submitted', $application->refresh()->status);
        $this->assertDatabaseHas('application_events', [
            'application_id' => $application->id,
            'type' => 'legacy_status_unmapped_change',
        ]);
    }

    public function test_the_customer_can_cancel_after_submitting_and_the_staff_request_is_canceled(): void
    {
        // Request 4278: "الغي الطلب" right after submitting got "ألغيت لك
        // الطلب" while the request stayed live for staff.
        [$application, $conversation] = $this->completeApplication();
        $this->submitConfirmed($conversation, $application);
        $application->refresh();

        $result = app(\App\Agent\Tools\WithdrawApplicationTool::class)->execute(['reason_code' => 'customer_request'], $this->ctx($conversation, $application, 3));

        $this->assertTrue($result->ok);
        $this->assertSame('withdrawn', $application->refresh()->status);
        $this->assertSame('canceled', InstallmentRequest::find($application->installment_request_id)->status);
        // the bot says it itself - no second "اتلغى" notification
        $this->assertSame(0, WhatsappMessage::where('sender_type', 'system')->count());
    }

    public function test_after_cancelling_he_can_continue_with_everything_he_sent(): void
    {
        [$application, $conversation] = $this->completeApplication();
        $this->submitConfirmed($conversation, $application);
        $oldRequest = $application->refresh()->installment_request_id;
        app(\App\Agent\Tools\WithdrawApplicationTool::class)->execute(['reason_code' => 'customer_request'], $this->ctx($conversation, $application, 3));

        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'لا خلاص كمل انا موظف']);
        $result = app(\App\Agent\Tools\StartApplicationTool::class)->execute(['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف'], $this->ctx($conversation, $application, 4));

        $this->assertTrue($result->ok);
        $this->assertSame($application->id, $result->data['application_id']);
        $this->assertArrayHasKey('reopened', $result->data);
        $this->assertSame('collecting', $application->refresh()->status);
        $this->assertNull($application->installment_request_id);
        $this->assertTrue($result->data['snapshot']['can_submit']);
        $this->assertSame('canceled', InstallmentRequest::find($oldRequest)->status);
    }

    public function test_the_address_split_never_changes_what_he_gave_field_by_field(): void
    {
        // Request 4276: the split made the street "مميز", took "الجامعة
        // الروسية" out of the address, and put it in the landmark.
        [$application] = $this->completeApplication();
        foreach (['address' => 'مدينه بدر مميز الجامعه الروسيه الحي التاني', 'address_building_no' => '2', 'address_apartment' => '41',
            'address_landmark' => 'شارع مدرسه تحيا مصر خلف البنك الأهلي'] as $key => $value) {
            ApplicationData::create(['application_id' => $application->id, 'party' => 'applicant', 'field_key' => $key, 'value' => $value, 'source' => 'customer_stated', 'status' => 'valid']);
        }
        $splitter = Mockery::mock(\App\Domain\Applications\AddressSplitter::class);
        $splitter->shouldReceive('split')->andReturn([
            'governorate' => 'القاهرة', 'area' => 'مدينة بدر - الحي التاني', 'street' => 'مميز', 'building_number' => '2',
            'apartment' => '41', 'landmark' => 'الجامعة الروسية، شارع مدرسة تحيا مصر، خلف البنك الأهلي',
        ]);
        $this->app->instance(\App\Domain\Applications\AddressSplitter::class, $splitter);

        $columns = app(\App\Domain\Applications\LegacyRequestProjector::class)->attributes($application, 'employee');

        $this->assertSame('مدينه بدر مميز الجامعه الروسيه الحي التاني', $columns['applicant_street']);
        $this->assertSame('شارع مدرسه تحيا مصر خلف البنك الأهلي', $columns['applicant_landmark']);
        $this->assertSame('41', $columns['applicant_apartment']);
        $this->assertArrayNotHasKey('applicant_floor', array_filter($columns));
    }

    public function test_a_building_number_stuck_to_the_street_keeps_a_correct_split(): void
    {
        // Request 4395: "48ش الحريه..." - the "48ش" token threw the whole
        // split away and the line landed in the street with no area.
        [$application] = $this->completeApplication();
        foreach (['address' => '48ش الحريه من جمال عبد الناصر المنيب الجيزه', 'address_building_no' => '48', 'address_landmark' => 'المنيب الجيزه',
            'work_address' => 'المعادي كورنيش صيدليه اللؤلؤه', 'work_building_no' => '33'] as $key => $value) {
            ApplicationData::create(['application_id' => $application->id, 'party' => 'applicant', 'field_key' => $key, 'value' => $value, 'source' => 'customer_stated', 'status' => 'valid']);
        }
        $splitter = Mockery::mock(\App\Domain\Applications\AddressSplitter::class);
        $splitter->shouldReceive('split')->with(Mockery::any(), Mockery::any(), 'home')->andReturn([
            'governorate' => 'الجيزة', 'area' => 'المنيب', 'street' => 'الحرية', 'branch_street' => 'جمال عبد الناصر', 'building_number' => '48',
        ]);
        // the work split came back as the home address
        $splitter->shouldReceive('split')->with(Mockery::any(), Mockery::any(), 'work')->andReturn([
            'governorate' => 'الجيزة', 'area' => 'المنيب', 'street' => 'الحريه', 'building_number' => '48',
        ]);
        $this->app->instance(\App\Domain\Applications\AddressSplitter::class, $splitter);

        $columns = app(\App\Domain\Applications\LegacyRequestProjector::class)->attributes($application, 'no_income_proof');

        $this->assertSame('الحرية', $columns['applicant_street']);
        $this->assertSame('المنيب', $columns['applicant_area']);
        $this->assertSame('جمال عبد الناصر', $columns['applicant_branch_street']);
        $this->assertSame('المعادي كورنيش صيدليه اللؤلؤه', $columns['work_street']);
        // the home's Giza never leaks in - the district in his own line decides
        $this->assertSame('القاهرة', $columns['work_governorate']);
        $this->assertSame('المعادي', $columns['work_area']);
    }

    public function test_without_the_ai_split_the_line_is_still_divided(): void
    {
        // server 2026-10-04: 7 of 15 requests had no governorate or area
        [$application] = $this->completeApplication();
        foreach (['address' => '34 شارع الشرفاء العشرين فيصل', 'address_building_no' => '34', 'address_floor' => '3',
            'work_address' => 'الهرم محطه مشعل شارع الامير متفرع من شارع زغلول'] as $key => $value) {
            ApplicationData::create(['application_id' => $application->id, 'party' => 'applicant', 'field_key' => $key, 'value' => $value, 'source' => 'customer_stated', 'status' => 'valid']);
        }
        $splitter = Mockery::mock(\App\Domain\Applications\AddressSplitter::class);
        $splitter->shouldReceive('split')->andReturn([]); // the provider was busy
        $this->app->instance(\App\Domain\Applications\AddressSplitter::class, $splitter);

        $columns = app(\App\Domain\Applications\LegacyRequestProjector::class)->attributes($application, 'no_income_proof');

        $this->assertSame('الجيزة', $columns['applicant_governorate']);
        $this->assertSame('فيصل', $columns['applicant_area']);
        $this->assertSame('الشرفاء العشرين', $columns['applicant_street']);
        $this->assertSame('3', $columns['applicant_floor']);
        $this->assertSame('الجيزة', $columns['work_governorate']);
        $this->assertSame('الهرم محطه مشعل', $columns['work_area']);
        $this->assertSame('الامير', $columns['work_street']);
        $this->assertSame('زغلول', $columns['work_branch_street']);
    }

    public function test_the_request_shows_what_he_works_not_just_the_category(): void
    {
        // owner 2026-10-04: "اسم العمل" said "شغل حر" for every freelancer
        [$application] = $this->completeApplication();
        $conversation = \App\Models\WhatsappConversation::find($application->origin_conversation_id);
        \App\Models\WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text',
            'text' => 'انا شغال صنايعي خراط وعجان في فرن بلدي وبكسب 9000 في الشهر وعندي رخصة']);
        app(\App\Domain\Memory\CustomerMemory::class)->applyModelUpdate($application->customer_id, $conversation->id, ['facts' => [
            ['key' => 'job', 'value' => 'صنايعي خراط وعجان', 'quote' => 'شغال صنايعي خراط وعجان'],
            ['key' => 'workplace', 'value' => 'فرن بلدي', 'quote' => 'في فرن بلدي'],
            ['key' => 'monthly_income', 'value' => '9000', 'quote' => 'بكسب 9000'],
            ['key' => 'has_driving_license', 'value' => 'معاه رخصة', 'quote' => 'عندي رخصة'],
        ]]);
        $splitter = Mockery::mock(\App\Domain\Applications\AddressSplitter::class);
        $splitter->shouldReceive('split')->andReturn([]);
        $this->app->instance(\App\Domain\Applications\AddressSplitter::class, $splitter);
        $this->app->instance(\App\Domain\Applications\WorkClassification::class, Mockery::mock(\App\Domain\Applications\WorkClassification::class, ['reading' => null]));

        $columns = app(\App\Domain\Applications\LegacyRequestProjector::class)->attributes($application, 'no_income_proof');

        $this->assertSame('صنايعي خراط وعجان - فرن بلدي', $columns['free_work_name']);
        $this->assertStringContainsString('المهنة: صنايعي خراط وعجان', $columns['notes']);
        $this->assertStringContainsString('مكان الشغل: فرن بلدي', $columns['notes']);
        $this->assertStringContainsString('الدخل الشهري: 9000', $columns['notes']);
        $this->assertStringContainsString('الرخصة: معاه رخصة', $columns['notes']);
    }

    public function test_an_address_with_no_street_leaves_the_street_empty(): void
    {
        [$application] = $this->completeApplication();
        foreach (['address' => 'محافظه القاهره مدينه بدر الحي الرابع ابن بيتك أ قطعه 194', 'address_building_no' => '194'] as $key => $value) {
            ApplicationData::create(['application_id' => $application->id, 'party' => 'applicant', 'field_key' => $key, 'value' => $value, 'source' => 'customer_stated', 'status' => 'valid']);
        }
        $splitter = Mockery::mock(\App\Domain\Applications\AddressSplitter::class);
        $splitter->shouldReceive('split')->andReturn(['governorate' => 'القاهرة', 'area' => 'مدينة بدر - الحي الرابع - ابن بيتك أ', 'building_number' => '194']);
        $this->app->instance(\App\Domain\Applications\AddressSplitter::class, $splitter);

        $columns = app(\App\Domain\Applications\LegacyRequestProjector::class)->attributes($application, 'no_income_proof');

        $this->assertNull($columns['applicant_street']);
        $this->assertSame('مدينة بدر - الحي الرابع - ابن بيتك أ', $columns['applicant_area']);
    }

    public function test_notification_is_persisted_as_a_system_outbound_message(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'wa_message_id' => 'abc'], 200)]);

        [$application, $conversation] = $this->completeApplication();
        $this->submitConfirmed($conversation, $application);
        $application->refresh();

        InstallmentRequest::find($application->installment_request_id)->update(['status' => 'approved']);

        $this->assertDatabaseHas('whatsapp_messages', [
            'whatsapp_conversation_id' => $conversation->id,
            'sender_type' => 'system',
            'direction' => 'outgoing',
            'delivery_status' => 'sent',
        ]);
    }

    public function test_the_request_belongs_to_the_employee_of_the_whatsapp_number(): void
    {
        [$application, $conversation] = $this->completeApplication();
        $this->submitConfirmed($conversation, $application);

        $this->assertSame(\App\Models\WhatsappBot::find($conversation->whatsapp_bot_id)->staff_id, InstallmentRequest::find($application->refresh()->installment_request_id)->staff_id);
    }

    public function test_approval_tells_him_to_come_to_the_nearest_branch(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'wa_message_id' => 'abc'], 200)]);

        [$application, $conversation] = $this->completeApplication();
        $this->submitConfirmed($conversation, $application);

        InstallmentRequest::find($application->refresh()->installment_request_id)->update(['status' => 'approved']);

        $text = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)->where('sender_type', 'system')->latest('id')->value('text');
        $this->assertStringContainsString('مبروك', $text);
        $this->assertStringContainsString('أقرب فرع', $text);
    }

    public function test_a_paused_request_asks_him_for_the_fix_and_goes_back_to_the_same_request(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'wa_message_id' => 'abc'], 200)]);

        [$application, $conversation] = $this->completeApplication();
        $this->submitConfirmed($conversation, $application);
        $request = InstallmentRequest::find($application->refresh()->installment_request_id);

        \Illuminate\Support\Carbon::setTestNow(now()->addMinute());
        $request->update(['status' => 'paused', 'customer_action' => 'data', 'checks_report' => 'الاسم في الطلب ناقص اسم الجد']);

        $application->refresh();
        $this->assertSame('needs_more_info', $application->status);
        $this->assertSame('data', $application->staff_request['type']);
        $this->assertSame('staff_request', app(\App\Domain\Applications\SnapshotService::class)->for($application)['next_step']['type']);

        $text = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)->where('sender_type', 'system')->latest('id')->value('text');
        $this->assertStringContainsString('الاسم في الطلب ناقص اسم الجد', $text);

        // Not fixed yet: nothing goes back.
        $early = app(SubmitApplicationTool::class)->execute(['confirm' => true], $this->ctx($conversation, $application, 5));
        $this->assertFalse($early->ok);

        \Illuminate\Support\Carbon::setTestNow(now()->addMinute());
        ApplicationData::where('application_id', $application->id)->where('field_key', 'full_name')->first()->update(['value' => 'Mohamed Ali Hassan']);

        $result = app(SubmitApplicationTool::class)->execute(['confirm' => true], $this->ctx($conversation, $application->refresh(), 6));
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertTrue($result->ok);
        $this->assertTrue($result->data['resubmitted']);
        $this->assertSame($request->id, $result->data['reference']['installment_request_id']);
        $this->assertSame(1, InstallmentRequest::count());

        $request->refresh();
        $this->assertSame('new', $request->status);
        $this->assertSame('Mohamed Ali Hassan', $request->applicant_name);
        $this->assertSame('submitted', $application->refresh()->status);
        $this->assertNull($application->staff_request);
    }

    private function lastSystemText(WhatsappConversation $conversation): string
    {
        return (string) WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)->where('sender_type', 'system')->latest('id')->value('text');
    }

    /** Owner 2026-10-02: an unclear ID photo is asked for again, and only that. */
    public function test_a_request_paused_for_an_unclear_id_asks_him_for_the_id_only(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'wa_message_id' => 'abc'], 200)]);
        [$application, $conversation] = $this->completeApplication();
        $front = \App\Models\DocumentType::create(['key' => 'national_id_front', 'label' => 'صورة وش البطاقة', 'is_active' => true]);
        $this->submitConfirmed($conversation, $application);
        $request = InstallmentRequest::find($application->refresh()->installment_request_id);
        $photo = WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image']);
        $media = \App\Models\MessageMedia::create(['message_id' => $photo->id, 'media_type' => 'image', 'mime' => 'image/jpeg', 'disk' => 'local', 'path' => 'id.jpg', 'size' => 10]);
        \App\Models\ApplicationDocument::create(['application_id' => $application->id, 'media_id' => $media->id, 'document_type_id' => $front->id, 'party' => 'applicant',
            'status' => 'accepted', 'expected_type_key' => 'national_id_front', 'detected_type_key' => 'national_id_front']);

        \Illuminate\Support\Carbon::setTestNow(now()->addMinute());
        $request->update(['status' => 'paused', 'customer_action' => 'national_id_front', 'checks_report' => 'صورة البطاقة مش واضحة']);
        \Illuminate\Support\Carbon::setTestNow();

        $text = $this->lastSystemText($conversation);
        $this->assertStringContainsString('#'.$request->id, $text);
        $this->assertStringContainsString('صورة البطاقة مش واضحة', $text);
        $this->assertStringContainsString('ابعتلي صورة وش البطاقة تاني', $text);

        $snapshot = app(\App\Domain\Applications\SnapshotService::class)->for($application->refresh());
        $this->assertSame('needs_more_info', $application->status);
        $this->assertSame('national_id_front', $snapshot['next_step']['key']);
        $this->assertSame(0, \App\Models\ApplicationDocument::where('application_id', $application->id)->where('status', 'accepted')->count());
    }

    public function test_approval_tells_him_his_number_and_to_come_sign(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'wa_message_id' => 'abc'], 200)]);
        [$application, $conversation] = $this->completeApplication();
        $this->submitConfirmed($conversation, $application);
        $request = InstallmentRequest::find($application->refresh()->installment_request_id);

        $request->update(['status' => 'approved']);

        $text = $this->lastSystemText($conversation);
        $this->assertStringContainsString('#'.$request->id, $text);
        $this->assertStringContainsString('اتوافق', $text);
        $this->assertStringContainsString('تمضي العقد', $text);
    }

    public function test_a_rejection_tells_him_why(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'wa_message_id' => 'abc'], 200)]);
        [$application, $conversation] = $this->completeApplication();
        $this->submitConfirmed($conversation, $application);
        $request = InstallmentRequest::find($application->refresh()->installment_request_id);

        $request->update(['status' => 'rejected', 'checks_report' => 'عليه أقساط متأخرة في الاستعلام']);

        $text = $this->lastSystemText($conversation);
        $this->assertStringContainsString('#'.$request->id, $text);
        $this->assertStringContainsString('ما وافقتش', $text);
        $this->assertStringContainsString('السبب: عليه أقساط متأخرة في الاستعلام', $text);
    }

    public function test_the_submit_reply_must_carry_the_request_number(): void
    {
        [$application, $conversation] = $this->completeApplication();
        $result = $this->submitConfirmed($conversation, $application);
        $number = (string) $result->data['reference']['installment_request_id'];
        $outcomes = [['name' => 'submit_application', 'ok' => true, 'data' => $result->data]];
        $check = fn (string $reply) => app(\App\Agent\Runtime\ReplyGuard::class)->check(['messages' => [$reply]], $conversation, '', [], $outcomes);

        $this->assertSame('REQUEST_NUMBER_MISSING', $check('تمام يا باشا، الطلب اتبعت للمراجعة.'));
        $this->assertNull($check("تمام يا باشا، طلبك اتبعت للمراجعة ورقمه #{$number}."));
    }
}
