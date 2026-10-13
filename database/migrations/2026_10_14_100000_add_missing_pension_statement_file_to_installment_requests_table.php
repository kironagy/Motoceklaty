<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The live database has installment_requests.pension_statement_file (the
 * website application form writes it), but no migration ever created it on
 * that table - only on deliveries. Fresh installs/test DBs failed the form.
 * Guarded, so existing databases are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('installment_requests', 'pension_statement_file')) {
                $table->string('pension_statement_file')->nullable()->after('salary_slip_file');
            }
        });
    }

    public function down(): void
    {
        // Not dropped: on existing databases the column predates this migration.
    }
};
