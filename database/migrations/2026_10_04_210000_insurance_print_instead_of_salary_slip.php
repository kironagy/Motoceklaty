<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owner 2026-10-04: an employee whose company gives no salary slip applies
// with his social-insurance print (برنت التأمينات); only with neither does
// he go the card-only (free work) route. The print is a document type of
// its own - App\Domain\Documents\DocumentEquivalents makes it count for the
// salary slip - so no employee loses a required paper.
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('document_types')->where('key', 'insurance_print')->exists()) {
            DB::table('document_types')->insert([
                'key' => 'insurance_print',
                'label' => 'برنت التأمينات',
                'description_for_ai' => 'برنت تأمينات / بيان تأميني / كشف مدد اشتراك من التأمينات الاجتماعية (ورقة أو سكرين من موقع/تطبيق التأمينات) فيه اسم المؤمن عليه ورقمه القومي وجهة العمل. '
                    .'full_name = اسم المؤمن عليه. national_id = الرقم القومي. employer_name = اسم جهة العمل / صاحب العمل. insurance_number = الرقم التأميني. '
                    .'insurance_start_date = تاريخ بداية الاشتراك مع جهة العمل الحالية. monthly_income = الأجر التأميني لو مكتوب.',
                'accepted_mimes' => json_encode(['image/jpeg', 'image/png', 'application/pdf']),
                'extraction_fields' => json_encode(['full_name']),
                'optional_fields' => json_encode(['national_id', 'employer_name', 'insurance_number', 'monthly_income']),
                'validation_rules' => json_encode([
                    ['rule_type' => 'matches_application_field', 'params' => ['extracted_field' => 'full_name', 'stored_field' => 'full_name', 'issue_code' => 'NAME_MISMATCH', 'match' => 'name_tokens']],
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The showroom note said "no salary slip at all = hand him to a colleague".
        DB::table('business_memories')->where('key', 'employment_category_docs')->get()->each(function ($memory) {
            $content = str_replace(
                'لو مش هيقدر يجيب مفردات خالص، حوّله لزميل يشوف حالته.',
                'لو الشركة مش بتطلع مفردات: برنت التأمينات بدالها. لو مش هيقدر يجيب ولا ده ولا ده خالص: يقدّم بالبطاقة بس على شروط العمل الحر (آخر حل).',
                (string) $memory->content
            );
            DB::table('business_memories')->where('id', $memory->id)->update(['content' => $content, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        DB::table('document_types')->where('key', 'insurance_print')->delete();
    }
};
