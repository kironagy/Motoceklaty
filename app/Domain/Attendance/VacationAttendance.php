<?php

namespace App\Domain\Attendance;

use App\Models\Attendance;
use App\Models\EmployeeVacationRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * An approved vacation shows in the attendance: each of its days (except weekly days off) becomes a
 * "vacation" day, which also lifts an absence point the 3 PM run gave that day. Rejecting, deleting
 * or moving the vacation puts back only the days it made - a day with a check-in is never touched.
 */
class VacationAttendance
{
    public static function marker(EmployeeVacationRequest $request): string
    {
        return "إجازة معتمدة #{$request->id}";
    }

    public static function sync(EmployeeVacationRequest $request, bool $deleted = false): void
    {
        // Undo what this vacation made: its days go back to absent when they are over, else away.
        Attendance::query()
            ->where('notes', static::marker($request))
            ->whereNull('check_in_at')
            ->get()
            ->each(function (Attendance $day) {
                if ($day->attendance_date->lt(today()) || ($day->attendance_date->isToday() && now()->hour >= AbsenceMarker::CUTOFF_HOUR)) {
                    $day->update(['status' => 'absent', 'notes' => null]);
                } else {
                    $day->delete();
                }
            });

        if ($deleted || $request->status !== 'approved' || ! $request->from_date || ! $request->to_date) {
            return;
        }

        $offDays = AttendancePoints::offDays();

        foreach (CarbonPeriod::create(Carbon::parse($request->from_date), Carbon::parse($request->to_date)) as $day) {
            if (in_array($day->dayOfWeek, $offDays, true)) {
                continue;
            }

            $attendance = Attendance::query()
                ->where('staff_id', $request->staff_id)
                ->whereDate('attendance_date', $day->toDateString())
                ->first();

            if ($attendance?->check_in_at) {
                continue;
            }

            if ($attendance) {
                $attendance->update(['status' => 'vacation', 'notes' => static::marker($request)]);
            } else {
                Attendance::create([
                    'staff_id' => $request->staff_id,
                    'attendance_date' => $day->toDateString(),
                    'status' => 'vacation',
                    'notes' => static::marker($request),
                ]);
            }
        }
    }
}
