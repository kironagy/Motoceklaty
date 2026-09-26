<?php

namespace App\Domain\Documents;

/**
 * Values read off a document that are not application fields: they stay on
 * the document (application_documents.extracted) for staff, the rules and
 * the legacy request - the customer is never asked for them.
 */
class DocumentFields
{
    public const LABELS = [
        'app_name' => 'اسم التطبيق',
        'period_start' => 'بداية الفترة',
        'period_end' => 'نهاية الفترة',
        'license_expiry_date' => 'تاريخ انتهاء الرخصة',
        'license_issue_date' => 'تاريخ تحرير الرخصة',
        'license_type' => 'نوع الرخصة',
        'traffic_unit' => 'وحدة المرور',
        'issue_date' => 'تاريخ الإصدار',
        'id_address' => 'العنوان المكتوب في البطاقة',
        'id_expiry_date' => 'البطاقة سارية حتى',
        'occupation' => 'المهنة المكتوبة في البطاقة',
        'marital_status' => 'الحالة الاجتماعية',
        'salary_slip_date' => 'تاريخ مفردات المرتب',
        'gross_salary' => 'إجمالي المرتب',
        'employer_name' => 'جهة العمل',
        'job_title' => 'الوظيفة',
        'hire_date' => 'تاريخ التعيين',
        'insurance_number' => 'الرقم التأميني',
        'pension_statement_date' => 'تاريخ كشف المعاش',
        'pension_authority' => 'جهة صرف المعاش',
        'income_proof_kind' => 'نوع المستند',
        'document_date' => 'تاريخ المستند',
        'document_expiry_date' => 'تاريخ انتهاء المستند',
        'registration_number' => 'رقم السجل / الملف الضريبي',
        'business_activity' => 'نوع النشاط',
        'taxpayer_name' => 'اسم الممول / صاحب النشاط',
        'total_earnings' => 'إجمالي الأرباح الظاهرة',
        'account_name' => 'الاسم في حساب التطبيق',
        'join_date' => 'تاريخ الانضمام للتطبيق',
        'rating' => 'التقييم',
        'trips_count' => 'عدد الرحلات / الطلبات',
    ];

    /** Amounts: read as a plain number so the money validator and staff get the same value. */
    public const MONEY = ['monthly_income', 'gross_salary', 'total_earnings'];

    public static function isDate(string $key): bool
    {
        return str_contains($key, 'date') || str_starts_with($key, 'period_');
    }

    /** Gemini response-schema property for one field. */
    public static function schema(string $key): array
    {
        // Asked for a period over a weekly list, the model once repeated
        // week dates into period_start until it hit the token limit, the
        // JSON was cut off, and a clear earnings screenshot was reported as
        // an unsupported document.
        if (self::isDate($key)) {
            return ['type' => 'string', 'description' => 'One single date only, YYYY-MM-DD. Never a list.'];
        }

        if (in_array($key, self::MONEY, true)) {
            return ['type' => 'string', 'description' => 'One amount in digits 0-9 only (e.g. 5432.50) - no currency, no thousands separators.'];
        }

        return ['type' => 'string'];
    }
}
