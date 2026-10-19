<?php

namespace App\Filament\Widgets;

use App\Domain\Attendance\AttendancePoints;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Staff;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

/**
 * Clock in / out from inside the employee's branch (its latitude / longitude), plus his points this month.
 * The point and deduction of the day are set by the Attendance model (AttendancePoints::apply).
 */
class AttendanceCheckWidget extends Widget
{
    protected static string $view = 'filament.widgets.attendance-check-widget';

    protected int | string | array $columnSpan = 'full';

    public ?Attendance $todayAttendance = null;

    public function mount(): void
    {
        $this->loadTodayAttendance();
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->is_showroom_employee;
    }

    /** The shift on screen: one still open (also from yesterday, past midnight), else today's. */
    public function loadTodayAttendance(): void
    {
        $this->todayAttendance = static::openShift((int) auth()->id())
            ?? Attendance::query()
                ->where('staff_id', auth()->id())
                ->whereDate('attendance_date', today())
                ->first();
    }

    /**
     * A check-in without a check-out from today or yesterday, younger than max_shift_hours - someone
     * working past midnight still checks out of it. An older one was forgotten and doesn't block today.
     */
    public static function openShift(int $staffId): ?Attendance
    {
        return Attendance::query()
            ->where('staff_id', $staffId)
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->whereDate('attendance_date', '>=', today()->subDay())
            ->where('check_in_at', '>=', now()->subHours(config('attendance_points.max_shift_hours')))
            ->latest('check_in_at')
            ->first();
    }

    public function getMonthPointsProperty(): array
    {
        return AttendancePoints::monthSummary((int) auth()->id(), now());
    }

    public function checkIn(float $latitude, float $longitude): void
    {
        $user = auth()->user();

        if (! $branch = $this->allowedBranch($user, $latitude, $longitude, 'الحضور')) {
            return;
        }

        if (static::openShift($user->id)) {
            Notification::make()
                ->title('عندك وردية مفتوحة')
                ->body('سجّل انصراف الوردية اللي فاتت الأول.')
                ->warning()
                ->send();

            return;
        }

        $attendance = Attendance::query()->firstOrCreate(
            ['staff_id' => $user->id, 'attendance_date' => today()->toDateString()],
            ['status' => 'present'],
        );

        if ($attendance->check_in_at) {
            Notification::make()
                ->title('تم تسجيل الحضور قبل كده')
                ->body('أنت مسجل حضور بالفعل اليوم.')
                ->warning()
                ->send();

            return;
        }

        // A day the 3 PM run marked absent turns into a (late) working day.
        $attendance->update([
            'status' => 'present',
            'check_in_at' => now(),
            'check_in_latitude' => $latitude,
            'check_in_longitude' => $longitude,
        ]);
        $attendance->refresh();
        $point = $attendance->point?->color;

        $attendance->forceFill(['notes' => match ($point) {
            AttendancePoints::RED => "تأخير {$attendance->late_minutes} دقيقة - نقطة حمرا",
            AttendancePoints::BLACK => "تأخير {$attendance->late_minutes} دقيقة - نقطة سودا",
            default => null,
        }])->saveQuietly();

        $this->loadTodayAttendance();

        $amount = number_format((float) $attendance->deduction_amount);

        match ($point) {
            AttendancePoints::GREEN => Notification::make()
                ->title('تم تسجيل الحضور - نقطة خضرا 🟢')
                ->body('جيت في معادك. النقط الخضرا بتلغي الخصم أو بتتحول بونص آخر الشهر.')
                ->success()
                ->send(),
            AttendancePoints::RED => Notification::make()
                ->title('تم تسجيل الحضور - نقطة حمرا 🔴')
                ->body("اتأخرت {$attendance->late_minutes} دقيقة، خصم {$amount} ج. تقدر تلغيه لو جيت في معادك ".AttendancePoints::greensNeeded(AttendancePoints::RED)." أيام بعد كده.")
                ->warning()
                ->send(),
            AttendancePoints::BLACK => Notification::make()
                ->title('تم تسجيل الحضور - نقطة سودا ⚫')
                ->body("اتأخرت {$attendance->late_minutes} دقيقة، خصم {$amount} ج. تقدر تلغيه لو جيت في معادك ".AttendancePoints::greensNeeded(AttendancePoints::BLACK)." أيام بعد كده.")
                ->danger()
                ->send(),
            default => Notification::make()
                ->title('تم تسجيل الحضور')
                ->body('ميعاد حضورك الرسمي مش متسجل، فمتحسبتلكش نقطة النهارده. كلّم الإدارة.')
                ->warning()
                ->send(),
        };
    }

    public function checkOut(float $latitude, float $longitude): void
    {
        $user = auth()->user();

        if (! $this->allowedBranch($user, $latitude, $longitude, 'الانصراف')) {
            return;
        }

        // The open shift, even when it started yesterday and it's past midnight now.
        $attendance = static::openShift($user->id)
            ?? Attendance::query()->where('staff_id', $user->id)->whereDate('attendance_date', today())->first();

        if (! $attendance || ! $attendance->check_in_at) {
            Notification::make()
                ->title('سجل حضور الأول')
                ->body('لازم تسجل حضور قبل الانصراف.')
                ->warning()
                ->send();

            return;
        }

        if ($attendance->check_out_at) {
            Notification::make()
                ->title('تم تسجيل الانصراف قبل كده')
                ->body('أنت مسجل انصراف بالفعل اليوم.')
                ->warning()
                ->send();

            return;
        }

        $attendance->update([
            'check_out_at' => now(),
            'check_out_latitude' => $latitude,
            'check_out_longitude' => $longitude,
        ]);
        $attendance->refresh();

        $this->loadTodayAttendance();

        $overtime = (int) $attendance->overtime_minutes;

        Notification::make()
            ->title('تم تسجيل الانصراف')
            ->body($overtime > 0
                ? 'تم تسجيل انصرافك. اتحسبلك '.static::hoursText($overtime).' زيادة بعد ميعادك.'
                : 'تم تسجيل انصرافك بنجاح.')
            ->success()
            ->send();
    }

    /** The employee's branch when he is standing inside it; otherwise tells him why not and returns null. */
    protected function allowedBranch(?Staff $user, float $latitude, float $longitude, string $what): ?Branch
    {
        if (! $user?->is_showroom_employee) {
            $this->refuse('غير مسموح', 'البصمة متاحة للموظفين اللي بيتحسب عليهم حضور بس.');

            return null;
        }

        // Moved between branches: clocks in at whichever branch he is standing in.
        if ($user->can_clock_in_any_branch) {
            $branches = Branch::query()->whereNotNull('latitude')->whereNotNull('longitude')->get();
            $nearest = $branches
                ->map(fn (Branch $b) => [$b, $this->distanceInMeters($latitude, $longitude, (float) $b->latitude, (float) $b->longitude)])
                ->sortBy(fn ($pair) => $pair[1])
                ->first();

            if ($nearest && $nearest[1] <= config('attendance_points.radius_meters')) {
                return $nearest[0];
            }

            $this->refuse('أنت خارج مكان البصمة', "لا يمكن تسجيل {$what} إلا من داخل أحد الفروع.");

            return null;
        }

        $branch = $user->branch;

        if (! $branch) {
            $this->refuse('مش متحدد لك فرع', 'الإدارة لازم تحدد الفرع اللي أنت شغال فيه من صفحة الموظفين.');

            return null;
        }

        if ($branch->latitude === null || $branch->longitude === null) {
            $this->refuse('مكان الفرع مش متحدد', "الإدارة لازم تحدد Latitude و Longitude لـ {$branch->name} من صفحة الفروع.");

            return null;
        }

        $distance = $this->distanceInMeters($latitude, $longitude, (float) $branch->latitude, (float) $branch->longitude);

        if ($distance > config('attendance_points.radius_meters')) {
            $this->refuse('أنت خارج مكان البصمة', "لا يمكن تسجيل {$what} إلا من داخل {$branch->name}.");

            return null;
        }

        return $branch;
    }

    public static function hoursText(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h ? $h.' ساعة ' : '').($m ? $m.' دقيقة' : ''));
    }

    protected function refuse(string $title, string $body): void
    {
        Notification::make()->title($title)->body($body)->danger()->send();
    }

    protected function distanceInMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $latDelta = $latTo - $latFrom;
        $lonDelta = deg2rad($lon2) - deg2rad($lon1);

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return 6371000 * $angle;
    }
}
