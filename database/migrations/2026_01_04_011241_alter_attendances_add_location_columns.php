<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // موقع تسجيل الحضور
            $table->decimal('lat', 10, 7)->nullable()->after('checked_in_at');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');

            // المسافة من الموقع المسموح (بالمتر)
            $table->integer('distance_m')->nullable()->after('lng');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng', 'distance_m']);
        });
    }
};

