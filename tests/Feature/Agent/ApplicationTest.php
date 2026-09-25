<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\CalculateInstallmentTool;
use App\Agent\Tools\RecordCustomerDataTool;
use App\Agent\Tools\StartApplicationTool;
use App\Agent\Tools\Tool;
use App\Agent\Tools\ToolContext;
use App\Agent\Tools\UpdateApplicationSelectionTool;
use App\Agent\Tools\WithdrawApplicationTool;
use App\Domain\Applications\ApplicationStateMachine;
use App\Domain\Applications\ApplicationTransitionException;
use App\Domain\Applications\SnapshotService;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationRequirement;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerAttribute;
use App\Models\CustomerType;
use App\Models\EligibilityRule;
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

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function customerType(): CustomerType
    {
        return CustomerType::create(['key' => 'employee', 'label' => 'Employee']);
    }

    private function fullNameField(): RequirementField
    {
        return RequirementField::create([
            'key' => 'full_name', 'label' => 'Full name', 'data_type' => 'person_name',
            'scope' => 'application', 'is_sensitive' => false, 'is_active' => true,
        ]);
    }

    private function nationalIdField(): RequirementField
    {
        return RequirementField::create([
            'key' => 'national_id', 'label' => 'National ID', 'data_type' => 'national_id',
            'scope' => 'customer', 'is_sensitive' => true, 'is_active' => true,
        ]);
    }

    private function conversationAndMessage(): array
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $message = WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text',
            'text' => 'انا موظف، اسمي Mohamed Ali ورقمي القومي 29112121234567',
        ]);

        return [$conversation, $message];
    }

    private function ctx(WhatsappConversation $conversation, ?int $activeApplicationId = null, ?int $customerId = null): ToolContext
    {
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $customerId ??= Customer::where('whatsapp_bot_id', $conversation->whatsapp_bot_id)->value('id');

        return new ToolContext($customerId, $conversation->id, $activeApplicationId, 1, $trace->id, new TurnResultBuilder());
    }

    private function customerWithBot(): array
    {
        [$conversation, $message] = $this->conversationAndMessage();
        $customer = Customer::create(['whatsapp_bot_id' => $conversation->whatsapp_bot_id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);

        return [$customer, $conversation, $message];
    }

    public function test_record_customer_data_saves_rejects_and_reports_conflicts(): void
    {
        [$customer, $conversation, $message] = $this->customerWithBot();
        $this->nationalIdField();
        $type = $this->customerType();
        $app = Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting',
        ]);

        CustomerAttribute::create([
            'customer_id' => $customer->id, 'field_key' => 'national_id',
            'value' => '29001010112345', 'source' => 'document', 'status' => 'valid',
        ]);

        $ctx = $this->ctx($conversation, $app->id);
        $tool = app(RecordCustomerDataTool::class);

        $result = $tool->execute(['fields' => [
            ['key' => 'ghost_field', 'value' => 'x', 'evidence_message_id' => $message->id],
            ['key' => 'national_id', 'value' => '29112121234567', 'evidence_message_id' => $message->id],
        ]], $ctx);

        // Nothing saved is reported as a failure, with the reasons.
        $this->assertFalse($result->ok);
        $this->assertSame('NOTHING_SAVED', $result->error['code']);
        $this->assertStringContainsString('UNKNOWN_FIELD', $result->error['detail']);
        $this->assertStringContainsString('CONFLICTS_WITH_VERIFIED_VALUE', $result->error['detail']);

        // Document-sourced value must be untouched.
        $this->assertSame('29001010112345', CustomerAttribute::where('customer_id', $customer->id)->where('field_key', 'national_id')->value('value'));
    }

    public function test_record_customer_data_writes_application_scoped_fields(): void
    {
        [$customer, $conversation, $message] = $this->customerWithBot();
        $this->fullNameField();
        $type = $this->customerType();
        $app = Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting',
        ]);

        $ctx = $this->ctx($conversation, $app->id);
        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [
            ['key' => 'full_name', 'value' => 'Mohamed Ali', 'evidence_message_id' => $message->id],
        ]], $ctx);

        $this->assertTrue($result->ok);
        $this->assertSame(['full_name'], $result->data['saved']);
        $this->assertNotNull($result->data['snapshot']);
        $this->assertSame(
            'Mohamed Ali',
            ApplicationData::where('application_id', $app->id)->where('field_key', 'full_name')->value('value')
        );
    }

    public function test_start_application_creates_then_reuses_active_application(): void
    {
        [$customer, $conversation] = $this->customerWithBot();
        $this->customerType();

        $ctx = $this->ctx($conversation);
        $tool = app(StartApplicationTool::class);

        $first = $tool->execute(['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف'], $ctx);
        $this->assertTrue($first->ok);
        $this->assertTrue($first->data['created']);

        $second = $tool->execute(['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف'], $ctx);
        $this->assertTrue($second->ok);
        $this->assertFalse($second->data['created']);
        $this->assertSame($first->data['application_id'], $second->data['application_id']);
        $this->assertSame(1, Application::where('customer_id', $customer->id)->count());
    }

    public function test_start_application_still_opens_when_months_come_without_a_system(): void
    {
        // Live: "عايز هجن f على سنة" -> months: 12, no system -> no application at all.
        [$customer, $conversation] = $this->customerWithBot();
        $this->customerType();

        $result = app(StartApplicationTool::class)->execute([
            'customer_type' => 'employee', 'customer_type_quote' => 'انا موظف', 'months' => 12,
        ], $this->ctx($conversation));

        $this->assertTrue($result->ok);
        $this->assertTrue($result->data['created']);
        $this->assertSame('SYSTEM_REQUIRED', $result->data['plan_not_set']['code']);
        $this->assertNull(Application::where('customer_id', $customer->id)->value('installment_plan_id'));
    }

    public function test_start_application_rejects_when_policy_forbids_concurrency(): void
    {
        config(['agent.applications.concurrent_active_policy' => 'reject']);

        [, $conversation] = $this->customerWithBot();
        $this->customerType();

        $ctx = $this->ctx($conversation);
        $tool = app(StartApplicationTool::class);

        $this->assertTrue($tool->execute(['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف'], $ctx)->ok);

        $second = $tool->execute(['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف'], $ctx);
        $this->assertFalse($second->ok);
        $this->assertSame('ACTIVE_APPLICATION_EXISTS', $second->error['code']);
    }

    public function test_start_application_rejects_an_out_of_stock_motorcycle(): void
    {
        [, $conversation] = $this->customerWithBot();
        $this->customerType();
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $machine = Machine::create([
            'name' => 'M', 'brand_id' => $brand->id, 'cash_price' => 50000,
            'is_active' => true, 'availability' => 'out_of_stock', 'type' => 'normal',
        ]);

        $ctx = $this->ctx($conversation);
        $tool = app(StartApplicationTool::class);

        $result = $tool->execute(['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف', 'motorcycle_id' => $machine->id], $ctx);

        $this->assertFalse($result->ok);
        $this->assertSame('MOTORCYCLE_NOT_AVAILABLE', $result->error['code']);
        $this->assertSame(0, Application::count());
    }

    public function test_calculate_installment_rejects_an_out_of_stock_motorcycle(): void
    {
        [, $conversation] = $this->customerWithBot();
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $machine = Machine::create([
            'name' => 'M', 'brand_id' => $brand->id, 'cash_price' => 50000, 'installment_price' => 55000,
            'is_active' => true, 'availability' => 'out_of_stock', 'type' => 'normal',
        ]);
        $system = InstallmentSystem::create(['name' => 'S', 'pricing_mode' => 'standard', 'plans' => [['months' => 12, 'interest' => 20]], 'administrative_fees' => 7]);
        $machine->installmentSystems()->sync([$system->id]);
        $plan = InstallmentPlan::where('installment_system_id', $system->id)->first();

        $ctx = $this->ctx($conversation);
        $result = app(CalculateInstallmentTool::class)->execute(['motorcycle_id' => $machine->id, 'plan_id' => $plan->id], $ctx);

        $this->assertFalse($result->ok);
        $this->assertSame('MOTORCYCLE_NOT_AVAILABLE', $result->error['code']);
    }

    public function test_update_application_selection_and_withdraw_transitions(): void
    {
        [$customer, $conversation] = $this->customerWithBot();
        $type = $this->customerType();
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $machine = Machine::create([
            'name' => 'M', 'brand_id' => $brand->id, 'cash_price' => 50000, 'installment_price' => 55000,
            'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal',
        ]);
        $system = InstallmentSystem::create([
            'name' => 'S', 'pricing_mode' => 'standard', 'plans' => [['months' => 12, 'interest' => 20]], 'administrative_fees' => 7,
        ]);
        $machine->update(['installment_systems' => [$system->id]]);
        $plan = InstallmentPlan::where('installment_system_id', $system->id)->first();

        $app = Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting',
        ]);

        $ctx = $this->ctx($conversation, $app->id);
        $result = app(UpdateApplicationSelectionTool::class)->execute([
            'motorcycle_id' => $machine->id, 'plan_id' => $plan->id, 'down_payment' => 10000,
        ], $ctx);

        $this->assertTrue($result->ok);
        $app->refresh();
        $this->assertSame($machine->id, $app->machine_id);
        $this->assertSame($plan->id, $app->installment_plan_id);

        $withdraw = app(WithdrawApplicationTool::class)->execute(['reason_code' => 'changed_mind'], $ctx);
        $this->assertTrue($withdraw->ok);
        $this->assertSame('withdrawn', $withdraw->data['status']);
        $this->assertSame('withdrawn', $app->refresh()->status);
    }

    public function test_disallowed_transition_is_rejected(): void
    {
        [$customer, $conversation] = $this->customerWithBot();
        $type = $this->customerType();
        $app = Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'approved',
        ]);

        $this->expectException(ApplicationTransitionException::class);
        app(ApplicationStateMachine::class)->transition($app, 'withdrawn', 'withdrawn', 'customer');
    }

    public function test_withdraw_application_no_active_application(): void
    {
        [, $conversation] = $this->customerWithBot();

        $result = app(WithdrawApplicationTool::class)->execute(['reason_code' => 'other'], $this->ctx($conversation));

        $this->assertFalse($result->ok);
        $this->assertSame('NO_ACTIVE_APPLICATION', $result->error['code']);
    }

    public function test_snapshot_reports_missing_fields_documents_and_can_submit(): void
    {
        [$customer, $conversation, $message] = $this->customerWithBot();
        $type = $this->customerType();
        $this->fullNameField();

        ApplicationRequirement::create([
            'customer_type_id' => $type->id, 'requirement_type' => 'field',
            'requirement_field_id' => RequirementField::where('key', 'full_name')->value('id'),
            'is_required' => true,
        ]);

        $app = Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting',
        ]);

        $snapshot = app(SnapshotService::class)->for($app);

        $this->assertSame(['full_name'], $snapshot['fields']['missing']);
        $this->assertFalse($snapshot['can_submit']);
        $this->assertSame('collecting', $snapshot['status']);

        app(RecordCustomerDataTool::class)->execute(['fields' => [
            ['key' => 'full_name', 'value' => 'Ali', 'evidence_message_id' => $message->id],
        ]], $this->ctx($conversation, $app->id));

        $snapshot = app(SnapshotService::class)->for($app->refresh());
        $this->assertSame([], $snapshot['fields']['missing']);
        $this->assertSame(['full_name'], $snapshot['fields']['collected']);

        // Data complete but nothing chosen yet: the selection blocks
        // submission (can_submit=true with no plan crashed the submit).
        $this->assertFalse($snapshot['can_submit']);
        $this->assertEqualsCanonicalizing(['motorcycle', 'plan', 'down_payment'], array_column($snapshot['blockers'], 'key'));
        $this->assertSame(['selection'], array_values(array_unique(array_column($snapshot['blockers'], 'type'))));
    }

    public function test_can_submit_is_false_when_not_eligible(): void
    {
        [$customer, $conversation] = $this->customerWithBot();
        $type = $this->customerType();
        EligibilityRule::create(['customer_type_id' => null, 'rule_type' => 'age_range', 'params' => ['min' => 21, 'max' => 60], 'is_active' => true]);

        $app = Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting',
        ]);

        $snapshot = app(SnapshotService::class)->for($app);

        $this->assertSame('unknown', $snapshot['eligibility']['status']);
        $this->assertFalse($snapshot['can_submit']);
    }

    public function test_application_tools_never_declare_customer_id_as_an_argument(): void
    {
        $tools = [
            app(RecordCustomerDataTool::class), app(StartApplicationTool::class),
            app(UpdateApplicationSelectionTool::class), app(WithdrawApplicationTool::class),
        ];

        foreach ($tools as $tool) {
            /** @var Tool $tool */
            $this->assertArrayNotHasKey('customer_id', $tool->inputSchema()['properties'] ?? []);
        }
    }
}
