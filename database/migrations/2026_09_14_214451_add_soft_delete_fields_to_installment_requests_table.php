<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// On production this file originally created staff_login_logs; it already ran there,
// so rewriting it only affects fresh databases.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('installment_requests', 'deleted_at')) {
                $table->softDeletes();
            }

            if (! Schema::hasColumn('installment_requests', 'deleted_by')) {
                $table->foreignId('deleted_by')->nullable()->constrained('staff')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            if (Schema::hasColumn('installment_requests', 'deleted_by')) {
                $table->dropConstrainedForeignId('deleted_by');
            }

            if (Schema::hasColumn('installment_requests', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
