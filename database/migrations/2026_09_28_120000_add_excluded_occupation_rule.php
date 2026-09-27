<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The owner (teach mode, lesson 33): government jobs and lawyers are refused
 * by the finance companies - the customer is told so plainly. Editable from
 * "شروط الأهلية" (words, message) and from teach mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('eligibility_rules')->where('rule_type', 'excluded_occupation')->exists()) {
            return;
        }

        DB::table('eligibility_rules')->insert([
            'customer_type_id' => null,
            'rule_type' => 'excluded_occupation',
            'params' => json_encode([
                'words' => 'حكومه، حكومي، حكوميه، امين شرطه، شرطه، ظابط، ضابط، امن مركزي، جيش، قوات مسلحه، عسكري، محامي، محاماه',
                'message' => 'للأسف جهات التمويل مش بتقبل الشغل في الجهات الحكومية ولا المحاماة، فالطلب هيترفض. لو حابب تشتري كاش أنا معاك.',
            ], JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('eligibility_rules')->where('rule_type', 'excluded_occupation')->delete();
    }
};
