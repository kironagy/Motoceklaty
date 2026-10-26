<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GuideResource\Pages\ListGuideEvents;
use App\Models\GuideEvent;
use App\Models\Staff;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class GuideResource extends Resource
{
    protected static ?string $model = GuideEvent::class;
    protected static ?string $slug = 'guide';
    protected static ?string $navigationIcon = 'heroicon-o-eye';
    protected static ?string $navigationLabel = 'المرشد';
    protected static ?string $modelLabel = 'حدث';
    protected static ?string $pluralModelLabel = 'المرشد';
    protected static ?int $navigationSort = -10;

    public static function canViewAny(): bool
    {
        $staff = Auth::guard('filament')->user();

        return $staff instanceof Staff && in_array((int) $staff->getKey(), [25, 27], true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function can(string $action, ?Model $record = null): bool
    {
        return in_array($action, ['view', 'viewAny'], true) && static::canViewAny();
    }

    public static function canView(Model $record): bool { return static::canViewAny(); }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }
    public static function canGloballySearch(): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        abort_unless(static::canViewAny(), 403);
        return parent::getEloquentQuery()->people();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')->poll('15s')
            ->striped()->paginated([25, 50, 100])
            ->heading('مين غيّر إيه؟')->description('تحركات الأشخاص فقط • بدون نشاط السيرفر والبوتات')
            ->emptyStateHeading('مفيش تحركات أشخاص في الفترة دي')
            ->emptyStateDescription('عمليات الموظفين الجديدة هتظهر هنا تلقائيًا.')
            ->emptyStateIcon('heroicon-o-eye')
            ->columns([
                Tables\Columns\TextColumn::make('actor_name')->label('الموظف')->searchable()
                    ->icon('heroicon-o-user-circle')->weight('bold'),
                Tables\Columns\TextColumn::make('event')->label('العملية')->badge()
                    ->formatStateUsing(fn ($state) => GuideEvent::EVENTS[$state] ?? $state)
                    ->color(fn (GuideEvent $record) => $record->color()),
                Tables\Columns\TextColumn::make('subject_label')->label('العنصر')->formatStateUsing(fn (GuideEvent $record) => $record->subjectTitle())->searchable()->wrap(),
                Tables\Columns\TextColumn::make('description')->label('التغيير اللي حصل')->searchable()->wrap()
                    ->formatStateUsing(fn (GuideEvent $record) => $record->changeSummary()),
                Tables\Columns\TextColumn::make('created_at')->label('الوقت')->dateTime('d/m/Y — H:i')
                    ->timezone('Africa/Cairo')->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('legacy')->label('السجل القديم فقط')->query(fn (Builder $query) => $query->whereNotNull('legacy_source')),
                Tables\Filters\SelectFilter::make('event')->label('نوع العملية')->options(GuideEvent::EVENTS)->multiple(),
                Tables\Filters\SelectFilter::make('actor_name')->label('صاحب العملية')->searchable()
                    ->options(fn () => GuideEvent::query()->people()->distinct()->orderBy('actor_name')->pluck('actor_name', 'actor_name')->all()),
                Tables\Filters\SelectFilter::make('subject_type')->label('القسم')->searchable()
                    ->options(fn () => GuideEvent::query()->people()->whereNotNull('subject_type')->distinct()->pluck('subject_type')->mapWithKeys(
                        fn ($type) => [$type => \App\Support\GuideRecorder::LABELS[class_basename($type)] ?? class_basename($type)]
                    )->all()),
                Tables\Filters\Filter::make('period')->label('الفترة')
                    ->form([DatePicker::make('from')->label('من يوم'), DatePicker::make('until')->label('لحد يوم')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', \Carbon\Carbon::parse($date, 'Africa/Cairo')->startOfDay()->setTimezone(config('app.timezone'))))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<=', \Carbon\Carbon::parse($date, 'Africa/Cairo')->endOfDay()->setTimezone(config('app.timezone')))))
                    ->indicateUsing(fn (array $data) => array_filter([
                        isset($data['from']) ? 'من: '.$data['from'] : null,
                        isset($data['until']) ? 'إلى: '.$data['until'] : null,
                    ])),
            ])
            ->actions([
                Tables\Actions\Action::make('details')->label('التفاصيل')->icon('heroicon-o-magnifying-glass')
                    ->authorize(fn () => static::canViewAny())
                    ->modalHeading(fn (GuideEvent $record) => $record->actor_name.' • '.$record->subjectTitle())
                    ->modalContent(fn (GuideEvent $record) => view('filament.guide.details', ['record' => $record]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('تمام')->slideOver(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListGuideEvents::route('/')];
    }
}
