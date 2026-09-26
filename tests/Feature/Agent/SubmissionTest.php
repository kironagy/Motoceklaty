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
        $this->assertSame('شارع الهرم', $ir->work_address);
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
}
