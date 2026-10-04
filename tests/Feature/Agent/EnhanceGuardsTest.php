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
        $this->assertNull($this->check('كده بياناتك تمام، فاضل بس حاجة أخيرة: هل قدّمت طلب تقسيط في أي معرض تاني قبل كده؟'));
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
        $contents = [['role' => 'model', 'parts' => [['type' => 'text', 'text' => 'القسط 4,700 جنيه في الشهر']]]];
        $this->message('outgoing', 'القسط 4,700 جنيه في الشهر');
        $this->message('incoming', '01033527924');

        $this->assertNull($this->check('تمام، والقسط زي ما قلتلك 4,700 جنيه في الشهر.', $contents));
    }

    public function test_an_invented_reason_for_admin_fees_is_refused(): void
    {
        $this->assertSame('UNSOURCED_REASON', $this->check('المصاريف الإدارية دي الرسوم اللي جهة التمويل بتفرضها عشان فتح الملف وتسجيل العقد وتقييم الطلب، وتدفعها ضمن أول قسط.'));
        $this->assertNull($this->check('المصاريف الإدارية مبلغ بيتدفع مرة واحدة وقت الاستلام، وقيمتها على حسب المكنة والمدة.'));
    }

    public function test_how_many_versions_needs_a_lookup(): void
    {
        $this->assertSame('VERSIONS_NOT_LOOKED_UP', $this->check('الهوجن ٤ عندنا بنسختين: استيراد عادي وفرز تاني. تحب أنهي؟'));
        $this->assertNull($this->check('الهوجن ٤ عندنا بنسختين: استيراد عادي وفرز تاني. تحب أنهي؟', [], [['name' => 'search_motorcycles', 'ok' => true, 'data' => ['results' => []]]]));
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

    public function test_instructions_are_never_described(): void
    {
        $this->assertSame('INTERNAL_KEY_IN_REPLY', $this->check('التعليمات اللي ماشيين بيها باختصار: مفيش عروض أو خصومات.'));
        $this->assertSame('BANNED_WORDING', $this->check('أهلاً بيك، إحنا بنبيع موتوسيكلات وسكوترات.'));
    }

    public function test_finance_companies_are_listed_only_when_he_asks(): void
    {
        $this->message('incoming', 'بتحسب القسط علي كام');
        $trace = \App\Models\AiTrace::create(['conversation_id' => $this->conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new \App\Agent\Tools\ToolContext(1, $this->conversation->id, null, 1, $trace->id, new \App\Agent\Runtime\TurnResultBuilder());

        $result = app(\App\Agent\Tools\GetInstallmentOptionsTool::class)->execute(['motorcycle_id' => 1], $ctx);

        $this->assertSame('USE_GET_INSTALLMENT_OFFER', $result->error['code']);
    }

    public function test_a_price_line_from_our_last_reply_is_not_sent_again(): void
    {
        $this->message('outgoing', 'دايو 4 على سنتين: القسط 2,625 جنيه في الشهر، ومصاريف إدارية 3,150 جنيه وقت الاستلام.');
        $this->message('incoming', 'عايز اقدم');

        $args = app(ReplyGuard::class)->withoutRepeatedOffer(['messages' => [
            'دايو 4 على سنتين: القسط 2,625 جنيه في الشهر، ومصاريف إدارية 3,150 جنيه وقت الاستلام.',
            'تمام، ابعتلي صورة وش وضهر البطاقة ورخصة القيادة عشان نبدأ.',
        ]], $this->conversation);

        $this->assertSame(['تمام، ابعتلي صورة وش وضهر البطاقة ورخصة القيادة عشان نبدأ.'], $args['messages']);
    }

    public function test_a_price_line_is_kept_when_he_asks_about_the_number_again(): void
    {
        $this->message('outgoing', 'القسط 2,625 جنيه في الشهر.');
        $this->message('incoming', 'يعني القسط كام في الشهر؟');

        $args = app(ReplyGuard::class)->withoutRepeatedOffer(['messages' => ['القسط 2,625 جنيه في الشهر.']], $this->conversation);

        $this->assertSame(['القسط 2,625 جنيه في الشهر.'], $args['messages']);
    }

    public function test_calling_a_customer_my_daughter_is_fixed_in_place(): void
    {
        $args = app(ReplyGuard::class)->tidy(['messages' => ['تمام يا بنتي، دي الصور.']]);

        $this->assertSame(['تمام يا فندم، دي الصور.'], $args['messages']);
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
        $any = app(\App\Agent\Tools\SearchMotorcyclesTool::class)->execute(['kind' => 'motorcycle', 'sort' => 'cheapest', 'brand' => '', 'color' => '', 'limit' => 3, 'cc_max' => 0, 'cc_min' => 0, 'name_query' => '', 'offers_only' => false, 'available_only' => true, 'max_cash_price' => 65000, 'min_cash_price' => 0, 'max_installment_price' => 0], $ctx);
        $this->assertSame(['Scooter X Road i', 'Keeway keet 150', 'هوجن 4'], array_column($any->data['items'], 'name'));

        $scooter = app(\App\Agent\Tools\SearchMotorcyclesTool::class)->execute(['max_cash_price' => 65000, 'kind' => 'scooter'], $ctx);
        $this->assertSame(['Scooter X Road i', 'Keeway keet 150'], array_column($scooter->data['items'], 'name'));
    }

    public function test_a_reworded_offer_with_the_same_prices_is_not_sent_again(): void
    {
        $this->message('outgoing', 'سنتين: مصاريف إدارية 3,570 بتدفعها مرة واحدة وقت الاستلام، والقسط 2,975 جنيه في الشهر.');
        $this->message('incoming', 'عايز اقدم');

        $args = app(ReplyGuard::class)->withoutRepeatedOffer(['messages' => [
            'تمام، فتحت لك الطلب. المصاريف الإدارية 3,570 وتدفعها مرة واحدة، والقسط 2,975 جنيه في الشهر.',
            'ابعتلي صورة وش وضهر البطاقة.',
        ]], $this->conversation);

        $this->assertSame(['تمام، فتحت لك الطلب.', 'ابعتلي صورة وش وضهر البطاقة.'], $args['messages']);
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

    public function test_asking_for_the_insurance_print_is_allowed_and_is_no_waiver(): void
    {
        // it was on the "never required" list before the owner allowed it
        $this->assertNull($this->check('لو الشركة مش بتطلع مفردات، ابعتلي برنت التأمينات بداله وبيتحسب زيه بالظبط.'));
        $this->assertSame('DOCUMENT_NOT_REQUIRED', $this->check('ممكن تبعتلي كشف حساب بنكي بدل المفردات.'));
    }

    public function test_the_card_only_answer_waits_for_his_work(): void
    {
        // simulator 930 on the server
        $this->message('incoming', 'انا جيلكم من فيديو تيك توك كنتم بتقولو فيه تقسيط بالبطاقه بس');
        $this->assertSame('CARD_ONLY_BEFORE_WORK', $this->check('أيوة، التقسيط بالبطاقة موجود، عايز نفتح لك الطلب دلوقتي؟'));

        // the same reply is fine once he said what he works, in this very message
        $this->message('incoming', 'انا شغال على توكتوك');
        $args = ['messages' => ['أيوه، شغلك بيقدّم بالبطاقة بس.'], 'memory' => ['facts' => [['key' => 'job', 'value' => 'سواق توكتوك', 'quote' => 'شغال على توكتوك']]]];
        $this->assertNull(app(ReplyGuard::class)->check($args, $this->conversation, '', [], []));
    }

    public function test_the_card_is_called_the_card(): void
    {
        $this->assertSame(['التقسيط بالبطاقة موجود.'], app(ReplyGuard::class)->tidy(['messages' => ['التقسيط بالكارت موجود.']])['messages']);
    }

    public function test_nudges_have_a_lifetime_cap_per_application(): void
    {
        $this->assertSame(3, ApplicationNudgeService::MAX_PER_APPLICATION);
        $this->assertSame(12, ApplicationNudgeService::MIN_GAP_HOURS);
    }
}
