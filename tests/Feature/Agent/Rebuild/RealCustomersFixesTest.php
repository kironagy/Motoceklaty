<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Context\Facts\ConversationFacts;
use App\Agent\Runtime\ReplyGuard;
use App\Agent\Tools\GetInstallmentOfferTool;
use App\Agent\Tools\ToolContext;
use App\Agent\Runtime\TurnResultBuilder;
use App\Domain\Applications\SnapshotService;
use App\Domain\Applications\WorkProfiles;
use App\Domain\Documents\DocumentEquivalents;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationRequirement;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\AiTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The failures of the real-customer simulation of 2026-10-07, one test each. */
class RealCustomersFixesTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private function check(string $reply, $conversation, array $outcomes = []): ?string
    {
        config(['agent.guard.number_min_value' => 1000]);

        return app(ReplyGuard::class)->check(['messages' => [$reply]], $conversation, '', [], $outcomes);
    }

    private function application($conversation, string $type = 'self_employed'): Application
    {
        $customerType = CustomerType::firstOrCreate(['key' => $type], ['label' => $type]);

        return Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $customerType->id, 'status' => 'collecting']);
    }

    private function doc(Application $application, string $key, string $status = 'accepted'): void
    {
        $type = DocumentType::firstOrCreate(['key' => $key], ['label' => $key, 'description_for_ai' => $key, 'accepted_mimes' => ['image/jpeg'], 'extraction_fields' => [], 'validation_rules' => [], 'is_active' => true]);
        $message = \App\Models\WhatsappMessage::create(['whatsapp_conversation_id' => $application->origin_conversation_id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image']);
        $media = \App\Models\MessageMedia::create(['message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg', 'disk' => 'local', 'path' => "{$key}.jpg", 'size' => 10, 'sha256' => hash('sha256', $key.uniqid())]);
        ApplicationDocument::create(['application_id' => $application->id, 'document_type_id' => $type->id, 'media_id' => $media->id, 'party' => 'applicant', 'status' => $status, 'detected_type_key' => $key]);
    }

    private function require(Application $application, string $docKey, bool $required = true): void
    {
        $type = DocumentType::firstOrCreate(['key' => $docKey], ['label' => $docKey, 'description_for_ai' => $docKey, 'accepted_mimes' => ['image/jpeg'], 'extraction_fields' => [], 'validation_rules' => [], 'is_active' => true]);
        ApplicationRequirement::create(['customer_type_id' => $application->customer_type_id, 'requirement_type' => 'document', 'document_type_id' => $type->id, 'is_required' => $required]);
    }

    // ---- 2: "وصلت" about a paper that never arrived

    public function test_saying_the_screenshots_arrived_when_none_is_accepted_is_refused(): void
    {
        $conversation = $this->conversation();
        $application = $this->application($conversation);
        $this->require($application, 'delivery_app_earnings');
        $this->doc($application, 'national_id_front');

        $this->assertSame('DOCUMENT_CLAIMED_NOT_ACCEPTED', $this->check('تمام يا باشا، وصلتني السكرينات. فاضل رقم تليفونك.', $conversation));
        $this->assertSame('DOCUMENT_CLAIMED_NOT_ACCEPTED', $this->check('وصلوا يا باشا، تسلم. محتاج بس العنوان.', $conversation));
    }

    public function test_saying_a_paper_arrived_is_fine_once_it_is_accepted_and_ordinary_words_are_untouched(): void
    {
        $conversation = $this->conversation();
        $application = $this->application($conversation);
        $this->require($application, 'delivery_app_earnings');
        $this->doc($application, 'delivery_app_earnings');

        // earnings accepted, nothing else missing: the claim is true
        $this->assertNull($this->check('سكرينات الأرباح وصلت وتمام. فاضل رقم تليفونك.', $conversation));
        // "your message arrived" says nothing about a paper
        $this->assertNull($this->check('وصلتني رسالتك يا باشا. تحب أحسبلك القسط على سنتين؟', $conversation));
    }

    // ---- 3: "معيش الورق ده" must lead to the card-only call, not a dead end

    public function test_a_blocked_waiver_tells_the_model_the_exact_legal_way_out(): void
    {
        $conversation = $this->conversation();
        $application = $this->application($conversation, 'business_owner');
        $this->require($application, 'tax_card');
        $this->require($application, 'national_id_front');
        $this->doc($application, 'national_id_front');
        $guard = app(ReplyGuard::class);

        $code = $guard->check(['messages' => ['ولا يهمك، تقدر تقدم بالبطاقة بس من غير الضريبية.']], $conversation, '', [], []);

        $this->assertSame('REQUIRED_DOCUMENT_WAIVED', $code);
        $this->assertStringContainsString('route="card_only"', (string) $guard->lastDetail());
        $this->assertStringContainsString('his exact words', (string) $guard->lastDetail());
    }

    public function test_once_the_card_only_route_is_on_the_same_words_are_allowed(): void
    {
        $conversation = $this->conversation();
        $application = $this->application($conversation, 'business_owner');
        $this->require($application, 'tax_card');
        $this->require($application, 'national_id_front');
        \App\Domain\Applications\CardOnlyRoute::mark($application, 'معيش ورق خالص');

        $this->assertNull($this->check('تمام، هنقدم بالبطاقة بس.', $conversation));
    }

    public function test_the_card_only_route_is_taken_in_one_call_on_his_verified_words_but_never_for_a_woman(): void
    {
        CustomerType::firstOrCreate(['key' => 'self_employed'], ['label' => 'عامل حر', 'is_active' => true])->update(['is_active' => true]);

        foreach (['male' => true, 'female' => false] as $gender => $allowed) {
            $conversation = $this->conversation($gender === 'male' ? '2011' : '2012');
            $application = $this->application($conversation, 'employee');
            \App\Models\WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'الشركة مش بتطلع مفردات ومعيش برنت تأمينات ومش هقدر اجيبهم']);
            $conversation->update(['state' => ['work_profile' => ['work_stated' => true, 'customer_type' => 'employee', 'applicant_gender' => $gender, 'insured' => 'yes',
                'relation_to_workplace' => 'works_for_someone', 'occupation' => 'موظف', 'evidence' => 'موظف']]]);
            $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
            $ctx = new ToolContext($conversation->customer_id, $conversation->id, $application->id, 1, $trace->id, new TurnResultBuilder());

            $result = app(\App\Agent\Tools\UpdateApplicationSelectionTool::class)->execute(['route' => 'card_only', 'customer_type_quote' => 'مش هقدر اجيبهم'], $ctx);

            if ($allowed) {
                $this->assertTrue($result->ok, json_encode($result->error ?? null));
                $this->assertTrue(\App\Domain\Applications\CardOnlyRoute::on($application->refresh()));
                $this->assertTrue(app(WorkProfiles::class)->get($conversation->id)['cannot_bring_work_papers']);
            } else {
                $this->assertFalse($result->ok);
                $this->assertSame('FEMALE_FREE_INCOME_NOT_ACCEPTED', $result->error['code'] ?? null);
                $this->assertFalse(\App\Domain\Applications\CardOnlyRoute::on($application->refresh()));
            }
        }
    }

    // ---- 4: the owner's notes

    public function test_the_inside_photo_stands_in_for_the_tax_card_and_the_profile_screenshot_is_optional(): void
    {
        $this->assertContains('tax_card', DocumentEquivalents::satisfied(['business_place_inside_photo']));
        $this->assertNotContains('tax_card', DocumentEquivalents::satisfied(['business_place_photo']));

        $conversation = $this->conversation();
        $application = $this->application($conversation);
        $this->require($application, 'delivery_app_earnings');
        $this->require($application, 'delivery_app_profile', required: false);

        $snapshot = app(SnapshotService::class)->for($application);
        $this->assertSame(['delivery_app_earnings'], $snapshot['documents']['missing']);
        $this->assertSame(['delivery_app_profile'], $snapshot['documents']['optional_missing']);
        $this->assertFalse(in_array('delivery_app_profile', $snapshot['documents']['required'], true));

        $facts = app(ConversationFacts::class)->for($conversation->refresh())->facts;
        $this->assertSame('delivery_app_profile', $facts['application']['missing']['optional_documents'][0]['key']);
    }

    public function test_offering_the_inside_photo_instead_of_the_tax_card_is_not_a_waiver(): void
    {
        $conversation = $this->conversation();
        $application = $this->application($conversation, 'business_owner');
        $this->require($application, 'tax_card');
        $this->require($application, 'national_id_front');
        $this->doc($application, 'national_id_front');

        // Simulation 2026-10-07: this reply was cut down to "ابعتلي رقم التليفون"
        $this->assertNull($this->check('لو مفيش بطاقة ضريبية ابعتلي صورة للمحل من جوا مع صورة اليافطة.', $conversation));
        // skipping the paper with no substitute is still a waiver
        $this->assertSame('REQUIRED_DOCUMENT_WAIVED', $this->check('مفيش مشكلة، بطاقة ضريبية مش شرط.', $conversation));
        // the natural wording, with the article, used to slip through
        $this->assertSame('REQUIRED_DOCUMENT_WAIVED', $this->check('مفيش مشكلة، البطاقة الضريبية مش شرط.', $conversation));
        $this->assertSame('REQUIRED_DOCUMENT_WAIVED', $this->check('السجل التجاري اختياري.', $conversation));
    }

    public function test_a_missing_paper_with_a_substitute_names_it_in_the_facts(): void
    {
        $conversation = $this->conversation();
        $application = $this->application($conversation, 'business_owner');
        $this->require($application, 'tax_card');
        DocumentType::firstOrCreate(['key' => 'business_place_inside_photo'], ['label' => 'صورة المحل من جوا', 'description_for_ai' => 'x', 'accepted_mimes' => ['image/jpeg'], 'extraction_fields' => [], 'validation_rules' => [], 'is_active' => true]);

        $missing = app(ConversationFacts::class)->for($conversation->refresh())->facts['application']['missing']['documents'][0];

        $this->assertSame('tax_card', $missing['key']);
        $this->assertSame(['صورة المحل من جوا'], $missing['accepted_instead']);
    }

    // ---- 5: women

    public function test_a_woman_is_not_called_ya_basha_once_she_is_recorded_as_one(): void
    {
        $conversation = $this->conversation();

        $this->assertNull($this->check('تمام يا باشا، أحسبلك القسط.', $conversation));

        $conversation->update(['state' => ['work_profile' => ['work_stated' => true, 'applicant' => 'customer', 'applicant_gender' => 'female', 'customer_type' => 'employee', 'occupation' => 'موظفة']]]);

        $this->assertSame('MASCULINE_ADDRESS_TO_WOMAN', $this->check('تمام يا باشا، أحسبلك القسط.', $conversation));
        $this->assertNull($this->check('تمام يا فندم، أحسبلك القسط.', $conversation));
    }

    public function test_the_applicant_gender_must_be_given_when_work_is_recorded(): void
    {
        $schema = app(\App\Agent\Tools\RecordWorkProfileTool::class)->inputSchema();

        $this->assertContains('applicant_gender', $schema['required']);
    }

    // ---- 6: an owner is an owner

    public function test_a_recorded_business_owner_is_an_owner_without_being_asked_again(): void
    {
        $profile = WorkProfiles::clean(['customer_type' => 'business_owner', 'work_stated' => true, 'occupation' => 'صاحب قهوة', 'evidence' => 'صاحبها']);

        $this->assertSame('owner', $profile['relation_to_workplace']);

        $employee = WorkProfiles::clean(['customer_type' => 'employee', 'work_stated' => true]);
        $this->assertSame('unknown', $employee['relation_to_workplace']);

        $worker = WorkProfiles::clean(['customer_type' => 'business_owner', 'relation_to_workplace' => 'works_for_someone']);
        $this->assertSame('works_for_someone', $worker['relation_to_workplace']);
    }

    // ---- 8: made-up pitches

    public function test_a_pitch_nothing_records_is_refused_but_a_plain_answer_is_not(): void
    {
        $conversation = $this->conversation();

        $this->assertSame('UNSOURCED_SALES_CLAIM', $this->check('الدايو 4 هي الأكثر مبيعا في فئة الشغل وسعرها 39,500 جنيه.', $conversation));
        $this->assertSame('UNSOURCED_SALES_CLAIM', $this->check('البوكسر معروفة إنها اقتصادية جدا في البنزين.', $conversation));
        $this->assertSame('UNSOURCED_SALES_CLAIM', $this->check('الهوجن 4 قطع غيارها متوفرة ورخيصة.', $conversation));
        $this->assertNull($this->check('الدايو 4 بتتقسط على أكتر من مدة. تحب أحسبلك القسط؟', $conversation));
    }

    // ---- 9: the offer says what the minimum down payment is

    public function test_the_offer_states_the_minimum_down_payment_and_says_the_admin_fee_is_not_one(): void
    {
        $brand = \App\Models\Brand::create(['name' => 'B', 'image' => 'b.jpg']);
        $machine = \App\Models\Machine::create(['name' => 'دايو 2', 'brand_id' => $brand->id, 'cash_price' => 35500, 'installment_price' => 40000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $system = \App\Models\InstallmentSystem::create(['name' => 'S', 'pricing_mode' => 'standard', 'plans' => [['months' => 24, 'interest' => 20]], 'administrative_fees' => 7]);
        $machine->update(['installment_systems' => [$system->id]]);
        CustomerType::firstOrCreate(['key' => 'employee'], ['label' => 'موظف']);
        $conversation = $this->conversation();
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new ToolContext($conversation->customer_id, $conversation->id, null, 1, $trace->id, new TurnResultBuilder());

        $result = app(GetInstallmentOfferTool::class)->execute(['motorcycle_id' => $machine->id, 'months' => 24, 'customer_type' => 'employee'], $ctx);

        $this->assertTrue($result->ok, json_encode($result->error ?? null));
        $this->assertArrayHasKey('minimum_down_payment', $result->data['offers'][0]);
        $this->assertSame(0, (int) $result->data['offers'][0]['minimum_down_payment']);
        $this->assertStringContainsString('المصاريف الإدارية رسوم مش مقدم', $result->data['down_payment_note']);
    }

    public function test_choosing_a_plan_sets_the_plans_minimum_down_payment_instead_of_leaving_the_application_blocked(): void
    {
        $brand = \App\Models\Brand::create(['name' => 'B2', 'image' => 'b.jpg']);
        $machine = \App\Models\Machine::create(['name' => 'دايو 2', 'brand_id' => $brand->id, 'cash_price' => 35500, 'installment_price' => 40000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $system = \App\Models\InstallmentSystem::create(['name' => 'S2', 'pricing_mode' => 'standard', 'plans' => [['months' => 24, 'interest' => 20]], 'administrative_fees' => 7]);
        $machine->update(['installment_systems' => [$system->id]]);
        $plan = \App\Models\InstallmentPlan::where('installment_system_id', $system->id)->where('months', 24)->firstOrFail();
        $conversation = $this->conversation();
        $application = $this->application($conversation, 'employee');

        app(\App\Domain\Applications\ApplicationService::class)->updateSelection($application, $machine, $plan, null);

        $this->assertSame(0.0, (float) $application->refresh()->down_payment);
        $this->assertNotContains('down_payment', array_column(app(SnapshotService::class)->for($application)['blockers'], 'key'));

        // an application OPENED with a plan gets it too (start_application does not go through updateSelection)
        $other = $this->conversation('2013');
        $started = app(\App\Domain\Applications\ApplicationService::class)->start($other->customer, $other->id, CustomerType::firstOrCreate(['key' => 'employee'], ['label' => 'e']), $machine, $plan, null);
        $this->assertSame(0.0, (float) $started['application']->down_payment);

        // what he says later wins
        app(\App\Domain\Applications\ApplicationService::class)->updateSelection($application, null, null, 3000.0);
        $this->assertSame(3000.0, (float) $application->refresh()->down_payment);
    }
}
