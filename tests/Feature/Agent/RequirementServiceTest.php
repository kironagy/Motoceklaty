<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\GetApplicationRequirementsTool;
use App\Agent\Tools\ToolContext;
use App\Agent\Tracing\Redactor;
use App\Domain\Applications\RequirementService;
use App\Models\AiTrace;
use App\Models\ApplicationRequirement;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\RequirementField;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequirementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_requirements_for_with_conditional_requirements(): void
    {
        $type = CustomerType::create(['key' => 'employee', 'label' => 'Employee']);
        $salary = RequirementField::create(['key' => 'salary', 'label' => 'Salary', 'data_type' => 'money', 'scope' => 'customer']);
        $guarantor = RequirementField::create(['key' => 'guarantor_name', 'label' => 'Guarantor', 'data_type' => 'person_name', 'scope' => 'guarantor']);
        $idDoc = DocumentType::create(['key' => 'national_id_front', 'label' => 'ID Front']);

        ApplicationRequirement::create([
            'customer_type_id' => $type->id, 'requirement_type' => 'field',
            'requirement_field_id' => $salary->id, 'is_required' => true, 'sort' => 1,
        ]);

        ApplicationRequirement::create([
            'customer_type_id' => $type->id, 'requirement_type' => 'field',
            'requirement_field_id' => $guarantor->id, 'is_required' => true, 'sort' => 2,
            'condition' => ['fact' => 'selection.financed_amount', 'op' => 'gt', 'value' => 40000],
        ]);

        ApplicationRequirement::create([
            'customer_type_id' => $type->id, 'requirement_type' => 'document',
            'document_type_id' => $idDoc->id, 'is_required' => true, 'sort' => 3,
        ]);

        $service = app(RequirementService::class);

        $withoutCondition = $service->requirementsFor($type);
        $this->assertCount(1, $withoutCondition['fields']); // guarantor excluded, condition unmet
        $this->assertSame('salary', $withoutCondition['fields'][0]['key']);
        $this->assertCount(1, $withoutCondition['documents']);

        $withCondition = $service->requirementsFor($type, ['selection' => ['financed_amount' => 50000]]);
        $this->assertCount(2, $withCondition['fields']);
    }

    public function test_tool_returns_unknown_customer_type(): void
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new ToolContext(1, $conversation->id, null, 1, $trace->id, new TurnResultBuilder());

        $result = app(GetApplicationRequirementsTool::class)->execute(['customer_type' => 'ghost'], $ctx);

        $this->assertFalse($result->ok);
        $this->assertSame('UNKNOWN_CUSTOMER_TYPE', $result->error['code']);
    }

    public function test_tool_output_shape_for_known_type(): void
    {
        $type = CustomerType::create(['key' => 'employee', 'label' => 'Employee']);
        $field = RequirementField::create(['key' => 'salary', 'label' => 'Salary', 'data_type' => 'money']);
        ApplicationRequirement::create(['customer_type_id' => $type->id, 'requirement_type' => 'field', 'requirement_field_id' => $field->id, 'is_required' => true]);

        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new ToolContext(1, $conversation->id, null, 1, $trace->id, new TurnResultBuilder());

        $result = app(GetApplicationRequirementsTool::class)->execute(['customer_type' => 'employee'], $ctx);

        $this->assertTrue($result->ok);
        $this->assertArrayHasKey('fields', $result->data);
        $this->assertArrayHasKey('documents', $result->data);
        $this->assertArrayHasKey('rules', $result->data);
        $this->assertSame('salary', $result->data['fields'][0]['key']);
    }

    public function test_redaction_uses_is_sensitive_flag(): void
    {
        RequirementField::create(['key' => 'salary_slip_number', 'label' => 'X', 'data_type' => 'string', 'is_sensitive' => true]);

        $redacted = Redactor::redact(['salary_slip_number' => 'SS-12345', 'name' => 'Ahmed']);

        $this->assertSame('[REDACTED]', $redacted['salary_slip_number']);
        $this->assertSame('Ahmed', $redacted['name']);
    }

    public function test_the_tool_resolves_conditional_documents_from_facts_and_lists_the_rest(): void
    {
        // Persona E: asked "what do I need" as a shop owner and got an
        // invented list - the conditional documents never showed up.
        $type = CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر', 'is_active' => true]);
        $workType = RequirementField::create(['key' => 'work_type', 'label' => 'نوع الشغل', 'data_type' => 'enum', 'enum_options' => ['business_owner', 'delivery_app'], 'scope' => 'application', 'is_active' => true]);
        $proof = DocumentType::create(['key' => 'self_employed_income_proof', 'label' => 'إثبات دخل']);
        $license = DocumentType::create(['key' => 'driving_license', 'label' => 'رخصة']);
        ApplicationRequirement::create(['customer_type_id' => $type->id, 'requirement_type' => 'document', 'document_type_id' => $proof->id, 'is_required' => true, 'condition' => ['fact' => 'work_type', 'op' => 'in', 'value' => ['business_owner']]]);
        ApplicationRequirement::create(['customer_type_id' => $type->id, 'requirement_type' => 'document', 'document_type_id' => $license->id, 'is_required' => true, 'condition' => ['fact' => 'work_type', 'op' => 'in', 'value' => ['delivery_app']]]);

        $ctx = new \App\Agent\Tools\ToolContext(1, 1, null, 1, 1, new \App\Agent\Runtime\TurnResultBuilder());
        $tool = app(\App\Agent\Tools\GetApplicationRequirementsTool::class);

        $unknown = $tool->execute(['customer_type' => 'self_employed'], $ctx);
        $this->assertSame([], $unknown->data['documents']);
        $this->assertCount(2, $unknown->data['conditional']);

        $owner = $tool->execute(['customer_type' => 'self_employed', 'facts' => ['work_type' => 'business_owner']], $ctx);
        $this->assertSame(['self_employed_income_proof'], array_column($owner->data['documents'], 'key'));

        $bad = $tool->execute(['customer_type' => 'self_employed', 'facts' => ['work_type' => 'astronaut']], $ctx);
        $this->assertSame('INVALID_ARGUMENTS', $bad->error['code']);
    }
}
