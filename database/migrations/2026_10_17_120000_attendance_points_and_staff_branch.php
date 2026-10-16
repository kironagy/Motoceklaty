<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Staff belong to a branch; attendance is checked against the branch location, not per-staff lat/long.
 * - The separate finance permission (staff.finance_role) is gone: dashboard roles decide everything.
 * - Attendance points: every check-in earns a green / red / black point; red and black carry a
 *   deduction that greens can cancel, and greens can become a bonus (attendance_point_exchanges).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('warehouse_id')->constrained('branches')->nullOnDelete();
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->string('point', 10)->nullable()->after('deduction_amount');          // green | red | black
            $table->string('point_status', 10)->nullable()->after('point');               // active | used | canceled
            $table->decimal('point_amount', 10, 2)->nullable()->after('point_status');    // the deduction the point carried
            $table->unsignedBigInteger('point_exchange_id')->nullable()->after('point_amount')->index();
        });

        Schema::create('attendance_point_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('kind', 20);                                 // cancel_red | cancel_black | bonus
            $table->foreignId('canceled_attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->unsignedInteger('greens_used');
            $table->decimal('amount', 10, 2)->default(0);              // deduction refunded, or bonus given
            $table->foreignId('bonus_id')->nullable()->constrained('employee_bonuses')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        $this->moveLocationsToBranches();

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['finance_role', 'attendance_latitude', 'attendance_longitude']);
        });
    }

    /**
     * Staff take the branch of their showroom. A branch without a location takes the location its
     * staff used to check in from (they all share one point per showroom).
     */
    private function moveLocationsToBranches(): void
    {
        DB::statement('UPDATE staff s JOIN warehouses w ON w.id = s.warehouse_id SET s.branch_id = w.branch_id WHERE s.branch_id IS NULL AND w.branch_id IS NOT NULL');

        // Staff whose showroom has no branch (an expense centre) but who checked in from a branch's point.
        $located = DB::table('staff')->whereNotNull('branch_id')->whereNotNull('attendance_latitude')
            ->get(['branch_id', 'attendance_latitude', 'attendance_longitude']);

        foreach (DB::table('staff')->whereNull('branch_id')->whereNotNull('attendance_latitude')->get() as $s) {
            $match = $located->first(fn ($l) => (string) $l->attendance_latitude === (string) $s->attendance_latitude
                && (string) $l->attendance_longitude === (string) $s->attendance_longitude);

            if ($match) {
                DB::table('staff')->where('id', $s->id)->update(['branch_id' => $match->branch_id]);
            }
        }

        foreach (DB::table('branches')->whereNull('latitude')->orWhereNull('longitude')->pluck('id') as $branchId) {
            $point = DB::table('staff')
                ->where('branch_id', $branchId)
                ->whereNotNull('attendance_latitude')->whereNotNull('attendance_longitude')
                ->select('attendance_latitude', 'attendance_longitude', DB::raw('COUNT(*) AS n'))
                ->groupBy('attendance_latitude', 'attendance_longitude')
                ->orderByDesc('n')
                ->first();

            if ($point) {
                DB::table('branches')->where('id', $branchId)->update([
                    'latitude' => $point->attendance_latitude,
                    'longitude' => $point->attendance_longitude,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('finance_role')->nullable()->after('is_company_employee');
            $table->decimal('attendance_latitude', 10, 7)->nullable()->after('departure_time');
            $table->decimal('attendance_longitude', 10, 7)->nullable()->after('attendance_latitude');
        });

        Schema::dropIfExists('attendance_point_exchanges');

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['point', 'point_status', 'point_amount', 'point_exchange_id']);
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
