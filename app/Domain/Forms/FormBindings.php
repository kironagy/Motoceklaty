<?php

namespace App\Domain\Forms;

/**
 * Everything in the system a form field can be tied to (spec §4.2). The owner picks one of
 * these from a dropdown in the builder; on submit the answer lands in the request column(s)
 * named here. A new request column means one new line here - nothing else.
 *
 * kinds:
 *  - column:   one installment_requests column (data_key also mirrors it into application_data)
 *  - address:  the composite address field - one column per part, plus the joined line
 *  - document: an upload copied to a request image column (array = the column holds a list)
 *  - machine / plan / deposit / work_status: the application's selection
 */
class FormBindings
{
    public const ADDRESS_PARTS = [
        'governorate' => 'المحافظة',
        'area' => 'المنطقة / المدينة',
        'street' => 'الشارع',
        'branch_street' => 'شارع جانبي',
        'building' => 'رقم العقار',
        'floor' => 'الدور',
        'apartment' => 'الشقة',
        'landmark' => 'علامة مميزة',
    ];

    /** @return array<string, array> */
    public static function all(): array
    {
        return [
            // صاحب الطلب
            'applicant.name' => ['group' => 'صاحب الطلب', 'label' => 'اسم صاحب الطلب', 'kind' => 'column', 'column' => 'applicant_name', 'data_key' => 'full_name', 'types' => ['text']],
            'applicant.national_id' => ['group' => 'صاحب الطلب', 'label' => 'الرقم القومي لصاحب الطلب', 'kind' => 'column', 'column' => 'applicant_national_id', 'data_key' => 'national_id', 'types' => ['national_id']],
            'applicant.phone' => ['group' => 'صاحب الطلب', 'label' => 'موبايل صاحب الطلب', 'kind' => 'column', 'column' => 'applicant_phone', 'data_key' => 'phone', 'types' => ['phone']],
            'applicant.phone_2' => ['group' => 'صاحب الطلب', 'label' => 'موبايل تاني', 'kind' => 'column', 'column' => 'applicant_phone_2', 'data_key' => 'phone_2', 'types' => ['phone']],
            'applicant.address' => ['group' => 'صاحب الطلب', 'label' => 'عنوان السكن بالتفصيل', 'kind' => 'address', 'prefix' => 'applicant', 'full' => 'applicant_address', 'types' => ['address']],
            'applicant.id_front' => ['group' => 'صاحب الطلب', 'label' => 'صورة بطاقة صاحب الطلب (وش)', 'kind' => 'document', 'column' => 'applicant_id_image', 'dir' => 'installments/applicants', 'document_type' => 'national_id_front', 'party' => 'applicant', 'types' => ['document']],
            'applicant.id_back' => ['group' => 'صاحب الطلب', 'label' => 'صورة بطاقة صاحب الطلب (ضهر)', 'kind' => 'document', 'column' => 'applicant_id_back_image', 'dir' => 'installments/applicants', 'document_type' => 'national_id_back', 'party' => 'applicant', 'types' => ['document']],
            'applicant.selfie' => ['group' => 'صاحب الطلب', 'label' => 'صورة سيلفي', 'kind' => 'document', 'column' => 'selfie_image', 'dir' => 'installments/applicants', 'party' => 'applicant', 'types' => ['document']],
            'applicant.medical_card' => ['group' => 'صاحب الطلب', 'label' => 'الكارت الطبي', 'kind' => 'document', 'column' => 'medical_card_image', 'dir' => 'installments/applicants', 'party' => 'applicant', 'types' => ['document']],

            // الضامن
            'guarantor.name' => ['group' => 'الضامن', 'label' => 'اسم الضامن', 'kind' => 'column', 'column' => 'guarantor_name', 'data_key' => 'guarantor_name', 'party' => 'guarantor', 'types' => ['text']],
            'guarantor.national_id' => ['group' => 'الضامن', 'label' => 'الرقم القومي للضامن', 'kind' => 'column', 'column' => 'guarantor_national_id', 'data_key' => 'guarantor_national_id', 'party' => 'guarantor', 'types' => ['national_id']],
            'guarantor.phone' => ['group' => 'الضامن', 'label' => 'موبايل الضامن', 'kind' => 'column', 'column' => 'guarantor_phone', 'data_key' => 'guarantor_phone', 'party' => 'guarantor', 'types' => ['phone']],
            'guarantor.list' => ['group' => 'الضامن', 'label' => 'الضامنين (أكتر من ضامن)', 'kind' => 'guarantors', 'types' => ['group']],
            'guarantor.id_front' => ['group' => 'الضامن', 'label' => 'بطاقة الضامن (وش)', 'kind' => 'document', 'column' => 'guarantor_id_image', 'dir' => 'installments/guarantors', 'document_type' => 'national_id_front', 'party' => 'guarantor', 'types' => ['document']],
            'guarantor.id_back' => ['group' => 'الضامن', 'label' => 'بطاقة الضامن (ضهر)', 'kind' => 'document', 'column' => 'guarantor_id_back_image', 'dir' => 'installments/guarantors', 'document_type' => 'national_id_back', 'party' => 'guarantor', 'types' => ['document']],

            // الشغل
            'work.status' => ['group' => 'الشغل', 'label' => 'نوع الشغل (بيحدد نوع العميل)', 'kind' => 'work_status', 'types' => ['select', 'radio']],
            'work.address' => ['group' => 'الشغل', 'label' => 'عنوان الشغل بالتفصيل', 'kind' => 'address', 'prefix' => 'work', 'full' => 'work_address', 'types' => ['address']],
            'work.place_name' => ['group' => 'الشغل', 'label' => 'اسم الشغل / الشركة / المحل', 'kind' => 'column', 'column' => 'free_work_name', 'types' => ['text']],
            'work.free_address' => ['group' => 'الشغل', 'label' => 'عنوان الشغل الحر (سطر واحد)', 'kind' => 'column', 'column' => 'free_work_address', 'types' => ['text', 'textarea']],
            'work.salary_amount' => ['group' => 'الشغل', 'label' => 'المرتب', 'kind' => 'column', 'column' => 'salary_amount', 'data_key' => 'monthly_income', 'types' => ['number', 'money']],
            'work.salary_issue_date' => ['group' => 'الشغل', 'label' => 'تاريخ المفردات', 'kind' => 'column', 'column' => 'salary_issue_date', 'types' => ['date']],
            'work.pension_amount' => ['group' => 'الشغل', 'label' => 'قيمة المعاش', 'kind' => 'column', 'column' => 'pension_amount', 'types' => ['number', 'money']],
            'work.commercial_reg_expiry' => ['group' => 'الشغل', 'label' => 'تاريخ انتهاء السجل التجاري', 'kind' => 'column', 'column' => 'commercial_reg_expiry', 'types' => ['date']],
            'work.tax_card_expiry' => ['group' => 'الشغل', 'label' => 'تاريخ انتهاء البطاقة الضريبية', 'kind' => 'column', 'column' => 'tax_card_expiry', 'types' => ['date']],
            'work.salary_slip' => ['group' => 'الشغل', 'label' => 'مفردات المرتب / برنت التأمينات', 'kind' => 'document', 'column' => 'salary_slip_file', 'dir' => 'installments/salary_slips', 'document_type' => 'salary_slip', 'party' => 'applicant', 'types' => ['document']],
            'work.pension_statement' => ['group' => 'الشغل', 'label' => 'إثبات المعاش', 'kind' => 'document', 'column' => 'pension_statement_file', 'dir' => 'installments/pension', 'document_type' => 'pension_statement', 'party' => 'applicant', 'types' => ['document']],
            'work.commercial_reg' => ['group' => 'الشغل', 'label' => 'السجل التجاري', 'kind' => 'document', 'column' => 'commercial_reg_file', 'dir' => 'installments/business', 'party' => 'applicant', 'types' => ['document']],
            'work.tax_card' => ['group' => 'الشغل', 'label' => 'البطاقة الضريبية', 'kind' => 'document', 'column' => 'tax_card_file', 'dir' => 'installments/business', 'document_type' => 'tax_card', 'party' => 'applicant', 'types' => ['document']],
            'work.place_media' => ['group' => 'الشغل', 'label' => 'صور / فيديو مكان الشغل', 'kind' => 'document', 'column' => 'place_video', 'dir' => 'installments/business', 'array' => true, 'document_type' => 'business_place_photo', 'party' => 'applicant', 'types' => ['document', 'images']],
            'work.vehicle_license' => ['group'=>'الشغل','label'=>'رخصة العربية','kind'=>'document','column'=>'free_income_proof_images','dir'=>'installments/free_income_proofs','array'=>true,'document_type'=>'vehicle_license','party'=>'applicant','types'=>['document']],
            'work.free_income_proof' => ['group' => 'الشغل', 'label' => 'إثبات الدخل الحر', 'kind' => 'document', 'column' => 'free_income_proof_images', 'dir' => 'installments/free_income_proofs', 'array' => true, 'party' => 'applicant', 'types' => ['document', 'images']],

            // الطلب
            'request.machine' => ['group' => 'الطلب', 'label' => 'المكنة', 'kind' => 'machine', 'types' => ['machine']],
            'request.plan' => ['group' => 'الطلب', 'label' => 'نظام القسط وعدد الشهور', 'kind' => 'plan', 'types' => ['plan']],
            'request.deposit' => ['group' => 'الطلب', 'label' => 'المقدم', 'kind' => 'deposit', 'types' => ['number', 'money']],
            'request.notes' => ['group' => 'الطلب', 'label' => 'ملاحظات (تتضاف للطلب)', 'kind' => 'notes', 'types' => ['text', 'textarea']],
            'request.price_offer' => ['group' => 'الطلب', 'label' => 'صورة عرض السعر', 'kind' => 'document', 'column' => 'price_offer_image', 'dir' => 'installments/applicants', 'party' => 'applicant', 'types' => ['document']],
        ];
    }

    public static function get(?string $key): ?array
    {
        return $key ? (self::all()[$key] ?? null) : null;
    }

    /** Dropdown options for the builder, grouped. @return array<string, array<string, string>> */
    public static function options(?string $fieldType = null): array
    {
        $options = [];

        foreach (self::all() as $key => $binding) {
            if ($fieldType !== null && ! in_array($fieldType, $binding['types'], true)) {
                continue;
            }

            $options[$binding['group']][$key] = $binding['label'];
        }

        return $options;
    }

    /** Request columns of one address binding: part => column. @return array<string, string> */
    public static function addressColumns(string $prefix): array
    {
        return [
            'governorate' => "{$prefix}_governorate",
            'area' => "{$prefix}_area",
            'street' => "{$prefix}_street",
            'branch_street' => "{$prefix}_branch_street",
            'building' => "{$prefix}_building_number",
            'floor' => "{$prefix}_floor",
            'apartment' => "{$prefix}_apartment",
            'landmark' => "{$prefix}_landmark",
        ];
    }

    /** The address as one line, the way the deliveries form shows it. */
    public static function joinAddress(array $parts): ?string
    {
        $line = array_filter([
            filled($parts['building'] ?? null) ? 'رقم العقار '.$parts['building'] : null,
            $parts['street'] ?? null,
            $parts['branch_street'] ?? null,
            $parts['area'] ?? null,
            $parts['governorate'] ?? null,
            filled($parts['floor'] ?? null) ? 'الدور '.$parts['floor'] : null,
            filled($parts['apartment'] ?? null) ? 'شقة '.$parts['apartment'] : null,
            filled($parts['landmark'] ?? null) ? 'بجوار '.$parts['landmark'] : null,
        ], fn ($v) => filled($v));

        return $line === [] ? null : implode(' - ', $line);
    }
}
