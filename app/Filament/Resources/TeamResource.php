<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TeamResource\Pages;
use App\Models\Staff;
use App\Models\Team;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Teams: a leader and his members. The leader hands out the daily tasks (DailyTaskResource). */
class TeamResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;

    protected static ?string $model = Team::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'التيمات';
    protected static ?string $modelLabel = 'تيم';
    protected static ?string $pluralModelLabel = 'التيمات';
    protected static ?string $navigationGroup = 'إدارة الموظفين';
    protected static ?int $navigationSort = 7;

    /** Without a role: the owner manages teams; a leader sees his own. */
    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return (bool) auth()->user()?->is_hitler || Team::ledBy(auth()->id()) !== [];
    }

    public static function canCreate(): bool
    {
        return static::roleAllows('create') ?? (bool) auth()->user()?->is_hitler;
    }

    public static function canEdit($record): bool
    {
        return static::roleAllows('update') ?? (bool) auth()->user()?->is_hitler;
    }

    public static function canDelete($record): bool
    {
        return static::roleAllows('delete') ?? (bool) auth()->user()?->is_hitler;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery()->with(['leader', 'members'])->withCount('members');

        if (! auth()->user()?->is_hitler && static::roleAllows('update') !== true) {
            $query->whereKey(Team::of(auth()->id()));
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('التيم')
                ->icon('heroicon-o-user-group')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('اسم التيم')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('leader_id')
                        ->label('التيم ليدر')
                        ->helperText('هو اللي بيضيف المهام اليومية للتيم وبيبعت التقرير آخر اليوم.')
                        ->options(fn () => Staff::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('members')
                        ->label('أعضاء التيم')
                        ->relationship('members', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('notes')
                        ->label('ملاحظات')
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('مفيش تيمات لسه')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('التيم')->weight('bold')->searchable(),
                Tables\Columns\TextColumn::make('leader.name')->label('التيم ليدر')->icon('heroicon-m-star')->searchable(),
                Tables\Columns\TextColumn::make('members_count')->label('عدد الأعضاء')->badge(),
                Tables\Columns\TextColumn::make('members.name')->label('الأعضاء')->badge()->color('gray')->limitList(4)->expandableLimitedList(),
            ])
            ->actions([
                Tables\Actions\Action::make('tasks')
                    ->label('المهام')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->url(fn (Team $record) => DailyTaskResource::getUrl('index', ['tableFilters' => ['team_id' => ['value' => $record->id]]])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeams::route('/'),
            'create' => Pages\CreateTeam::route('/create'),
            'edit' => Pages\EditTeam::route('/{record}/edit'),
        ];
    }
}
