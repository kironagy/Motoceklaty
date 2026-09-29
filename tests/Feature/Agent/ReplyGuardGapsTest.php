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
        foreach (['driving_license' => 'رخصة القيادة', 'delivery_app_earnings' => 'سكرين أرباح'] as $key => $label) {
            $document = \App\Models\DocumentType::create(['key' => $key, 'label' => $label, 'is_active' => true]);
            \App\Models\ApplicationRequirement::create(['customer_type_id' => $type->id, 'requirement_type' => 'document', 'document_type_id' => $document->id,
                'is_required' => true, 'condition' => ['op' => 'in', 'fact' => 'work_type', 'value' => ['delivery_app']]]);
        }
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
        // saved in this turn
        \App\Models\ApplicationData::create(['application_id' => \App\Models\Application::first()->id, 'field_key' => 'work_type', 'party' => 'applicant', 'value' => 'delivery_app', 'source' => 'customer_stated', 'status' => 'valid']);
        $this->assertNull(app(ReplyGuard::class)->check(['messages' => [$reply]], $conversation, '', [], [
            ['name' => 'record_customer_data', 'ok' => true, 'data' => []],
        ]));
    }

    /**
     * Owner 2026-09-29: every document on his list is asked, none waived.
     * Simulated workshop owner: "البطاقة الضريبية أو السجل التجاري مش شرط
     * أساسي، لو مش معاك مفيش مشكلة" while the tax card was required.
     */
    public function test_a_required_document_is_never_waived(): void
    {
        $conversation = $this->conversation();
        $this->selfEmployedApplicationFor($conversation, workTypeSaved: true);
        $check = fn (string $reply) => app(ReplyGuard::class)->check(['messages' => [$reply]], $conversation, '', [], []);

        $this->assertSame('REQUIRED_DOCUMENT_WAIVED', $check('أيوة طبعاً، رخصة القيادة مش شرط أساسي، لو مش معاك مفيش مشكلة.'));
        $this->assertSame('REQUIRED_DOCUMENT_WAIVED', $check('ابعتلي صورة البطاقة، ولو معاك سكرين الأرباح يا ريت تبعته.'));
        $this->assertSame('REQUIRED_DOCUMENT_WAIVED', $check('أيوه بالبطاقة بس يا باشا.'));
        $this->assertNull($check('لا يا باشا، رخصة القيادة لازم عشان جهة التمويل، مش هينفع من غيرها.'));
        $this->assertNull($check('محتاجين رخصة القيادة وسكرين الأرباح'));
    }

    public function test_only_the_id_is_fine_when_that_is_all_his_job_needs(): void
    {
        $conversation = $this->conversation();
        $this->selfEmployedApplicationFor($conversation, workTypeSaved: false);
        \App\Models\ApplicationData::create(['application_id' => \App\Models\Application::first()->id, 'field_key' => 'work_type', 'party' => 'applicant', 'value' => 'other', 'source' => 'customer_stated', 'status' => 'valid']);

        $this->assertNull(app(ReplyGuard::class)->check(['messages' => ['أيوه بالبطاقة بس يا باشا، وش وضهر.']], $conversation, '', [], []));
    }

    /**
     * Owner 2026-09-29: "بما إن المكنة سعرها تحت 60 ألف، قولي بتشتغل إيه"
     * is our own rule, not something to tell a customer. Above the cap it is
     * said - a freelancer pays the difference in cash and the rest is
     * financed - but only with the offer's explanation.
     */
    public function test_the_financing_cap_threshold_is_said_only_when_it_applies(): void
    {
        \App\Models\EligibilityRule::create(['customer_type_id' => \App\Models\CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر'])->id,
            'rule_type' => 'financing_cap', 'params' => ['max_amount' => 60000], 'is_active' => true]);
        $check = fn (string $reply, array $outcomes = []) => app(ReplyGuard::class)->check(['messages' => [$reply]], $this->conversation(), '', [], $outcomes);

        $this->assertSame('CAP_THRESHOLD_MENTIONED', $check('بما إن المكنة سعرها تحت 60 ألف، قولي حضرتك بتشتغل إيه؟'));
        $this->assertSame('CAP_THRESHOLD_MENTIONED', $check('المكنة دي أقل من ٦٠ ألف فمش محتاج تدفع فرق.'));
        $this->assertSame('CAP_THRESHOLD_MENTIONED', $check('المكنة أعلى من 60,000 جنيه فهتدفع الفرق كاش.'));

        $capped = [['name' => 'get_installment_offer', 'ok' => true, 'data' => ['explain_to_customer' => 'بما إن شغلك عامل حر، أقصى مبلغ بيتقسط 60,000 جنيه']]];
        $this->assertNull($check('بما إن شغلك عامل حر والمكنة أعلى من 60 ألف، هتدفع الفرق كاش والباقي يتقسط.', $capped));
        // before his work is known he is only asked what he does
        $askWork = [['name' => 'get_installment_offer', 'ok' => false, 'data' => ['code' => 'ASK_WORK_FIRST']]];
        $this->assertSame('CAP_THRESHOLD_MENTIONED', $check('المكنة أكتر من 60 ألف، فالقسط على حسب شغلك. حضرتك بتشتغل إيه؟', $askWork));
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

    /** Sentences the bot really sent in bot requests 4272-4278 (2026-09-26). */
    public function test_what_went_wrong_in_the_bot_requests_is_blocked(): void
    {
        $this->assertSame('GARBLED_TEXT', $this->check('كده كل البيانات خلصت، هبعتلusd الطلب دلوقتي للمراجعة.'));
        $this->assertNull($this->check('صور الفيجوري بالVLR200 وصلت؟'));
        $this->assertSame('RESUBMISSION_PROMISED', $this->check('طالما معاك البطاقة بس، إحنا ممكن نقدم تاني على نظام مختلف من غير ما نعقد الدنيا.'));
        $this->assertSame('WORK_TYPE_SWITCH_SUGGESTED', $this->check('ممكن نقدّم على إنك "عامل حر" (دخل حر) بدل "موظف". تحب نكمل كده؟'));
        $this->assertSame('DATA_CLAIMED_NOT_SAVED', $this->check('ولا يهمك، أنا عدلتها عندي في الطلب لـ "شركة اسكويار".'));
        $this->assertSame('DATA_CLAIMED_NOT_SAVED', $this->check('تمام يا غالي، الرقم وصل. ابعتلي عنوان السكن.'));
        $this->assertSame('BANNED_WORDING', $this->check('بص يا غالي، مفيش عندنا مقدم خالص.'));
        $this->assertSame('DOCUMENT_NOT_REQUIRED', $this->check('المطلوب منك صورة من عقد الورشة أو إيصال مرافق للمكان.'));
        $this->assertSame('UNRECORDED_PROMISE', $this->check('في الغالب الرد بييجي خلال أيام قليلة، مش أسبوعين.'));
        $this->assertSame('UNRECORDED_PROMISE', $this->check('في العادة الموضوع بياخد من يوم لـ ٣ أيام عمل.'));
        $this->assertSame('UNRECORDED_PROMISE', $this->check('غالباً في خلال يوم أو يومين عمل بتكون الأمور وضحت.'));
        $this->assertSame('UNRECORDED_PROMISE', $this->check('كده الطلب متسجل بالرقمين عشان نضمن إننا نوصلك.'));
    }

    public function test_a_colleague_is_never_promised_in_seconds(): void
    {
        $conversation = $this->conversation();
        $conversation->update(['status' => 'awaiting_agent']);

        $this->assertSame('HANDOFF_TIME_PROMISED', app(ReplyGuard::class)->check(['messages' => ['ثواني ويكون معاك زميل من فريق المبيعات يوضحلك كل التفاصيل حالا.']], $conversation, '', [], []));
        $this->assertNull(app(ReplyGuard::class)->check(['messages' => ['زميلي هيرد عليك، ولحد ما يرد اسألني أي حاجة.']], $conversation, '', [], []));
    }

    public function test_a_second_submission_is_not_claimed_when_nothing_was_sent(): void
    {
        // Request 4272, refused by the finance company: "الطلب اتقفل واتبعت على أمان".
        $conversation = $this->conversation();
        $customer = \App\Models\Customer::create(['whatsapp_bot_id' => $conversation->whatsapp_bot_id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $conversation->update(['customer_id' => $customer->id]);
        $type = \App\Models\CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر']);
        \App\Models\Application::create(['customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'submitted', 'submitted_at' => now()]);

        $check = fn (string $reply) => app(ReplyGuard::class)->check(['messages' => [$reply]], $conversation, '', [], []);

        $this->assertSame('RESUBMISSION_CLAIMED', $check('الطلب اتقفل واتبعت على أمان يا غالي.'));
        $this->assertSame('RESUBMISSION_CLAIMED', $check('أيوه يا باشا، الطلب اتبعت على النظام الجديد.'));
        $this->assertNull($check('أيوه يا باشا، طلبك اتبعت وفي المراجعة.'));
    }

    public function test_opening_hours_come_from_this_turns_lookup(): void
    {
        // Simulator 688: the table said 1 الصبح - 1 بالليل, the bot repeated
        // "10 الصبح لـ 10 بالليل" from earlier in the chat.
        $conversation = $this->conversation();
        $reply = ['messages' => ['مواعيد العمل في كل فروعنا يا باشا من السبت للخميس من 10 الصبح لـ 10 بالليل.']];
        $lookup = fn (string $hours) => [['name' => 'get_branch_information', 'ok' => true, 'data' => ['branches' => [
            ['name' => 'فرع عين شمس', 'city' => 'عين شمس', 'map_url' => null, 'working_hours' => ['السبت - الخميس' => $hours]],
        ]]]];

        $this->assertSame('BRANCH_NOT_SOURCED', app(ReplyGuard::class)->check($reply, $conversation, '', [], []));
        $this->assertSame('BRANCH_NOT_SOURCED', app(ReplyGuard::class)->check($reply, $conversation, '', [], $lookup('١ الصبح - ١ بالليل')));
        $this->assertNull(app(ReplyGuard::class)->check($reply, $conversation, '', [], $lookup('10 الصبح - 10 بالليل')));
    }

    public function test_the_word_catalog_is_never_said_to_a_customer(): void
    {
        $this->assertSame('BANNED_WORDING', $this->check('للأسف مفيش ميعاد محدد لتوفيرها، المتاح عندنا حالياً هو اللي موجود في الكتالوج.'));
        $this->assertNull($this->check('للأسف مش متوفرة عندنا حاليا، تحب أشوفلك بديل قريب منها؟'));
    }
}
