<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('installment_requests', 'request_type')) {
            Schema::table('installment_requests', function (Blueprint $table) {
                $table->string('request_type')->default('normal')->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('installment_requests', 'request_type')) {
            Schema::table('installment_requests', function (Blueprint $table) {
                $table->dropIndex(['request_type']);
                $table->dropColumn('request_type');
            });
        }
    }
};
