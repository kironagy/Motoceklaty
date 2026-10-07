<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Runtime\ReplyGuard;
use App\Domain\Applications\WorkClassification;
use App\Models\Brand;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The problems the owner found testing the live bot on 2026-10-07, one test each. */
class OwnerReportFixesTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private function check(string $reply, $conversation): ?string
    {
        config(['agent.guard.number_min_value' => 1000]);

        return app(ReplyGuard::class)->check(['messages' => [$reply]], $conversation, '', [], []);
    }

    private function says($conversation, string $text): void
    {
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => $text]);
    }

    private function work($conversation, array $profile): void
    {
        $conversation->update(['state' => ['work_profile' => $profile + ['work_stated' => true, 'applicant' => 'customer', 'applicant_gender' => 'male', 'working_now' => 'yes', 'evidence' => 'x']]]);
    }

    // ---- فصحى

    public function test_formal_arabic_is_refused_and_colloquial_is_not(): void
    {
        $conversation = $this->conversation();

        $this->assertSame('FORMAL_ARABIC', $this->check('يمكنك اختيار المدة اللي تناسبك.', $conversation));
        $this->assertSame('FORMAL_ARABIC', $this->check('تمام، وهذا هو السعر النهائي.', $conversation));
        $this->assertSame('FORMAL_ARABIC', $this->check('المقدم مش إجباري، المصاريف فقط وقت الاستلام.', $conversation));
        $this->assertNull($this->check('تقدر تختار المدة اللي تناسبك. امتى تحب تيجي الفرع؟', $conversation));
    }

    // ---- "اهلا بيك يا استاذ محاسب"

    public function test_his_job_is_never_used_to_address_him(): void
    {
        $conversation = $this->conversation();
        $this->says($conversation, 'انا محاسب');
        $this->work($conversation, ['customer_type' => 'unknown', 'occupation' => 'محاسب', 'job_title' => 'محاسب']);

        $this->assertSame('JOB_AS_TITLE', $this->check('اهلا بيك يا استاذ محاسب، بتدور على إيه؟', $conversation));
        $this->assertSame('JOB_AS_TITLE', $this->check('اهلا بيك ياستاذ محاسب', $conversation));
        $this->assertNull($this->check('أهلا بيك يا باشا، بتدور على موتوسيكل ولا سكوتر؟', $conversation));
    }

    // ---- "عايز اسكوتر" got the Superlight

    public function test_a_motorcycle_is_not_offered_to_someone_who_asked_for_a_scooter(): void
    {
        $conversation = $this->conversation();
        $keeway = Brand::create(['name' => 'كيواي', 'image' => 'x.png']);
        $scooters = Brand::create(['name' => 'Scooters', 'image' => 'x.png']);
        Machine::create(['name' => 'Superlight', 'brand_id' => $keeway->id, 'cash_price' => 74000, 'is_active' => true]);
        Machine::create(['name' => 'Keeway keet 150', 'brand_id' => $scooters->id, 'cash_price' => 53000, 'is_active' => true]);
        $this->says($conversation, 'عايز اسكوتر');

        $this->assertSame('KIND_NOT_ASKED', $this->check('عندك Superlight حلوة جدا.', $conversation));
        $this->assertNotSame('KIND_NOT_ASKED', $this->check('عندك Keeway keet 150.', $conversation));

        // "Dayun Tx 250" (a scooter) holds the name of the Hogan "Tx 250" (a motorcycle)
        $hogan = Brand::create(['name' => 'هوجان', 'image' => 'x.png']);
        Machine::create(['name' => 'Tx 250', 'brand_id' => $hogan->id, 'cash_price' => 70000, 'is_active' => true]);
        Machine::create(['name' => 'Dayun Tx 250', 'brand_id' => $scooters->id, 'cash_price' => 108000, 'is_active' => true]);
        app()->forgetInstance(\App\Agent\Runtime\CatalogMentions::class);
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertNotSame('KIND_NOT_ASKED', $this->check('عندك Keeway keet 150 و Dayun Tx 250.', $conversation));
        $this->assertSame('KIND_NOT_ASKED', $this->check('عندك Tx 250 بس.', $conversation));

        // he named the motorcycle himself: talking about it is fine
        $this->says($conversation, 'طب والسوبر لايت Superlight؟');
        $this->assertNotSame('KIND_NOT_ASKED', $this->check('الSuperlight موتوسيكل مش سكوتر.', $conversation));
    }

    // ---- "خليها ٣٠٪ على السنتين" - "ماشي"

    public function test_a_rate_on_the_wrong_duration_is_refused_with_the_real_rates(): void
    {
        $conversation = $this->conversation();
        $aman = InstallmentSystem::create(['name' => 'امان', 'administrative_fees' => 7, 'is_active' => true]);
        $none = InstallmentSystem::create(['name' => 'امان بدون مصاريف', 'administrative_fees' => 0, 'is_active' => true]);

        foreach ([12 => 20, 18 => 30, 24 => 40, 36 => 60] as $months => $rate) {
            InstallmentPlan::create(['installment_system_id' => $aman->id, 'months' => $months, 'interest_percent' => $rate, 'is_active' => true]);
            InstallmentPlan::create(['installment_system_id' => $none->id, 'months' => $months, 'interest_percent' => $rate * 1.5, 'is_active' => true]);
        }

        $guard = app(ReplyGuard::class);
        config(['agent.guard.number_min_value' => 1000]);

        $this->assertSame('PERCENT_DURATION_MISMATCH', $guard->check(['messages' => ['ماشي هي ٣٠٪ ع السنتين']], $conversation, '', [], []));
        $this->assertStringContainsString('not negotiable', (string) $guard->lastDetail());
        $this->assertStringContainsString('24 months = 40%', (string) $guard->lastDetail());
        // the instructions carry the per-duration rates, so the right ones are sourced
        $system = 'النسبة على السنة الواحدة: سنتين = ٤٠٪ مع المصاريف أو ٦٠٪ من غيرها';
        $this->assertNull($guard->check(['messages' => ['على سنتين الفايدة 40% مع المصاريف، أو 60% من غير مصاريف.']], $conversation, $system, [], []));
    }

    // ---- "المكن اللي بيستحمل ومطلوب دايما"

    public function test_a_made_up_pitch_about_toughness_and_demand_is_refused(): void
    {
        $conversation = $this->conversation();

        $this->assertSame('UNSOURCED_SALES_CLAIM', $this->check('للشغل على ديدي المكن اللي بيستحمل ومطلوب دايما هو دايو ٤.', $conversation));
        $this->assertNull($this->check('للشغل على ديدي عندك دايو ٤. تحب أحسبلك القسط؟', $conversation));
    }

    // ---- "موظف في شركة" reached staff with no job

    public function test_an_application_needs_what_he_does_exactly(): void
    {
        $conversation = $this->conversation();
        $classification = app(WorkClassification::class);

        $this->work($conversation, ['customer_type' => 'employee', 'occupation' => 'موظف في شركة', 'job_title' => '']);
        $this->assertSame('ASK_EXACT_JOB', $classification->exactJobMissing($conversation->id)['code'] ?? null);

        $this->work($conversation, ['customer_type' => 'employee', 'occupation' => 'موظف في شركة', 'job_title' => 'موظف في شركة خاصة']);
        $this->assertSame('ASK_EXACT_JOB', $classification->exactJobMissing($conversation->id)['code'] ?? null);

        $this->work($conversation, ['customer_type' => 'self_employed', 'work_type' => 'craftsman', 'occupation' => 'صنايعي', 'job_title' => 'صنايعي']);
        $this->assertSame('ASK_EXACT_JOB', $classification->exactJobMissing($conversation->id)['code'] ?? null);

        // the company's business is not his job
        $this->work($conversation, ['customer_type' => 'employee', 'occupation' => 'شغال في شركة مقاولات', 'job_title' => 'موظف شركة مقاولات', 'workplace_activity' => 'مقاولات']);
        $this->assertSame('ASK_EXACT_JOB', $classification->exactJobMissing($conversation->id)['code'] ?? null);
        $this->work($conversation, ['customer_type' => 'employee', 'occupation' => 'عامل بوفيه', 'job_title' => 'عامل بوفيه', 'workplace_activity' => 'مقاولات']);
        $this->assertNull($classification->exactJobMissing($conversation->id));

        $this->work($conversation, ['customer_type' => 'employee', 'occupation' => 'محاسب', 'job_title' => 'محاسب']);
        $this->assertNull($classification->exactJobMissing($conversation->id));

        // the app is the job; a pensioner has none now
        $this->work($conversation, ['customer_type' => 'self_employed', 'work_type' => 'delivery_app', 'occupation' => 'شغال على ديدي', 'job_title' => '']);
        $this->assertNull($classification->exactJobMissing($conversation->id));
        $this->work($conversation, ['customer_type' => 'pension', 'occupation' => 'على المعاش', 'job_title' => '']);
        $this->assertNull($classification->exactJobMissing($conversation->id));
    }

    // ---- after applying: "اول قسط امتى؟" got "أقدملك تاني؟"

    public function test_a_customer_whose_request_was_sent_is_not_offered_to_apply_again(): void
    {
        $conversation = $this->conversation();
        $type = \App\Models\CustomerType::firstOrCreate(['key' => 'self_employed'], ['label' => 'x']);
        \App\Models\Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'submitted']);
        $this->says($conversation, 'هو اول قسط امتى؟');

        $this->assertSame('REAPPLY_OFFERED_AFTER_SUBMIT', $this->check('تحب أقدملك تاني؟', $conversation));
        $this->assertSame('REAPPLY_OFFERED_AFTER_SUBMIT', $this->check('تمام يا باشا، نبدأ إجراءات الطلب؟', $conversation));
        $this->assertNull($this->check('أول قسط بيبدأ بعد شهر ونص من الاستلام.', $conversation));

        // he asked for a second application himself
        $this->says($conversation, 'عايز اقدم طلب تاني لأخويا');
        $this->assertNotSame('REAPPLY_OFFERED_AFTER_SUBMIT', $this->check('تحب أقدملك على أنهي مكنة؟', $conversation));
    }

    public function test_the_safe_line_for_a_sent_request_never_offers_to_submit_it(): void
    {
        $conversation = $this->conversation();
        $type = \App\Models\CustomerType::firstOrCreate(['key' => 'self_employed'], ['label' => 'x']);
        \App\Models\Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'submitted']);

        $runner = app(\App\Agent\Runtime\AgentRunner::class);
        $lines = (new \ReflectionMethod($runner, 'keepGoingLines'))->invoke($runner, $conversation);

        $this->assertStringContainsString('اتبعت', $lines[0]);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('أقدّم', $line);
        }
    }

    public function test_a_twice_refused_draft_keeps_its_true_sentences(): void
    {
        $conversation = $this->conversation();
        config(['agent.guard.number_min_value' => 1000]);
        $guard = app(ReplyGuard::class);

        $kept = $guard->passingSentences(['messages' => ['تمام يا باشا، الدايو ٤ موجودة عندنا ومتاحة للتقسيط. يمكنك تختار المدة اللي تناسبك.']], $conversation, '', [], []);
        $this->assertSame(['تمام يا باشا، الدايو ٤ موجودة عندنا ومتاحة للتقسيط.'], $kept);

        // nothing worth sending left: the honest fallback stays
        $this->assertNull($guard->passingSentences(['messages' => ['تمام. يمكنك تختار المدة.']], $conversation, '', [], []));
    }

    public function test_the_plain_version_is_the_one_found_for_al_3adeya(): void
    {
        $brand = Brand::create(['name' => 'دايو', 'image' => 'x.png']);
        Machine::create(['name' => 'دايو 2', 'brand_id' => $brand->id, 'cash_price' => 35500, 'is_active' => true]);
        Machine::create(['name' => 'دايو 2 استيراد', 'brand_id' => $brand->id, 'cash_price' => 40000, 'is_active' => true]);
        $catalog = app(\App\Domain\Catalog\CatalogService::class);

        $this->assertSame(['دايو 2'], array_column($catalog->search(['name_query' => 'دايو 2 العادية'])['items'], 'name'));
        $this->assertCount(2, $catalog->search(['name_query' => 'دايو 2'])['items']);
    }

    public function test_papers_are_not_asked_for_before_an_application_is_open(): void
    {
        $conversation = $this->conversation();

        $this->assertSame('DOCUMENTS_ASKED_WITHOUT_APPLICATION', $this->check('تقدر تبعت صورة البطاقة وش وضهر؟', $conversation));
        // telling him what is needed is fine
        $this->assertNull($this->check('التقديم محتاج البطاقة ومفردات المرتب. تحب نبدأ؟', $conversation));
    }

    public function test_a_paper_no_list_holds_is_never_asked_for(): void
    {
        $conversation = $this->conversation();

        $this->assertSame('UNLISTED_DOCUMENT', $this->check('المطلوب البطاقة وبيان المعاش وإيصال مرافق حديث.', $conversation));
        $this->assertNull($this->check('مش محتاج إيصال مرافق ولا أي حاجة زيادة.', $conversation));
    }

    public function test_an_age_he_never_wrote_is_not_used(): void
    {
        $conversation = $this->conversation();
        $ctx = new \App\Agent\Tools\ToolContext($conversation->customer_id, $conversation->id, null, 1, 1, new \App\Agent\Runtime\TurnResultBuilder());
        $this->says($conversation, 'انا على المعاش يا ابني');

        $this->assertSame('AGE_NOT_STATED', app(\App\Agent\Tools\CheckEligibilityTool::class)->execute(['age' => 65], $ctx)->error['code'] ?? null);
    }

    public function test_an_invented_difference_between_two_models_is_refused(): void
    {
        $conversation = $this->conversation();

        $this->assertSame('UNSOURCED_SALES_CLAIM', $this->check('الفرق الأساسي في جودة الخامات والتقفيل، والاستيراد مكونات أعلى في الاعتمادية.', $conversation));
        $this->assertSame('UNSOURCED_SALES_CLAIM', $this->check('الدايو 2 اختيار ممتاز للمشاوير.', $conversation));
    }

    public function test_an_accepted_paper_is_not_asked_for_again(): void
    {
        $conversation = $this->conversation();
        $type = \App\Models\CustomerType::firstOrCreate(['key' => 'self_employed'], ['label' => 'x']);
        $application = \App\Models\Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'collecting']);
        $docType = \App\Models\DocumentType::create(['key' => 'driving_license', 'label' => 'l', 'description_for_ai' => 'x', 'accepted_mimes' => ['image/jpeg'], 'extraction_fields' => [], 'validation_rules' => [], 'is_active' => true]);
        $message = WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image']);
        $media = \App\Models\MessageMedia::create(['message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg', 'disk' => 'local', 'path' => 'l.jpg', 'size' => 1, 'sha256' => 'l']);
        \App\Models\ApplicationDocument::create(['application_id' => $application->id, 'document_type_id' => $docType->id, 'media_id' => $media->id, 'party' => 'applicant', 'status' => 'accepted', 'detected_type_key' => 'driving_license']);

        $this->assertSame('ACCEPTED_DOCUMENT_ASKED_AGAIN', $this->check('محتاجين صورة تانية واضحة لرخصة القيادة.', $conversation));
        $this->assertNotSame('ACCEPTED_DOCUMENT_ASKED_AGAIN', $this->check('رخصة القيادة وصلت واتقبلت.', $conversation));
    }

    public function test_a_claim_that_his_phone_was_saved_needs_the_phone_saved(): void
    {
        $conversation = $this->conversation();
        $read = [['name' => 'process_document', 'ok' => true, 'data' => ['results' => [['accepted' => true]]]]];

        $this->assertSame('DATA_CLAIMED_NOT_SAVED', app(ReplyGuard::class)->check(['messages' => ['تمام يا يوسف، رقم تليفونك اتسجل.']], $conversation, '', [], $read));
    }
}
