<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-10-02: the job words behind WorkClassification - which work may
 * be government (asked "حكومي ولا خاص؟"), which words already say private,
 * and which trades need no insurance question. Edited by the owner like
 * the other eligibility rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('eligibility_rules')->where('rule_type', 'work_questions')->exists()) {
            return;
        }

        DB::table('eligibility_rules')->insert([
            'customer_type_id' => null,
            'rule_type' => 'work_questions',
            'params' => json_encode([
                'sector_words' => 'مدرس، معلم، معلمه، ممرض، ممرضه، دكتور، دكتوره، طبيب، طبيبه، مهندس، مهندسه، محاسب، محاسبه، موظف، موظفه، اداري، اداريه، سكرتير، سكرتيره، اخصائي، اخصائيه، صيدلي، صيدلانيه، فني معمل، كاتب، مستشفي، مدرسه، وحده صحيه، بريد، سكه حديد',
                'private_words' => 'خاص، خاصه، خصوصي، شركه، مكتب، عياده، صيدليه، ورشه، مطعم، كافيه، قهوه، مصنع، سنتر، لغات، انترناشونال، مول، فندق، سوبر ماركت، هايبر، مزرعه، مخبز، فرن',
                'trade_words' => 'سباك، نجار، نقاش، كهربائي، كهربايي، حداد، ميكانيكي، مبيض، محاره، سمكري، ترزي، حلاق، فران، خباز، عجان، خراط، صنايعي، حرفي، اسطي، سواق، سايق، دليفري، ديليفري، طيار، مندوب، طلبات، اوبر، كريم، اندرايف، ديدي، مرسول، توصيل، تاجر، بياع، فلاح، مزارع، مقاول، شيف، طباخ، كوافير',
            ], JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('eligibility_rules')->where('rule_type', 'work_questions')->delete();
    }
};
