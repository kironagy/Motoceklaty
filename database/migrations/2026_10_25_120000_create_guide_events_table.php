<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guide_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40)->index();
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable()->index();
            $table->string('actor_name');
            $table->string('subject_type')->nullable()->index();
            $table->string('subject_id')->nullable()->index();
            $table->string('subject_label');
            $table->text('description');
            $table->json('changes')->nullable();
            $table->json('context')->nullable();
            $table->string('legacy_source', 50)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->unique(['legacy_source', 'legacy_id']);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_events');
    }
};
