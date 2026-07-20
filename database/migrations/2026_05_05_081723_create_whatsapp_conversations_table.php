<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::create('whatsapp_conversations', function (Blueprint $table) {
        $table->id();
        $table->foreignId('whatsapp_bot_id')->nullable();
        $table->string('phone');
        $table->string('status')->default('open');
        $table->foreignId('last_machine_id')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
