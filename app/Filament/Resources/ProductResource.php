<?php

namespace App\Filament\Resources;

use App\Models\Product;
use App\Filament\Resources\ProductResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class ProductResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    protected static ?string $model = Product::class;

    protected static ?string $navigationLabel = 'الأصناف';
    protected static ?string $navigationGroup = 'إدارة المعرض';
    protected static ?int $navigationSort = 6;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        $user = auth()->user();

        return \App\Support\Access::allows(static::class, 'view');
    }
    // 🧾 الفورم
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label('اسم الصنف')
                    ->required()
                    ->maxLength(255),
                \Filament\Forms\Components\Select::make('machine_id')
                    ->label('الموتوسيكل في الكتالوج')
                    ->helperText('اختياري - يربط الصنف بالموتوسيكل اللي البوت والموقع بيعرضوه.')
                    ->relationship('machine', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable(),
            ]);
    }

    // 📊 الجدول
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('اسم الصنف')
                    ->searchable(),
                TextColumn::make('machine.name')
                    ->label('الموتوسيكل في الكتالوج')
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    // 📄 الصفحات (المهم 🔥)
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
