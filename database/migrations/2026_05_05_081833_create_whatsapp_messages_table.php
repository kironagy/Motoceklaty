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
     Schema::create('whatsapp_messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('whatsapp_conversation_id');
    $table->string('direction'); // incoming / outgoing
    $table->text('message')->nullable();
    $table->json('payload')->nullable();
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
