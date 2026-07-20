<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::table('installment_requests', function (Blueprint $table) {
    $table->boolean('employee_editable')->default(false);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            //
        });
    }
};
