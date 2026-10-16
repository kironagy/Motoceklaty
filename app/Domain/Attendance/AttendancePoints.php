<?php

namespace App\Domain\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\AttendancePointExchange;
use App\Models\DashboardSetting;
use App\Models\EmployeeBonus;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Attendance points (table attendance_points).
 *
 * Every check-in earns one point: green on time (or early), red when late, black when late
 * black_after_minutes or more. Red / black carry a deduction, fixed when the point is given.
 * The admin can also add points by hand: greens as a reward, reds / blacks as a deduction.
 *
 * Greens earned AFTER a red / black cancel it (greens_for_red / greens_for_black): the bad point turns
 * "canceled" and its deduction is gone, the greens turn "used". A month with no red / black standing
 * lets the admin turn that month's greens into a bonus.
 *
 * Values come from the dashboard settings page, falling back to config/attendance_points.php.
 */
class AttendancePoints
{
    public const GREEN = 'green';
    public const RED = 'red';
    public const BLACK = 'black';

    public const LABELS = [
        self::GREEN => 'نقطة خضرا',
        self::RED => 'نقطة حمرا',
        self::BLACK => 'نقطة سودا',
    ];

    public const ICONS = [
        self::GREEN => '🟢',
        self::RED => '🔴',
        self::BLACK => '⚫',
    ];

    public const ACTIVE = 'active';
    public const USED = 'used';
    public const CANCELED = 'canceled';

    public const SETTINGS_KEY = 'attendance_points';

    /** Editable on the settings page; the rest of config/attendance_points.php stays in the file. */
    public const EDITABLE = ['red_amount', 'black_amount', 'greens_for_red', 'greens_for_black', 'black_after_minutes', 'grace_minutes'];

    public static function setting(string $key): float|int
    {
        $saved = (array) DashboardSetting::get(self::SETTINGS_KEY, []);
        $value = $saved[$key] ?? config("attendance_points.{$key}");

        return in_array($key, ['red_amount', 'black_amount'], true) ? (float) $value : (int) $value;
    }

    public const WEEK_DAYS = [6 => 'السبت', 0 => 'الحد', 1 => 'الاتنين', 2 => 'التلات', 3 => 'الأربع', 4 => 'الخميس', 5 => 'الجمعة'];

    /** Weekly days off (Carbon dayOfWeek, 0 = Sunday): never counted as absence. Default Friday. */
    public static function offDays(): array
    {
        $saved = (array) DashboardSetting::get(self::SETTINGS_KEY, []);

        return array_map('intval', $saved['off_days'] ?? [5]);
    }

    public static function amountFor(string $color): float
    {
        return match ($color) {
            self::RED => static::setting('red_amount'),
            self::BLACK => static::setting('black_amount'),
            default => 0.0,
        };
    }

    public static function greensNeeded(string $color): int
    {
        return $color === self::BLACK ? static::setting('greens_for_black') : static::setting('greens_for_red');
    }

    // ---------------------------------------------------------------- attendance days

    /** Late and overtime minutes of the day, while it is being saved. */
    public static function beforeSave(Attendance $attendance): void
    {
        $staff = $attendance->staff;
        $date = Carbon::parse($attendance->attendance_date ?? $attendance->check_in_at ?? now())->toDateString();

        if ($attendance->isDirty(['check_in_at', 'attendance_date']) || ! $attendance->exists) {
            $attendance->late_minutes = 0;

            if ($attendance->check_in_at && $staff?->attendance_time) {
                $official = Carbon::parse($date.' '.$staff->attendance_time);
                $checkIn = Carbon::parse($attendance->check_in_at);
                $attendance->late_minutes = $checkIn->greaterThan($official) ? (int) $official->diffInMinutes($checkIn) : 0;
            }
        }

        if ($attendance->isDirty(['check_out_at', 'attendance_date']) || ! $attendance->exists) {
            $attendance->overtime_minutes = null;

            if ($attendance->check_out_at && $staff?->departure_time) {
                $leave = static::officialLeave($date, $staff);
                $out = Carbon::parse($attendance->check_out_at);
                $attendance->overtime_minutes = $out->greaterThan($leave) ? (int) $leave->diffInMinutes($out) : 0;
            }
        }
    }

    /** Official leave time of a day; a shift that ends at or before it starts ends the next day. */
    public static function officialLeave(string $date, Staff $staff): Carbon
    {
        $leave = Carbon::parse($date.' '.$staff->departure_time);

        if ($staff->attendance_time && $leave->lessThanOrEqualTo(Carbon::parse($date.' '.$staff->attendance_time))) {
            $leave->addDay();
        }

        return $leave;
    }

    /** The day's point follows its check-in; a used or canceled point never changes. */
    public static function afterSave(Attendance $attendance): void
    {
        if (! $attendance->wasChanged(['check_in_at', 'attendance_date']) && ! $attendance->wasRecentlyCreated) {
            return;
        }

        $point = AttendancePoint::query()->where('attendance_id', $attendance->id)->first();

        if ($point && $point->status !== self::ACTIVE) {
            return;
        }

        if (! $attendance->check_in_at || ! $attendance->staff?->attendance_time) {
            $point?->delete();
            static::mirrorDeduction($attendance, null);

            return;
        }

        $late = (int) $attendance->late_minutes;
        $color = match (true) {
            $late >= static::setting('black_after_minutes') => self::BLACK,
            $late > static::setting('grace_minutes') => self::RED,
            default => self::GREEN,
        };

        $point = AttendancePoint::query()->updateOrCreate(['attendance_id' => $attendance->id], [
            'staff_id' => $attendance->staff_id,
            'color' => $color,
            'amount' => static::amountFor($color),
            'earned_on' => $attendance->attendance_date,
            'status' => self::ACTIVE,
            'source' => 'attendance',
        ]);

        static::mirrorDeduction($attendance, $point);
    }

    /** attendance.deduction_amount shows the deduction its point still carries. */
    protected static function mirrorDeduction(Attendance $attendance, ?AttendancePoint $point): void
    {
        $amount = $point && $point->status === self::ACTIVE ? (float) $point->amount : 0.0;

        $attendance->forceFill(['deduction_amount' => $amount, 'deduction_days' => 0])->saveQuietly();
    }

    // ---------------------------------------------------------------- manual points

    /** The admin adds points by hand: greens as a reward, reds / blacks as a deduction. */
    public static function addManual(Staff $staff, string $color, int $count, Carbon|string $date, ?string $reason, ?Staff $by = null): Collection
    {
        if (! isset(self::LABELS[$color]) || $count < 1) {
            throw new RuntimeException('نوع النقطة أو العدد مش صح.');
        }

        return DB::transaction(fn () => collect(range(1, $count))->map(fn () => AttendancePoint::create([
            'staff_id' => $staff->id,
            'color' => $color,
            'amount' => static::amountFor($color),
            'earned_on' => Carbon::parse($date)->toDateString(),
            'status' => self::ACTIVE,
            'source' => 'manual',
            'reason' => $reason,
            'created_by' => $by?->id,
        ])));
    }

    // ---------------------------------------------------------------- exchanges

    /** Unused greens earned after the given day, oldest first. */
    public static function greensAfter(int $staffId, Carbon|string $date): Collection
    {
        return AttendancePoint::query()
            ->where('staff_id', $staffId)
            ->where('color', self::GREEN)
            ->where('status', self::ACTIVE)
            ->whereDate('earned_on', '>', Carbon::parse($date)->toDateString())
            ->orderBy('earned_on')
            ->orderBy('id')
            ->get();
    }

    public static function canCancel(?AttendancePoint $bad): bool
    {
        return $bad
            && in_array($bad->color, [self::RED, self::BLACK], true)
            && $bad->status === self::ACTIVE
            && static::greensAfter($bad->staff_id, $bad->earned_on)->count() >= static::greensNeeded($bad->color);
    }

    /** Spends greens earned after a red / black to cancel it and its deduction. */
    public static function cancel(AttendancePoint $bad, ?Staff $by = null): AttendancePointExchange
    {
        return DB::transaction(function () use ($bad, $by) {
            $bad = AttendancePoint::query()->lockForUpdate()->findOrFail($bad->id);

            if (! in_array($bad->color, [self::RED, self::BLACK], true) || $bad->status !== self::ACTIVE) {
                throw new RuntimeException('النقطة دي مش حمرا أو سودا قائمة.');
            }

            $needed = static::greensNeeded($bad->color);
            $greens = AttendancePoint::query()
                ->where('staff_id', $bad->staff_id)
                ->where('color', self::GREEN)
                ->where('status', self::ACTIVE)
                ->whereDate('earned_on', '>', $bad->earned_on->toDateString())
                ->orderBy('earned_on')
                ->orderBy('id')
                ->lockForUpdate()
                ->limit($needed)
                ->get();

            if ($greens->count() < $needed) {
                throw new RuntimeException("محتاج {$needed} نقط خضرا بعد يوم {$bad->earned_on->format('Y-m-d')}، والموجود {$greens->count()}.");
            }

            $exchange = AttendancePointExchange::create([
                'staff_id' => $bad->staff_id,
                'kind' => $bad->color === self::BLACK ? 'cancel_black' : 'cancel_red',
                'canceled_point_id' => $bad->id,
                'greens_used' => $needed,
                'amount' => (float) $bad->amount,
                'created_by' => $by?->id,
            ]);

            AttendancePoint::whereKey($greens->pluck('id'))->update(['status' => self::USED, 'exchange_id' => $exchange->id]);
            $bad->update(['status' => self::CANCELED, 'exchange_id' => $exchange->id]);

            if ($bad->attendance) {
                static::mirrorDeduction($bad->attendance, $bad);
            }

            return $exchange;
        });
    }

    /** Counts and money of a month (by the day each point was earned). */
    public static function monthSummary(int $staffId, Carbon $month): array
    {
        $points = AttendancePoint::query()
            ->where('staff_id', $staffId)
            ->whereBetween('earned_on', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->get(['color', 'status', 'amount']);

        $active = $points->where('status', self::ACTIVE);

        return [
            'green' => $active->where('color', self::GREEN)->count(),
            'red' => $active->where('color', self::RED)->count(),
            'black' => $active->where('color', self::BLACK)->count(),
            'green_used' => $points->where('color', self::GREEN)->where('status', self::USED)->count(),
            'canceled' => $points->where('status', self::CANCELED)->count(),
            'deductions' => (float) $active->whereIn('color', [self::RED, self::BLACK])->sum('amount'),
        ];
    }

    public static function canConvertToBonus(int $staffId, Carbon $month): bool
    {
        $s = static::monthSummary($staffId, $month);

        return $s['green'] > 0 && $s['red'] === 0 && $s['black'] === 0;
    }

    /** A month with no red / black standing: its unused greens become a bonus of the given amount. */
    public static function convertToBonus(Staff $staff, Carbon $month, float $amount, ?Staff $by = null): AttendancePointExchange
    {
        return DB::transaction(function () use ($staff, $month, $amount, $by) {
            $from = $month->copy()->startOfMonth()->toDateString();
            $to = $month->copy()->endOfMonth()->toDateString();

            $points = AttendancePoint::query()
                ->where('staff_id', $staff->id)
                ->whereBetween('earned_on', [$from, $to])
                ->where('status', self::ACTIVE)
                ->lockForUpdate()
                ->get();

            if ($points->whereIn('color', [self::RED, self::BLACK])->isNotEmpty()) {
                throw new RuntimeException('الموظف عليه نقط حمرا أو سودا لسه قائمة في الشهر ده.');
            }

            $greens = $points->where('color', self::GREEN);

            if ($greens->isEmpty()) {
                throw new RuntimeException('مفيش نقط خضرا متاحة في الشهر ده.');
            }

            $bonus = EmployeeBonus::create([
                'staff_id' => $staff->id,
                'amount' => $amount,
                // Inside the month it was earned for, so that month's salary counts it.
                'bonus_date' => max($from, min($to, today()->toDateString())),
                'reason' => 'مكافأة التزام: استبدال '.$greens->count().' نقطة خضرا ('.$month->format('Y-m').')',
                'created_by' => $by?->id,
            ]);

            $exchange = AttendancePointExchange::create([
                'staff_id' => $staff->id,
                'kind' => 'bonus',
                'greens_used' => $greens->count(),
                'amount' => $amount,
                'bonus_id' => $bonus->id,
                'created_by' => $by?->id,
            ]);

            AttendancePoint::whereKey($greens->pluck('id'))->update(['status' => self::USED, 'exchange_id' => $exchange->id]);

            return $exchange;
        });
    }
}
