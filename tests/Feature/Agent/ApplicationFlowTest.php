<?php

namespace Tests\Feature\Agent;

use App\Domain\Applications\ApplicationNudgeService;
use App\Domain\Applications\SnapshotService;
use App\Models\Application;
use App\Models\ApplicationRequirement;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\RequirementField;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Owner 2026-09-25: applying must be quick - one thing at a time, and nobody is lost half way. */
class ApplicationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function application(): array
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'customer_id' => $customer->id, 'phone' => '2011', 'status' => 'open']);
        $type = CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر']);

        foreach (['full_name' => 'الاسم بالكامل', 'phone' => 'رقم التليفون', 'national_id' => 'الرقم القومي', 'address' => 'عنوان السكن', 'work_type' => 'نوع الشغل'] as $key => $label) {
            $field = RequirementField::create(['key' => $key, 'label' => $label, 'data_type' => $key === 'work_type' ? 'enum' : 'string',
                'enum_options' => $key === 'work_type' ? ['other'] : null, 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
            ApplicationRequirement::create(['customer_type_id' => $type->id, 'requirement_type' => 'field', 'requirement_field_id' => $field->id, 'is_required' => true]);
        }

        $id = DocumentType::create(['key' => 'national_id_front', 'label' => 'بطاقة الرقم القومي', 'description_for_ai' => 'id',
            'accepted_mimes' => ['image/jpeg'], 'extraction_fields' => ['full_name', 'national_id'], 'validation_rules' => [], 'is_active' => true]);
        ApplicationRequirement::create(['customer_type_id' => $type->id, 'requirement_type' => 'document', 'document_type_id' => $id->id, 'is_required' => true]);

        $application = Application::create(['customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting']);

        return [$application, $conversation];
    }

    public function test_the_id_photo_comes_first_and_the_fields_it_fills_are_not_asked(): void
    {
        [$application] = $this->application();

        $snapshot = app(SnapshotService::class)->for($application);

        $this->assertSame('national_id_front', $snapshot['next_step']['key']);
        // name and national id come from the photo, so the work type is next
        $this->assertSame('نوع الشغل', $snapshot['progress']['then']);
        // six things left: no scary count
        $this->assertArrayNotHasKey('remaining', $snapshot['progress']);
    }

    private function botSaid(WhatsappConversation $conversation, string $text, \DateTimeInterface $at): void
    {
        $message = WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'text', 'text' => $text]);
        $message->forceFill(['created_at' => $at])->save();
    }

    public function test_a_quiet_customer_gets_one_reminder_of_the_single_thing_left(): void
    {
        config(['agent.enabled' => true, 'agent.applications.nudge_after_minutes' => 45, 'agent.applications.nudge_quiet_from' => 0, 'agent.applications.nudge_quiet_to' => 0]);
        Http::fake(['*' => fn () => Http::response(['ok' => true, 'wa_message_id' => uniqid('wa', true)])]);
        [, $conversation] = $this->application();
        $this->botSaid($conversation, 'ابعتلي صورة وش البطاقة', now()->subHour());

        $this->assertSame(1, app(ApplicationNudgeService::class)->nudgeStalled());
        $reminder = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)->latest('id')->value('text');
        $this->assertStringContainsString('صورة وش البطاقة', $reminder);

        // not again right away, and never a third time in the same silence
        $this->travel(2)->hours();
        $this->assertSame(0, app(ApplicationNudgeService::class)->nudgeStalled());
        $this->travel(1)->days();
        $this->assertSame(1, app(ApplicationNudgeService::class)->nudgeStalled());
        $this->travel(2)->days();
        $this->assertSame(0, app(ApplicationNudgeService::class)->nudgeStalled());
    }

    public function test_no_reminder_while_waiting_for_staff_or_before_the_delay(): void
    {
        config(['agent.enabled' => true, 'agent.applications.nudge_after_minutes' => 45, 'agent.applications.nudge_quiet_from' => 0, 'agent.applications.nudge_quiet_to' => 0]);
        Http::fake(['*' => fn () => Http::response(['ok' => true, 'wa_message_id' => uniqid('wa', true)])]);
        [, $conversation] = $this->application();

        $this->botSaid($conversation, 'ابعتلي صورة وش البطاقة', now()->subMinutes(10));
        $this->assertSame(0, app(ApplicationNudgeService::class)->nudgeStalled());

        $conversation->update(['status' => 'awaiting_agent']);
        $this->travel(1)->hours();
        $this->assertSame(0, app(ApplicationNudgeService::class)->nudgeStalled());
    }
    public function test_after_two_unanswered_asks_the_next_thing_is_asked_instead(): void
    {
        [$application, $conversation] = $this->application();

        foreach (['ابعتلي صورة وش البطاقة', 'مستنيين بس صورة البطاقة'] as $text) {
            WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'عايز اقدم']);
            WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'text', 'text' => $text]);
        }

        $snapshot = app(SnapshotService::class)->for($application);

        $this->assertSame('work_type', $snapshot['next_step']['key']);
        $this->assertStringContainsString('بطاقة الرقم القومي', $snapshot['next_step']['why']);
    }

    public function test_a_quote_about_the_motorcycle_is_not_a_work_statement(): void
    {
        // Live: "عايز اقسط هوجن ٤ استيراد على سنة" opened the customer as عامل حر.
        $this->assertFalse(\App\Agent\Tools\StartApplicationTool::talksAboutWork('عايز اقسط هوجن ٤ استيراد على سنة'));
        $this->assertTrue(\App\Agent\Tools\StartApplicationTool::talksAboutWork('شغال حر'));
        $this->assertTrue(\App\Agent\Tools\StartApplicationTool::talksAboutWork('انا موظف في شركة'));
        $this->assertTrue(\App\Agent\Tools\StartApplicationTool::talksAboutWork('على المعاش'));
        $this->assertTrue(\App\Agent\Tools\StartApplicationTool::talksAboutWork('سواق اوبر'));
    }
    public function test_the_selection_cannot_silently_swap_the_model_just_quoted(): void
    {
        // Live: quoted "هوجن ٤ استيراد", then selected "هوجن ٤ استيراد فرز تاني".
        [$application, $conversation] = $this->application();
        $brand = \App\Models\Brand::create(['name' => 'هوجان', 'image' => 'b.jpg']);
        $quoted = \App\Models\Machine::create(['name' => 'هوجن ٤ استيراد', 'brand_id' => $brand->id, 'cash_price' => 50000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $other = \App\Models\Machine::create(['name' => 'هوجن ٤ استيراد فرز تاني', 'brand_id' => $brand->id, 'cash_price' => 40000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        \App\Domain\Conversations\QuotedMotorcycle::remember($conversation->id, $quoted->id);

        $trace = \App\Models\AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new \App\Agent\Tools\ToolContext($application->customer_id, $conversation->id, $application->id, 1, $trace->id, new \App\Agent\Runtime\TurnResultBuilder());
        $tool = app(\App\Agent\Tools\UpdateApplicationSelectionTool::class);

        $swapped = $tool->execute(['motorcycle_id' => $other->id], $ctx);
        $this->assertSame('MOTORCYCLE_DIFFERS_FROM_LAST_QUOTED', $swapped->error['code']);
        $this->assertNull($application->fresh()->machine_id);

        $this->assertTrue($tool->execute(['motorcycle_id' => $quoted->id], $ctx)->ok);
        $this->assertTrue($tool->execute(['motorcycle_id' => $other->id, 'different_motorcycle_confirmed' => true], $ctx)->ok);
    }
}
