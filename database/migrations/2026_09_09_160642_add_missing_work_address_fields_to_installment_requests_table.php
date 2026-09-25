<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {

            if (!Schema::hasColumn('installment_requests', 'work_street')) {
                $table->string('work_street')
                    ->nullable()
                    ->after('work_building_number');
            }

            if (!Schema::hasColumn('installment_requests', 'work_landmark')) {
                $table->string('work_landmark')
                    ->nullable()
                    ->after('work_area');
            }

            if (!Schema::hasColumn('installment_requests', 'work_floor')) {
                $table->string('work_floor')
                    ->nullable()
                    ->after('work_landmark');
            }

            if (!Schema::hasColumn('installment_requests', 'work_apartment')) {
                $table->string('work_apartment')
                    ->nullable()
                    ->after('work_floor');
            }

        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {

            $columns = [];

            foreach ([
                'work_street',
                'work_landmark',
                'work_floor',
                'work_apartment',
            ] as $column) {
                if (Schema::hasColumn('installment_requests', $column)) {
                    $columns[] = $column;
                }
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
