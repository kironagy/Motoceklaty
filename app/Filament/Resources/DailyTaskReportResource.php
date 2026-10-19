<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyTaskReportResource\Pages;
use App\Models\DailyTaskReport;
use App\Models\Team;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The daily reports team leaders send: the owner reads every team's day with its proofs. */
class DailyTaskReportResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use \App\Filament\Concerns\HasFullAccess;

    protected static ?string $model = DailyTaskReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';
    protected static ?string $navigationLabel = 'تقارير المهام';
    protected static ?string $modelLabel = 'تقرير مهام';
    protected static ?string $pluralModelLabel = 'تقارير المهام';
    protected static ?string $navigationGroup = 'إدارة الموظفين';
    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return static::fullAccess() || Team::ledBy(auth()->id()) !== [];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canView($record): bool
    {
        return (static::roleAllows('show') ?? true)
            && (static::fullAccess() || in_array($record->team_id, Team::ledBy(auth()->id()), true));
    }

    public static function canDelete($record): bool
    {
        return (static::roleAllows('delete') ?? true) && static::fullAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['team', 'sender']);

        return static::fullAccess() ? $query : $query->whereIn('team_id', Team::ledBy(auth()->id()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('مفيش تقارير لسه')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('report_date')->label('اليوم')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('team.name')->label('التيم')->searchable(),
                Tables\Columns\TextColumn::make('sender.name')->label('بعته'),
                Tables\Columns\TextColumn::make('done')
                    ->label('اتنفذ')
                    ->badge()
                    ->state(fn (DailyTaskReport $r) => "{$r->tasks_done}/{$r->tasks_total}")
                    ->color(fn (DailyTaskReport $r) => $r->tasks_done >= $r->tasks_total ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('created_at')->label('اتبعت')->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('team_id')->label('التيم')->relationship('team', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('فتح التقرير'),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyTaskReports::route('/'),
            'view' => Pages\ViewDailyTaskReport::route('/{record}'),
        ];
    }
}
