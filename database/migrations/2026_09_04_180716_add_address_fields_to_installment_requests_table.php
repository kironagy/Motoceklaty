<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('installment_requests', 'work_branch_street')) {
            Schema::table('installment_requests', function (Blueprint $table) {
                $table->string('work_branch_street')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('installment_requests', 'work_branch_street')) {
            Schema::table('installment_requests', function (Blueprint $table) {
                $table->dropColumn('work_branch_street');
            });
        }
    }
};
