<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\RequirementField;
use Illuminate\Database\Seeder;

/**
 * Every document is read for what it proves, not only the name on it: a
 * salary slip gives the net salary, the month it was issued for and the
 * hire date; a pension statement gives the pension amount the eligibility
 * minimum is checked against. extraction_fields must be on the document,
 * optional_fields are read when printed. Idempotent.
 */
class DocumentExtractionSeeder extends Seeder
{
    private const NAME_MATCH = ['rule_type' => 'matches_application_field', 'params' => [
        'extracted_field' => 'full_name', 'stored_field' => 'full_name', 'issue_code' => 'NAME_MISMATCH', 'match' => 'name_tokens',
    ]];

    public function run(): void
    {
        // Was "قيمة المعاش الشهري" while a salary slip fills it too.
        RequirementField::where('key', 'monthly_income')->update([
            'label' => 'الدخل الشهري (المرتب أو المعاش)',
            'description_for_ai' => 'صافي المرتب أو المعاش الشهري بالجنيه، رقم بس. بيتقري من مفردات المرتب أو كشف المعاش - لو اتقري من المستند ما تسألش عنه.',
        ]);

        $this->type('national_id_front', [
            'description_for_ai' => 'وش بطاقة الرقم القومي المصرية (الوش اللي فيه الصورة الشخصية والاسم والعنوان والرقم القومي ١٤ رقم). الضهر (اللي فيه المهنة والحالة الاجتماعية وتاريخ الانتهاء) مش النوع ده. '
                .'full_name = الاسم كامل (السطر الأول الاسم الأول لوحده والسطر التاني باقي الاسم - اجمعهم). national_id = الـ١٤ رقم بأرقام إنجليزي. '
                .'id_address = العنوان المكتوب تحت الاسم زي ما هو.',
            'extraction_fields' => ['full_name', 'national_id'],
            'optional_fields' => ['id_address'],
            'validation_rules' => [
                self::NAME_MATCH,
                ['rule_type' => 'matches_application_field', 'params' => [
                    'extracted_field' => 'national_id', 'stored_field' => 'national_id', 'issue_code' => 'ID_MISMATCH',
                ]],
            ],
        ]);

        $this->type('salary_slip', [
            'description_for_ai' => 'مفردات مرتب / كشف مرتب / قسيمة راتب (Pay slip) من جهة الشغل، أو شهادة مفردات مرتب مختومة. '
                .'full_name = اسم الموظف. monthly_income = صافي المرتب (الصافي / صافي المستحق / Net) بالجنيه - مش الإجمالي؛ لو مكتوب إجمالي بس حطه. '
                .'salary_slip_date = الشهر اللي المفردات عنه أو تاريخ إصدارها/الختم (لو مكتوب شهر بس، آخر يوم في الشهر ده). '
                .'hire_date = تاريخ التعيين / تاريخ الالتحاق بالعمل. employer_name = اسم الشركة أو الجهة. job_title = الوظيفة / الدرجة. '
                .'gross_salary = إجمالي المرتب (الإجمالي / إجمالي المستحقات / Gross). insurance_number = الرقم التأميني.',
            'extraction_fields' => ['full_name', 'monthly_income', 'salary_slip_date'],
            'optional_fields' => ['hire_date', 'employer_name', 'job_title', 'gross_salary', 'insurance_number'],
            'validation_rules' => [
                self::NAME_MATCH,
                ['rule_type' => 'not_expired', 'params' => ['date_field' => 'salary_slip_date', 'max_age_days' => 90, 'issue_code' => 'EXPIRED_DOCUMENT']],
            ],
        ]);

        $this->type('pension_statement', [
            'description_for_ai' => 'كشف / برنت معاش أو خطاب من التأمينات الاجتماعية أو البنك/البريد بيثبت إن العميل بيقبض معاش شهري. '
                .'full_name = اسم صاحب المعاش. monthly_income = قيمة المعاش الشهري (الصافي) بالجنيه. '
                .'pension_statement_date = تاريخ الكشف أو آخر شهر صرف ظاهر فيه (لو مكتوب شهر بس، آخر يوم فيه). '
                .'pension_authority = جهة الصرف (التأمينات / بنك / بريد...).',
            'extraction_fields' => ['full_name', 'monthly_income', 'pension_statement_date'],
            'optional_fields' => ['pension_authority'],
            'validation_rules' => [
                self::NAME_MATCH,
                ['rule_type' => 'not_expired', 'params' => ['date_field' => 'pension_statement_date', 'max_age_days' => 90, 'issue_code' => 'EXPIRED_DOCUMENT']],
            ],
        ]);

        $this->type('self_employed_income_proof', [
            'description_for_ai' => 'مستند يثبت دخل صاحب نشاط أو مهنة: سجل تجاري، بطاقة ضريبية، أو كشف حساب بنكي. سكرينات تطبيقات التوصيل ليها أنواع لوحدها ومش تبع النوع ده. '
                .'full_name = اسم صاحب السجل/البطاقة/الحساب. income_proof_kind = نوع المستند بالعربي (سجل تجاري / بطاقة ضريبية / كشف حساب بنكي). '
                .'business_name = اسم النشاط أو المنشأة لو مكتوب. business_activity = نوع النشاط. registration_number = رقم السجل أو رقم الملف/التسجيل الضريبي. '
                .'document_date = تاريخ الإصدار أو آخر تاريخ في كشف الحساب. document_expiry_date = تاريخ انتهاء السجل/البطاقة لو مكتوب.',
            'extraction_fields' => ['full_name'],
            'optional_fields' => ['income_proof_kind', 'business_name', 'business_activity', 'registration_number', 'document_date', 'document_expiry_date'],
            'validation_rules' => [
                self::NAME_MATCH,
                ['rule_type' => 'not_past', 'params' => ['date_field' => 'document_expiry_date', 'issue_code' => 'EXPIRED_DOCUMENT']],
            ],
        ]);

        $this->type('driving_license', [
            'optional_fields' => ['license_type', 'license_issue_date', 'traffic_unit'],
            'description_for_ai' => 'رخصة قيادة مصرية شخصية (كارت مكتوب عليه "رخصة قيادة" + إدارة مرور، فيه الاسم عربي وإنجليزي، الرقم القومي، الجنسية، المهنة، تاريخ التحرير ونهاية الترخيص). '
                .'رخصة الموتوسيكل ("رخصة قيادة دراجة نارية" / فئة A) ورخصة العربية ("رخصة قيادة خاصة/مهنية") الاتنين يتصنفوا هنا. مش رخصة تسيير المركبة (رخصة العربية نفسها). '
                .'full_name = الاسم بالعربي. license_expiry_date = تاريخ "نهاية الترخيص" بصيغة YYYY-MM-DD. license_issue_date = "تاريخ التحرير". '
                .'license_type = نوع الرخصة زي ما هو مكتوب (دراجة نارية / خاصة / مهنية درجة أولى...). traffic_unit = وحدة/إدارة المرور.',
        ]);

        $this->type('delivery_app_earnings', [
            'optional_fields' => ['total_earnings', 'account_name'],
            'description_for_ai' => DocumentType::where('key', 'delivery_app_earnings')->value('description_for_ai')
                .' total_earnings = إجمالي المبلغ اللي الصفحة بتبينه للفترة دي (رقم بس). account_name = اسم صاحب الحساب لو ظاهر.',
        ], appendOnce: 'total_earnings =');

        $this->type('delivery_app_profile', [
            'optional_fields' => ['join_date', 'rating', 'trips_count'],
            'description_for_ai' => DocumentType::where('key', 'delivery_app_profile')->value('description_for_ai')
                .' join_date = تاريخ الانضمام / عضو منذ. rating = التقييم. trips_count = عدد الرحلات أو الطلبات لو ظاهر.',
        ], appendOnce: 'join_date =');

        $this->type('business_place_photo', [
            'optional_fields' => ['business_activity'],
            'description_for_ai' => DocumentType::where('key', 'business_place_photo')->value('description_for_ai')
                .' business_activity = نوع النشاط اللي باين (مطعم، بقالة، ورشة...).',
        ], appendOnce: 'business_activity =');

        $this->type('tax_card', [
            'description_for_ai' => 'بطاقة ضريبية أو سجل تجاري لنشاط العميل. business_name = اسم النشاط/المنشأة المكتوب عليها. '
                .'taxpayer_name = اسم الممول / صاحب المنشأة. registration_number = رقم التسجيل الضريبي أو رقم السجل. business_activity = النشاط. '
                .'document_expiry_date = تاريخ انتهاء البطاقة/السجل لو مكتوب.',
            'optional_fields' => ['taxpayer_name', 'registration_number', 'business_activity', 'document_expiry_date'],
            'validation_rules' => [
                ['rule_type' => 'matches_application_field', 'params' => [
                    'extracted_field' => 'business_name', 'stored_field' => 'business_name', 'issue_code' => 'BUSINESS_NAME_MISMATCH', 'match' => 'contains',
                ]],
                ['rule_type' => 'not_past', 'params' => ['date_field' => 'document_expiry_date', 'issue_code' => 'EXPIRED_DOCUMENT']],
            ],
        ]);
    }

    /** Updates an existing type only - which documents exist is the owner's call. */
    private function type(string $key, array $values, ?string $appendOnce = null): void
    {
        $type = DocumentType::where('key', $key)->first();

        if (! $type) {
            return;
        }

        // Descriptions that get a sentence appended: only the first run appends.
        if ($appendOnce !== null && str_contains((string) $type->description_for_ai, $appendOnce)) {
            unset($values['description_for_ai']);
        }

        $type->update($values);
    }
}
