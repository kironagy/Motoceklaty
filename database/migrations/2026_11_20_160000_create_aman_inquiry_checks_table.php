<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-20: the extension control's daily report counts each employee's inquiry follow-ups,
// so every look-up is kept (installment_requests keeps only the last one).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aman_inquiry_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_request_id')->index();
            $table->foreignId('staff_id')->index();
            $table->string('text', 500);
            $table->string('status', 30)->nullable();
            $table->boolean('changed')->default(false);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aman_inquiry_checks');
    }
};
