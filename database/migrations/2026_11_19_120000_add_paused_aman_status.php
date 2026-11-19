<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owner 2026-11-19: new request status "متوقف امان" (paused_aman), parked on hold-aman@ after 48 h.
return new class extends Migration
{
    private const STATUSES = "'new_request','new','pending','work_check','approved','rejected','paused','transferred','delivered','canceled'";

    public function up(): void
    {
        DB::statement("ALTER TABLE installment_requests MODIFY status ENUM(".self::STATUSES.",'paused_aman') NOT NULL DEFAULT 'new'");
    }

    public function down(): void
    {
        DB::table('installment_requests')->where('status', 'paused_aman')->update(['status' => 'paused']);
        DB::statement("ALTER TABLE installment_requests MODIFY status ENUM(".self::STATUSES.") NOT NULL DEFAULT 'new'");
    }
};
