<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner review of the live chats (2026-09-26):
 * - the back of the national ID is required from every customer, read and
 *   checked (same national ID, not expired) - it was rejected as unsupported;
 * - the apartment number is its own field ("شقة 41" was saved as the floor);
 * - a tax card must carry the same name as the ID;
 * - the last installment must fall by age 64.
 * Idempotent: rows are matched by key.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Configuration rows only exist on a seeded database (live/local);
        // a fresh test database builds its own.
        if (! DB::table('document_types')->where('key', 'national_id_front')->exists()) {
            return;
        }

        $now = now();

        // --- ID back -------------------------------------------------------
        $back = [
            'label' => 'ضهر بطاقة الرقم القومي',
            'description_for_ai' => 'ضهر بطاقة الرقم القومي المصرية: الوش اللي من غير صورة شخصية، فيه المهنة والحالة الاجتماعية والديانة '
                .'و"البطاقة سارية حتى" والرقم القومي ١٤ رقم تحت. national_id = الرقم القومي ١٤ رقم زي ما هو مطبوع. '
                .'id_expiry_date = تاريخ "البطاقة سارية حتى". occupation = المهنة المكتوبة. marital_status = الحالة الاجتماعية.',
            'accepted_mimes' => json_encode(['image/jpeg', 'image/png', 'image/webp']),
            'extraction_fields' => json_encode(['national_id', 'id_expiry_date']),
            'optional_fields' => json_encode(['occupation', 'marital_status']),
            'validation_rules' => json_encode([
                ['rule_type' => 'matches_application_field', 'params' => ['extracted_field' => 'national_id', 'stored_field' => 'national_id', 'issue_code' => 'ID_MISMATCH']],
                ['rule_type' => 'not_past', 'params' => ['date_field' => 'id_expiry_date', 'issue_code' => 'ID_EXPIRED']],
            ], JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'updated_at' => $now,
        ];

        $front = DB::table('document_types')->where('key', 'national_id_front')->first();

        if ($front && $front->accepted_mimes) {
            $back['accepted_mimes'] = $front->accepted_mimes;
        }

        if (DB::table('document_types')->where('key', 'national_id_back')->exists()) {
            DB::table('document_types')->where('key', 'national_id_back')->update($back);
        } else {
            DB::table('document_types')->insert($back + ['key' => 'national_id_back', 'created_at' => $now]);
        }

        $backId = DB::table('document_types')->where('key', 'national_id_back')->value('id');

        // --- apartment field ---------------------------------------------
        $floor = DB::table('requirement_fields')->where('key', 'address_floor')->first();

        if ($floor) {
            DB::table('requirement_fields')->where('key', 'address_floor')->update([
                'description_for_ai' => 'رقم الدور بس (أرضي، الأول، التالت...). "شقة ٤١" رقم شقة مش دور - ده بيتسجل في address_apartment.',
                'updated_at' => $now,
            ]);

            $apartment = [
                'label' => 'رقم الشقة (السكن)',
                'data_type' => 'string',
                'scope' => $floor->scope,
                'is_sensitive' => false,
                'description_for_ai' => 'رقم الشقة زي ما العميل قاله. لو ساكن في بيت مستقل أو بيت عيلة من غير شقق، سجّل كلامه زي ما هو ("بيت").',
                'is_active' => true,
                'updated_at' => $now,
            ];

            DB::table('requirement_fields')->where('key', 'address_apartment')->exists()
                ? DB::table('requirement_fields')->where('key', 'address_apartment')->update($apartment)
                : DB::table('requirement_fields')->insert($apartment + ['key' => 'address_apartment', 'created_at' => $now]);
        }

        DB::table('requirement_fields')->where('key', 'address')->update([
            'label' => 'عنوان السكن (المحافظة والمنطقة والشارع)',
            'description_for_ai' => 'المحافظة + المنطقة/الحي/المدينة + اسم الشارع (ومتفرع من إيه لو قال). رقم العقار والدور والشقة والعلامة المميزة ليهم حقول لوحدهم.',
            'updated_at' => $now,
        ]);

        DB::table('requirement_fields')->where('key', 'work_address')->update([
            'label' => 'عنوان الشغل (المحافظة والمنطقة والشارع)',
            'description_for_ai' => 'المحافظة + المنطقة/الحي/المدينة + اسم الشارع. من غير دور ومن غير إيجار/تمليك.',
            'updated_at' => $now,
        ]);

        $apartmentId = DB::table('requirement_fields')->where('key', 'address_apartment')->value('id');
        $floorId = $floor?->id;

        // --- requirements for every customer type -------------------------
        foreach (DB::table('customer_types')->pluck('id') as $typeId) {
            $frontSort = DB::table('application_requirements')->where('customer_type_id', $typeId)
                ->where('document_type_id', $front?->id)->value('sort');

            if ($backId && ! DB::table('application_requirements')->where('customer_type_id', $typeId)->where('document_type_id', $backId)->exists()) {
                DB::table('application_requirements')->insert([
                    'customer_type_id' => $typeId, 'requirement_type' => 'document', 'document_type_id' => $backId,
                    'is_required' => true, 'sort' => ($frontSort ?? 0) + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $hasFloor = $floorId && DB::table('application_requirements')->where('customer_type_id', $typeId)->where('requirement_field_id', $floorId)->exists();

            if ($apartmentId && $hasFloor && ! DB::table('application_requirements')->where('customer_type_id', $typeId)->where('requirement_field_id', $apartmentId)->exists()) {
                DB::table('application_requirements')->insert([
                    'customer_type_id' => $typeId, 'requirement_type' => 'field', 'requirement_field_id' => $apartmentId,
                    'is_required' => true,
                    'sort' => (int) DB::table('application_requirements')->where('customer_type_id', $typeId)->where('requirement_field_id', $floorId)->value('sort') + 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // --- tax card: same name as the ID --------------------------------
        $tax = DB::table('document_types')->where('key', 'tax_card')->first();

        if ($tax) {
            $rules = json_decode((string) $tax->validation_rules, true) ?: [];
            $rules = array_values(array_filter($rules, fn ($r) => ($r['params']['issue_code'] ?? null) !== 'TAXPAYER_NAME_MISMATCH'));
            $rules[] = ['rule_type' => 'matches_application_field', 'params' => [
                'extracted_field' => 'taxpayer_name', 'stored_field' => 'full_name', 'issue_code' => 'TAXPAYER_NAME_MISMATCH', 'match' => 'name_tokens',
            ]];

            $required = array_values(array_unique(array_merge(json_decode((string) $tax->extraction_fields, true) ?: [], ['taxpayer_name'])));
            $optional = array_values(array_diff(json_decode((string) $tax->optional_fields, true) ?: [], ['taxpayer_name']));

            DB::table('document_types')->where('id', $tax->id)->update([
                'validation_rules' => json_encode($rules, JSON_UNESCAPED_UNICODE),
                'extraction_fields' => json_encode($required),
                'optional_fields' => json_encode($optional),
                'updated_at' => $now,
            ]);
        }

        // --- age at the last installment ------------------------------------
        foreach (DB::table('eligibility_rules')->where('rule_type', 'age_range')->get() as $rule) {
            $params = json_decode((string) $rule->params, true) ?: [];
            $params['max_age_at_end'] ??= 64;
            DB::table('eligibility_rules')->where('id', $rule->id)->update(['params' => json_encode($params), 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $backId = DB::table('document_types')->where('key', 'national_id_back')->value('id');
        $apartmentId = DB::table('requirement_fields')->where('key', 'address_apartment')->value('id');

        DB::table('application_requirements')->where('document_type_id', $backId)->delete();
        DB::table('application_requirements')->where('requirement_field_id', $apartmentId)->delete();
        DB::table('document_types')->where('key', 'national_id_back')->update(['is_active' => false]);
        DB::table('requirement_fields')->where('key', 'address_apartment')->update(['is_active' => false]);
    }
};
