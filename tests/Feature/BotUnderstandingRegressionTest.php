<?php

namespace Tests\Feature;

use App\Services\ApplicationStateService;
use App\Services\MachineSearchService;
use App\Support\AddressParser;
use Tests\TestCase;

/**
 * اختبارات تراجع للسبع مشاكل اللي اتصلحت في AI_BOT_ISSUES_FIX_PLAN.md.
 * كل test هنا بيمثّل رسالة حقيقية من محادثة حقيقية كسرت البوت.
 *
 * ملحوظة عن الاتصال بالداتابيز:
 * phpunit.xml بيحول الاتصال الافتراضي لـ sqlite في الذاكرة عشان باقي
 * السويت يشتغل بمعزل. لكن مايجريشن قديم
 * (2025_11_16_212617_update_work_status_enum_in_installment_requests.php)
 * بيستخدم "ALTER TABLE ... MODIFY" وهو syntax خاص بـ MySQL بس، فأي test
 * بيستخدم RefreshDatabase في المشروع كله بيفشل بغض النظر عن أي تعديل هنا -
 * ده عطل موجود قبل التعديلات دي وخارج نطاق الـ tasks. الاختبارات هنا بتقرا
 * بس (search/parse/bind - بدون أي كتابة أو migration)، فبنحوّل الاتصال
 * الافتراضي لقاعدة البيانات الحقيقية (نفس اللي في .env) عشان تشتغل على
 * كتالوج حقيقي من غير ما تحتاج أي migration جديدة.
 */
class BotUnderstandingRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'motoceklaty',
        ]);
    }

    /**
     * محادثة حقيقية (02/09): البوت عرض "دايو 2" و"دايو 2 استيراد"، والعميل
     * سأل بعدها "سعر دايونج كام" فرجعله نفس موديلات دايو 2 تاني - و"دايونج"
     * (موديل حقيقي id=56 سعره 45,000) مرجعش خالص.
     *
     * السبب إن الـ planner قرا "دايونج" كتضييق على قايمة دايو المعروضة.
     * الحارس الحتمي في الراوتر (machinesNamedOutsideLastSet) بيقارن نتيجة
     * البحث في نص الرسالة بمجموعة الموديلات القديمة، فالشرط اللي بيعتمد
     * عليه هو ده بالظبط: "دايونج" لازم تطلّع موديل مالوش أي تقاطع مع
     * عيلة دايو 2.
     */
    public function test_dayung_does_not_overlap_the_dayo_2_family(): void
    {
        $search = app(MachineSearchService::class);

        $dayungIds = $search->search('سعر دايونج كام', 20)->pluck('id')->all();
        $dayo2Ids = $search->search('سعر دايو 2 كام', 20)->pluck('id')->all();

        $this->assertNotEmpty($dayungIds, '"دايونج" لازم ترجّع موديل');
        $this->assertNotEmpty($dayo2Ids, '"دايو 2" لازم ترجّع موديلات');
        $this->assertEmpty(
            array_intersect($dayungIds, $dayo2Ids),
            '"دايونج" مالهاش أي تقاطع مع عيلة دايو 2 - لو حصل تقاطع الحارس هيفتكرها تضييق'
        );
    }

    /**
     * الوجه التاني للحارس: التضييق الحقيقي لازم يفضل شغال. "دايو 2
     * استيراد" بعد عرض العيلة لازم يفضل جوه نفس المجموعة، وإلا الحارس
     * هيعتبره موضوع جديد ويكسر متابعة طبيعية.
     */
    public function test_a_real_narrowing_still_overlaps_the_shown_set(): void
    {
        $search = app(MachineSearchService::class);

        $shown = $search->search('سعر دايو 2 كام', 20)->pluck('id')->all();
        $narrowed = $search->search('دايو 2 استيراد', 20)->pluck('id')->all();

        $this->assertNotEmpty(array_intersect($shown, $narrowed));
    }

    /**
     * العميل كتب "بوكسر ١٥٠" - اسم مكنة حرفي في الداتابيز - والبحث كان
     * بيرجّع ٣ نتايج (بوكسر ١٥٠، بلسر ١٥٠، وينج ١٥٠) لأن تجميع العيلة
     * بيمسك الرقم ١٥٠. النتيجة إن البوت كان بيسأله "تحب تقدم على أنهي
     * موديل؟" وهو مسمّي الموديل بالحرف، وبعدين يلف في نفس السؤال تلات
     * مرات لحد ما يحوّله لموظف.
     */
    public function test_an_exact_model_name_returns_only_that_model(): void
    {
        $names = app(MachineSearchService::class)->search('بوكسر ١٥٠', 20)->pluck('name')->all();

        $this->assertSame(['بوكسر ١٥٠'], $names);
    }

    /**
     * الحارس على الإصلاح اللي فوق: التضييق ده ممنوع يكسر تجميع
     * الفاريانتس الحقيقية. "سعر دايو ٤" لازم يفضل يعرض الموديل
     * والنسخة الأصلي مع بعض.
     */
    public function test_an_exact_name_still_returns_its_own_variants(): void
    {
        $names = app(MachineSearchService::class)->search('دايو ٤', 20)->pluck('name')->all();

        $this->assertContains('دايو ٤', $names);
        $this->assertContains('دايو ٤ اصلي', $names);
    }

    /** مشكلة 1: "دايونج" كانت بترجع صفر نتايج فالـ LLM يخمّن "دايو". */
    public function test_dayung_resolves_to_its_own_model(): void
    {
        $names = app(MachineSearchService::class)->search('دايونج', 20)->pluck('name')->all();

        // الموديل اتسمى "دايونج" في الكتالوج (كان "Dayung" قبل كده،
        // والاسم القديم لسه بيوصّل ليه عن طريق aliases).
        $this->assertContains('دايونج', $names);
        $this->assertNotContains('دايو ٤', $names);
    }

    /** مشكلة 1 (تراجع): الموديلات اللي كانت شغالة لازم تفضل شغالة. */
    public function test_existing_model_matching_still_works(): void
    {
        $search = app(MachineSearchService::class);

        $this->assertContains('دايو ٤', $search->search('دايو ٤', 20)->pluck('name')->all());
        $this->assertNotEmpty($search->search('بينيلي', 20));
        $this->assertNotEmpty($search->search('هوجن ٤', 20));
        $this->assertNotEmpty($search->search('تي اكس', 20));
    }

    /** مشكلة 7: بينيلي براند حقيقي عندنا فيه موديلات. */
    public function test_benelli_brand_is_findable(): void
    {
        $names = app(MachineSearchService::class)->search('بينيلي', 20)->pluck('name')->all();

        $this->assertContains('VLR 150', $names);
    }

    /** مشكلة 4/6: "قدام" أشهر كلمة علامة مميزة وكانت ناقصة من القاموس. */
    public function test_landmark_keyword_qoddam_is_recognised(): void
    {
        $parsed = app(AddressParser::class)->parse('والله ساكن قدام سوبر ماركت الاخوه');

        $this->assertNotNull($parsed['landmark']);
        $this->assertStringContainsString('سوبر ماركت', $parsed['landmark']);
    }

    /** مشكلة 5: "١٢ ش فلان" رقم العمارة كان بيتحسب "منطقة". */
    public function test_leading_number_before_street_is_the_building(): void
    {
        $parsed = app(AddressParser::class)
            ->parse('١٢ ش محمد ابو النجا من العشرين عين شمس القاهره قدام شرموط الميكانيكيه');

        $this->assertSame('12', $parsed['building']);
        $this->assertNotSame('١٢', $parsed['area']);
        $this->assertNotNull($parsed['landmark']);
        $this->assertSame('القاهرة', $parsed['governorate']);
    }

    /** مشكلة 4/5/6: رد مجرد ما ينفعش يبقى "شارع" ويمسح الشارع الصح. */
    public function test_bare_answer_does_not_become_a_street(): void
    {
        $this->assertNull(app(AddressParser::class)->parse('سوبر ماركت الاخوه')['street']);
    }

    /** مشكلة 4/6: رد مجرد لازم يترمي على المكوّن اللي سألنا عنه. */
    public function test_bare_answer_binds_to_the_asked_component(): void
    {
        $result = app(ApplicationStateService::class)->bindAnswerToAskedComponent(
            ['home_address_components' => ['area' => 'عين شمس']],
            'home_address',
            'landmark',
            'والله ساكن قدام سوبر ماركت الاخوه'
        );

        $this->assertNotNull($result['home_address_components']['landmark']);
    }

    /** مشكلة 6: "السكن تمليك" لازم تتحسب إجابة على سؤال الملكية. */
    public function test_ownership_answer_is_bound(): void
    {
        $result = app(ApplicationStateService::class)->bindAnswerToAskedComponent(
            ['home_address_components' => []],
            'home_address',
            'ownership',
            'السكن تمليك'
        );

        $this->assertSame('ملك', $result['home_address_components']['ownership']);
    }

    /** مشكلة 4: ممنوع "استلمت منك كذا" في سؤال الناقص. */
    public function test_question_does_not_list_what_was_received(): void
    {
        $component = null;
        $field = null;

        $question = app(ApplicationStateService::class)->questionForMissing(
            ['home_address'],
            [
                'home_address' => 'x',
                'home_address_missing_components' => ['landmark'],
                'home_address_newly_received_components' => ['building', 'floor'],
            ],
            [],
            0,
            [],
            false,
            $component,
            $field
        );

        $this->assertStringNotContainsString('استلمت منك', $question);
        $this->assertStringContainsString('علامة مميزة', $question);
        $this->assertSame('landmark', $component);
    }

    /** مشكلة 3: "شغال طلبات على العجلة" لازم تتفهم من أول رسالة. */
    public function test_delivery_on_bicycle_is_recognised_from_first_message(): void
    {
        $handler = app(\App\Services\Handlers\ApplicationHandler::class);

        $this->assertSame('bicycle', $handler->normalizeVehicle('انا شغال طلبات على العجله'));
        $this->assertSame('bicycle', $handler->normalizeVehicle('شغال طلبات بالعجله'));
        $this->assertSame('car', $handler->normalizeVehicle('شغال اوبر'));
    }

    /**
     * جولة تانية من البلاغات (محادثة 31/08 المسائية).
     *
     * "دايونج" فيها "دايو" كـ substring، وextractRequestedBrand كانت
     * بتستخدم str_contains من غير حدود كلمات - فطلب موديل بالاسم كان
     * بيتقري كأنه طلب البراند كله والعميل ياخد قايمة أسعار دايو كلها.
     */
    public function test_dayung_is_not_read_as_the_dayo_brand(): void
    {
        $router = app(\App\Services\WhatsappIntentRouter::class);
        $method = new \ReflectionMethod($router, 'extractRequestedBrand');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($router, 'دايونج'));
        $this->assertNull($method->invoke($router, 'انا عاوز دايونج'));

        // ذكر البراند الحقيقي لازم يفضل شغال زي ما هو.
        $this->assertSame('دايو', $method->invoke($router, 'عاوز دايو')['name'] ?? null);
        $this->assertSame('بينيلي', $method->invoke($router, 'عندكم بينيلي')['name'] ?? null);
    }

    /** كل صيغ كتابة دايونج لازم توصّل لنفس الموديل. */
    public function test_every_dayung_spelling_resolves_to_one_machine(): void
    {
        $search = app(MachineSearchService::class);

        foreach (['دايونج', 'دايونغ', 'Dayung', 'dayung', 'عاوز دايونج'] as $spelling) {
            $names = $search->search($spelling, 20)->pluck('name')->all();

            $this->assertSame(['دايونج'], $names, "فشل في: {$spelling}");
        }
    }

    /**
     * سؤال سعر صريح لازم يتصنّف price حتى لو الـ planner قال general -
     * الحارس الحتمي بعدها بيمنع الـ LLM إنه يخترع رقم (رد "دايونج سعرها
     * 65,000" وده سعر H250 أصلاً).
     */
    public function test_price_questions_are_detected_deterministically(): void
    {
        $router = app(\App\Services\WhatsappIntentRouter::class);
        $method = new \ReflectionMethod($router, 'detectIntent');
        $method->setAccessible(true);

        foreach (['سعرها كام', 'سعرها كام كاش ؟', 'بكام', 'دي بكام'] as $question) {
            $this->assertSame('price', $method->invoke($router, $question), "فشل في: {$question}");
        }
    }

    /** مشكلة 2: اللي على عجلة ممنوع يتطلب منه رخصة في رسالة المتطلبات. */
    public function test_bicycle_courier_is_never_asked_for_a_licence(): void
    {
        $handler = app(\App\Services\Handlers\ApplicationHandler::class);
        $method = new \ReflectionMethod($handler, 'categoryRequirementsNote');
        $method->setAccessible(true);

        $bicycleNote = (string) $method->invoke($handler, 'delivery', 'bicycle');

        $this->assertNotSame('', $bicycleNote);

        /*
         * كل صيغ رد العجلة بتذكر كلمة "رخصة" - بس دايمًا منفيّة ("مش
         * هنطلب منك رخصة"، "مالوش رخصة"، "مفيش رخصة مطلوبة")، فمينفعش
         * نتأكد بغياب الكلمة. اللي يهم إنها متتطلبش منه، وإن السكرين
         * هو المطلوب فعلاً.
         */
        $negations = ['مش هنطلب', 'مالوش رخصة', 'مفيش رخصة'];
        $negated = false;

        foreach ($negations as $negation) {
            if (str_contains($bicycleNote, $negation)) {
                $negated = true;
                break;
            }
        }

        $this->assertTrue($negated, "رد العجلة لازم ينفي الرخصة صراحة، الرد كان: {$bicycleNote}");
        $this->assertStringContainsString('سكرين', $bicycleNote);
        $this->assertStringNotContainsString('رخصة سارية', $bicycleNote);

        // اللي على موتوسيكل لسه لازم رخصة - مش المفروض نلغيها للكل.
        $motorcycleNote = (string) $method->invoke($handler, 'delivery', 'motorcycle');
        $this->assertStringContainsString('رخصة', $motorcycleNote);
    }

    /** ميموري "ديلفري عجله" لازم تكون موجودة وفعّالة عشان الفرع اللي فوق يشتغل. */
    public function test_bicycle_delivery_memory_exists(): void
    {
        $memory = \Illuminate\Support\Facades\DB::table('ai_memories')
            ->where('title', 'ديلفري عجله')
            ->first();

        $this->assertNotNull($memory);
        $this->assertSame(1, (int) $memory->is_active);
        $this->assertStringContainsString('سكرين', $memory->content);
    }

    /**
     * مشكلة 3: تصحيح الرقم القومي وسط مرحلة المستندات كان بيتضاع تمامًا،
     * فـ expected_national_id يفضل الرقم الغلط وكل رفع للبطاقة يترفض
     * بنفس الرسالة للأبد.
     */
    public function test_corrected_national_id_is_applied_during_document_stage(): void
    {
        $conversation = \App\Models\WhatsappConversation::query()->first();

        if (! $conversation) {
            $this->markTestSkipped('مفيش محادثة في الداتابيز للاختبار.');
        }

        $handler = app(\App\Services\Handlers\ApplicationHandler::class);
        $method = new \ReflectionMethod($handler, 'correctedIdentityFromText');
        $method->setAccessible(true);

        $payload = [
            'application' => [
                'national_id' => '30511150101911',
                'phone' => '01000000000',
                'national_id_issue' => 'مش مطابق للبطاقة',
            ],
            'documents_required' => ['id_card_front'],
            'documents_index' => 0,
        ];

        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            $reply = $method->invoke(
                $handler,
                $conversation,
                $payload,
                'يبقي انا كتبته غلط معلش الرقم القومي 30511150101971'
            );

            $this->assertNotNull($reply);

            $saved = $conversation->fresh()->context_payload['application'] ?? [];

            $this->assertSame('30511150101971', $saved['national_id'] ?? null);
            $this->assertArrayNotHasKey('national_id_issue', $saved);

            // نص عادي من غير أرقام مش تصحيح.
            $this->assertNull($method->invoke($handler, $conversation, $payload, 'ابعتلي البطاقة'));

            // نفس الرقم المحفوظ مش تصحيح - ما يعملش دورة جديدة.
            $this->assertNull($method->invoke($handler, $conversation, $payload, '30511150101911'));

            // رقم 14 خانة بس مش بيفك لتاريخ ميلاد صحيح - ممنوع يتكتب.
            $this->assertNull($method->invoke($handler, $conversation, $payload, 'رقمي 99999999999999'));
        } finally {
            \Illuminate\Support\Facades\DB::rollBack();
        }
    }

    /** رقمين في رسالة واحدة ممنوع يتلزقوا في رقم واحد معطوب. */
    public function test_two_numbers_in_one_message_are_read_separately(): void
    {
        $conversation = \App\Models\WhatsappConversation::query()->first();

        if (! $conversation) {
            $this->markTestSkipped('مفيش محادثة في الداتابيز للاختبار.');
        }

        $handler = app(\App\Services\Handlers\ApplicationHandler::class);
        $method = new \ReflectionMethod($handler, 'correctedIdentityFromText');
        $method->setAccessible(true);

        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            $method->invoke(
                $handler,
                $conversation,
                ['application' => ['national_id' => 'x', 'phone' => 'y'], 'documents_required' => ['id_card_front'], 'documents_index' => 0],
                'الرقم القومي 30511150101971 والموبايل 01234567891'
            );

            $saved = $conversation->fresh()->context_payload['application'] ?? [];

            $this->assertSame('30511150101971', $saved['national_id'] ?? null);
            $this->assertSame('01234567891', $saved['phone'] ?? null);
        } finally {
            \Illuminate\Support\Facades\DB::rollBack();
        }
    }
}
