<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan task 3.3: fill the structured half for the memories whose prose the
 * code already enforces, so the two halves start out saying the same thing.
 * Only touches rows that have no rules yet - a staff edit is never
 * overwritten by a redeploy.
 */
return new class extends Migration
{
    private array $rules = [
        'الفئات الممنوعة' => [
            /*
             * مقصود: «معاون» و«أمين» لوحدهم مش هنا. الكود بيفحصهم مع سياق
             * الداخلية (أمين شرطة / معاون في الداخلية) عشان «أمين مخزن» و
             * «معاون مدير مبيعات» ميترفضوش بالغلط - والقاعدة هنا بتتفحص
             * بـ str_contains مباشر من غير السياق ده.
             */
            'banned_professions' => 'ضابط, أمين شرطة, محام, قاضي, نيابة',
        ],
        'الدليفري' => [
            'job_category' => 'delivery',
            'job_keywords' => 'دليفري, طلبات, اوبر, أوبر, uber, اندرايف, indrive, مرسول',
            'required_documents' => 'driver_license, trips_screenshot',
        ],
        'التاكسي' => [
            'job_category' => 'taxi_owner',
            'job_keywords' => 'تاكسي, تاكس',
            'required_documents' => 'driver_license, vehicle_license',
        ],
        'الميكروباص' => [
            'job_category' => 'taxi_owner',
            'job_keywords' => 'ميكروباص, ميكروباس',
            'required_documents' => 'driver_license, vehicle_license',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ai_memories') || ! Schema::hasColumn('ai_memories', 'rules')) {
            return;
        }

        foreach ($this->rules as $title => $rules) {
            DB::table('ai_memories')
                ->where('title', $title)
                ->whereNull('rules')
                ->update(['rules' => json_encode($rules, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_memories') || ! Schema::hasColumn('ai_memories', 'rules')) {
            return;
        }

        DB::table('ai_memories')
            ->whereIn('title', array_keys($this->rules))
            ->update(['rules' => null]);
    }
};
