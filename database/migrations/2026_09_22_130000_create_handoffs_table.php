<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->string('reason');
            $table->string('note', 300)->nullable();
            $table->string('source'); // ai|system|staff
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->index(['conversation_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handoffs');
    }
};
