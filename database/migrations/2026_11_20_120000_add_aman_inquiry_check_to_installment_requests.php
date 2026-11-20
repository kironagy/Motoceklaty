<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-20: "متابعة الاستعلام" in the Aman extension reads each under-inquiry request's
// status off the Aman portal - when it last looked and what Aman said.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            $table->timestamp('aman_checked_at')->nullable()->after('staff_assigned_at');
            $table->string('aman_check_text', 500)->nullable()->after('aman_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            $table->dropColumn(['aman_checked_at', 'aman_check_text']);
        });
    }
};
