<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('installment_requests', 'guarantor_phone')) {
                $table->string('guarantor_phone')->nullable()->after('guarantor_national_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            if (Schema::hasColumn('installment_requests', 'guarantor_phone')) {
                $table->dropColumn('guarantor_phone');
            }
        });
    }
};
