<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    DB::statement("ALTER TABLE installment_requests MODIFY status ENUM(
        'pending',
        'needs_more_info',
        'approved',
        'rejected',
        'paused',
        'new',
        'transferred',
        'work_check',       -- استعلام عمل
        'delivered',        -- استلم المكنة
        'canceled'          -- الطلب ملغي
    ) DEFAULT 'pending'");
}

public function down()
{
    DB::statement("ALTER TABLE installment_requests MODIFY status ENUM(
        'pending',
        'needs_more_info',
        'approved',
        'rejected',
        'paused',
        'new',
        'transferred'
    ) DEFAULT 'pending'");
}
};
