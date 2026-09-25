<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\ReplyGuard;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Gaps found by the 2026-09-24 conversation simulation. */
class ReplyGuardGapsTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): WhatsappConversation
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);

        return WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
    }

    private function check(string $reply, array $contents = []): ?string
    {
        config(['agent.guard.number_min_value' => 1000]);

        return app(ReplyGuard::class)->check(['messages' => [$reply]], $this->conversation(), '', $contents, []);
    }

    public function test_someone_will_be_with_you_is_a_handoff_promise(): void
    {
        $this->assertSame('HANDOFF_CLAIMED_NOT_DONE', $this->check('ولا يهمك يا غالي، ثواني ويكون معاك حد من الزملاء في المعرض يتابع معاك فورًا.'));
    }

    public function test_repeating_a_price_the_bot_already_gave_is_not_unverified(): void
    {
        $contents = [
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'عايز اقسط هوجن 4']]],
            ['role' => 'model', 'parts' => [['type' => 'text', 'text' => 'هوجن 4 استيراد فرز تاني بـ 40,000 كاش، والاستيراد بـ 50,000 كاش']]],
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'انا موظف حكومه']]],
        ];

        $this->assertNull($this->check('تمام. تقصد الفرز التاني بـ 40,000 ولا الاستيراد بـ 50,000؟', $contents));
    }

    public function test_a_new_price_is_still_unverified(): void
    {
        $contents = [['role' => 'model', 'parts' => [['type' => 'text', 'text' => 'هوجن 4 بـ 40,000 كاش']]]];

        $this->assertSame('UNVERIFIED_NUMBER', $this->check('الدايو 2 بـ 35,500 كاش', $contents));
    }

    public function test_latin_letters_inside_an_arabic_word_are_garbled_text(): void
    {
        $this->assertSame('GARBLED_TEXT', $this->check('وlـحظة واحدة معاك يا فندم'));
    }

    public function test_model_names_next_to_arabic_are_not_garbled(): void
    {
        $this->assertNull($this->check('الـHLX 150 والـ VLR 200 موجودين'));
    }

    public function test_ill_confirm_with_a_colleague_and_get_back_is_a_handoff_promise(): void
    {
        $this->assertSame('HANDOFF_CLAIMED_NOT_DONE', $this->check('دي نقطة لسه مفيش لها إجابة رسمية، فخليني أتأكد لك من الزميل المختص وأرجع أقولك فوراً.'));
    }

    public function test_an_if_you_want_offer_to_transfer_is_not_a_promise(): void
    {
        // a customer who only asked "are you a bot?" was handed to staff on this
        $this->assertNull($this->check("أنا المساعد الذكي بتاع المعرض، موجود هنا عشان أرد على استفساراتك بخصوص الموتوسيكلات والتقسيط فورًا في أي وقت.\n\nلو حابب تتكلم مع حد من زمايلي في المعرض، قولي وأنا أحولك ليهم على طول."));
    }

    public function test_a_committed_transfer_after_a_condition_elsewhere_is_still_a_promise(): void
    {
        $this->assertSame('HANDOFF_CLAIMED_NOT_DONE', $this->check('لو عايز تقسيط تمام. هحولك لزميل دلوقتي.'));
    }

    public function test_letters_from_another_script_are_garbled_text(): void
    {
        $this->assertSame('GARBLED_TEXT', $this->check('لو تحب تشפّي كاش، منورنا طبعاً!'));
    }
    private function selfEmployedApplicationFor(WhatsappConversation $conversation, bool $workTypeSaved): void
    {
        $customer = \App\Models\Customer::create(['whatsapp_bot_id' => $conversation->whatsapp_bot_id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $conversation->update(['customer_id' => $customer->id]);
        $type = \App\Models\CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر']);
        $field = \App\Models\RequirementField::create([
            'key' => 'work_type', 'label' => 'نوع الشغل', 'data_type' => 'enum', 'enum_options' => ['delivery_app', 'other'],
            'scope' => 'application', 'is_sensitive' => false, 'is_active' => true,
        ]);
        \App\Models\ApplicationRequirement::create(['customer_type_id' => $type->id, 'requirement_type' => 'field', 'requirement_field_id' => $field->id, 'is_required' => true]);
        $application = \App\Models\Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting',
        ]);

        if ($workTypeSaved) {
            \App\Models\ApplicationData::create(['application_id' => $application->id, 'field_key' => 'work_type', 'party' => 'applicant', 'value' => 'delivery_app', 'source' => 'customer_stated', 'status' => 'valid']);
        }
    }

    public function test_listing_work_documents_before_work_type_is_saved_is_blocked(): void
    {
        // Live: "شغال اوبر" got رخصة + سكرين أرباح recited from memory, work_type never saved.
        $conversation = $this->conversation();
        $this->selfEmployedApplicationFor($conversation, workTypeSaved: false);
        $reply = 'المطلوب: صورة البطاقة، ورخصة قيادة سارية، وسكرين أرباح من التطبيق.';

        $this->assertSame('WORK_TYPE_NOT_RECORDED', app(ReplyGuard::class)->check(['messages' => [$reply]], $conversation, '', [], []));
        $this->assertNull(app(ReplyGuard::class)->check(['messages' => [$reply]], $conversation, '', [], [
            ['name' => 'record_customer_data', 'ok' => true, 'data' => []],
        ]));
    }

    public function test_the_same_list_passes_once_work_type_is_saved(): void
    {
        $conversation = $this->conversation();
        $this->selfEmployedApplicationFor($conversation, workTypeSaved: true);

        $this->assertNull(app(ReplyGuard::class)->check(['messages' => ['محتاجين رخصة القيادة وسكرين الأرباح']], $conversation, '', [], []));
    }

    public function test_asking_about_the_work_mentions_no_document_list(): void
    {
        $conversation = $this->conversation();
        $this->selfEmployedApplicationFor($conversation, workTypeSaved: false);

        $this->assertNull(app(ReplyGuard::class)->check(['messages' => ['الرخصة دي بتعتمد على شغلك، حضرتك بتشتغل إيه بالظبط؟']], $conversation, '', [], []));
    }
    public function test_a_finance_company_no_tool_returned_is_blocked(): void
    {
        // Live: "مع مين التقسيط؟" -> "أمان وفاليو وكونتكت" with no tool call.
        $this->assertSame('UNSOURCED_FINANCE_COMPANY', $this->check('بنتعامل مع كذا جهة زي فاليو وكونتكت'));
        $this->assertNull(app(ReplyGuard::class)->check(['messages' => ['التقسيط مع مايلو']], $this->conversation(), '', [], [
            ['name' => 'get_installment_options', 'ok' => true, 'data' => ['systems' => [['name' => 'مايلو']]]],
        ]));
        $this->assertNull($this->check('المكنة دي فيها أمان وثبات على الطريق، وتقدر تستلمها حالا من الفرع'));
    }

    public function test_ending_with_the_same_question_as_the_last_reply_is_blocked(): void
    {
        $conversation = $this->conversation();
        \App\Models\WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'text',
            'text' => 'الضمان بيوضحه الزميل في الفرع. تحب أقولك سعر الهوجن كاش كام؟']);

        $this->assertSame('REPEATED_QUESTION', app(ReplyGuard::class)->check(['messages' => ['الترخيص بيتظبط في الفرع.', 'تحب أقولك سعر الهوجن كاش كام؟']], $conversation, '', [], []));
        $this->assertNull(app(ReplyGuard::class)->check(['messages' => ['الترخيص بيتظبط في الفرع. تحب تعرف القسط عليها؟']], $conversation, '', [], []));
    }
}
