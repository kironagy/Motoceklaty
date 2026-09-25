<?php

namespace Database\Seeders;

use App\Models\ApplicationRequirement;
use App\Models\Branch;
use App\Models\BusinessMemory;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\RequirementField;
use Illuminate\Database\Seeder;

/**
 * Per-work-type requirements for عامل حر (business memories
 * delivery_driver_docs / freelance_profession_docs), as data the dashboard
 * can edit: which documents a customer owes depends on the work_type field
 * through application_requirements.condition, never on code branches.
 *
 * Idempotent (keyed on key / type+document). Also gives the branches a
 * starter working-hours table so the agent can answer "بتفتحوا امتى"; edit
 * it from the dashboard.
 */
class WorkTypeRequirementsSeeder extends Seeder
{
    public function run(): void
    {
        $workType = RequirementField::updateOrCreate(['key' => 'work_type'], [
            'label' => 'نوع الشغل',
            'data_type' => 'enum',
            'enum_options' => ['delivery_app', 'delivery_app_bicycle', 'delivery_company', 'craftsman', 'business_owner', 'other'],
            'scope' => 'application',
            'is_sensitive' => false,
            'is_active' => true,
            'description_for_ai' => 'delivery_app = سواق/دليفري على تطبيق بموتوسيكل (طلبات، أوبر، إندرايف، كريم، مرسول، بولت، ديدي، بريدفاست، أو أي تطبيق تاني). '
                .'delivery_app_bicycle = دليفري تطبيق بالعجلة. delivery_company = دليفري مطعم أو شركة. '
                .'craftsman = صاحب مهنة (نجار، سباك، كهربائي، نقاش...). '
                .'business_owner = بس لو العميل قال صراحة إنه صاحب شركة أو محل أو ورشة أو معرض أو مصنع - "شغال حر" أو "دخل حر" لوحدها مش business_owner. '
                .'other = أي شغل حر تاني. '
                .'استنتجه من كلام العميل (مثلا "شغال طلبات" = delivery_app) وسجّله بـ record_customer_data من غير ما تسأله عن الكود.',
        ]);

        $workAddress = RequirementField::updateOrCreate(['key' => 'work_address'], [
            'label' => 'عنوان الشغل',
            'data_type' => 'address',
            'scope' => 'application',
            'is_sensitive' => false,
            'is_active' => true,
        ]);

        $license = DocumentType::updateOrCreate(['key' => 'driving_license'], [
            'label' => 'رخصة القيادة',
            'description_for_ai' => 'رخصة قيادة مصرية شخصية (كارت مكتوب عليه "رخصة قيادة" + إدارة مرور، فيه الاسم عربي وإنجليزي، الرقم القومي، الجنسية، المهنة، تاريخ التحرير ونهاية الترخيص). '
                .'رخصة الموتوسيكل ("رخصة قيادة دراجة نارية" / فئة A) ورخصة العربية ("رخصة قيادة خاصة/مهنية") الاتنين يتصنفوا هنا. '
                .'license_expiry_date = تاريخ "نهاية الترخيص" بصيغة YYYY-MM-DD. '
                .'مش رخصة تسيير المركبة (رخصة العربية نفسها).',
            'accepted_mimes' => ['image/jpeg', 'image/png', 'application/pdf'],
            'extraction_fields' => ['full_name', 'national_id', 'license_expiry_date'],
            'validation_rules' => [
                ['rule_type' => 'matches_application_field', 'params' => ['issue_code' => 'ID_MISMATCH', 'stored_field' => 'national_id', 'extracted_field' => 'national_id']],
                ['rule_type' => 'not_past', 'params' => ['date_field' => 'license_expiry_date', 'issue_code' => 'LICENSE_EXPIRED']],
            ],
            'is_active' => true,
        ]);

        // One screenshot per month/week is normal: each is accepted on its
        // own and period_coverage adds their periods up (PeriodCoverage).
        $earnings = DocumentType::updateOrCreate(['key' => 'delivery_app_earnings'], [
            'label' => 'سكرين أرباح تطبيق التوصيل (آخر ٣ شهور)',
            'description_for_ai' => 'سكرين شوت من أي تطبيق توصيل أو رحلات أو شغل حر (طلبات، أوبر، إندرايف، كريم، ديدي، بولت، مرسول، بريدفاست، رابيت، جاهز، مستر مندوب، أو أي تطبيق تاني بأي لغة) '
                .'بيبيّن فلوس العميل كسبها في فترة: صفحة الأرباح/Earnings/الدخل/المحفظة/Wallet/الرصيد/كشف حساب التطبيق/ملخص أسبوعي أو شهري/جدول أيام أو أسابيع بمبالغ/رسم بياني للأرباح/سجل رحلات أو طلبات جنبها أجرة كل واحدة. '
                .'الشكل بيختلف من تطبيق للتاني - المهم إن فيه مبالغ فلوس مربوطة بتواريخ أو فترة. '
                .'app_name = اسم التطبيق (من اللوجو أو الألوان أو النص). '
                .'period_start و period_end = أول وآخر يوم الأرباح الظاهرة بتغطيه، YYYY-MM-DD: لو ظاهر قايمة أسابيع أو شهور خد من أول واحدة لآخر واحدة؛ '
                .'لو شهر بس ("سبتمبر") يبقى من أول الشهر لآخره (أو لحد النهارده لو الشهر الحالي)؛ لو فترة نسبية ("آخر 7 أيام"، "this week") احسبها من تاريخ النهارده. '
                .'صفحة البروفايل أو الحساب أو التقييم أو نسبة القبول من غير مبالغ أرباح مش تبع النوع ده.',
            'accepted_mimes' => ['image/jpeg', 'image/png', 'application/pdf'],
            'extraction_fields' => ['app_name', 'period_start', 'period_end'],
            'validation_rules' => [
                ['rule_type' => 'period_coverage', 'params' => [
                    'start_field' => 'period_start', 'end_field' => 'period_end',
                    // ~3 months together (a partial current month counts), the
                    // newest screenshot no older than 45 days.
                    'min_days' => 80, 'max_age_days' => 45,
                ]],
            ],
            'is_active' => true,
        ]);

        DocumentType::updateOrCreate(['key' => 'delivery_app_profile'], [
            'label' => 'سكرين بروفايل تطبيق التوصيل',
            'description_for_ai' => 'سكرين شوت لصفحة البروفايل أو الحساب في تطبيق توصيل/رحلات (اسم المندوب، صورته، التقييم، نسبة القبول، تاريخ الانضمام، عقد النشاط) من غير مبالغ أرباح. '
                .'بيثبت إن العميل شغال على التطبيق بس مش إثبات دخل. full_name = الاسم الظاهر في الحساب. app_name = اسم التطبيق.',
            'accepted_mimes' => ['image/jpeg', 'image/png'],
            'extraction_fields' => ['full_name', 'app_name'],
            'validation_rules' => [],
            'is_active' => true,
        ]);

        DocumentType::where('key', 'self_employed_income_proof')->update([
            'description_for_ai' => 'مستند يثبت دخل صاحب نشاط أو مهنة: سجل تجاري، بطاقة ضريبية، أو كشف حساب بنكي. سكرينات تطبيقات التوصيل ليها أنواع لوحدها ومش تبع النوع ده.',
        ]);

        $selfEmployed = CustomerType::where('key', 'self_employed')->firstOrFail();

        $this->require($selfEmployed, 'field', $workType->id, 5);
        $this->require($selfEmployed, 'field', $workAddress->id, 6, ['fact' => 'work_type', 'op' => 'in', 'value' => ['delivery_company', 'craftsman', 'business_owner', 'other']]);
        $this->require($selfEmployed, 'document', $license->id, 11, ['fact' => 'work_type', 'op' => 'in', 'value' => ['delivery_app', 'delivery_company']]);
        $this->require($selfEmployed, 'document', $earnings->id, 12, ['fact' => 'work_type', 'op' => 'in', 'value' => ['delivery_app', 'delivery_app_bicycle']]);

        $incomeProof = DocumentType::where('key', 'self_employed_income_proof')->value('id');
        ApplicationRequirement::where('customer_type_id', $selfEmployed->id)
            ->where('requirement_type', 'document')
            ->where('document_type_id', $incomeProof)
            // Only a customer who says he owns a company/shop/workshop/showroom
            // owes activity proof - "شغال حر" alone was asked for a
            // commercial register and tax card.
            ->update(['condition' => ['fact' => 'work_type', 'op' => 'in', 'value' => ['business_owner']]]);

        BusinessMemory::firstOrCreate(['key' => 'self_employed_vs_business_owner'], [
            'category' => 'eligibility',
            'title' => 'الفرق بين الدخل الحر وصاحب العمل',
            'content' => "الدخل الحر (عامل حر) = بيشتغل لنفسه من غير شركة أو محل: سواق، دليفري، صنايعي، شغل يومي منتظم... ده بيقدّم بالبطاقة (+ مستندات نوع شغله لو ليه مستندات زي رخصة وسكرين أرباح للدليفري).\n"
                ."صاحب العمل = بس لو العميل قال بنفسه صراحة إنه صاحب شركة أو محل أو ورشة أو معرض أو مصنع. ده بس اللي يتطلب منه صور النشاط وعنوان الشغل، وسجل تجاري وبطاقة ضريبية لو موجودين.\n"
                ."ممنوع تسأل عامل حر عن سجل تجاري أو بطاقة ضريبية أو صور نشاط أو «مستندات للنشاط» - ولا حتى بصيغة «لو موجودة».\n"
                ."لو العميل سأل «بالبطاقة بس؟» وهو عامل حر: أيوه، التقديم بالبطاقة (ولو شغله دليفري/تطبيق قوله على الرخصة وسكرين الأرباح).",
            'priority' => 90,
            'is_pinned' => true,
            'is_active' => true,
        ]);

        BusinessMemory::firstOrCreate(['key' => 'self_employed_financing_cap'], [
            'category' => 'pricing',
            'title' => 'أقصى مبلغ تمويل للعامل الحر',
            'content' => "العامل الحر أقصى حاجة يقسطها ٦٠ ألف. لو المكنة أغلى، بيدفع الفرق + المصاريف الإدارية كاش مقدم ويقسط الباقي (٦٠ ألف).\n"
                ."مثال: مكنة سعرها ٦٦ ألف ومصاريفها ٣ آلاف = يدفع ٩ آلاف كاش مقدم ويقسط ٦٠ ألف.\n"
                ."الأرقام دي بتيجي جاهزة من get_installment_options / calculate_installment (cash_due_upfront و monthly) - قولها للعميل على طول. ممنوع تقول إنه مينفعش يقسط المكنة الغالية.",
            'priority' => 90,
            'is_pinned' => true,
            'is_active' => true,
        ]);

        // Live testing: asked about warranty, licensing, home delivery and
        // payment methods, the agent invented answers (InstaPay, "الترخيص
        // عليك") because nothing on file covered them. Until the owner writes
        // the real answers, these are explicitly "not on file".
        BusinessMemory::firstOrCreate(['key' => 'policies_not_on_file'], [
            'category' => 'guardrails',
            'title' => 'سياسات لسه مش متسجلة - ما تجاوبش فيها من عندك',
            'content' => "المواضيع دي لسه ملهاش إجابة رسمية متسجلة: الضمان ومدته، الترخيص (على مين وتكلفته)، التوصيل للبيت، طرق الدفع غير الكاش (فيزا، إنستاباي، تحويل)، الخصومات والعروض، الاسترجاع والاستبدال، مواعيد التسليم، الصيانة المجانية.
"
                ."لو العميل سأل في أي واحدة منهم: ما تألفش إجابة ولا تقول «غالبًا». قوله إنك هتتأكدله من الزميل المختص، ونادي handoff_to_human بسبب واضح في الـ note (مثلًا: عميل كاش بيسأل عن الضمان).
"
                ."لما صاحب المعرض يكتب الإجابة الرسمية لموضوع منهم في لوحة التحكم، يتشال من القايمة دي.",
            'priority' => 100,
            'is_pinned' => true,
            'is_active' => true,
        ]);

        $hours = ['السبت - الخميس' => '10 الصبح - 10 بالليل', 'الجمعة' => '2 الضهر - 10 بالليل'];
        Branch::whereNull('working_hours')->orWhere('working_hours', '[]')->get()
            ->each(fn (Branch $b) => $b->update(['working_hours' => $hours]));
    }

    private function require(CustomerType $type, string $kind, int $id, int $sort, ?array $condition = null): void
    {
        ApplicationRequirement::updateOrCreate([
            'customer_type_id' => $type->id,
            'requirement_type' => $kind,
            $kind === 'field' ? 'requirement_field_id' : 'document_type_id' => $id,
        ], ['is_required' => true, 'sort' => $sort, 'condition' => $condition]);
    }
}
