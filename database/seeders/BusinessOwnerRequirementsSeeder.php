<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Owner's rules (2026-09-26):
 * - A business owner is not a freelancer: his own customer type, no 60,000
 *   financing cap, and a photo of the place with its name showing - the
 *   same name as on the tax card when he sends one.
 * - "أي عنوان وخلاص" is not accepted: the home address needs the street,
 *   building number, floor, a landmark and whether it is rented or owned;
 *   the work address the street, building number and a landmark.
 *
 * Idempotent (keyed on key / type+field / type+document), so it runs the
 * same on any database and stays editable from the dashboard afterwards:
 * php artisan db:seed --class=BusinessOwnerRequirementsSeeder --force
 */
class BusinessOwnerRequirementsSeeder extends Seeder
{
    private const FIELDS = [
        'address' => ['label' => 'عنوان السكن (الشارع والمنطقة)', 'data_type' => 'address',
            'description_for_ai' => 'اسم الشارع + المنطقة/الحي + المدينة/المركز. رقم العقار والدور والعلامة المميزة ليهم حقول لوحدهم.'],
        'address_building_no' => ['label' => 'رقم العقار (السكن)', 'data_type' => 'string',
            'description_for_ai' => 'رقم العمارة/البيت زي ما العميل كتبه. لو قال مفيش رقم، سجّل كلامه زي ما هو ("مفيش رقم").'],
        'address_floor' => ['label' => 'الدور (السكن)', 'data_type' => 'string',
            'description_for_ai' => 'الدور زي ما العميل قاله (أرضي، التالت، ...).'],
        'address_landmark' => ['label' => 'علامة مميزة جنب السكن', 'data_type' => 'string',
            'description_for_ai' => 'حاجة معروفة جنب السكن (جنب مسجد/مدرسة/صيدلية...).'],
        'residence_ownership' => ['label' => 'السكن إيجار ولا تمليك', 'data_type' => 'enum', 'enum_options' => ['owned', 'rented', 'family'],
            'description_for_ai' => 'owned = تمليك، rented = إيجار، family = ساكن مع أهله (بيت العيلة).'],
        'work_address' => ['label' => 'عنوان الشغل (الشارع والمنطقة)', 'data_type' => 'address',
            'description_for_ai' => 'اسم الشارع + المنطقة/الحي + المدينة. من غير دور ومن غير إيجار/تمليك.'],
        'work_building_no' => ['label' => 'رقم العقار (الشغل)', 'data_type' => 'string',
            'description_for_ai' => 'رقم العقار بتاع مكان الشغل. لو قال مفيش رقم، سجّل كلامه زي ما هو.'],
        'work_landmark' => ['label' => 'علامة مميزة جنب الشغل', 'data_type' => 'string',
            'description_for_ai' => 'حاجة معروفة جنب مكان الشغل.'],
        'business_name' => ['label' => 'اسم النشاط', 'data_type' => 'string',
            'description_for_ai' => 'اسم المحل/المطعم/الشركة. بيتقري من صورة المكان أو البطاقة الضريبية - ما تطلبوش كتابة.'],
    ];

    public function run(): void
    {
        foreach (self::FIELDS as $key => $field) {
            DB::table('requirement_fields')->updateOrInsert(['key' => $key], [
                'label' => $field['label'],
                'data_type' => $field['data_type'],
                'enum_options' => isset($field['enum_options']) ? json_encode($field['enum_options']) : null,
                'scope' => 'application',
                'is_sensitive' => false,
                'description_for_ai' => $field['description_for_ai'],
                'is_active' => true,
                'updated_at' => now(),
            ]);
        }

        $nameRule = fn (string $code) => [[
            'rule_type' => 'matches_application_field',
            'params' => ['extracted_field' => 'business_name', 'stored_field' => 'business_name', 'issue_code' => $code, 'match' => 'contains'],
        ]];

        DB::table('document_types')->updateOrInsert(['key' => 'business_place_photo'], [
            'label' => 'صورة مكان النشاط',
            'description_for_ai' => 'صورة لمكان النشاط نفسه (محل، مطعم، ورشة، شركة، معرض...) ظاهر فيها اسم المكان بوضوح (اليافطة). '
                .'business_name = الاسم المكتوب على اليافطة بالظبط. لو مفيش اسم ظاهر، سيبه فاضي.',
            'accepted_mimes' => json_encode(['image/jpeg', 'image/png']),
            'extraction_fields' => json_encode(['business_name']),
            'validation_rules' => json_encode($nameRule('BUSINESS_NAME_MISMATCH')),
            'is_active' => true,
            'updated_at' => now(),
        ]);

        DB::table('document_types')->updateOrInsert(['key' => 'tax_card'], [
            'label' => 'البطاقة الضريبية / السجل التجاري',
            'description_for_ai' => 'بطاقة ضريبية أو سجل تجاري لنشاط العميل. business_name = اسم النشاط/المنشأة المكتوب عليها.',
            'accepted_mimes' => json_encode(['image/jpeg', 'image/png', 'application/pdf']),
            'extraction_fields' => json_encode(['business_name']),
            'validation_rules' => json_encode($nameRule('BUSINESS_NAME_MISMATCH')),
            'is_active' => true,
            'updated_at' => now(),
        ]);

        DB::table('customer_types')->updateOrInsert(['key' => 'business_owner'], [
            'label' => 'صاحب نشاط (محل/مطعم/شركة/ورشة)',
            'legacy_work_status' => 'self_employed',
            'is_active' => true,
            'sort' => 3,
            'updated_at' => now(),
        ]);

        $field = fn (string $key) => DB::table('requirement_fields')->where('key', $key)->value('id');
        $document = fn (string $key) => DB::table('document_types')->where('key', $key)->value('id');
        $homeAddress = ['address_building_no' => 4, 'address_floor' => 5, 'address_landmark' => 6, 'residence_ownership' => 7];
        $workAddress = ['work_building_no' => 8, 'work_landmark' => 9];

        // Every type that asks for a home address now asks for all of it.
        foreach ($this->typesRequiringField($field('address')) as $typeId) {
            foreach ($homeAddress as $key => $sort) {
                $this->requireField($typeId, $field($key), $sort);
            }
        }

        // Same for the work address, under the same condition it already has.
        foreach (DB::table('application_requirements')->where('requirement_field_id', $field('work_address'))->get() as $row) {
            foreach ($workAddress as $key => $sort) {
                $this->requireField($row->customer_type_id, $field($key), $sort, $row->condition);
            }
        }

        $owner = DB::table('customer_types')->where('key', 'business_owner')->value('id');

        foreach (['full_name' => 0, 'phone' => 1, 'national_id' => 2, 'address' => 3, 'work_address' => 8] as $key => $sort) {
            $this->requireField($owner, $field($key), $sort);
        }

        foreach ($homeAddress + $workAddress as $key => $sort) {
            $this->requireField($owner, $field($key), $sort);
        }

        $this->requireDocument($owner, $document('national_id_front'), 10, true);
        $this->requireDocument($owner, $document('business_place_photo'), 11, true);
        $this->requireDocument($owner, $document('tax_card'), 12, false);
    }

    /** @return int[] */
    private function typesRequiringField(?int $fieldId): array
    {
        return DB::table('application_requirements')->where('requirement_field_id', $fieldId)
            ->pluck('customer_type_id')->unique()->all();
    }

    private function requireField(int $typeId, ?int $fieldId, int $sort, ?string $condition = null): void
    {
        if ($fieldId === null) {
            return;
        }

        DB::table('application_requirements')->updateOrInsert(
            ['customer_type_id' => $typeId, 'requirement_type' => 'field', 'requirement_field_id' => $fieldId],
            ['is_required' => true, 'condition' => $condition, 'sort' => $sort, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function requireDocument(int $typeId, ?int $documentId, int $sort, bool $required): void
    {
        if ($documentId === null) {
            return;
        }

        DB::table('application_requirements')->updateOrInsert(
            ['customer_type_id' => $typeId, 'requirement_type' => 'document', 'document_type_id' => $documentId],
            ['is_required' => $required, 'sort' => $sort, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
