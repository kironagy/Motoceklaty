<?php

namespace App\Filament\Resources;

use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use App\Filament\Resources\WarehouseResource\Pages;
class WarehouseResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    protected static ?string $model = Warehouse::class;

    protected static ?string $navigationLabel = 'المخازن';
    protected static ?string $navigationGroup = 'إدارة المعرض';
    protected static ?int $navigationSort = 5;

	protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
   public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

    $user = auth()->user();

            return \App\Support\Access::allows(static::class, 'view');


    }
    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('اسم المخزن')
                ->required(),
            \Filament\Forms\Components\Select::make('branch_id')
                ->label('فرع الموقع المرتبط')
                ->helperText('اختياري - سيبه فاضي لو المخزن مش فرع (زي مصاريف).')
                ->relationship('branch', 'name')
                ->searchable()
                ->preload()
                ->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('اسم المخزن'),
                TextColumn::make('branch.name')->label('فرع الموقع')->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
		Tables\Actions\DeleteAction::make(),
           
 ]);
    }

		public static function getPages(): array
{
    return [
        'index' => Pages\ListWarehouses::route('/'),
        'create' => Pages\CreateWarehouse::route('/create'),
        'edit' => Pages\EditWarehouse::route('/{record}/edit'),
    ];
}

}
