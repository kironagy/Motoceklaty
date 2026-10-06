<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversation 206 (2026-10-05): one application held the father's pension
 * (2,000), then the brother's ID - eligibility refused the brother for his
 * father's income three times. An application now records whose it is:
 * {"who": "customer"} or {"who": "other", "relation": "أخوه", "quote": "..."}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->json('applicant')->nullable()->after('customer_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('applicant');
        });
    }
};
