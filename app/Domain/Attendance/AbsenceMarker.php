<?php

namespace App\Domain\Attendance;

use App\Models\Attendance;
use App\Models\EmployeeVacationRequest;
use App\Models\Staff;
use Carbon\Carbon;

/**
 * Absence without leave: at 3 PM, every employee who clocks in (is_showroom_employee, with an official
 * time) and hasn't checked in today - not on a weekly day off, not on an approved vacation - gets the
 * day marked absent, which is a black point (AttendancePoints::afterSave). A check-in later that day
 * turns the day back into a normal (late) one.
 */
class AbsenceMarker
{
    public const CUTOFF_HOUR = 15;

    /** @return int how many days were marked absent */
    public static function markDay(Carbon $date): int
    {
        $date = $date->copy()->startOfDay();

        if ($date->isFuture() || ($date->isToday() && now()->hour < self::CUTOFF_HOUR)) {
            return 0;
        }

        if (in_array($date->dayOfWeek, AttendancePoints::offDays(), true)) {
            return 0;
        }

        $marked = 0;

        $staff = Staff::query()
            ->where('is_showroom_employee', true)
            ->whereNotNull('attendance_time')
            ->get();

        foreach ($staff as $s) {
            // Not before he started: his first check-in day (or the day he was added).
            $first = Attendance::query()->where('staff_id', $s->id)->whereNotNull('check_in_at')->min('attendance_date');

            if (! $first || $date->lt(Carbon::parse($first))) {
                continue;
            }

            $attendance = Attendance::query()
                ->where('staff_id', $s->id)
                ->whereDate('attendance_date', $date->toDateString())
                ->first();

            if ($attendance && ($attendance->check_in_at || in_array($attendance->status, ['vacation', 'absent', 'manual'], true))) {
                continue;
            }

            $onVacation = EmployeeVacationRequest::query()
                ->where('staff_id', $s->id)
                ->where('status', 'approved')
                ->whereDate('from_date', '<=', $date->toDateString())
                ->whereDate('to_date', '>=', $date->toDateString())
                ->exists();

            if ($onVacation) {
                continue;
            }

            if ($attendance) {
                $attendance->update(['status' => 'absent', 'notes' => 'غياب بدون إجازة - مبصمش لحد 3 العصر']);
            } else {
                Attendance::create([
                    'staff_id' => $s->id,
                    'attendance_date' => $date->toDateString(),
                    'status' => 'absent',
                    'notes' => 'غياب بدون إجازة - مبصمش لحد 3 العصر',
                ]);
            }

            $marked++;
        }

        return $marked;
    }
}
