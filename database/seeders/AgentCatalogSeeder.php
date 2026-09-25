<?php

namespace Database\Seeders;

use App\Models\ApplicationRequirement;
use App\Models\Branch;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\EligibilityRule;
use App\Models\RequirementField;
use Illuminate\Database\Seeder;

/**
 * Starter catalog so the agent can be exercised end to end (agent:readiness,
 * agent:evaluate). Branch data is a placeholder - replace with the real
 * branch(es) from the dashboard before AGENT_ENABLED goes true. Customer
 * types, fields, documents, and the 21-62 age rule reflect the owner's
 * brief; refine per-type document requirements from the dashboard as needed.
 */
class AgentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        Branch::firstOrCreate(
            ['name' => 'الفرع الرئيسي (بيانات تجريبية - عدّلها)'],
            [
                'governorate' => 'cairo',
                'city' => 'القاهرة',
                'address' => 'عنوان تجريبي - يُستبدل ببيانات الفرع الحقيقية قبل التشغيل الفعلي',
                'phones' => ['01000000000'],
                'is_active' => true,
                'sort' => 0,
            ]
        );

        $fullName = RequirementField::firstOrCreate(
            ['key' => 'full_name'],
            ['label' => 'الاسم بالكامل', 'data_type' => 'person_name', 'scope' => 'application', 'is_active' => true]
        );
        $phone = RequirementField::firstOrCreate(
            ['key' => 'phone'],
            ['label' => 'رقم التليفون', 'data_type' => 'phone', 'scope' => 'application', 'is_active' => true]
        );
        $nationalId = RequirementField::firstOrCreate(
            ['key' => 'national_id'],
            ['label' => 'الرقم القومي', 'data_type' => 'national_id', 'scope' => 'customer', 'is_sensitive' => true, 'is_active' => true]
        );
        $address = RequirementField::firstOrCreate(
            ['key' => 'address'],
            ['label' => 'عنوان السكن', 'data_type' => 'address', 'scope' => 'application', 'is_active' => true]
        );

        $nationalIdDoc = DocumentType::firstOrCreate(
            ['key' => 'national_id_front'],
            [
                'label' => 'بطاقة الرقم القومي',
                'description_for_ai' => 'صورة واضحة لوش بطاقة الرقم القومي',
                'accepted_mimes' => ['image/jpeg', 'image/png', 'application/pdf'],
                'extraction_fields' => ['full_name', 'national_id'],
                'validation_rules' => [
                    ['rule_type' => 'matches_application_field', 'params' => ['extracted_field' => 'full_name', 'stored_field' => 'full_name', 'issue_code' => 'NAME_MISMATCH']],
                    ['rule_type' => 'matches_application_field', 'params' => ['extracted_field' => 'national_id', 'stored_field' => 'national_id', 'issue_code' => 'ID_MISMATCH']],
                ],
                'is_active' => true,
            ]
        );
        $salarySlip = DocumentType::firstOrCreate(
            ['key' => 'salary_slip'],
            [
                'label' => 'مفردات المرتب',
                'description_for_ai' => 'مفردات مرتب حديثة تثبت إن العميل موظف متأمن عليه',
                'accepted_mimes' => ['image/jpeg', 'image/png', 'application/pdf'],
                'extraction_fields' => ['full_name'],
                'is_active' => true,
            ]
        );
        $pensionStatement = DocumentType::firstOrCreate(
            ['key' => 'pension_statement'],
            [
                'label' => 'كشف معاش',
                'description_for_ai' => 'كشف معاش يثبت إن العميل على المعاش',
                'accepted_mimes' => ['image/jpeg', 'image/png', 'application/pdf'],
                'extraction_fields' => ['full_name'],
                'is_active' => true,
            ]
        );
        $incomeProof = DocumentType::firstOrCreate(
            ['key' => 'self_employed_income_proof'],
            [
                'label' => 'إثبات دخل (عمل حر)',
                'description_for_ai' => 'أي مستند يثبت دخل العميل كعامل حر - سجل تجاري، بطاقة ضريبية، أو كشف حساب بنكي',
                'accepted_mimes' => ['image/jpeg', 'image/png', 'application/pdf'],
                'extraction_fields' => ['full_name'],
                'is_active' => true,
            ]
        );

        $employee = CustomerType::firstOrCreate(
            ['key' => 'employee'],
            ['label' => 'موظف متأمن عليه', 'legacy_work_status' => 'employee', 'is_active' => true, 'sort' => 1]
        );
        $selfEmployed = CustomerType::firstOrCreate(
            ['key' => 'self_employed'],
            ['label' => 'عامل حر', 'legacy_work_status' => 'self_employed', 'is_active' => true, 'sort' => 2]
        );
        $pension = CustomerType::firstOrCreate(
            ['key' => 'pension'],
            ['label' => 'على المعاش', 'legacy_work_status' => 'pension', 'is_active' => true, 'sort' => 3]
        );

        $commonFields = [$fullName, $phone, $nationalId, $address];

        foreach ([$employee, $selfEmployed, $pension] as $type) {
            $sort = 0;

            foreach ($commonFields as $field) {
                ApplicationRequirement::firstOrCreate([
                    'customer_type_id' => $type->id,
                    'requirement_type' => 'field',
                    'requirement_field_id' => $field->id,
                ], ['is_required' => true, 'sort' => $sort++]);
            }

            ApplicationRequirement::firstOrCreate([
                'customer_type_id' => $type->id,
                'requirement_type' => 'document',
                'document_type_id' => $nationalIdDoc->id,
            ], ['is_required' => true, 'sort' => $sort++]);
        }

        ApplicationRequirement::firstOrCreate([
            'customer_type_id' => $employee->id,
            'requirement_type' => 'document',
            'document_type_id' => $salarySlip->id,
        ], ['is_required' => true, 'sort' => 10]);

        ApplicationRequirement::firstOrCreate([
            'customer_type_id' => $pension->id,
            'requirement_type' => 'document',
            'document_type_id' => $pensionStatement->id,
        ], ['is_required' => true, 'sort' => 10]);

        ApplicationRequirement::firstOrCreate([
            'customer_type_id' => $selfEmployed->id,
            'requirement_type' => 'document',
            'document_type_id' => $incomeProof->id,
        ], ['is_required' => true, 'sort' => 10]);

        // Confirmed against production data (2026-09-23): 21-62, not the
        // owner's initial 21-60 recollection - see ProductionMemoryImportSeeder.
        EligibilityRule::updateOrCreate(
            ['customer_type_id' => null, 'rule_type' => 'age_range'],
            ['params' => ['min' => 21, 'max' => 62], 'is_active' => true]
        );
    }
}
