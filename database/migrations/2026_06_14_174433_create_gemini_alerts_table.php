<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gemini_alerts', function (Blueprint $table) {
            $table->id();

            $table->string('type')->index();
            $table->text('message');
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gemini_alerts');
    }
};
