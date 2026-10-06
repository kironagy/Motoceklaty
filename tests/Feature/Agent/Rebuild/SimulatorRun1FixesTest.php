<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Context\ContextBuilder;
use App\Agent\Runtime\ReplyGuard;
use App\Agent\Runtime\ToolOutcomeRecorder;
use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\RecordCustomerDataTool;
use App\Agent\Tools\RecordWorkProfileTool;
use App\Agent\Tools\ToolContext;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\Brand;
use App\Models\CustomerType;
use App\Models\Machine;
use App\Models\RequirementField;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** What the first paid simulator run (2026-10-05) showed. */
class SimulatorRun1FixesTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private function ctx($conversation, ?int $applicationId = null): ToolContext
    {
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);

        return new ToolContext($conversation->customer_id, $conversation->id, $applicationId, 1, $trace->id, new TurnResultBuilder());
    }

    public function test_his_recorded_work_is_in_front_of_the_model_next_turn(): void
    {
        // a Didi rider was asked "بتشتغل إيه؟" eight times after it was recorded
        $conversation = $this->conversation();
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'عايز موتوسيكل اشتغل بيه على ديدي']);
        app(RecordWorkProfileTool::class)->execute(['evidence' => 'اشتغل بيه على ديدي', 'occupation' => 'دليفري ديدي', 'work_stated' => true, 'customer_type' => 'self_employed',
            'work_type' => 'delivery_app', 'working_now' => 'yes', 'relation_to_workplace' => 'independent'], $this->ctx($conversation));

        $system = app(ContextBuilder::class)->build($this->turnFor($conversation, 'بكام دايو 4 قسط'))->system;

        $this->assertStringContainsString('"his_work":{"whose_work":"العميل نفسه","his_words":"اشتغل بيه على ديدي"', $system);
        $this->assertStringContainsString('"customer_type":"self_employed","work_type":"delivery_app"', $system);
    }

    public function test_a_reply_of_only_brackets_is_empty(): void
    {
        $this->assertSame('EMPTY_REPLY', app(ReplyGuard::class)->check(['messages' => ['[]']], $this->conversation(), '', [], []));
    }

    public function test_cash_prices_he_was_shown_are_a_source_next_turn(): void
    {
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'هوجان', 'image' => 'b.jpg']);
        $machine = Machine::create(['name' => 'هوجن 4', 'brand_id' => $brand->id, 'cash_price' => 43000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        app(ToolOutcomeRecorder::class)->record('search_motorcycles', ['name_query' => 'هوجن 4'], ['ok' => true, 'data' => ['items' => [['id' => $machine->id]]]], $this->ctx($conversation));

        $turn = $this->turnFor($conversation, 'على سنتين');
        $system = app(ContextBuilder::class)->build($turn)->system;

        $this->assertStringContainsString('"cash_prices_shown":[{"motorcycle_id":'.$machine->id.',"motorcycle":"هوجن 4","cash_price":43000}]', $system);
        $this->assertNull(app(ReplyGuard::class)->check(['messages' => ['هوجن 4 كاش بـ 43,000 جنيه، تحب أحسبها على سنتين؟']], $conversation->refresh(), $system, [], []));

        // a price edited since is not a source any more
        $this->travel(1)->minutes();
        $machine->update(['cash_price' => 45000]);
        $this->assertStringNotContainsString('"cash_prices_shown"', app(ContextBuilder::class)->build($turn)->system);
    }

    public function test_a_guarantor_field_his_application_does_not_need_is_refused(): void
    {
        $conversation = $this->conversation();
        $type = CustomerType::create(['key' => 'employee', 'label' => 'موظف']);
        $application = Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'collecting']);
        RequirementField::create(['key' => 'guarantor_phone', 'label' => 'تليفون الضامن', 'data_type' => 'phone', 'scope' => 'guarantor', 'is_sensitive' => false, 'is_active' => true]);
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'رقمي 01147709597 ورقم تاني 01128884715']);

        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [['key' => 'guarantor_phone', 'value' => '01128884715']]], $this->ctx($conversation, $application->id));

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('NOT_NEEDED_FOR_THIS_APPLICATION', $result->error['detail']);
        $this->assertSame(0, ApplicationData::where('field_key', 'guarantor_phone')->count());
    }

    // ---- second paid run (2026-10-05)

    public function test_its_own_arguments_written_as_text_are_unwrapped_and_english_only_is_empty(): void
    {
        $guard = app(ReplyGuard::class);
        $this->assertSame(['messages' => [], 'no_reply' => true], $guard->tidy(['messages' => ['{"messages":[],"no_reply":true}']]));
        $this->assertSame('EMPTY_REPLY', $guard->check(['messages' => ['(no reply)']], $this->conversation(), '', [], []));
        $this->assertSame('EMPTY_REPLY', $guard->check(['messages' => ['NO_REPLY_FIELD']], $this->conversation(), '', [], []));
    }

    public function test_a_submitted_request_is_never_called_not_sent(): void
    {
        $outcomes = [['name' => 'submit_application', 'ok' => true, 'data' => ['submitted' => true, 'reference' => ['installment_request_id' => 3672]]]];

        $this->assertSame('SUBMISSION_DENIED_BUT_DONE', app(ReplyGuard::class)->check(
            ['messages' => ['حصلت مشكلة بسيطة والطلب ما اتبعتش لأن لازم نحدد فرع التقديم']], $this->conversation(), '', [], $outcomes));
        // an offer of a branch address after a real submission is no branch fact
        $this->assertNull(app(ReplyGuard::class)->check(
            ['messages' => ['تمام يا باشا، الطلب اتبعت ورقم طلبك #3672. لو عايز عنوان فرع أقرب ليك أبعتهولك.']], $this->conversation(), '', [], $outcomes));
    }

    public function test_a_summary_is_only_mentioned_when_submit_application_sent_it(): void
    {
        $this->assertSame('SUMMARY_CLAIMED_NOT_SENT', app(ReplyGuard::class)->check(['messages' => ['ده ملخص طلبك، راجعه ولو كله تمام قولّي اه']], $this->conversation(), '', [], []));
        $sent = [['name' => 'submit_application', 'ok' => false, 'data' => ['code' => 'CUSTOMER_CONFIRMATION_REQUIRED']]];
        $this->assertNull(app(ReplyGuard::class)->check(['messages' => ['ده ملخص طلبك، راجعه ولو كله تمام قولّي اه']], $this->conversation(), '', [], $sent));
    }

    public function test_a_saved_claim_with_a_diacritic_is_still_a_claim(): void
    {
        $this->assertSame('DATA_CLAIMED_NOT_SAVED', app(ReplyGuard::class)->check(['messages' => ['تمام يا باشا، سِجلت إن السكن ملك.']], $this->conversation(), '', [], []));
    }

    public function test_one_value_per_field_in_one_call(): void
    {
        $conversation = $this->conversation();
        $type = CustomerType::create(['key' => 'employee', 'label' => 'موظف']);
        $application = Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'collecting']);
        RequirementField::create(['key' => 'phone', 'label' => 'رقم التليفون', 'data_type' => 'phone', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'رقمي 01147709597 ورقم تاني 01128884715']);

        $result = app(RecordCustomerDataTool::class)->execute(['fields' => [['key' => 'phone', 'value' => '01147709597'], ['key' => 'phone', 'value' => '01128884715']]], $this->ctx($conversation, $application->id));

        $this->assertSame(['phone'], $result->data['saved']);
        $this->assertSame('ONE_VALUE_PER_FIELD', $result->data['rejected'][0]['code']);
        $this->assertSame('01147709597', ApplicationData::where('field_key', 'phone')->value('value'));
    }

    public function test_the_requirements_say_whether_a_guarantor_is_needed(): void
    {
        $conversation = $this->conversation();
        $pension = CustomerType::create(['key' => 'pension', 'label' => 'معاش']);
        $field = RequirementField::create(['key' => 'guarantor_name', 'label' => 'اسم الضامن', 'data_type' => 'person_name', 'scope' => 'guarantor', 'is_sensitive' => false, 'is_active' => true]);
        \App\Models\ApplicationRequirement::create(['customer_type_id' => $pension->id, 'requirement_type' => 'field', 'requirement_field_id' => $field->id, 'is_required' => true]);

        $result = app(\App\Agent\Tools\GetApplicationRequirementsTool::class)->execute(['customer_type' => 'pension'], $this->ctx($conversation));

        $this->assertSame(['اسم الضامن'], $result->data['guarantor_fields']);
    }
}
