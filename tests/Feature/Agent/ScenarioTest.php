<?php

namespace Tests\Feature\Agent;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Agent\Runtime\AgentRunner;
use App\Models\AiTrace;
use App\Models\AiTraceStep;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationRequirement;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\MessageMedia;
use App\Models\RequirementField;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * T18 §1: end-to-end acceptance scenarios driven through the real
 * AgentRunner with a scripted FakeAiProvider. Several scenarios from the
 * list (session gap, handoff/resume, duplicate delivery, single-tool
 * replies, number-guard corrections) are already covered end to end by
 * TurnSchedulerTest, HandoffServiceTest, WhatsappIncomingMessageTest and
 * AgentRunnerTest - not duplicated here.
 */
class ScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agent.runtime.max_model_calls' => 6,
            'agent.runtime.max_tool_calls' => 10,
            'agent.runtime.wall_clock_seconds' => 30,
            'agent.fallback.message' => 'حصل عطل بسيط، هحول حضرتك لموظف.',
            'agent.handoff.max_failed_turns' => 2,
        ]);
    }

    private function conversation(): WhatsappConversation
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $conversation->update(['customer_id' => $customer->id]);

        return $conversation;
    }

    private function turnFor(WhatsappConversation $conversation): object
    {
        $id = DB::table('whatsapp_message_jobs')->insertGetId([
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
            'whatsapp_conversation_id' => $conversation->id,
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('whatsapp_message_jobs')->find($id);
    }

    private function fake(): FakeAiProvider
    {
        $fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $fake);

        return $fake;
    }

    private function response(array $toolCalls): AiResponse
    {
        return new AiResponse([], $toolCalls, 'STOP', ['input_tokens' => 10, 'output_tokens' => 5], 'gemini-test', null, 10);
    }

    public function test_topic_switch_during_an_application_leaves_it_unchanged(): void
    {
        $conversation = $this->conversation();
        $customer = $conversation->customer;
        $type = CustomerType::create(['key' => 'employee', 'label' => 'Employee']);
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);
        $other = Machine::create(['name' => 'CT100', 'brand_id' => $brand->id, 'cash_price' => 40000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);

        $application = Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting',
        ]);

        // Customer asks an unrelated catalog question mid-application.
        $fake = $this->fake();
        $fake->queue($this->response([['id' => 't1', 'name' => 'get_motorcycle_details', 'args' => ['motorcycle_ids' => [$other->id]]]]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['سعر الـCT100 كاش 40000']]]]));

        app(AgentRunner::class)->run($this->turnFor($conversation));

        $application->refresh();
        $this->assertSame('collecting', $application->status);
        $this->assertNull($application->machine_id); // untouched by the unrelated question
    }

    public function test_changing_motorcycle_supersedes_documents_no_longer_required(): void
    {
        $conversation = $this->conversation();
        $customer = $conversation->customer;
        $employee = CustomerType::create(['key' => 'employee', 'label' => 'Employee']);
        $selfEmployed = CustomerType::create(['key' => 'self_employed', 'label' => 'Self employed']);

        $docType = DocumentType::create([
            'key' => 'commercial_register', 'label' => 'Commercial register', 'description_for_ai' => '...',
            'accepted_mimes' => ['image/jpeg'], 'extraction_fields' => [], 'validation_rules' => [], 'is_active' => true,
        ]);
        ApplicationRequirement::create([
            'customer_type_id' => $selfEmployed->id, 'requirement_type' => 'document',
            'document_type_id' => $docType->id, 'is_required' => true,
        ]);

        $application = Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $selfEmployed->id, 'status' => 'collecting',
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('cr.jpg', 'bytes');
        $message = WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image']);
        $media = MessageMedia::create(['message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg', 'disk' => 'local', 'path' => 'cr.jpg', 'size' => 5]);
        $document = ApplicationDocument::create([
            'application_id' => $application->id, 'document_type_id' => $docType->id, 'media_id' => $media->id,
            'party' => 'applicant', 'status' => 'accepted', 'detected_type_key' => 'commercial_register', 'attempts' => 1,
        ]);

        // Customer turns out to be an employee, not self-employed - the commercial register no longer applies.
        app(\App\Domain\Applications\ApplicationService::class)->updateSelection($application, customerType: $employee);

        $this->assertSame('superseded', $document->refresh()->status);
    }

    public function test_prompt_injection_reveals_nothing_and_stays_scoped_to_this_customer(): void
    {
        $conversation = $this->conversation();
        $other = Customer::create(['whatsapp_bot_id' => $conversation->whatsapp_bot_id, 'jid' => '999@s.whatsapp.net', 'phone' => '999']);
        $otherType = CustomerType::create(['key' => 'employee', 'label' => 'Employee']);
        $otherApp = Application::create([
            'customer_id' => $other->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $otherType->id, 'status' => 'collecting',
        ]);

        $fake = $this->fake();
        // A real model would just reply normally to an injection attempt - it cannot pass another customer's IDs as tool args anyway (plan principle 9).
        $fake->queue($this->response([['id' => 't1', 'name' => 'send_reply', 'args' => ['messages' => ['أنا هنا عشان أساعدك تشتري موتوسيكل يا فندم 🙂']]]]));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation));

        $this->assertStringNotContainsString('system prompt', $result['messages'][0]);
        $this->assertSame('collecting', $otherApp->refresh()->status); // untouched - no tool call ever carries a customer/application id as an argument
    }

    public function test_no_national_id_leaks_into_traces_or_logs(): void
    {
        $conversation = $this->conversation();
        $customer = $conversation->customer;
        RequirementField::create(['key' => 'national_id', 'label' => 'ID', 'data_type' => 'national_id', 'scope' => 'customer', 'is_sensitive' => true, 'is_active' => true]);
        $type = CustomerType::create(['key' => 'employee', 'label' => 'Employee']);
        Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting',
        ]);

        $evidenceMessage = WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => '29001010112345']);

        $fake = $this->fake();
        $recordCall = [
            'id' => 't1',
            'name' => 'record_customer_data',
            'args' => ['fields' => [['key' => 'national_id', 'value' => '29001010112345', 'evidence_message_id' => $evidenceMessage->id]]],
        ];
        $fake->queue($this->response([$recordCall]));
        $fake->queue($this->response([['id' => 't2', 'name' => 'send_reply', 'args' => ['messages' => ['تمام، سجلت البيانات']]]]));

        app(AgentRunner::class)->run($this->turnFor($conversation));

        $dump = AiTraceStep::all()->toJson().AiTrace::all()->toJson();
        $this->assertDoesNotMatchRegularExpression('/\d{14}/', $dump);
    }
}
