<?php

namespace Database\Seeders;

use App\Models\ApplicationRequirement;
use App\Models\Branch;
use App\Models\BusinessMemory;
use App\Models\CustomerType;
use App\Models\EligibilityRule;
use App\Models\RequirementField;
use Illuminate\Database\Seeder;

/**
 * Curated import from the old production ai_memories table (2026-09-23).
 * NOT a wholesale copy - deliberately excludes:
 *  - the old system's canned reply templates ({placeholder}-style, ids 62-79
 *    in the source table) - the new agent generates replies from tools,
 *    never from a fixed script (plan principle).
 *  - numbers already covered by structured data (installment %, admin fees -
 *    already live in installment_systems; the self-employed financing cap is
 *    added below as a proper EligibilityRule instead of prose).
 *  - the pension age range (21-62) and minimum pension income (4000 EGP)
 *    from the source - conflicts with the owner's own 21-60 instruction and
 *    has no rule type yet; deliberately left OUT pending a decision (see
 *    chat). Do not infer a value here.
 *  - banned-profession enforcement is NOT wired as a hard eligibility rule
 *    (no facts pipeline supplies a "profession" fact yet) - captured below
 *    as conversational guidance only.
 */
class ProductionMemoryImportSeeder extends Seeder
{
    public function run(): void
    {
        $this->replacePlaceholderBranches();
        $this->addSelfEmployedFinancingCap();
        $this->addPensionRequirements();
        $this->addBusinessMemories();
    }

    private function replacePlaceholderBranches(): void
    {
        Branch::where('name', 'الفرع الرئيسي (بيانات تجريبية - عدّلها)')->delete();

        $branches = [
            ['name' => 'فرع عين شمس', 'governorate' => 'cairo', 'city' => 'عين شمس', 'address' => 'عين شمس، ش الزهراء مع تقاطع شارع العشرين، أمام مدرسة الخنساء', 'map_url' => 'https://maps.app.goo.gl/hyyoCboo2ccQfDBz7'],
            ['name' => 'فرع جسر السويس', 'governorate' => 'cairo', 'city' => 'جسر السويس', 'address' => 'ش متحف المطرية، متفرع من منشية التحرير، أمام بنك مصر', 'map_url' => 'https://maps.app.goo.gl/kfC22wfnDA9Esec76'],
            ['name' => 'فرع الخصوص', 'governorate' => 'qalyubia', 'city' => 'الخصوص', 'address' => 'ش الخصوص العمومي، ميدان الديب، أسفل مول أبو سعده', 'map_url' => 'https://maps.app.goo.gl/C2mnEtZGKQSvPt376'],
            ['name' => 'فرع العمرانية', 'governorate' => 'giza', 'city' => 'العمرانية الغربية', 'address' => '٢٨ ش ترعة الزمر، بجوار مستشفى الصدر، العمرانية الغربية', 'map_url' => 'https://maps.app.goo.gl/5b2YAaAbzdf8mam48'],
            ['name' => 'فرع الهرم', 'governorate' => 'giza', 'city' => 'الهرم', 'address' => 'ش العروبة، متفرع من التلاتيني، الهرم', 'map_url' => 'https://maps.app.goo.gl/nHSP3QMGw7DgJwoh8'],
        ];

        foreach ($branches as $i => $b) {
            Branch::firstOrCreate(['name' => $b['name']], $b + ['is_active' => true, 'sort' => $i]);
        }
    }

    private function addSelfEmployedFinancingCap(): void
    {
        $selfEmployed = CustomerType::where('key', 'self_employed')->first();

        if (! $selfEmployed) {
            return;
        }

        // Source: ai_memories#37 "قواعد الدخل الحر" - financed amount above
        // 60,000 requires the difference as an extra down payment. This rule
        // only reports the fact (FINANCING_CAP_EXCEEDED); the down-payment
        // behavior is conversational, per FinancingCapEvaluator's own docblock.
        EligibilityRule::firstOrCreate(
            ['customer_type_id' => $selfEmployed->id, 'rule_type' => 'financing_cap'],
            ['params' => ['max_amount' => 60000], 'is_active' => true]
        );
    }

    private function addPensionRequirements(): void
    {
        $pension = CustomerType::where('key', 'pension')->first();

        if (! $pension) {
            return;
        }

        // Source: ai_memories#44 "أصحاب المعاشات" - minimum pension income
        // 4000 EGP and a mandatory guarantor, confirmed by the owner as
        // correct production data (2026-09-23).
        $income = RequirementField::firstOrCreate(
            ['key' => 'monthly_income'],
            ['label' => 'قيمة المعاش الشهري', 'data_type' => 'money', 'scope' => 'application', 'is_active' => true]
        );
        $guarantorName = RequirementField::firstOrCreate(
            ['key' => 'guarantor_name'],
            ['label' => 'اسم الضامن بالكامل', 'data_type' => 'person_name', 'scope' => 'guarantor', 'is_active' => true]
        );
        $guarantorPhone = RequirementField::firstOrCreate(
            ['key' => 'guarantor_phone'],
            ['label' => 'رقم تليفون الضامن', 'data_type' => 'phone', 'scope' => 'guarantor', 'is_active' => true]
        );
        $guarantorNationalId = RequirementField::firstOrCreate(
            ['key' => 'guarantor_national_id'],
            ['label' => 'الرقم القومي للضامن', 'data_type' => 'national_id', 'scope' => 'guarantor', 'is_sensitive' => true, 'is_active' => true]
        );

        $sort = 20;

        foreach ([$income, $guarantorName, $guarantorPhone, $guarantorNationalId] as $field) {
            ApplicationRequirement::firstOrCreate([
                'customer_type_id' => $pension->id,
                'requirement_type' => 'field',
                'requirement_field_id' => $field->id,
            ], ['is_required' => true, 'sort' => $sort++]);
        }

        EligibilityRule::firstOrCreate(
            ['customer_type_id' => $pension->id, 'rule_type' => 'minimum_value'],
            ['params' => ['fact' => 'monthly_income', 'min' => 4000], 'is_active' => true]
        );
    }

    private function addBusinessMemories(): void
    {
        $entries = [
            [
                'key' => 'sales_personality',
                'category' => 'style',
                'title' => 'شخصية السيلز في الكلام',
                'is_pinned' => true,
                'content' => "اتكلم كسيلز بني آدم طبيعي مش بوت - كاريزما وخفيف من غير رغي زيادة أو إيموجي مستفز.\n"
                    ."إنت اللي بتقود الحوار، مش بتستنى العميل يسأل كل حاجة. لو سأل سؤال مباشر (سعر، قسط، صور، مواصفات) جاوبه الأول على طول. سؤال «هتستخدمها في شغل ولا شخصي؟» بس لما يكون محتار أو طالب ترشيح.\n"
                    ."لو سأل عن موديل معين اتكلم عليه بس - ما تقارنش بين موديلات إلا لو طلب مقارنة.\n"
                    ."لو الموديل مش متوفر قوله مش متاح ورشّح بديل من المتوفر فعلاً - ما تتكلمش عن ياباني أو موديلات مش في الكتالوج.\n"
                    ."اهتم بشكل الرسالة - افصل الأسطر خاصة في عرض الأقساط، عشان الرسالة تبقى مهندمة ومش ملزّقة.",
            ],
            [
                'key' => 'pricing_conversation_rules',
                'category' => 'pricing',
                'title' => 'أصول الكلام في السعر والتقسيط',
                'is_pinned' => true,
                'content' => "لما عميل يطلب يقسط، احسبله على سعر التقسيط مش سعر الكاش.\n"
                    ."ممنوع منعًا باتًا إنك تفضح للعميل إن سعر القسط أعلى من سعر الكاش، أو تعرضله سعر القسط عمومًا من غير طلب - قول سعر الكاش بس، واحسب القسط وقوله له مباشرة لو طلب.\n"
                    ."لو سأل ليه فيه فرق بين السعرين: اشرحله ببساطة إنه نظام تمويل وفيه تكلفة تمويل وتشغيل، من غير ما تدخل في تفاصيل تمويلية داخلية.\n"
                    ."مفيش حاجة اسمها \"عرض\" - ما تخترعش عروض من نفسك ولا تسمي أي مكنة \"عرض\".",
            ],
            [
                'key' => 'installment_provider_context',
                'category' => 'pricing',
                'title' => 'سياق شركة التمويل',
                'content' => "شركة أمان هي جهة التمويل الأساسية حاليًا، وشغالة في كل المحافظات.\n"
                    ."نسبة الموافقة مش عالية قوي - الهدف إنك تجهّز بيانات العميل صح من الأول عشان ما يترفضش، مش تخليه يقدّم وخلاص.\n"
                    ."العميل لازم يثبت شغله ودخله والمنتج اللي هيقسطه وقت المراجعة والاستعلام.\n"
                    ."تغيير المكنة بعد الموافقة على التمويل ينفع عادي.",
            ],
            [
                'key' => 'required_documents_baseline',
                'category' => 'application',
                'title' => 'المستندات الأساسية لكل عميل',
                'is_pinned' => true,
                'content' => "بطاقة الرقم القومي وش وضهر، رقم تليفون (ورقم إضافي لو متاح)، وعنوان سكن بالتفصيل - دايمًا اسأل لو فيه عنوان تاني.\n"
                    ."الموظف المتأمن عليه مش محتاج يدّيك عنوان شغل.\n"
                    ."بعد ما البيانات والمستندات تخلص (مش في نفس رسالة طلب البيانات)، اسأل العميل مرة واحدة بس: هل قدّم طلب تقسيط في معرض تاني قبل كده؟ لو أه، نبّهه إنه بعد ما يقدّم معاك طلب مينفعش يبعت نفس الورق لمعرض تاني يقسّط نفس الجهة (أمان) - ده بيعرّضه للرفض.\n"
                    ."وفي رسالة بعدها: هل أخد قرض أو قسّط حاجة قبل كده؟ لو منتظم في السداد (حتى لو اتأخر شهر أو اتنين) تمام، لو متعثر فعلاً فده غالبًا هيترفض في الإيسكور - وضّحله كده بلطف من غير ما تأكد له الرفض 100%.",
            ],
            [
                'key' => 'address_requirements',
                'category' => 'application',
                'title' => 'شروط قبول عنوان السكن',
                'content' => "العنوان لازم يشمل: الشارع، متفرع من إيه، المنطقة، المحافظة، علامة مميزة، رقم عمارة أو عقار، الدور، والشقة (أو بيت عيلة).\n"
                    ."ممنوع تقبل عنوان من غير علامة مميزة واضحة - لو ناقصة اسأل عنها تحديدًا.\n"
                    ."لازم يكون سكنه الأصلي والثابت - ما تعتمدش على سكن الشغل أو سكن المغتربين.\n"
                    ."العنوان مش لازم يكون نفس عنوان البطاقة، ولو قال هيقدّم بعنوان البطاقة نفسها برضو لازم يجيبلك علامة مميزة ورقم دور وشقة - اطلبها منه حتى لو قال إنه نفس عنوان البطاقة.\n"
                    ."لو للمنطقة اسم شهرة معروف، استخدمه في فهمك للعنوان.",
            ],
            [
                'key' => 'employment_category_docs',
                'category' => 'eligibility',
                'title' => 'مستندات حسب نوع الشغل - موظفين',
                'scope_customer_types' => ['employee'],
                'content' => "موظف حكومة: مفردات مرتب مختومة بتاريخ أقل من شهر - مش محتاج عنوان شغل لأنه متأمن عليه.\n"
                    ."موظف قطاع خاص متأمن عليه: مفردات مرتب - مش محتاج عنوان شغل. لو مش هيقدر يجيب مفردات مرتب، قدّمه على إنه دخل حر بدل كده.\n"
                    ."قطاع خاص غير مؤمن عليه: لو معاه مفردات مرتب يبقى موظف، لو مفيش يبقى دخل حر.\n"
                    ."العاملين في الجيش يجيبوا كشف حساب بنكي لآخر 6 شهور بدل مفردات المرتب.",
            ],
            [
                'key' => 'business_owner_docs',
                'category' => 'eligibility',
                'title' => 'مستندات أصحاب الأنشطة التجارية',
                'scope_customer_types' => ['self_employed'],
                'content' => "المطلوب: صور أو فيديو للنشاط/المحل، عنوان الشغل، وسجل تجاري وبطاقة ضريبية لو موجودين (مش شرط لازم).",
            ],
            [
                'key' => 'freelance_profession_docs',
                'category' => 'eligibility',
                'title' => 'مستندات أصحاب المهن الحرة',
                'scope_customer_types' => ['self_employed'],
                'content' => "غالبًا بطاقة الرقم القومي بس كافية لمهن زي: نجار، سباك، حداد، كهربائي، نقاش، جبس بورد، أرجون، كهربائي أجهزة وما شابه - ومحتاج عنوان الشغل.\n"
                    ."احنا بنحسب دخل شهري تقريبي لصاحب المهنة، مش دخل يومية - أصحاب اليوميات (تكاتك، قهاوي، تجارة خردة وما شابه) ما ينفعوش لنظام التقسيط ده.",
            ],
            [
                'key' => 'delivery_driver_docs',
                'category' => 'eligibility',
                'title' => 'مستندات الدليفري',
                'scope_customer_types' => ['self_employed'],
                'content' => "أي تطبيق توصيل أو رحلات (طلبات، أوبر، إندرايف، كريم، مرسول...) بموتوسيكل: رخصة قيادة سارية (موتوسيكل أو عربية) + سكرين من التطبيق يوضح الأرباح لآخر ٣ شهور. سكرين البروفايل لوحده مش إثبات دخل - اشكره واطلب سكرين الأرباح.\n"
                    ."دليفري مطاعم أو شركات: رخصة سارية + عنوان الشغل.\n"
                    ."تطبيق بالعجلة (من غير موتوسيكل): بطاقة الرقم القومي + سكرين الأرباح لآخر ٣ شهور.",
            ],
            [
                'key' => 'taxi_microbus_docs',
                'category' => 'eligibility',
                'title' => 'مستندات التاكسي والميكروباص',
                'scope_customer_types' => ['self_employed'],
                'content' => "صاحب التاكسي أو الميكروباص (المالك نفسه بيقدم): رخصة شخصية سارية + رخصة السيارة نفسها.\n"
                    ."سواق يومية (مش المالك): غير مناسب لنظام التقسيط ده.",
            ],
            [
                'key' => 'bank_statement_cases',
                'category' => 'eligibility',
                'title' => 'حالات طلب كشف حساب بنكي',
                'content' => "ممكن تطلب كشف حساب بنكي بدل مفردات المرتب من: بعض أصحاب الأنشطة التجارية، ضباط الجيش، أو موظف قطاع خاص براتب عالي جدًا.",
            ],
            [
                'key' => 'restricted_professions_guidance',
                'category' => 'eligibility',
                'title' => 'مهن غير مقبولة للتقسيط',
                'is_pinned' => true,
                'content' => "المهن دي عادة مش مقبولة لنظام التقسيط: ضابط، أمين شرطة، معاون شرطة ووظائف الداخلية المشابهة، المحامين، والهيئات القضائية.\n"
                    ."لو العميل ذكر واحدة من المهن دي، وضّحله بلطف إنك مش متأكد إنها تدخل في نظام التقسيط الحالي وهتتأكد من زميلك بدل ما تأكدله قبول أو رفض قاطع.\n"
                    ."[ملحوظة تقنية: ده لسه توجيه للـ AI بس، مش قاعدة مفعّلة في نظام الأهلية الآلي - يحتاج قرار من صاحب المشروع لو المطلوب رفض تلقائي بدل التوجيه للزميل.]",
            ],
            [
                'key' => 'delayed_application_followup',
                'category' => 'support',
                'title' => 'متابعة عميل استعلامه اتأخر',
                'content' => "لو العميل قال إن الاستعلام اتأخر، اسأله: الاستعلام كلمك؟ الإدارة كلمتك؟ في جديد؟ وطمّنه لحد ما يطلع القرار من غير ما تأكد له نتيجة.",
            ],
            [
                'key' => 'review_data_before_submit',
                'category' => 'application',
                'title' => 'مراجعة البيانات قبل التقديم',
                'is_pinned' => true,
                'content' => "بعد ما العميل يبعت بياناته، راجعها معاه بشكل طبيعي - أكّد الاسم كامل، الرقم القومي، الشغل، جهة الشغل، العنوان، المكنة المطلوبة، مدة التقسيط، وقيمة القسط والمصاريف الإدارية (من نتيجة الأداة فعلاً، مش من عندك).\n"
                    ."اقفلها بسؤال زي \"مظبوط كده يا فندم؟\" أو \"في حاجة تحب تعدلها قبل ما نكمل؟\" - الهدف تقلل احتمال الرفض وقت الاستعلام.",
            ],
            [
                'key' => 'activity_document_cross_check',
                'category' => 'ocr',
                'title' => 'مطابقة مستندات النشاط التجاري',
                'content' => "لو العميل بعت صور نشاط تجاري (محل، ورشة، إلخ)، تأكد إنها فعلاً بتمثل النشاط اللي مقدم عليه - مثلاً لو مقدم كصاحب محل موبايلات، الصور لازم تكون لمحل موبايلات فعلاً.\n"
                    ."لو بعت سجل تجاري وبطاقة ضريبية، اسم المحل لازم يكون نفسه في المستندين، واسم صاحب البطاقة الشخصية لازم يكون نفس الاسم في السجل التجاري والبطاقة الضريبية.",
            ],
            [
                'key' => 'installment_payment_schedule',
                'category' => 'pricing',
                'title' => 'ميعاد أول قسط',
                'content' => "أول قسط بيبقى مستحق بعد استلام العميل للمكنة بـ 45 يوم، ومعاه 5 أيام سماح.",
            ],
            [
                'key' => 'catalog_naming_and_grades',
                'category' => 'catalog',
                'title' => 'أسماء شعبية للموديلات والفرز',
                'is_pinned' => true,
                'content' => "أسماء شعبية بيستخدمها العملاء: هوجن جمبو/جامبو/جيمبو = هوجن 4. النحلة = دايو 2. الأرنبة/الأرنب = هوجن 3. التفاحة = دايو 4. زد = Z250. تي إكس = Tx 250. آر كي = Rk200 R. إتش / إتش 250 = H250.\n"
                    ."المتوفر عندنا صيني وهندي بس - مفيش ياباني. لو العميل سأل عن موديل ياباني أو أي حاجة مش في كتالوجنا، ينفع تديله معلومة عامة عنها لو سأل، بس وضّح إنها مش من عندنا وممنوع تقوله سعر أو تخليه يشتريها منك.\n"
                    ."هوجن 3 / هوجن 4 / دايو 4 / دايو 2 متوفرين بفرزين: أصلي (فرز أول) وفرز تاني - لو العميل قال \"مصري\" فهو غالبًا يقصد الفرز التاني. لو مش واضح استخدم أداة البحث في الكتالوج وأكّد مع العميل أي فرز يقصد، من غير ما تستخدم أنت كلمة \"أرنبة\" أو \"تفاحة\" أو \"مصري\" قدامه - قول \"فرز تاني\" بس.",
            ],
            [
                'key' => 'spec_explanation_style',
                'category' => 'catalog',
                'title' => 'أسلوب شرح مواصفات المكنة',
                'content' => "اشرح المواصفات بطريقة سيلز طبيعي مش كتالوج - ركّز على: المواصفات العامة، استهلاك البنزين، الاعتمادية، الراحة، الصيانة وقطع الغيار، ومناسبتها للسفر أو الشغل.\n"
                    ."خلي الرسالة مختصرة إلا لو العميل مكانش عارف تفاصيل أكتر واحتاج شرح موسّع.",
            ],
        ];

        foreach ($entries as $entry) {
            BusinessMemory::firstOrCreate(['key' => $entry['key']], $entry + ['is_active' => true]);
        }
    }
}
