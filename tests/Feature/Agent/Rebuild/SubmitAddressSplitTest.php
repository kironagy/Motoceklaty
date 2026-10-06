<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\SubmitApplicationTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Applications\AddressSplitter;
use App\Jobs\SplitRequestAddresses;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationRequirement;
use App\Models\Brand;
use App\Models\CustomerType;
use App\Models\InstallmentPlan;
use App\Models\InstallmentRequest;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\RequirementField;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/** Rebuild APP-006: the AI address split is not on the submit path. */
class SubmitAddressSplitTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private function submitted(): InstallmentRequest
    {
        $conversation = $this->conversation();
        $type = CustomerType::create(['key' => 'employee', 'label' => 'Employee', 'legacy_work_status' => 'employee']);
        $field = RequirementField::create(['key' => 'full_name', 'label' => 'Full name', 'data_type' => 'person_name', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        ApplicationRequirement::create(['customer_type_id' => $type->id, 'requirement_type' => 'field', 'requirement_field_id' => $field->id, 'is_required' => true]);
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $machine = Machine::create(['name' => 'M', 'brand_id' => $brand->id, 'cash_price' => 50000, 'installment_price' => 55000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $system = InstallmentSystem::create(['name' => 'S', 'pricing_mode' => 'standard', 'plans' => [['months' => 12, 'interest' => 20]], 'administrative_fees' => 7]);
        $machine->update(['installment_systems' => [$system->id]]);
        $application = Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id,
            'machine_id' => $machine->id, 'installment_plan_id' => InstallmentPlan::where('installment_system_id', $system->id)->value('id'), 'down_payment' => 5000, 'status' => 'collecting']);

        foreach (['full_name' => 'Mohamed Ali', 'address' => '34 شارع الشرفاء العشرين فيصل', 'address_building_no' => '34'] as $key => $value) {
            ApplicationData::create(['application_id' => $application->id, 'party' => 'applicant', 'field_key' => $key, 'value' => $value, 'source' => 'customer_stated', 'status' => 'valid']);
        }

        // turn 1: the summary; turn 2: he confirms
        $ctx = fn (int $turn) => new ToolContext($application->customer_id, $conversation->id, $application->id, $turn,
            AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => $turn, 'status' => 'running'])->id, new TurnResultBuilder());
        $first = $ctx(1);
        app(SubmitApplicationTool::class)->execute(['confirm' => true], $first);
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'turn_id' => 1, 'direction' => 'outgoing', 'sender_type' => 'bot',
            'type' => 'text', 'text' => end($first->outbound->toArray()['messages']), 'delivery_status' => 'sent']);

        $result = app(SubmitApplicationTool::class)->execute(['confirm' => true], $ctx(2));
        $this->assertTrue($result->ok, json_encode($result->error ?? null));

        return InstallmentRequest::findOrFail($application->refresh()->installment_request_id);
    }

    public function test_submit_does_not_wait_for_the_ai_split_and_uses_the_parser_at_once(): void
    {
        Queue::fake();
        $splitter = Mockery::mock(AddressSplitter::class);
        $splitter->shouldNotReceive('split');
        $this->app->instance(AddressSplitter::class, $splitter);

        $request = $this->submitted();

        $this->assertSame('الجيزة', $request->applicant_governorate);
        $this->assertSame('فيصل', $request->applicant_area);
        $this->assertSame('الشرفاء العشرين', $request->applicant_street);
        Queue::assertPushed(SplitRequestAddresses::class, fn ($job) => $job->installmentRequestId === $request->id);
    }

    public function test_the_queued_split_refines_only_what_staff_did_not_change(): void
    {
        Queue::fake();
        $request = $this->submitted();
        // staff fixed the area by hand before the job ran
        $request->forceFill(['applicant_area' => 'فيصل - محطة العشرين'])->saveQuietly();

        $splitter = Mockery::mock(AddressSplitter::class);
        $splitter->shouldReceive('split')->andReturn(['governorate' => 'الجيزة', 'area' => 'فيصل - العشرين', 'street' => 'الشرفاء', 'building_number' => '34']);
        $this->app->instance(AddressSplitter::class, $splitter);
        $this->app->forgetInstance(\App\Domain\Applications\LegacyRequestProjector::class);

        app()->call([new SplitRequestAddresses($request->id), 'handle']);
        $request->refresh();

        $this->assertSame('الشرفاء', $request->applicant_street);
        $this->assertSame('فيصل - محطة العشرين', $request->applicant_area);
    }
}
