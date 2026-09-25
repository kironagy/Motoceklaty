<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEC-21: installment_requests.work_status is a fixed legacy enum
 * ('employee'|'pension'|'self_employed'|'no_income_proof'). Rather than
 * assume a new customer_type.key always matches one of those values
 * (the plan explicitly warns not to invent legacy meaning), the owner
 * maps each customer type to its legacy equivalent from the dashboard.
 * Submission fails loudly (LEGACY_WORK_STATUS_NOT_MAPPED) instead of
 * guessing when this is left unset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_types', function (Blueprint $table) {
            $table->enum('legacy_work_status', ['employee', 'pension', 'self_employed', 'no_income_proof'])
                ->nullable()
                ->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('customer_types', function (Blueprint $table) {
            $table->dropColumn('legacy_work_status');
        });
    }
};
