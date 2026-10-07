<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\RecordCustomerDataTool;
use App\Agent\Tools\StartApplicationTool;
use App\Agent\Tools\ToolContext;
use App\Agent\Tools\UpdateApplicationSelectionTool;
use App\Domain\Applications\SnapshotService;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\ApplicationRequirement;
use App\Models\Brand;
use App\Models\CustomerType;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\RequirementField;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Database\Seeders\AgentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rebuild TOOL-006 / TOOL-005 / CTX-002: write tools return a compact status, the full snapshot lives once. */
class WriteToolSizeTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private WhatsappConversation $conversation;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AgentCatalogSeeder::class);
        $employee = CustomerType::where('key', 'employee')->firstOrFail();

        // The address parts the real employee list asks for, with the option lists that made snapshots long.
        foreach (['address_building_no' => 'رقم العمارة', 'address_floor' => 'الدور', 'address_landmark' => 'علامة مميزة', 'work_address' => 'عنوان الشغل', 'work_landmark' => 'علامة الشغل'] as $key => $label) {
            $field = RequirementField::create(['key' => $key, 'label' => $label, 'data_type' => 'string', 'scope' => 'application', 'is_active' => true,
                'description_for_ai' => 'Ask for the '.$label.' in his own words; never guess it from another field.']);
            ApplicationRequirement::create(['customer_type_id' => $employee->id, 'requirement_type' => 'field', 'requirement_field_id' => $field->id, 'is_required' => true]);
        }

        $this->conversation = $this->conversation();
        $this->application = Application::create([
            'customer_id' => $this->conversation->customer_id, 'origin_conversation_id' => $this->conversation->id,
            'customer_type_id' => $employee->id, 'status' => 'collecting',
        ]);
    }

    private function ctx(?int $applicationId): ToolContext
    {
        $trace = AiTrace::create(['conversation_id' => $this->conversation->id, 'turn_id' => 1, 'status' => 'running']);

        return new ToolContext($this->conversation->customer_id, $this->conversation->id, $applicationId, 1, $trace->id, new TurnResultBuilder());
    }

    private function fullSnapshotChars(): int
    {
        return mb_strlen(json_encode(app(SnapshotService::class)->for($this->application->refresh()), JSON_UNESCAPED_UNICODE));
    }

    private function assertCompact(array $data, int $maxChars): void
    {
        $this->assertArrayNotHasKey('snapshot', $data);
        $status = $data['application_now'];
        $this->assertArrayHasKey('next_step', $status);
        $this->assertArrayHasKey('progress', $status);
        $this->assertArrayHasKey('can_submit', $status);
        $this->assertLessThan($maxChars, mb_strlen(json_encode($data, JSON_UNESCAPED_UNICODE)));
    }

    public function test_record_customer_data_returns_what_was_saved_and_the_compact_status(): void
    {
        $message = WhatsappMessage::create([
            'whatsapp_bot_id' => $this->conversation->whatsapp_bot_id, 'whatsapp_conversation_id' => $this->conversation->id,
            'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'رقمي 01012345678',
        ]);

        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [
            ['key' => 'phone', 'value' => '01012345678', 'evidence_message_id' => $message->id],
        ]], $this->ctx($this->application->id));

        $this->assertTrue($result->ok);
        $this->assertSame(['phone'], $result->data['saved']);
        $this->assertArrayNotHasKey('missing', $result->data['application_now']);
        $this->assertSame('fields 1/9, documents 0/2', $result->data['application_now']['progress']);
        $this->assertCompact($result->data, (int) ($this->fullSnapshotChars() * 0.7));
    }

    public function test_update_application_selection_returns_the_compact_status(): void
    {
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $machine = Machine::create(['name' => 'M', 'brand_id' => $brand->id, 'cash_price' => 50000, 'installment_price' => 55000,
            'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $system = InstallmentSystem::create(['name' => 'S', 'pricing_mode' => 'standard', 'plans' => [['months' => 12, 'interest' => 20]], 'administrative_fees' => 7]);
        $machine->update(['installment_systems' => [$system->id]]);
        $plan = InstallmentPlan::where('installment_system_id', $system->id)->first();

        $result = app(UpdateApplicationSelectionTool::class)->execute([
            'motorcycle_id' => $machine->id, 'plan_id' => $plan->id, 'down_payment' => 10000,
        ], $this->ctx($this->application->id));

        $this->assertTrue($result->ok);
        $this->assertSame($machine->id, $result->data['application_now']['selection']['motorcycle_id']);
        $this->assertCompact($result->data, (int) ($this->fullSnapshotChars() * 0.8));
    }

    public function test_start_application_gives_the_full_snapshot_once_and_the_status_after(): void
    {
        $this->application->delete();
        $tool = app(StartApplicationTool::class);
        WhatsappMessage::create([
            'whatsapp_bot_id' => $this->conversation->whatsapp_bot_id, 'whatsapp_conversation_id' => $this->conversation->id,
            'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'انا موظف متأمن عليا',
        ]);
        app(\App\Agent\Tools\RecordWorkProfileTool::class)->execute(['evidence' => 'انا موظف متأمن عليا', 'occupation' => 'موظف', 'job_title' => 'محاسب', 'work_stated' => true,
            'customer_type' => 'employee', 'working_now' => 'yes', 'relation_to_workplace' => 'works_for_someone', 'insured' => 'yes'], $this->ctx(null));

        $opened = $tool->execute(['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف'], $this->ctx(null));
        $this->assertTrue($opened->ok, json_encode($opened->error ?? null));
        $this->assertTrue($opened->data['created']);
        $this->assertArrayHasKey('snapshot', $opened->data);

        $again = $tool->execute(['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف'], $this->ctx($opened->data['application_id']));
        $this->assertFalse($again->data['created']);
        $this->assertCompact($again->data, 1500);
    }

    public function test_the_compact_status_keeps_the_hint_of_the_next_question_only(): void
    {
        $snapshot = app(SnapshotService::class)->for($this->application);
        $compact = SnapshotService::compact($snapshot);

        // the ID is asked first: its one-line hint comes, the salary slip's (and its substitute rule) does not
        $this->assertSame('national_id_front', $compact['next_step']['key']);
        $this->assertSame(['national_id_front'], array_keys($compact['next_step_hints']));
        $this->assertArrayNotHasKey('if_unavailable', $compact);
        $this->assertArrayNotHasKey('values', $compact);
        $this->assertArrayNotHasKey('blockers', $compact);
        $this->assertFalse($compact['can_submit']);
    }
}
