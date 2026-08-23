<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            foreach (['home', 'work'] as $prefix) {
                foreach (['governorate', 'city', 'area', 'street', 'building', 'floor', 'apartment', 'landmark'] as $component) {
                    $column = "{$prefix}_{$component}";

                    if (! Schema::hasColumn('installment_requests', $column)) {
                        $table->string($column)->nullable();
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            foreach (['home', 'work'] as $prefix) {
                foreach (['governorate', 'city', 'area', 'street', 'building', 'floor', 'apartment', 'landmark'] as $component) {
                    $column = "{$prefix}_{$component}";

                    if (Schema::hasColumn('installment_requests', $column)) {
                        $table->dropColumn($column);
                    }
                }
            }
        });
    }
};
