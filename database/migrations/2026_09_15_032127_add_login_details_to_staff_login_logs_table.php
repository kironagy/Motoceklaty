<?php

use Illuminate\Database\Migrations\Migration;

// Columns now live in 2026_09_15_025449_create_staff_login_logs_table; kept so the
// migrations table stays in sync with production where this already ran.
return new class extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
