<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Intentionally removes the retired feature's complete log and storage.
        Schema::dropIfExists('guide_events');
    }

    public function down(): void
    {
        // Deleted logs cannot be restored by rolling back code.
    }
};
