<?php

namespace App\Filament\Resources;

use App\Support\FinanceAccess;
use App\Filament\Resources\TargetResource\Pages;
use App\Models\Target;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TargetResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    protected static ?string $model = Target::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';
    protected static ?string $navigationLabel = 'التارجت';
    protected static ?string $modelLabel = 'تارجت';
    protected static ?string $pluralModelLabel = 'التارجت';
    protected static ?string $navigationGroup = 'إدارة المعرض';
    protected static ?int $navigationSort = 4;

    protected static function isSuperAdmin(): bool
    {
        $user = auth()->user();

        return (bool) (
            $user &&
            (
                FinanceAccess::isSuper($user)
            )
        );
    }

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return static::isSuperAdmin();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('بيانات التارجت')
                    ->icon('heroicon-o-flag')
                    ->schema([
                        Forms\Components\Select::make('warehouse_id')
                            ->label('المعرض / المخزن')
                            ->relationship('warehouse', 'name')
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(),

                        Forms\Components\TextInput::make('target_count')
                            ->label('عدد المكن المطلوب بيعه')
                            ->numeric()
                            ->minValue(1)
                            ->required(),

                        Forms\Components\DatePicker::make('from_date')
                            ->label('من تاريخ'),

                        Forms\Components\DatePicker::make('to_date')
                            ->label('إلى تاريخ'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('مفعل')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('warehouse.name')
                    ->label('المعرض / المخزن')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('target_count')
                    ->label('التارجت')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sold_count')
                    ->label('المباع')
                    ->getStateUsing(fn (Target $record) => $record->soldCount()),

                Tables\Columns\TextColumn::make('progress')
                    ->label('العداد')
                    ->badge()
                    ->getStateUsing(fn (Target $record) => $record->target_count . '/' . $record->soldCount())
                    ->color(fn (Target $record) => $record->soldCount() >= $record->target_count ? 'success' : 'warning'),

                Tables\Columns\TextColumn::make('from_date')
                    ->label('من')
                    ->date('Y-m-d')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('to_date')
                    ->label('إلى')
                    ->date('Y-m-d')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('مفعل')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->label('فلترة بالمعرض')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('مفعل'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('تعديل'),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->label('حذف المحدد'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTargets::route('/'),
            'create' => Pages\CreateTarget::route('/create'),
            'edit' => Pages\EditTarget::route('/{record}/edit'),
        ];
    }
}
