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
    Schema::table('installment_requests', function (Blueprint $table) {
        $table->string('free_work_name')->nullable();
        $table->text('free_work_address')->nullable();
    });
}

public function down()
{
    Schema::table('installment_requests', function (Blueprint $table) {
        $table->dropColumn(['free_work_name', 'free_work_address']);
    });
}

};
