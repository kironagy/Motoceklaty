<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
  public function up(): void
{
    Schema::table('staff', function (Blueprint $table) {
        $table->boolean('is_company_employee')
            ->default(false)
            ->after('is_bot');

        $table->foreignId('installment_system_id')
            ->nullable()
            ->after('is_company_employee')
            ->constrained('installment_systems')
            ->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('staff', function (Blueprint $table) {
        $table->dropForeign(['installment_system_id']);
        $table->dropColumn([
            'installment_system_id',
            'is_company_employee',
        ]);
    });
}
};
