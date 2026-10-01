<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-10-02: every عامل حر the bot submitted showed as "صاحب نشاط".
 * In the deliveries form `self_employed` is صاحب نشاط and `no_income_proof`
 * is دخل حر (with the free-work name and income-proof images). The عامل حر
 * customer type pointed at self_employed; only business_owner should.
 * Existing requests are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customer_types')->where('key', 'self_employed')
            ->update(['legacy_work_status' => 'no_income_proof', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('customer_types')->where('key', 'self_employed')
            ->update(['legacy_work_status' => 'self_employed', 'updated_at' => now()]);
    }
};
