<?php

namespace App\Domain\Requests;

use App\Models\InstallmentRequest;

/**
 * Owner 2026-11-13: a paused request the customer fixed came back with no trace of what he
 * changed. Every field he changed (form or chat) is logged old -> new with its Arabic name,
 * and the request notes name them, so staff see "عدّل: العنوان، صورة البطاقة، ...".
 */
class CustomerEditLog
{
    public const LABELS = [
        'machine_id' => 'المكنة',
        'installment_type' => 'نوع النظام',
        'months' => 'عدد الشهور',
        'machine_installment_price' => 'سعر المكنة',
        'deposit' => 'المقدم',
        'applicant_name' => 'اسم العميل',
        'applicant_phone' => 'رقم الهاتف',
        'applicant_phone_2' => 'رقم هاتف تاني',
        'applicant_address' => 'العنوان',
        'applicant_national_id' => 'الرقم القومي',
        'applicant_id_image' => 'صورة البطاقة (وش)',
        'applicant_id_back_image' => 'صورة البطاقة (ضهر)',
        'applicant_birthdate' => 'تاريخ الميلاد',
        'medical_card_image' => 'الكارنيه الطبي',
        'selfie_image' => 'صورة سيلفي',
        'price_offer_image' => 'عرض السعر',
        'applicant_building_number' => 'رقم العمارة',
        'applicant_street' => 'الشارع',
        'applicant_branch_street' => 'الشارع الفرعي',
        'applicant_governorate' => 'المحافظة',
        'applicant_area' => 'المنطقة',
        'applicant_landmark' => 'علامة مميزة',
        'applicant_floor' => 'الدور',
        'applicant_apartment' => 'الشقة',
        'guarantor_name' => 'اسم الضامن',
        'guarantor_national_id' => 'الرقم القومي للضامن',
        'guarantor_id_image' => 'بطاقة الضامن (وش)',
        'guarantor_id_back_image' => 'بطاقة الضامن (ضهر)',
        'guarantor_birthdate' => 'تاريخ ميلاد الضامن',
        'guarantor_phone' => 'رقم الضامن',
        'guarantors' => 'الضامنين',
        'work_status' => 'الحالة الوظيفية',
        'work_address' => 'عنوان الشغل',
        'work_building_number' => 'رقم عمارة الشغل',
        'work_street' => 'شارع الشغل',
        'work_branch_street' => 'الشارع الفرعي للشغل',
        'work_governorate' => 'محافظة الشغل',
        'work_area' => 'منطقة الشغل',
        'work_landmark' => 'علامة مميزة للشغل',
        'work_floor' => 'دور الشغل',
        'work_apartment' => 'شقة الشغل',
        'free_work_name' => 'اسم النشاط',
        'free_work_address' => 'عنوان النشاط',
        'free_income_proof_images' => 'إثبات الدخل',
        'salary_amount' => 'المرتب',
        'salary_issue_date' => 'تاريخ المرتب',
        'salary_slip_file' => 'مفردات المرتب',
        'pension_amount' => 'المعاش',
        'pension_statement_file' => 'بيان المعاش',
        'commercial_reg_file' => 'السجل التجاري',
        'commercial_reg_expiry' => 'انتهاء السجل التجاري',
        'tax_card_file' => 'البطاقة الضريبية',
        'tax_card_expiry' => 'انتهاء البطاقة الضريبية',
        'place_video' => 'فيديو المكان',
    ];

    /** A file column: the log says "ملف جديد" instead of a storage path. */
    public static function isFile(string $field): bool
    {
        return (bool) preg_match('/(_image|_images|_file|_video)$/', $field);
    }

    /**
     * Logs what changed between $before (raw attributes) and the saved request.
     *
     * @return string[] Arabic names of the changed fields
     */
    public static function record(InstallmentRequest $request, array $before, string $source, mixed $causer = null): array
    {
        $after = $request->getAttributes();
        $old = [];
        $new = [];

        foreach (array_keys(self::LABELS) as $field) {
            if (! array_key_exists($field, $after)) {
                continue;
            }

            if (self::normalize($before[$field] ?? null) !== self::normalize($after[$field])) {
                $old[$field] = $before[$field] ?? null;
                $new[$field] = $after[$field];
            }
        }

        $labels = array_values(array_map(fn ($f) => self::LABELS[$f], array_keys($new)));

        // the same entry shows the request going back from "متوقف"
        if (($before['status'] ?? null) !== ($after['status'] ?? null)) {
            $old['status'] = $before['status'] ?? null;
            $new['status'] = $after['status'] ?? null;
        }

        if ($new === []) {
            return [];
        }

        $activity = activity('installment_request')->performedOn($request)
            ->withProperties(['old' => $old, 'attributes' => $new, 'source' => $source, 'changed' => $labels]);

        if ($causer) {
            $activity->causedBy($causer);
        }

        $activity->event('updated')->log($labels ? 'العميل عدّل: '.implode('، ', $labels) : 'العميل رجّع الطلب');

        return $labels;
    }

    /** Note line for the request: what he changed, in order. */
    public static function noteLine(array $labels, string $where): string
    {
        return $labels === []
            ? 'العميل رجّع الطلب من '.$where.' من غير ما يغيّر بيانات - '.now()->format('Y-m-d H:i')
            : 'العميل عدّل من '.$where.' ('.count($labels).'): '.implode('، ', $labels).' - '.now()->format('Y-m-d H:i');
    }

    /** Raw DB value vs freshly cast value: same JSON, date, or number counts as unchanged. */
    private static function normalize(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\s*[\[{]/', $value) && is_array($decoded = json_decode($value, true))) {
            $value = $decoded;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }

        $value = trim((string) $value);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]00:00:00/', $value, $m)) {
            return $m[1];
        }

        return is_numeric($value) ? (string) (0 + $value) : $value;
    }
}
