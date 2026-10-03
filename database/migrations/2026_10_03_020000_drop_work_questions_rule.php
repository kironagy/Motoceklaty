<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-10-03: no job lists - the applicant's work is read by the AI
 * (WorkClassifier). The sector / private / trade word lists are gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('eligibility_rules')->where('rule_type', 'work_questions')->delete();
    }

    public function down(): void
    {
    }
};
