<?php

namespace App\Filament\Resources\AttendanceResource\Pages;

use App\Domain\Attendance\AttendanceMonthStats;
use App\Filament\Resources\AttendanceResource;
use App\Models\Staff;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Attendance opens on one card per employee for the month (days present, late, absent, points and a
 * day strip); the table of days is one click away, filtered to the employee whose card was clicked.
 */
class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected static string $view = 'filament.resources.attendance.list';

    #[Url(as: 'view')]
    public string $mode = 'cards';

    #[Url]
    public ?string $month = null;

    public function mount(): void
    {
        parent::mount();

        if (! $this->month || ! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getMonthDateProperty(): Carbon
    {
        return Carbon::parse($this->month.'-01');
    }

    /** Cards of the month: everyone for full access, else only the user's own. */
    public function getCardsProperty(): Collection
    {
        $staff = Staff::query()
            ->with('branch')
            ->when(
                AttendanceResource::fullAccess(),
                fn ($q) => $q->where(fn ($q) => $q
                    ->where('is_showroom_employee', true)
                    ->orWhereHas('attendances', fn ($a) => $a->whereBetween('attendance_date', [
                        $this->monthDate->copy()->startOfMonth()->toDateString(),
                        $this->monthDate->copy()->endOfMonth()->toDateString(),
                    ]))),
                fn ($q) => $q->whereKey(auth()->id()),
            )
            ->orderBy('name')
            ->get();

        return AttendanceMonthStats::forMonth($this->monthDate, $staff);
    }

    public function shiftMonth(int $by): void
    {
        $this->month = $this->monthDate->addMonthsNoOverflow($by)->format('Y-m');
    }

    /** From a card to that employee's days in the table. */
    public function openStaff(int $staffId): void
    {
        $from = $this->monthDate->copy()->startOfMonth()->toDateString();
        $until = $this->monthDate->copy()->endOfMonth()->toDateString();

        $this->tableFilters['staff_id']['value'] = (string) $staffId;
        $this->tableFilters['attendance_date'] = ['from' => $from, 'until' => $until];
        $this->mode = 'table';
        $this->resetPage();
    }
}
