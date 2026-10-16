<?php

namespace App\Filament\Widgets;

use App\Support\FinanceAccess;
use App\Models\Attendance;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

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
        return FinanceAccess::isShowroomEmployee();
    }

    public function loadTodayAttendance(): void
    {
        $this->todayAttendance = Attendance::query()
            ->where('staff_id', auth()->id())
            ->whereDate('attendance_date', today())
            ->first();
    }

    public function checkIn(float $latitude, float $longitude): void
    {
        $user = auth()->user();

        if (! $user?->is_showroom_employee) {
            Notification::make()
                ->title('غير مسموح')
                ->body('البصمة متاحة لموظفين المعرض فقط.')
                ->danger()
                ->send();

            return;
        }

        if (! $user->attendance_latitude || ! $user->attendance_longitude) {
            Notification::make()
                ->title('مكان البصمة غير محدد')
                ->body('لازم السوبر أدمن يحدد Lat و Long للموظف الأول.')
                ->danger()
                ->send();

            return;
        }

        if (! $this->isInsideAllowedLocation($latitude, $longitude, (float) $user->attendance_latitude, (float) $user->attendance_longitude)) {
            Notification::make()
                ->title('أنت خارج مكان البصمة')
                ->body('لا يمكن تسجيل الحضور إلا من داخل المعرض.')
                ->danger()
                ->send();

            return;
        }

        $attendance = Attendance::query()
            ->firstOrCreate(
                [
                    'staff_id' => $user->id,
                    'attendance_date' => today()->toDateString(),
                ],
                [
                    'status' => 'present',
                ]
            );

        if ($attendance->check_in_at) {
            Notification::make()
                ->title('تم تسجيل الحضور قبل كده')
                ->body('أنت مسجل حضور بالفعل اليوم.')
                ->warning()
                ->send();

            return;
        }

        $now = now();
        $lateMinutes = $this->calculateLateMinutes($user->attendance_time, $now);
        $deductionDays = $this->getDeductionDays($user->id, $lateMinutes);
        $deductionAmount = $this->calculateDeductionAmount((float) $user->monthly_salary, $deductionDays);

        $attendance->update([
            'check_in_at' => $now,
            'check_in_latitude' => $latitude,
            'check_in_longitude' => $longitude,
            'late_minutes' => $lateMinutes,
            'deduction_days' => $deductionDays,
            'deduction_amount' => $deductionAmount,
            'notes' => $deductionAmount > 0
                ? "تأخير {$lateMinutes} دقيقة - خصم {$deductionDays} يوم"
                : null,
        ]);

        $this->loadTodayAttendance();

        if ($deductionAmount > 0) {
            Notification::make()
                ->title('تم تسجيل الحضور مع خصم')
                ->body("أنت اتأخرت {$lateMinutes} دقيقة، وتم خصم {$deductionAmount} ج من المرتب. التزم في التأخير وأنا هلتزم في الخصم.")
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('تم تسجيل الحضور')
            ->body('تم تسجيل حضورك بنجاح بدون خصم.')
            ->success()
            ->send();
    }

    public function checkOut(float $latitude, float $longitude): void
    {
        $user = auth()->user();

        if (! $user?->is_showroom_employee) {
            Notification::make()
                ->title('غير مسموح')
                ->body('البصمة متاحة لموظفين المعرض فقط.')
                ->danger()
                ->send();

            return;
        }

        if (! $user->attendance_latitude || ! $user->attendance_longitude) {
            Notification::make()
                ->title('مكان البصمة غير محدد')
                ->body('لازم السوبر أدمن يحدد Lat و Long للموظف الأول.')
                ->danger()
                ->send();

            return;
        }

        if (! $this->isInsideAllowedLocation($latitude, $longitude, (float) $user->attendance_latitude, (float) $user->attendance_longitude)) {
            Notification::make()
                ->title('أنت خارج مكان البصمة')
                ->body('لا يمكن تسجيل الانصراف إلا من داخل المعرض.')
                ->danger()
                ->send();

            return;
        }

        $attendance = Attendance::query()
            ->where('staff_id', $user->id)
            ->whereDate('attendance_date', today())
            ->first();

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

        $this->loadTodayAttendance();

        Notification::make()
            ->title('تم تسجيل الانصراف')
            ->body('تم تسجيل انصرافك بنجاح.')
            ->success()
            ->send();
    }

    protected function calculateLateMinutes(?string $attendanceTime, Carbon $now): int
    {
        if (! $attendanceTime) {
            return 0;
        }

        $officialTime = Carbon::parse(today()->toDateString() . ' ' . $attendanceTime);

        if ($now->lessThanOrEqualTo($officialTime)) {
            return 0;
        }

        return $officialTime->diffInMinutes($now);
    }

    protected function getDeductionDays(int $userId, int $lateMinutes): float
    {
        if ($lateMinutes <= 0) {
            return 0;
        }

        $rules = auth()->user()
            ->attendanceDeductionRules()
            ->orderBy('from_minute')
            ->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $matchedRule = $rules->first(function ($rule) use ($lateMinutes) {
            return $lateMinutes >= $rule->from_minute
                && $lateMinutes <= $rule->to_minute;
        });

        if ($matchedRule) {
            return (float) $matchedRule->deduction_days;
        }

        return (float) $rules->last()->deduction_days;
    }

    protected function calculateDeductionAmount(float $monthlySalary, float $deductionDays): float
    {
        if ($monthlySalary <= 0 || $deductionDays <= 0) {
            return 0;
        }

        $daySalary = $monthlySalary / 30;

        return round($daySalary * $deductionDays, 2);
    }

    protected function isInsideAllowedLocation(
        float $currentLat,
        float $currentLng,
        float $allowedLat,
        float $allowedLng
    ): bool {
        $allowedDistanceInMeters = 80;

        return $this->calculateDistanceInMeters(
            $currentLat,
            $currentLng,
            $allowedLat,
            $allowedLng
        ) <= $allowedDistanceInMeters;
    }

    protected function calculateDistanceInMeters(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371000;

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return $earthRadius * $angle;
    }
}
