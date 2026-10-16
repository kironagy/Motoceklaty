<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - dashboard_settings: owner settings edited from the dashboard (point values, sidebar layout).
 * - attendance_points: points get their own table, so the admin can add points that have no
 *   attendance day behind them (a bonus of N greens, a deduction of N reds / blacks).
 * - attendances.overtime_minutes: minutes worked after the official leave time, also past midnight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('attendance_id')->nullable()->unique()->constrained('attendances')->cascadeOnDelete();
            $table->string('color', 10);                        // green | red | black
            $table->decimal('amount', 10, 2)->default(0);      // deduction of a red / black, fixed when it was given
            $table->date('earned_on');
            $table->string('status', 10)->default('active');   // active | used | canceled
            $table->unsignedBigInteger('exchange_id')->nullable()->index();
            $table->string('source', 12)->default('attendance'); // attendance | manual
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->index(['staff_id', 'earned_on']);
        });

        // Points written on attendance rows so far move to the new table.
        DB::statement("INSERT INTO attendance_points (staff_id, attendance_id, color, amount, earned_on, status, exchange_id, source, created_at, updated_at)
            SELECT staff_id, id, point, COALESCE(point_amount, 0), attendance_date, COALESCE(point_status, 'active'), point_exchange_id, 'attendance', NOW(), NOW()
            FROM attendances WHERE point IS NOT NULL");

        Schema::table('attendance_point_exchanges', function (Blueprint $table) {
            $table->foreignId('canceled_point_id')->nullable()->after('kind')->constrained('attendance_points')->nullOnDelete();
        });

        DB::statement('UPDATE attendance_point_exchanges e JOIN attendance_points p ON p.attendance_id = e.canceled_attendance_id SET e.canceled_point_id = p.id');

        Schema::table('attendance_point_exchanges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canceled_attendance_id');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['point_exchange_id']);
            $table->dropColumn(['point', 'point_status', 'point_amount', 'point_exchange_id']);
            $table->unsignedInteger('overtime_minutes')->nullable()->after('late_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('overtime_minutes');
            $table->string('point', 10)->nullable()->after('deduction_amount');
            $table->string('point_status', 10)->nullable()->after('point');
            $table->decimal('point_amount', 10, 2)->nullable()->after('point_status');
            $table->unsignedBigInteger('point_exchange_id')->nullable()->after('point_amount')->index();
        });

        Schema::table('attendance_point_exchanges', function (Blueprint $table) {
            $table->foreignId('canceled_attendance_id')->nullable()->after('kind')->constrained('attendances')->nullOnDelete();
            $table->dropConstrainedForeignId('canceled_point_id');
        });

        Schema::dropIfExists('attendance_points');
        Schema::dropIfExists('dashboard_settings');
    }
};
