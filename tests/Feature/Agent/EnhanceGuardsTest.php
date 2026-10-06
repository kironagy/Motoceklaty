<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\ReplyGuard;
use App\Domain\Applications\ApplicationNudgeService;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ENHANCE-Ai: failures found in the server history (conversations 302, 852, 26...). */
class EnhanceGuardsTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.guard.number_min_value' => 1000]);

        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $this->conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
    }

    private function check(string $reply, array $contents = [], array $outcomes = []): ?string
    {
        return app(ReplyGuard::class)->check(['messages' => [$reply]], $this->conversation, '', $contents, $outcomes);
    }

    private function message(string $direction, string $text): void
    {
        WhatsappMessage::create([
            'whatsapp_conversation_id' => $this->conversation->id, 'direction' => $direction,
            'sender_type' => $direction === 'incoming' ? 'customer' : 'bot', 'type' => 'text', 'text' => $text,
        ]);
    }

    public function test_a_question_about_earlier_applications_is_not_a_submission_claim(): void
    {
        // refused 212 times in four days, each one a wasted model call
        $this->assertNull($this->check('كده بياناتك تمام، فاضل بس حاجة أخيرة: قدّمت طلب تقسيط في أي معرض تاني قبل كده؟'));
    }

    public function test_a_real_submission_claim_is_still_caught(): void
    {
        $this->assertSame('SUBMISSION_CLAIMED_NOT_DONE', $this->check('تمام يا باشا، الطلب اتبعت للمراجعة، تحب حاجة تانية؟'));
    }

    public function test_a_save_claim_before_a_question_is_still_caught(): void
    {
        $this->assertSame('DATA_CLAIMED_NOT_SAVED', $this->check('تمام يا باشا، سجلت رقمك، تحب نكمل؟'));
    }

    public function test_promising_to_check_branch_stock_is_refused(): void
    {
        $this->assertSame('STOCK_OR_CHECK_CLAIMED', $this->check('تمام يا غالية، هشوفلك حالا المتاح في الفرعين وأبلغك عشان تروحي تعاينيه.'));
        $this->assertSame('STOCK_OR_CHECK_CLAIMED', $this->check('أنا اتأكدتلك، الموديل متاح حالياً في فرع عين شمس وفرع جسر السويس.'));
        $this->assertNull($this->check('الموديل ده متاح عندنا، والفرع بيأكدلك اللون والقطعة وإنت هناك.'));
    }

    public function test_when_he_brings_a_price_our_old_reply_is_no_source(): void
    {
        // conversation 302: "الكاش بقى ٤١٠٠٠" answered with the stale 39,000 from our own earlier reply
        $contents = [
            ['role' => 'model', 'parts' => [['type' => 'text', 'text' => 'هوجن 4 استيراد فرز تاني: سعر الكاش 39,000 جنيه.']]],
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'الفرز التاني الكاش بتاعها دلوقتي بقي ٤١٠٠٠']]],
        ];
        $this->message('outgoing', 'هوجن 4 استيراد فرز تاني: سعر الكاش 39,000 جنيه.');
        $this->message('incoming', 'الفرز التاني الكاش بتاعها دلوقتي بقي ٤١٠٠٠');

        $this->assertSame('UNVERIFIED_NUMBER', $this->check('السعر اللي عندي 39,000 جنيه كاش.', $contents));

        // with the price looked up this turn it passes
        $outcomes = [['name' => 'get_motorcycle_details', 'ok' => true, 'data' => ['motorcycles' => [['cash_price' => 41000]]]]];
        $this->assertNull($this->check('آخر سعر عندي 41,000 جنيه كاش.', $contents, $outcomes));
    }

    public function test_a_phone_number_is_not_a_price_he_brings(): void
    {
        // his own budget stays a source when the next thing he sends is his phone
        $contents = [['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'ميزانيتي 40,000']]]];
        $this->message('incoming', 'ميزانيتي 40,000');
        $this->message('outgoing', 'تمام، ابعتلي رقمك.');
        $this->message('incoming', '01033527924');

        $this->assertNull($this->check('تمام، على ميزانية 40,000 عندنا اختيارات كتير.', $contents));
    }

    public function test_our_machinery_never_reaches_the_customer(): void
    {
        $this->assertSame('INTERNAL_KEY_IN_REPLY', $this->check('المبلغ ده بيتحسب حسب سعر المكنة وتطلع لك نتيجته مع القسط في الأداة.'));
        $this->assertSame('INTERNAL_KEY_IN_REPLY', $this->check('السيستم مش قادر يقرأ الصورة، ابعتها تاني.'));
        $this->assertSame('INTERNAL_KEY_IN_REPLY', $this->check('تمام، اتغير وضعك وبقيت سواق اوبر في النظام.'));
        $this->assertNull($this->check('على نظام التقسيط ده بتدفع المصاريف وقت الاستلام.'));
    }

    public function test_an_invented_review_time_is_refused(): void
    {
        $this->assertSame('UNRECORDED_PROMISE', $this->check('مدة التقديم بتاعة التقسيط عادةً من يومين لغاية أسبوع لما المستندات تكون كاملة.'));
        $this->assertNull($this->check('مفيش مدة ثابتة للرد، والرد بيوصلك هنا أول ما يطلع.'));
        $this->assertSame('UNRECORDED_PROMISE', $this->check('مش فيه مدة ثابتة للرد، عادةً بين يومين لحد أسبوع حسب اكتمال المستندات.'));
        $this->assertNull($this->check('أول قسط بيبدأ بعد 45 يوم من الاستلام.', [], [['name' => 'get_installment_offer', 'ok' => true, 'data' => ['first_payment' => 'بعد 45 يوم من الاستلام']]]));
    }

    public function test_we_will_check_and_arrange_a_viewing_is_refused(): void
    {
        $this->assertSame('STOCK_OR_CHECK_CLAIMED', $this->check('أكيد، نقدر نتحقق من التوافر في الفرعين ونرتب لك معاينة قبل الكاش.'));
    }

    public function test_a_budget_search_returns_what_is_nearest_the_budget_and_scooters_for_her(): void
    {
        $moto = \App\Models\Brand::create(['name' => 'Hojan', 'image' => 'b.jpg']);
        $scooters = \App\Models\Brand::create(['name' => 'Scooters', 'image' => 's.jpg']);
        foreach ([['هوجن 4', 40000, $moto], ['دايو 4', 39500, $moto], ['دايو 2', 35500, $moto], ['وينج 150', 39000, $moto], ['فيجوري', 39000, $moto], ['Keeway keet 150', 53000, $scooters], ['Scooter X Road i', 59000, $scooters], ['بوكسر 150', 70000, $moto]] as [$name, $price, $brand]) {
            \App\Models\Machine::create(['name' => $name, 'brand_id' => $brand->id, 'cash_price' => $price, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        }
        $this->message('incoming', 'أنا بنت وف حدود 60 او 65 وعايزة حاجة شكلها حلو');
        $trace = \App\Models\AiTrace::create(['conversation_id' => $this->conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new \App\Agent\Tools\ToolContext(1, $this->conversation->id, null, 1, $trace->id, new \App\Agent\Runtime\TurnResultBuilder());

        // exactly what gpt-5-nano sent: every optional filter filled with 0/"" and motorcycles only
        // she named no kind, so the model leaves kind out (the tool trusts the argument)
        $any = app(\App\Agent\Tools\SearchMotorcyclesTool::class)->execute(['sort' => 'cheapest', 'brand' => '', 'color' => '', 'limit' => 3, 'cc_max' => 0, 'cc_min' => 0, 'name_query' => '', 'offers_only' => false, 'available_only' => true, 'max_cash_price' => 65000, 'min_cash_price' => 0, 'max_installment_price' => 0], $ctx);
        $this->assertSame(['Scooter X Road i', 'Keeway keet 150', 'هوجن 4'], array_column($any->data['items'], 'name'));

        $scooter = app(\App\Agent\Tools\SearchMotorcyclesTool::class)->execute(['max_cash_price' => 65000, 'kind' => 'scooter'], $ctx);
        $this->assertSame(['Scooter X Road i', 'Keeway keet 150'], array_column($scooter->data['items'], 'name'));
    }

    public function test_her_budget_in_thousands_is_not_an_invented_number(): void
    {
        $contents = [['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'أنا بنت وف حدود 60 او 65']]]];

        $this->assertNull($this->check('في ميزانية 60–65 ألف عندنا كذا اختيار حلو.', $contents, [['name' => 'search_motorcycles', 'ok' => true, 'data' => []]]));
    }

    public function test_in_stock_at_the_branch_and_ill_check_are_refused(): void
    {
        $this->assertSame('STOCK_OR_CHECK_CLAIMED', $this->check('وكلهم في الفرع متوفرين كاش وتقسيط.'));
        $this->assertSame('STOCK_OR_CHECK_CLAIMED', $this->check('عايز أشيّك لك فرع أقرب لك؟'));
    }

    public function test_an_insurance_print_counts_for_the_salary_slip_and_nothing_less(): void
    {
        $eq = \App\Domain\Documents\DocumentEquivalents::class;

        $this->assertSame(['national_id_front', 'insurance_print', 'salary_slip'], $eq::satisfied(['national_id_front', 'insurance_print']));
        $this->assertSame(['national_id_front'], $eq::satisfied(['national_id_front']));
        $this->assertSame(['salary_slip', 'national_id_back', 'insurance_print'], $eq::acceptable(['salary_slip', 'national_id_back']));
    }

    public function test_nudges_have_a_lifetime_cap_per_application(): void
    {
        $this->assertSame(3, ApplicationNudgeService::MAX_PER_APPLICATION);
        $this->assertSame(12, ApplicationNudgeService::MIN_GAP_HOURS);
    }

    public function test_the_job_on_the_id_decides_the_salary_slip(): void
    {
        $this->assertTrue(\App\Domain\Applications\IdOccupation::isNoJob('بدون عمل'));
        $this->assertTrue(\App\Domain\Applications\IdOccupation::isNoJob('طالب'));
        $this->assertFalse(\App\Domain\Applications\IdOccupation::isNoJob('مهندس بشركة المقاولون العرب'));
        $this->assertFalse(\App\Domain\Applications\IdOccupation::isNoJob(null));
    }

    public function test_a_rate_said_with_the_wrong_duration_is_refused(): void
    {
        // conversation 855, owner: "٣٠ ع السنه ونص ٤٠ سنتين ٦٠ تلت سنين مع ٧٪ مصاريف"
        \App\Models\InstallmentSystem::create(['name' => 'أمان', 'pricing_mode' => 'standard', 'administrative_fees' => 7,
            'plans' => [['months' => 12, 'interest' => 20], ['months' => 18, 'interest' => 30], ['months' => 24, 'interest' => 40], ['months' => 36, 'interest' => 60]]]);
        \App\Models\InstallmentSystem::create(['name' => 'أمان بدون مصاريف', 'pricing_mode' => 'standard', 'administrative_fees' => 0,
            'plans' => [['months' => 12, 'interest' => 30], ['months' => 24, 'interest' => 60]]]);
        $this->assertSame(4, \App\Models\InstallmentPlan::where('installment_system_id', \App\Models\InstallmentSystem::first()->id)->count());

        $this->assertSame('PERCENT_DURATION_MISMATCH', $this->check('تمام، 30% بدون مصاريف على سنتين ممكن. نحسب القسط؟'));
        $this->assertSame('PERCENT_DURATION_MISMATCH', $this->check('الفايدة 20% على سنتين.'));
        $options = [['name' => 'get_installment_options', 'ok' => true, 'data' => ['systems' => [['admin_fee_percent' => 7, 'plans' => [['months' => 12, 'interest_percent' => 20], ['months' => 18, 'interest_percent' => 30], ['months' => 24, 'interest_percent' => 40]]]]]]];
        $this->assertNull($this->check('على سنة 20% مع 7% مصاريف إدارية، وعلى سنتين 40%.', [], $options));
        $this->assertNull($this->check('على سنة ونص الفايدة 30% مع 7% مصاريف إدارية.', [], $options));
    }

}
