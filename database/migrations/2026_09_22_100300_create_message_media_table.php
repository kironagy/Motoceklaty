<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('whatsapp_messages')->cascadeOnDelete();
            $table->string('media_type');
            $table->string('mime');
            // DEC-18: new media goes on the private 'local' disk.
            $table->string('disk')->default('local');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256')->nullable();
            $table->string('original_filename')->nullable();
            $table->json('analysis')->nullable();
            $table->timestamps();

            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_media');
    }
};
