<?php

namespace App\Filament\Pages;

use App\Models\Attendance;
use App\Models\EmployeeAdvanceRequest;
use App\Models\EmployeeBonus;
use App\Models\Staff;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class Salaries extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'المرتبات';
    protected static ?string $navigationGroup = 'إدارة الموظفين';
    protected static ?string $title = 'المرتبات';

    protected static string $view = 'filament.pages.salaries';

    public ?string $month = null;

    public bool $showBonusModal = false;
    public ?int $bonusUserId = null;
    public ?string $bonusEmployeeName = null;
    public ?float $bonusAmount = null;
    public ?string $bonusDate = null;
    public ?string $bonusReason = null;

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
        $this->bonusDate = today()->toDateString();
    }

    public static function canAccess(): bool
    {
        return \App\Support\Access::allows(static::class, 'view');
    }

    public function openBonusModal(int $userId): void
    {
        $user = Staff::findOrFail($userId);

        $this->bonusUserId = $user->id;
        $this->bonusEmployeeName = $user->name;
        $this->bonusAmount = null;
        $this->bonusDate = today()->toDateString();
        $this->bonusReason = null;
        $this->showBonusModal = true;
    }

    public function closeBonusModal(): void
    {
        $this->showBonusModal = false;
    }

    public function saveBonus(): void
    {
        $this->validate([
            'bonusUserId' => ['required', 'exists:staff,id'],
            'bonusAmount' => ['required', 'numeric', 'min:1'],
            'bonusDate' => ['required', 'date'],
            'bonusReason' => ['nullable', 'string'],
        ]);

        EmployeeBonus::create([
            'staff_id' => $this->bonusUserId,
            'amount' => $this->bonusAmount,
            'bonus_date' => $this->bonusDate,
            'reason' => $this->bonusReason,
            'created_by' => auth()->id(),
        ]);

        $this->showBonusModal = false;

        Notification::make()
            ->title('تم إضافة البونص')
            ->body('تم إضافة البونص للموظف واحتسابه في المرتب.')
            ->success()
            ->send();
    }

    public function getSalaryRowsProperty(): Collection
    {
        $start = Carbon::parse($this->month . '-01')->startOfMonth();
        $end = Carbon::parse($this->month . '-01')->endOfMonth();

        return Staff::query()
            ->where('is_showroom_employee', true)
            ->with('branch')
            ->orderBy('name')
            ->get()
            ->map(function (Staff $user) use ($start, $end) {
                $monthlySalary = (float) $user->monthly_salary;
                $daySalary = $monthlySalary > 0 ? $monthlySalary / 30 : 0;

                $attendances = Attendance::query()
                    ->where('staff_id', $user->id)
                    ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
                    ->get();

                $approvedAdvances = EmployeeAdvanceRequest::query()
                    ->where('staff_id', $user->id)
                    ->where('status', 'approved')
                    ->whereBetween('request_date', [$start->toDateString(), $end->toDateString()])
                    ->sum('amount');

                $bonuses = EmployeeBonus::query()
                    ->where('staff_id', $user->id)
                    ->whereBetween('bonus_date', [$start->toDateString(), $end->toDateString()])
                    ->sum('amount');

                $presentDays = $attendances->whereNotNull('check_in_at')->count();
                $lateDays = $attendances->where('late_minutes', '>', 0)->count();
                $lateMinutes = $attendances->sum('late_minutes');
                // Days before the points system keep their old deduction; since then the standing
                // red / black points (from clock-in or added by hand) are the deduction.
                $points = \App\Domain\Attendance\AttendancePoints::monthSummary($user->id, $start);
                $legacyDeductions = (float) Attendance::query()
                    ->where('staff_id', $user->id)
                    ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
                    ->whereDoesntHave('point')
                    ->sum('deduction_amount');
                $attendanceDeductions = $legacyDeductions + $points['deductions'];
                $overtimeMinutes = (int) $attendances->sum('overtime_minutes');

                $netSalary = $monthlySalary - $attendanceDeductions - (float) $approvedAdvances + (float) $bonuses;

                return [
                    'user' => $user,
                    'warehouse' => $user->branch?->name ?? '—',
                    'monthly_salary' => $monthlySalary,
                    'day_salary' => round($daySalary, 2),
                    'present_days' => $presentDays,
                    'late_days' => $lateDays,
                    'late_minutes' => $lateMinutes,
                    'points' => $points,
                    'overtime' => $overtimeMinutes > 0 ? \App\Filament\Widgets\AttendanceCheckWidget::hoursText($overtimeMinutes) : '—',
                    'attendance_deductions' => round($attendanceDeductions, 2),
                    'approved_advances' => round((float) $approvedAdvances, 2),
                    'bonuses' => round((float) $bonuses, 2),
                    'net_salary' => round($netSalary, 2),
                ];
            });
    }
}
