<?php

namespace App\Domain\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\EmployeeVacationRequest;
use App\Models\Staff;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * One month of attendance per employee, for the attendance cards: days present, late, absent,
 * points earned, and a day-by-day strip.
 *
 * A day is absent when it is over (before today), isn't a weekly day off, has no check-in and no
 * approved vacation - counted from the employee's first attendance day, so the time before he
 * started using the clock-in isn't absence.
 */
class AttendanceMonthStats
{
    /**
     * @param  Collection<int, Staff>  $staff
     * @return Collection<int, array<string, mixed>>
     */
    public static function forMonth(Carbon $month, Collection $staff): Collection
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $ids = $staff->pluck('id');
        $grace = AttendancePoints::setting('grace_minutes');
        $offDays = AttendancePoints::offDays();

        $attendances = Attendance::query()
            ->whereIn('staff_id', $ids)
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->with('point')
            ->get()
            ->groupBy('staff_id');

        $points = AttendancePoint::query()
            ->whereIn('staff_id', $ids)
            ->whereBetween('earned_on', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('staff_id');

        $vacations = EmployeeVacationRequest::query()
            ->whereIn('staff_id', $ids)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->get()
            ->groupBy('staff_id');

        $firstDays = Attendance::query()
            ->whereIn('staff_id', $ids)
            ->whereNotNull('check_in_at')
            ->selectRaw('staff_id, MIN(attendance_date) AS first_day')
            ->groupBy('staff_id')
            ->pluck('first_day', 'staff_id');

        return $staff->map(function (Staff $s) use ($from, $to, $grace, $offDays, $attendances, $points, $vacations, $firstDays) {
            $days = ($attendances[$s->id] ?? collect())->keyBy(fn (Attendance $a) => $a->attendance_date->toDateString());
            $myPoints = $points[$s->id] ?? collect();
            $myVacations = $vacations[$s->id] ?? collect();
            $start = isset($firstDays[$s->id]) ? Carbon::parse($firstDays[$s->id]) : null;

            $strip = [];
            $absent = 0;

            foreach (CarbonPeriod::create($from, $to) as $day) {
                $date = $day->toDateString();
                $a = $days[$date] ?? null;
                $onVacation = $myVacations->contains(fn ($v) => $date >= Carbon::parse($v->from_date)->toDateString() && $date <= Carbon::parse($v->to_date)->toDateString());

                $state = match (true) {
                    $a && $a->check_in_at && $a->point => $a->point->status === AttendancePoints::CANCELED ? 'canceled' : $a->point->color,
                    $a && $a->check_in_at => 'present',
                    $onVacation || $a?->status === 'vacation' => 'vacation',
                    in_array($day->dayOfWeek, $offDays, true) => 'off',
                    $day->greaterThanOrEqualTo(today()) => 'future',
                    ! $start || $day->lessThan($start) => 'none',
                    default => 'absent',
                };

                if ($state === 'absent') {
                    $absent++;
                }

                $strip[] = ['day' => $day->day, 'state' => $state, 'date' => $date];
            }

            $present = $days->filter(fn (Attendance $a) => $a->check_in_at)->count();
            $late = $days->filter(fn (Attendance $a) => $a->check_in_at && (int) $a->late_minutes > $grace)->count();
            $overtime = (int) $days->sum('overtime_minutes');
            $earned = fn (string $color) => $myPoints->where('color', $color)->count();
            $active = $myPoints->where('status', AttendancePoints::ACTIVE);

            return [
                'staff' => $s,
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'green' => $earned(AttendancePoints::GREEN),
                'red' => $earned(AttendancePoints::RED),
                'black' => $earned(AttendancePoints::BLACK),
                'green_available' => $active->where('color', AttendancePoints::GREEN)->count(),
                'canceled' => $myPoints->where('status', AttendancePoints::CANCELED)->count(),
                'deductions' => (float) $active->whereIn('color', [AttendancePoints::RED, AttendancePoints::BLACK])->sum('amount'),
                'overtime' => $overtime,
                // On time share of the days he came: greens over days with a point.
                'on_time' => ($scored = $earned(AttendancePoints::GREEN) + $earned(AttendancePoints::RED) + $earned(AttendancePoints::BLACK)) > 0
                    ? (int) round($earned(AttendancePoints::GREEN) / $scored * 100)
                    : null,
                'strip' => $strip,
            ];
        });
    }
}
