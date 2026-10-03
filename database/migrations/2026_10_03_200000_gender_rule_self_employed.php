<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner 2026-10-03: "مفيش بنت بتقدم دخل حر" - no woman applies as self_employed (دخل حر). */
return new class extends Migration
{
    public function up(): void
    {
        $typeId = DB::table('customer_types')->where('key', 'self_employed')->value('id');

        if ($typeId === null || DB::table('eligibility_rules')->where('rule_type', 'gender')->exists()) {
            return;
        }

        DB::table('eligibility_rules')->insert([
            'rule_type' => 'gender',
            'customer_type_id' => $typeId,
            'params' => json_encode([
                'excluded' => 'female',
                'message' => 'للأسف جهات التمويل مش بتقبل تقديم الآنسات والسيدات بدخل حر - التقسيط محتاج شغل متأمن عليه أو نشاط باسمك بأوراقه أو معاش. والكاش متاح في الفرع.',
            ], JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('eligibility_rules')->where('rule_type', 'gender')->delete();
    }
};
