<?php

namespace App\Filament\Resources\DailyTaskResource\Pages;

use App\Filament\Resources\DailyTaskReportResource;
use App\Filament\Resources\DailyTaskResource;
use App\Models\DailyTask;
use App\Models\DailyTaskReport;
use App\Models\Staff;
use App\Models\Team;
use App\Support\StaffNotifier;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * Three views of the same tasks: a to-do board (مطلوب / جاري التنفيذ / تم التنفيذ), the table, and one
 * card per team member with every task of the days in detail (times, feedback, proofs).
 */
class ListDailyTasks extends ListRecords
{
    protected static string $resource = DailyTaskResource::class;

    protected static string $view = 'filament.resources.daily-tasks.list';

    public const MODES = ['board' => 'لوحة المهام', 'members' => 'حسب الموظف', 'table' => 'جدول'];

    #[Url]
    public string $mode = 'board';

    #[Url]
    public ?string $teamId = null;

    #[Url]
    public ?string $memberId = null;

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    public function mount(): void
    {
        parent::mount();

        $this->mode = array_key_exists($this->mode, self::MODES) ? $this->mode : 'board';
        $this->from ??= today()->toDateString();
        $this->to ??= $this->from;
    }

    public function setMode(string $mode): void
    {
        $this->mode = array_key_exists($mode, self::MODES) ? $mode : 'board';
    }

    public function updatedTeamId(): void
    {
        $this->memberId = null;
    }

    /** Whole days, and "to" never before "from". */
    protected function range(): array
    {
        $from = rescue(fn () => \Carbon\Carbon::parse($this->from)->startOfDay(), today(), false);
        $to = rescue(fn () => \Carbon\Carbon::parse($this->to)->startOfDay(), $from, false);

        return [$from, $to->lt($from) ? $from->copy() : $to];
    }

    /** The teams in the filter: the ones the user leads (or all, full access), else the ones he is in. */
    public function teamOptions(): array
    {
        $ids = DailyTaskResource::managedTeamIds() ?: Team::of(auth()->id());

        return Team::query()->whereKey($ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Leaders see their teams' members; a member sees only himself. */
    protected function people(): Collection
    {
        $teamIds = $this->teamId ? [(int) $this->teamId] : DailyTaskResource::managedTeamIds();
        $teamIds = array_values(array_intersect($teamIds, DailyTaskResource::managedTeamIds()));

        if ($teamIds === []) {
            return Staff::query()->whereKey(auth()->id())->get();
        }

        $ids = DB::table('team_staff')->whereIn('team_id', $teamIds)->pluck('staff_id')
            ->merge(DailyTask::query()->whereIn('team_id', $teamIds)->select('assigned_to')->distinct()->pluck('assigned_to'));

        return Staff::query()->whereKey($ids->unique())->orderBy('name')->get();
    }

    public function memberOptions(): array
    {
        return $this->people()->pluck('name', 'id')->all();
    }

    /** The days' tasks, plus anything still open from before them (late). */
    protected function tasks(): Collection
    {
        [$from, $to] = $this->range();

        return DailyTaskResource::getEloquentQuery()
            ->with(['files'])
            ->when($this->teamId, fn ($q, $id) => $q->where('team_id', $id))
            ->when($this->memberId, fn ($q, $id) => $q->where('assigned_to', $id))
            ->where(fn ($q) => $q
                ->whereBetween('task_date', [$from->toDateString(), $to->toDateString()])
                ->orWhere(fn ($q) => $q->where('status', '!=', DailyTask::DONE)->whereDate('task_date', '<', $from)))
            ->orderBy('task_date')
            ->orderBy('id')
            ->get();
    }

    protected function getViewData(): array
    {
        if ($this->mode === 'table') {
            return [];
        }

        $tasks = $this->tasks();

        if ($this->mode === 'board') {
            return ['columns' => collect(DailyTask::STATUSES)->map(fn ($label, $status) => $tasks->where('status', $status)->values())];
        }

        $people = $this->memberId ? $this->people()->whereIn('id', [(int) $this->memberId]) : $this->people();

        return [
            'members' => $people
                ->map(fn (Staff $staff) => ['staff' => $staff, 'tasks' => $tasks->where('assigned_to', $staff->id)->values()])
                ->sortByDesc(fn ($row) => $row['tasks']->count())
                ->values(),
        ];
    }

    protected function boardTask(array $arguments): DailyTask
    {
        return DailyTaskResource::getEloquentQuery()->findOrFail($arguments['task'] ?? 0);
    }

    public function openTaskAction(): Actions\Action
    {
        return Actions\Action::make('openTask')
            ->label('فتح')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->link()
            ->modalHeading(fn (array $arguments) => $this->boardTask($arguments)->title)
            ->modalContent(fn (array $arguments) => view('filament.resources.daily-tasks.task', [
                'task' => $this->boardTask($arguments)->load(['files', 'assignee', 'creator', 'team']),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('قفل');
    }

    public function startTaskAction(): Actions\Action
    {
        return Actions\Action::make('startTask')
            ->label('ابدأ')
            ->icon('heroicon-o-play')
            ->color('info')
            ->size('sm')
            ->action(fn (array $arguments) => DailyTaskResource::start($this->boardTask($arguments)));
    }

    public function finishTaskAction(): Actions\Action
    {
        return Actions\Action::make('finishTask')
            ->label('تم التنفيذ')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->size('sm')
            ->modalHeading(fn (array $arguments) => 'خلصت: '.$this->boardTask($arguments)->title)
            ->modalDescription('ارفع إثبات إنك خلصت المهمة (سكرينات أو ملف).')
            ->form(DailyTaskResource::doneForm())
            ->action(fn (array $arguments, array $data) => DailyTaskResource::finish($this->boardTask($arguments), $data));
    }

    public function reopenTaskAction(): Actions\Action
    {
        return Actions\Action::make('reopenTask')
            ->label('رجّعها مطلوب')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->link()
            ->requiresConfirmation()
            ->modalDescription('المهمة هترجع «مطلوب» ووقت البدء والتنفيذ هيتمسحوا.')
            ->modalSubmitActionLabel('رجّعها')
            ->modalCancelActionLabel('إلغاء')
            ->action(fn (array $arguments) => DailyTaskResource::reopen($this->boardTask($arguments)));
    }

    public function deleteTaskAction(): Actions\Action
    {
        return Actions\Action::make('deleteTask')
            ->label('حذف')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->iconButton()
            ->requiresConfirmation()
            ->modalHeading('حذف المهمة')
            ->modalDescription('المهمة وملفاتها هيتمسحوا نهائي.')
            ->modalSubmitActionLabel('احذف')
            ->modalCancelActionLabel('إلغاء')
            ->action(function (array $arguments) {
                $task = $this->boardTask($arguments);
                abort_unless(DailyTaskResource::canDelete($task), 403);
                $task->delete();
                Notification::make()->title('المهمة اتمسحت')->success()->send();
            });
    }

    public function getTabs(): array
    {
        $count = fn (?string $status) => DailyTaskResource::getEloquentQuery()->when($status, fn ($q) => $q->where('status', $status))->count();

        return [
            'todo' => Tab::make('مطلوب')
                ->icon('heroicon-o-clock')
                ->badge(fn () => $count(DailyTask::TODO))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DailyTask::TODO)),
            'doing' => Tab::make('جاري التنفيذ')
                ->icon('heroicon-o-play')
                ->badge(fn () => $count(DailyTask::DOING))
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DailyTask::DOING)),
            'done' => Tab::make('تم التنفيذ')
                ->icon('heroicon-o-check-circle')
                ->badge(fn () => $count(DailyTask::DONE))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DailyTask::DONE)),
            'all' => Tab::make('الكل'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('إضافة مهمة'),

            // End of the day: the leader sends the team's day (tasks, proofs, feedback) to the owner.
            Actions\Action::make('send_report')
                ->label('إرسال تقرير اليوم')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn () => DailyTaskResource::managedTeamIds() !== [])
                ->form([
                    Forms\Components\Select::make('team_id')
                        ->label('التيم')
                        ->options(fn () => Team::query()->whereKey(DailyTaskResource::managedTeamIds())->pluck('name', 'id'))
                        ->default(fn () => count($ids = DailyTaskResource::managedTeamIds()) === 1 ? $ids[0] : null)
                        ->required(),
                    Forms\Components\DatePicker::make('report_date')
                        ->label('اليوم')
                        ->default(today())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required(),
                    Forms\Components\Textarea::make('notes')
                        ->label('ملاحظات التيم ليدر')
                        ->rows(3),
                ])
                ->modalDescription('التقرير بيروح لهتلر بكل مهام اليوم: اللي اتنفذ بإثباته واللي لسه.')
                ->modalSubmitActionLabel('إرسال')
                ->action(function (array $data) {
                    abort_unless(in_array((int) $data['team_id'], DailyTaskResource::managedTeamIds(), true), 403);

                    $tasks = DailyTask::query()
                        ->where('team_id', $data['team_id'])
                        ->whereDate('task_date', $data['report_date'])
                        ->get();

                    if ($tasks->isEmpty()) {
                        Notification::make()->title('مفيش مهام في اليوم ده للتيم ده')->warning()->send();

                        return;
                    }

                    $report = DB::transaction(function () use ($data, $tasks) {
                        $report = DailyTaskReport::create([
                            'team_id' => $data['team_id'],
                            'sent_by' => auth()->id(),
                            'report_date' => $data['report_date'],
                            'tasks_total' => $tasks->count(),
                            'tasks_done' => $tasks->where('status', DailyTask::DONE)->count(),
                            'notes' => $data['notes'] ?? null,
                        ]);

                        DailyTask::whereKey($tasks->pluck('id'))->update(['report_id' => $report->id]);

                        return $report;
                    });

                    $team = Team::find($data['team_id']);

                    StaffNotifier::owners(
                        "تقرير مهام {$team->name} - ".$report->report_date->format('d/m/Y'),
                        auth()->user()->name." بعت تقرير اليوم: اتنفذ {$report->tasks_done} من {$report->tasks_total} مهمة.",
                        DailyTaskReportResource::getUrl('view', ['record' => $report]),
                        'daily-report-'.$report->id,
                    );

                    Notification::make()->title('التقرير اتبعت لهتلر')->success()->send();
                }),
        ];
    }
}
