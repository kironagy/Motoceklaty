<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Runtime\ReplyGuard;
use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\CheckEligibilityTool;
use App\Agent\Tools\RecordWorkProfileTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Applications\Applicant;
use App\Domain\Applications\ApplicationService;
use App\Domain\Memory\CustomerMemory;
use App\Models\Application;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\EligibilityRule;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conversation 206 (number ending 119, 2026-10-05): a 20-year-old talked
 * about his mother, his father (pension 2,000) and his brother. One
 * application held the father's income and the brother's ID; the brother
 * was refused three times for his father's pension; "اخ" + "اه" became
 * "أبوك"; his mother's age replaced his own.
 */
class Conversation206Test extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private function says(int $conversationId, string $text): void
    {
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversationId, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => $text]);
    }

    private function ctx($conversation, ?int $applicationId = null): ToolContext
    {
        return new ToolContext($conversation->customer_id, $conversation->id, $applicationId, 1, 1, new TurnResultBuilder());
    }

    private function profile($conversation, array $args)
    {
        return app(RecordWorkProfileTool::class)->execute($args + ['work_stated' => true, 'customer_type' => 'unknown', 'working_now' => 'unknown'], $this->ctx($conversation));
    }

    public function test_the_brother_does_not_inherit_the_fathers_application(): void
    {
        $conversation = $this->conversation();
        $pension = CustomerType::create(['key' => 'pension', 'label' => 'على المعاش']);
        $selfEmployed = CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر']);
        $this->says($conversation->id, 'طيب انا ابويا معاه معاش');

        $this->profile($conversation, ['evidence' => 'ابويا معاه معاش', 'applicant' => 'someone_else', 'applicant_relation' => 'father',
            'applicant_quote' => 'ابويا معاه معاش', 'customer_type' => 'pension', 'working_now' => 'no']);
        $father = app(ApplicationService::class)->start(Customer::find($conversation->customer_id), $conversation->id, $pension, null, null, null,
            Applicant::fromProfile(app(\App\Domain\Applications\WorkProfiles::class)->get($conversation->id)))['application'];
        $this->assertSame('father', $father->applicant['relation']);

        $this->says($conversation->id, 'طيب اخويا عنده ٢١ سنه وشغال نجار');
        $result = $this->profile($conversation, ['evidence' => 'شغال نجار', 'applicant' => 'someone_else', 'applicant_relation' => 'brother',
            'applicant_quote' => 'اخويا عنده ٢١ سنه', 'customer_type' => 'self_employed', 'working_now' => 'yes', 'work_type' => 'craftsman']);

        $this->assertSame($father->id, $result->data['previous_application_closed']['closed_application_id']);
        $this->assertSame('withdrawn', $father->refresh()->status);
        $this->assertSame('أخوك', $result->data['applicant']['person']);

        // starting again opens the brother's own application - the father's is never reopened for him
        $brother = app(ApplicationService::class)->start(Customer::find($conversation->customer_id), $conversation->id, $selfEmployed, null, null, null,
            Applicant::fromProfile(app(\App\Domain\Applications\WorkProfiles::class)->get($conversation->id)));
        $this->assertTrue($brother['created']);
        $this->assertNotSame($father->id, $brother['application']->id);
        $this->assertSame('brother', $brother['application']->applicant['relation']);
    }

    public function test_a_relation_his_words_do_not_name_stays_unclear(): void
    {
        // "قصدك أبوك هو اللي هيقدم؟" - "اخ" - "اه": no father in his words
        $conversation = $this->conversation();
        $this->says($conversation->id, 'طيب انا ابويا معاه معاش');
        $this->says($conversation->id, 'اخ');
        $this->says($conversation->id, 'اه');

        $result = $this->profile($conversation, ['evidence' => 'ابويا معاه معاش', 'applicant' => 'someone_else', 'applicant_relation' => 'father', 'applicant_quote' => 'ابويا هو اللي هيقدم']);

        $this->assertSame('حد تاني (مين بالظبط مش واضح لسه)', $result->data['applicant']['person']);
        $this->assertSame('unclear', app(\App\Domain\Applications\WorkProfiles::class)->get($conversation->id)['applicant_relation']);
    }

    public function test_an_unclear_person_never_closes_a_named_persons_application(): void
    {
        $this->assertTrue(Applicant::same(['who' => 'other', 'relation' => 'brother'], ['who' => 'other', 'relation' => 'unclear']));
        $this->assertFalse(Applicant::same(['who' => 'other', 'relation' => 'brother'], ['who' => 'other', 'relation' => 'father']));
        $this->assertFalse(Applicant::same(['who' => 'customer'], ['who' => 'other', 'relation' => 'brother']));
        // "انا اللي هقدم" after talking about his brother
        $this->assertSame('العميل نفسه', Applicant::label(Applicant::fromProfile(['applicant' => 'customer'])));
    }

    public function test_his_mothers_age_is_not_his_age(): void
    {
        $conversation = $this->conversation();
        $this->says($conversation->id, 'عندي ٢٠ سنه ينفع اقدم');
        $this->says($conversation->id, 'عندها ٤٥');
        $memory = app(CustomerMemory::class);

        $memory->applyModelUpdate($conversation->customer_id, $conversation->id, ['facts' => [['key' => 'age', 'value' => '20', 'quote' => 'عندي ٢٠ سنه']]]);
        $memory->applyModelUpdate($conversation->customer_id, $conversation->id, ['facts' => [['key' => 'age', 'value' => '45', 'quote' => 'عندها ٤٥', 'about' => 'other_applicant']]]);

        $this->assertSame(20, $memory->statedAge($conversation->customer_id));
        $this->assertSame('45', $memory->get($conversation->customer_id)['applicant_facts']['facts']['age']['value']);
    }

    public function test_the_other_persons_age_after_his_refusal_is_checked_not_doubted(): void
    {
        EligibilityRule::create(['customer_type_id' => null, 'rule_type' => 'age_range', 'params' => ['min' => 21, 'max' => 62], 'is_active' => true]);
        $conversation = $this->conversation();
        $tool = app(CheckEligibilityTool::class);
        // ages are checked only when he wrote them
        $this->says($conversation->id, 'انا عندي 20 سنة');
        $this->says($conversation->id, 'اخويا عنده 21');
        $this->says($conversation->id, 'لا انا 22');

        $refusal = $tool->execute(['age' => 20], $this->ctx($conversation));
        $this->assertSame('not_eligible', $refusal->data['status']);
        // the runner records every tool step; the "changed his age" check reads them
        $trace = \App\Models\AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'done']);
        \App\Models\AiTraceStep::create(['trace_id' => $trace->id, 'turn_id' => 1, 'seq' => 1, 'kind' => 'tool', 'tool_name' => 'check_eligibility', 'result_redacted' => json_encode(['data' => $refusal->data])]);
        // his brother is 21: not "AGE_CHANGED_AFTER_REFUSAL"
        $this->assertSame('eligible', $tool->execute(['age' => 21, 'about' => 'other_applicant'], $this->ctx($conversation))->data['status']);
        // he himself suddenly 22: still doubted
        $this->assertSame('needs_id', $tool->execute(['age' => 22], $this->ctx($conversation))->data['status']);
    }

    public function test_a_mother_who_does_not_work_is_refused_with_the_other_person_line(): void
    {
        $conversation = $this->conversation();
        $this->says($conversation->id, 'امي مش شغاله بس عندها شقه تمليك');
        $this->profile($conversation, ['evidence' => 'امي مش شغاله', 'applicant' => 'someone_else', 'applicant_relation' => 'mother', 'applicant_quote' => 'امي', 'working_now' => 'no']);

        $result = app(CheckEligibilityTool::class)->execute(['work' => 'امي مش شغاله', 'about' => 'other_applicant'], $this->ctx($conversation))->data;

        $this->assertSame('not_eligible', $result['status']);
        $this->assertStringStartsWith('للأسف اللي هيقدّم لازم يكون شغال', $result['other_applicant_line']);
        $this->assertStringNotContainsString('بدالك', $result['other_applicant_line']);
    }

    public function test_the_guards_that_broke_this_conversation(): void
    {
        $guard = app(ReplyGuard::class);
        $method = new \ReflectionMethod($guard, 'makesUnrecordedPromise');
        // answering "ينفع بضمان الشقه؟" is no warranty promise
        $this->assertFalse($method->invoke($guard, 'لو أمك مش شغالة جهات التمويل مش بتقبلها بضمان دخل حر.', ''));
        $this->assertTrue($method->invoke($guard, 'المكنة بضمان المعرض.', ''));
    }

    public function test_a_greeting_has_no_nickname(): void
    {
        $conversation = $this->conversation();

        $this->assertSame('وعليكم السلام ورحمة الله وبركاته، نورتنا. بتدور على موتوسيكل ولا سكوتر؟',
            app(\App\Agent\Runtime\GreetingReply::class)->for($conversation, 'سلام عليكم'));
    }

    public function test_the_context_tells_it_its_last_replies_all_said_ya_basha(): void
    {
        $conversation = $this->conversation();

        foreach (['تمام يا باشا، أمك هتقدم باسمها؟', 'تمام يا باشا. هي سنها كام؟', 'تمام يا باشا، قصدك أبوك هو اللي هيقدم بدل أمك؟'] as $text) {
            WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'text', 'text' => $text]);
        }

        $note = (new \ReflectionMethod(\App\Agent\Context\ContextBuilder::class, 'ownStyleNote'))->invoke(app(\App\Agent\Context\ContextBuilder::class), $conversation);

        $this->assertStringContainsString('3 من آخر 3 ردود فيهم نداء', $note);
    }

    public function test_the_context_points_out_a_refusal_it_keeps_repeating(): void
    {
        $conversation = $this->conversation();

        foreach (['أخوك مش هينفع يقدم لأنه مش شغال، جهات التمويل بتطلب اللي يقدم يكون شغال أو على المعاش. تحب أبوك يقدم؟',
            'معلش، أخوك مش هينفع يقدم لأنه مش شغال، جهات التمويل بتطلب اللي يقدم يكون شغال أو على المعاش. الكاش متاح في أي فرع.'] as $text) {
            WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'text', 'text' => $text]);
        }

        $note = (new \ReflectionMethod(\App\Agent\Context\ContextBuilder::class, 'ownStyleNote'))->invoke(app(\App\Agent\Context\ContextBuilder::class), $conversation);

        $this->assertStringContainsString('ما تعيدوش', $note);
        $this->assertStringContainsString('جهات التمويل بتطلب اللي يقدم يكون شغال أو على المعاش', $note);
    }

    public function test_a_stated_pension_is_checked_against_the_minimum(): void
    {
        $pension = CustomerType::create(['key' => 'pension', 'label' => 'على المعاش']);
        EligibilityRule::create(['customer_type_id' => $pension->id, 'rule_type' => 'minimum_value', 'params' => ['min' => 4000, 'fact' => 'monthly_income'], 'is_active' => true]);
        $conversation = $this->conversation();

        $result = app(CheckEligibilityTool::class)->execute(['customer_type' => 'pension', 'monthly_income' => 2000, 'about' => 'other_applicant'], $this->ctx($conversation))->data;

        $this->assertSame('not_eligible', $result['status']);
        $this->assertSame('BELOW_MINIMUM_VALUE', $result['reasons'][0]['code']);
    }

    public function test_a_reply_about_a_person_nobody_recorded_is_sent_back(): void
    {
        $conversation = $this->conversation();
        $guard = new \ReflectionMethod(ReplyGuard::class, 'speaksOfUnrecordedPerson');
        $this->says($conversation->id, 'اخ اخويا حبيب صاحب');

        // "اخ اخويا حبيب صاحب" -> "أخوك سنه كام؟" with nobody recorded
        $this->assertTrue($guard->invoke(app(ReplyGuard::class), 'تمام، أخوك سنه كام؟', $conversation));
        // asking which one is fine
        $this->assertFalse($guard->invoke(app(ReplyGuard::class), 'مين اللي هيقدم، أخوك ولا صاحبك؟', $conversation));
        $this->assertFalse($guard->invoke(app(ReplyGuard::class), 'مين اللي هيقدّم؟', $conversation));

        $this->says($conversation->id, 'قصدي اخويا');
        $this->profile($conversation, ['evidence' => 'قصدي اخويا', 'applicant' => 'someone_else', 'applicant_relation' => 'brother', 'applicant_quote' => 'قصدي اخويا']);

        $this->assertFalse($guard->invoke(app(ReplyGuard::class), 'تمام، أخوك سنه كام؟', $conversation));
        // "اخ" + "اه" is no father
        $this->assertTrue($guard->invoke(app(ReplyGuard::class), 'عشان نكمل التقديم باسم أبوك ابعت البطاقة', $conversation));
    }

    public function test_he_works_then_he_does_not_closes_his_application(): void
    {
        $conversation = $this->conversation();
        $selfEmployed = CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر']);
        $this->says($conversation->id, 'اخويا هيقدم عنده ٢٥ وشغال نجار');
        $this->profile($conversation, ['evidence' => 'شغال نجار', 'applicant' => 'someone_else', 'applicant_relation' => 'brother', 'applicant_quote' => 'اخويا هيقدم',
            'customer_type' => 'self_employed', 'working_now' => 'yes', 'work_type' => 'craftsman']);
        $application = app(ApplicationService::class)->start(Customer::find($conversation->customer_id), $conversation->id, $selfEmployed, null, null, null,
            Applicant::fromProfile(app(\App\Domain\Applications\WorkProfiles::class)->get($conversation->id)))['application'];

        $this->says($conversation->id, 'لا هو مش شغال دلوقتي');
        $result = $this->profile($conversation, ['evidence' => 'لا هو مش شغال دلوقتي', 'applicant' => 'someone_else', 'applicant_relation' => 'brother', 'applicant_quote' => 'اخويا هيقدم',
            'customer_type' => 'self_employed', 'working_now' => 'no']);

        $this->assertSame('withdrawn', $application->refresh()->status);
        $this->assertStringStartsWith('للأسف اللي هيقدّم لازم يكون شغال', $result->data['other_applicant_line']);
    }

    public function test_what_is_recorded_about_the_person_is_in_the_facts_every_turn(): void
    {
        $conversation = $this->conversation();
        $this->says($conversation->id, 'اخويا هيقدم عنده ٢٥ وشغال نجار');
        $this->profile($conversation, ['evidence' => 'شغال نجار', 'applicant' => 'someone_else', 'applicant_relation' => 'brother', 'applicant_quote' => 'اخويا هيقدم', 'working_now' => 'yes', 'work_stated' => true, 'occupation' => 'نجار']);

        $facts = app(\App\Agent\Context\Facts\ConversationFacts::class)->for($conversation->refresh())->facts;

        $this->assertSame('أخوك', $facts['applicant']['person']);
        $this->assertSame('yes', $facts['work']['working_now']);
        $this->assertSame('شغال نجار', $facts['work']['his_words']);
        $this->assertSame('نجار', $facts['work']['occupation']);
    }

    public function test_offering_options_is_not_naming_a_person(): void
    {
        $conversation = $this->conversation();
        $guard = new \ReflectionMethod(ReplyGuard::class, 'speaksOfUnrecordedPerson');

        $this->assertFalse($guard->invoke(app(ReplyGuard::class), 'ممكن حد تاني من أهلك أو صاحبك يقدم باسمه.', $conversation));
    }

    public function test_saying_the_id_arrived_is_true_when_no_application_can_hold_it(): void
    {
        $conversation = $this->conversation();
        $outcomes = [['name' => 'process_document', 'ok' => false, 'data' => ['code' => 'NO_ACTIVE_APPLICATION']]];

        $this->assertNotSame('DOCUMENT_CLAIMED_NOT_ACCEPTED', app(ReplyGuard::class)->check(['messages' => ['البطاقة وصلت. تحب تجيب حد تاني شغال يقدم باسمه؟']], $conversation, '', [], $outcomes));
        $this->assertSame('DOCUMENT_CLAIMED_NOT_ACCEPTED', app(ReplyGuard::class)->check(['messages' => ['البطاقة وصلت واتقبلت.']], $conversation, '', [], $outcomes));
    }

    public function test_his_words_naming_two_people_keep_the_person_unclear(): void
    {
        $conversation = $this->conversation();
        $this->says($conversation->id, 'اخ اخويا حبيب صاحب');

        $result = $this->profile($conversation, ['evidence' => 'اخ اخويا حبيب صاحب', 'applicant' => 'someone_else', 'applicant_relation' => 'brother',
            'applicant_quote' => 'اخويا', 'people_named' => ['brother', 'friend']]);

        $this->assertSame('حد تاني (مين بالظبط مش واضح لسه)', $result->data['applicant']['person']);

        // "قصدي اخويا" names one
        $this->says($conversation->id, 'قصدي اخويا');
        $result = $this->profile($conversation, ['evidence' => 'قصدي اخويا', 'applicant' => 'someone_else', 'applicant_relation' => 'brother',
            'applicant_quote' => 'قصدي اخويا', 'people_named' => ['brother']]);

        $this->assertSame('أخوك', $result->data['applicant']['person']);
    }

    public function test_the_system_followed_by_and_is_still_internal_wording(): void
    {
        $guard = new \ReflectionMethod(ReplyGuard::class, 'containsInternalKey');

        $this->assertTrue($guard->invoke(app(ReplyGuard::class), 'أعدتلك شغله في النظام وكمان سجلت المعاش 2000 جنيه.'));
        $this->assertFalse($guard->invoke(app(ReplyGuard::class), 'إحنا بنتعامل مع أنظمة تقسيط متنوعة.'));
    }

    public function test_his_brother_is_never_my_brother(): void
    {
        $conversation = $this->conversation();
        $guard = new \ReflectionMethod(ReplyGuard::class, 'speaksOfUnrecordedPerson');

        $this->assertTrue($guard->invoke(app(ReplyGuard::class), 'تمام، نبدأ التقديم باسم أخويا دلوقتي؟', $conversation));
    }

    public function test_recording_that_the_other_person_does_not_work_refuses_at_once(): void
    {
        $conversation = $this->conversation();
        $this->says($conversation->id, 'طيب اخويا عنده ٢١ سنه بس مش شغال هيحيب المكنه لسه يشتغل عليها');

        $result = $this->profile($conversation, ['evidence' => 'مش شغال هيحيب المكنه لسه يشتغل عليها', 'applicant' => 'someone_else', 'applicant_relation' => 'brother',
            'applicant_quote' => 'اخويا عنده ٢١ سنه', 'people_named' => ['brother'], 'working_now' => 'not_yet', 'customer_type' => 'self_employed']);

        $this->assertStringStartsWith('للأسف اللي هيقدّم لازم يكون شغال', $result->data['other_applicant_line']);
    }

    public function test_the_full_list_is_told_once_and_then_it_is_known(): void
    {
        // Owner 2026-10-05: everything in the first message, then "تمام" + what is missing.
        // The fact the agent reads is told_so_far.requirements_listed - no step, no list to hide.
        $conversation = $this->conversation();
        $type = CustomerType::create(['key' => 'self_employed', 'label' => 'عامل حر']);
        $application = Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'collecting']);
        $told = app(\App\Agent\Context\Facts\ToldSoFar::class);

        $this->assertArrayNotHasKey('requirements_listed', $told->for($conversation, $application));

        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'text', 'text' => 'المطلوب: ...']);

        $this->assertTrue($told->for($conversation, $application)['requirements_listed']);
    }

    public function test_the_same_long_reply_twice_is_stopped(): void
    {
        $conversation = $this->conversation();
        $said = 'يا غالي، أنا مقدر إنك عايز تساعد أخوك، بس أنا بوضحلك شروط جهات التمويل اللي بنتعامل معاها، وهي إن اللي هيقدم لازم يكون شغال حاليا أو على المعاش عشان الموافقة.';
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'text', 'text' => $said]);
        $guard = new \ReflectionMethod(ReplyGuard::class, 'repeatsAnEarlierReply');

        $this->assertTrue($guard->invoke(app(ReplyGuard::class), $said, $conversation));
        $this->assertFalse($guard->invoke(app(ReplyGuard::class), 'زي ما قلتلك، للأسف مش هينفع وهو مش شغال.', $conversation));
    }

    public function test_a_second_repeat_sends_only_what_is_new(): void
    {
        $conversation = $this->conversation();
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'bot', 'type' => 'text',
            'text' => 'زي ما وضحتلك، جهات التمويل بتشترط وجود شغل فعلي ومستندات تثبت الدخل عشان يوافقوا على التقسيط.']);

        $trimmed = app(ReplyGuard::class)->withoutRepeatedSentences([
            "أنا فاهمك.\nزي ما وضحتلك، جهات التمويل بتشترط وجود شغل فعلي ومستندات تثبت الدخل عشان يوافقوا على التقسيط. لما أخوك يشتغل ابعتلي ونقدمله.",
        ], $conversation);

        $this->assertSame(['أنا فاهمك. لما أخوك يشتغل ابعتلي ونقدمله.'], $trimmed);
    }
}
