<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE installment_requests
            MODIFY status ENUM(
                'new_request',
                'new',
                'pending',
                'work_check',
                'approved',
                'rejected',
                'paused',
                'transferred',
                'delivered',
                'canceled'
            ) NOT NULL DEFAULT 'new'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE installment_requests
            MODIFY status ENUM(
                'new',
                'pending',
                'work_check',
                'approved',
                'rejected',
                'paused',
                'transferred',
                'delivered',
                'canceled'
            ) NOT NULL DEFAULT 'new'
        ");
    }
};

