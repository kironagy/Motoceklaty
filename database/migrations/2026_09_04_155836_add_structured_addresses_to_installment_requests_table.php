<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columns = [
        'applicant_building_number',
        'applicant_street',
        'applicant_branch_street',
        'applicant_governorate',
        'applicant_area',
        'applicant_landmark',
        'applicant_floor',
        'applicant_apartment',
        'work_building_number',
    ];

    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (! Schema::hasColumn('installment_requests', $column)) {
                    $table->string($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (Schema::hasColumn('installment_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
