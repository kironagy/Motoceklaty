<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyTaskResource\Pages;
use App\Models\DailyTask;
use App\Models\DailyTaskFile;
use App\Models\Staff;
use App\Models\Team;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily tasks (a to-do board per team). The team leader adds a task for one member, with files to
 * download; the member moves it to "in progress", then to "done" with proof (screenshots, files); at the end of the day the
 * leader sends the day's report to the owner (DailyTaskReportResource).
 */
class DailyTaskResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use \App\Filament\Concerns\HasFullAccess;

    protected static ?string $model = DailyTask::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'المهام اليومية';
    protected static ?string $modelLabel = 'مهمة';
    protected static ?string $pluralModelLabel = 'المهام اليومية';
    protected static ?string $navigationGroup = 'إدارة الموظفين';
    protected static ?int $navigationSort = 8;

    public const UPLOAD_DIR = 'daily-tasks';

    /** Owner or full access: every team. */
    public static function isManager(): bool
    {
        return static::fullAccess();
    }

    /** Teams whose tasks the user hands out. */
    public static function managedTeamIds(): array
    {
        return static::isManager() ? Team::query()->pluck('id')->all() : Team::ledBy(auth()->id());
    }

    public static function canManage(?DailyTask $task): bool
    {
        return $task && in_array($task->team_id, static::managedTeamIds(), true);
    }

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return static::isManager() || Team::of(auth()->id()) !== [];
    }

    /** Only a team leader (or full access) adds tasks - a role can close it, not open it to members. */
    public static function canCreate(): bool
    {
        return (static::roleAllows('create') ?? true) && static::managedTeamIds() !== [];
    }

    public static function canEdit($record): bool
    {
        return (static::roleAllows('update') ?? true) && static::canManage($record) && ! $record->report_id;
    }

    public static function canDelete($record): bool
    {
        return (static::roleAllows('delete') ?? true) && static::canManage($record) && ! $record->report_id;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = DailyTask::query()->where('assigned_to', auth()->id())->where('status', DailyTask::TODO)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['team', 'assignee', 'creator'])->withCount(['attachments', 'proofs']);

        if (static::isManager()) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->where('assigned_to', auth()->id())
            ->orWhereIn('team_id', Team::ledBy(auth()->id())));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('المهمة')
                ->icon('heroicon-o-clipboard-document-check')
                ->schema([
                    Forms\Components\Select::make('team_id')
                        ->label('التيم')
                        ->options(fn () => Team::query()->whereKey(static::managedTeamIds())->pluck('name', 'id'))
                        ->default(fn () => count($ids = static::managedTeamIds()) === 1 ? $ids[0] : null)
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('assigned_to', null))
                        ->required(),

                    Forms\Components\Select::make('assigned_to')
                        ->label('لمين')
                        ->options(fn (Forms\Get $get) => ($team = Team::with('members')->find($get('team_id')))
                            ? $team->members->sortBy('name')->pluck('name', 'id')
                            : [])
                        ->searchable()
                        ->required(),

                    Forms\Components\TextInput::make('title')
                        ->label('المهمة')
                        ->placeholder('مثال: كلم الأرقام اللي في الملف وابعتلي فيدباك')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('التفاصيل')
                        ->rows(3)
                        ->columnSpanFull(),

                    Forms\Components\DatePicker::make('task_date')
                        ->label('يوم المهمة')
                        ->default(today())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required(),

                    Forms\Components\FileUpload::make('new_attachments')
                        ->label('ملفات للمهمة (إكسيل، تيكست، صور...)')
                        ->helperText('الموظف هيقدر ينزلها من المهمة.')
                        ->multiple()
                        ->disk(DailyTaskFile::DISK)
                        ->directory(static::UPLOAD_DIR)
                        ->storeFileNamesIn('new_attachment_names')
                        ->maxSize(20480)
                        ->downloadable()
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    /** Files from a FileUpload (paths + original names) become rows of the task. */
    public static function saveFiles(DailyTask $task, array $paths, array $names, string $kind): void
    {
        foreach (array_values($paths) as $path) {
            DailyTaskFile::create([
                'daily_task_id' => $task->id,
                'kind' => $kind,
                'path' => $path,
                'original_name' => $names[$path] ?? null,
                'uploaded_by' => auth()->id(),
            ]);
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('task_date', 'desc')
            ->emptyStateIcon('heroicon-o-clipboard-document-check')
            ->emptyStateHeading('مفيش مهام هنا')
            ->emptyStateDescription(fn () => \App\Models\Team::query()->exists()
                ? 'التيم ليدر بيضيف المهام من زرار «إضافة مهمة».'
                : 'اعمل تيم الأول من صفحة «التيمات» وحدد التيم ليدر والأعضاء، بعدها التيم ليدر يقدر يضيف المهام.')
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->paginated([12, 24, 48])
            ->columns([
                Stack::make([
                    Split::make([
                        Tables\Columns\TextColumn::make('status')
                            ->badge()
                            ->formatStateUsing(fn ($state) => DailyTask::STATUSES[$state] ?? $state)
                            ->color(fn ($state) => DailyTask::COLORS[$state] ?? 'gray')
                            ->grow(false),
                        Tables\Columns\TextColumn::make('task_date')
                            ->date('d/m/Y')
                            ->icon('heroicon-m-calendar')
                            ->color('gray')
                            ->alignEnd(),
                    ]),
                    Tables\Columns\TextColumn::make('title')
                        ->weight(FontWeight::Bold)
                        ->size(Tables\Columns\TextColumn\TextColumnSize::Large)
                        ->searchable()
                        ->wrap(),
                    Tables\Columns\TextColumn::make('description')
                        ->color('gray')
                        ->limit(120)
                        ->wrap(),
                    Split::make([
                        Tables\Columns\TextColumn::make('assignee.name')
                            ->icon('heroicon-m-user')
                            ->searchable(),
                        Tables\Columns\TextColumn::make('team.name')
                            ->icon('heroicon-m-user-group')
                            ->color('gray')
                            ->alignEnd(),
                    ]),
                    Split::make([
                        Tables\Columns\TextColumn::make('attachments_count')
                            ->formatStateUsing(fn ($state) => "📎 {$state} ملف")
                            ->visible(fn () => true)
                            ->color('gray'),
                        Tables\Columns\TextColumn::make('proofs_count')
                            ->formatStateUsing(fn ($state) => "✅ {$state} إثبات")
                            ->color('success')
                            ->alignEnd(),
                    ]),
                    Tables\Columns\TextColumn::make('report_id')
                        ->formatStateUsing(fn ($state) => '📤 اتبعتت في التقرير')
                        ->color('info'),
                ])->space(2),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('team_id')
                    ->label('التيم')
                    ->options(fn () => Team::query()->whereKey(static::isManager() ? Team::query()->pluck('id') : Team::of(auth()->id()))->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('الموظف')
                    ->options(fn () => Staff::query()->whereIn('id', DailyTask::query()->select('assigned_to'))->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                Tables\Filters\Filter::make('task_date')
                    ->form([
                        Forms\Components\DatePicker::make('date')->label('اليوم')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['date'] ?? null, fn ($q, $d) => $q->whereDate('task_date', $d)))
                    ->indicateUsing(fn (array $data) => ($data['date'] ?? null) ? 'يوم '.\Carbon\Carbon::parse($data['date'])->format('d/m/Y') : null),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('فتح')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (DailyTask $record) => $record->title)
                    ->modalContent(fn (DailyTask $record) => view('filament.resources.daily-tasks.task', ['task' => $record->load(['files', 'assignee', 'creator', 'team'])]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('قفل'),

                Tables\Actions\Action::make('start')
                    ->label('ابدأ')
                    ->icon('heroicon-o-play')
                    ->color('info')
                    ->visible(fn (DailyTask $record) => static::canStart($record))
                    ->action(fn (DailyTask $record) => static::start($record)),

                Tables\Actions\Action::make('done')
                    ->label('تم التنفيذ')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DailyTask $record) => static::canFinish($record))
                    ->modalHeading(fn (DailyTask $record) => 'خلصت: '.$record->title)
                    ->modalDescription('ارفع إثبات إنك خلصت المهمة (سكرينات أو ملف).')
                    ->form(static::doneForm())
                    ->action(fn (DailyTask $record, array $data) => static::finish($record, $data)),

                Tables\Actions\Action::make('reopen')
                    ->label('رجّعها مطلوب')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (DailyTask $record) => static::canReopen($record))
                    ->requiresConfirmation()
                    ->action(fn (DailyTask $record) => static::reopen($record)),

                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    /** The member (or the leader) works on it while it is open and not yet in a sent report. */
    public static function canWork(DailyTask $task): bool
    {
        return ! $task->report_id && ((int) $task->assigned_to === (int) auth()->id() || static::canManage($task));
    }

    public static function canStart(DailyTask $task): bool
    {
        return $task->status === DailyTask::TODO && static::canWork($task);
    }

    public static function canFinish(DailyTask $task): bool
    {
        return $task->status !== DailyTask::DONE && static::canWork($task);
    }

    public static function canReopen(DailyTask $task): bool
    {
        return $task->status !== DailyTask::TODO && ! $task->report_id && static::canManage($task);
    }

    public static function start(DailyTask $task): void
    {
        abort_unless(static::canStart($task), 403);

        $task->update(['status' => DailyTask::DOING, 'started_at' => now()]);

        Notification::make()->title('المهمة اتنقلت لـ «جاري التنفيذ»')->success()->send();
    }

    public static function doneForm(): array
    {
        return [
            Forms\Components\FileUpload::make('proofs')
                ->label('الإثبات')
                ->multiple()
                ->required()
                ->disk(DailyTaskFile::DISK)
                ->directory(static::UPLOAD_DIR.'/proofs')
                ->storeFileNamesIn('proof_names')
                ->maxSize(20480),
            Forms\Components\Textarea::make('done_note')
                ->label('فيدباك / ملاحظة')
                ->rows(3),
        ];
    }

    public static function finish(DailyTask $task, array $data): void
    {
        abort_unless(static::canFinish($task), 403);

        static::saveFiles($task, (array) $data['proofs'], (array) ($data['proof_names'] ?? []), DailyTaskFile::PROOF);
        $task->update([
            'status' => DailyTask::DONE,
            'started_at' => $task->started_at ?? now(),
            'done_note' => $data['done_note'] ?? null,
            'done_at' => now(),
        ]);

        $leader = $task->team?->leader_id;

        if ($leader && (int) $leader !== (int) auth()->id()) {
            \App\Support\StaffNotifier::send(
                (int) $leader,
                'مهمة اتنفذت',
                auth()->user()->name.' خلص: '.$task->title,
                static::getUrl('index'),
                'daily-task-'.$task->id,
            );
        }

        Notification::make()->title('المهمة اتنقلت لـ «تم التنفيذ»')->success()->send();
    }

    public static function reopen(DailyTask $task): void
    {
        abort_unless(static::canReopen($task), 403);

        $task->update(['status' => DailyTask::TODO, 'started_at' => null, 'done_at' => null]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyTasks::route('/'),
            'create' => Pages\CreateDailyTask::route('/create'),
            'edit' => Pages\EditDailyTask::route('/{record}/edit'),
        ];
    }
}
