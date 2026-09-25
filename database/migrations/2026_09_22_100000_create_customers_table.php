<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_bot_id')->constrained()->cascadeOnDelete();
            $table->string('jid');
            $table->string('lid_jid')->nullable();
            $table->string('phone')->nullable();
            $table->string('push_name')->nullable();
            $table->timestamps();

            // DEC-09: customer identity is scoped per bot.
            $table->unique(['whatsapp_bot_id', 'jid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
