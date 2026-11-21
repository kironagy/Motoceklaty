<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owner 2026-11-21: new request status "تم التعديل" (edited).
return new class extends Migration
{
    private const STATUSES = "'new_request','new','pending','work_check','approved','rejected','paused','transferred','delivered','canceled','paused_aman'";

    public function up(): void
    {
        DB::statement("ALTER TABLE installment_requests MODIFY status ENUM(".self::STATUSES.",'edited') NOT NULL DEFAULT 'new'");
    }

    public function down(): void
    {
        DB::table('installment_requests')->where('status', 'edited')->update(['status' => 'transferred']);
        DB::statement("ALTER TABLE installment_requests MODIFY status ENUM(".self::STATUSES.") NOT NULL DEFAULT 'new'");
    }
};
