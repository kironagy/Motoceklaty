<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_login_logs')) {
            return;
        }

        Schema::create('staff_login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_hash', 64)->nullable()->index();
            $table->string('browser')->nullable();
            $table->string('platform')->nullable();
            $table->string('device_type')->nullable();
            $table->timestamp('logged_in_at')->nullable()->index();
            $table->timestamps();

            $table->index(['staff_id', 'logged_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_login_logs');
    }
};
