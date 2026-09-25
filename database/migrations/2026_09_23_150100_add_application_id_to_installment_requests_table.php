<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** T14: links a legacy InstallmentRequest back to the Application that projected it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            $table->foreignId('application_id')->nullable()->after('id')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('application_id');
        });
    }
};
