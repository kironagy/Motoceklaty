<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BranchResource\Pages;
use App\Models\Branch;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'المعرض';
    protected static ?string $navigationLabel = 'الفروع';
    protected static ?string $modelLabel = 'فرع';
    protected static ?string $pluralModelLabel = 'الفروع';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->schema([
                TextInput::make('name')->label('اسم الفرع')->required(),
                Select::make('governorate')
                    ->label('المحافظة')
                    ->options(config('agent.governorates', []))
                    ->searchable()
                    ->required(),
                TextInput::make('city')->label('المدينة')->required(),
                TextInput::make('address')->label('العنوان')->required(),
                TextInput::make('map_url')->label('رابط الخريطة')->url(),
                TagsInput::make('phones')->label('أرقام التليفون'),
                TagsInput::make('services')->label('الخدمات المتاحة'),
                KeyValue::make('working_hours')
                    ->label('مواعيد العمل')
                    ->keyLabel('الأيام')
                    ->valueLabel('من - إلى'),
                TextInput::make('sort')->label('الترتيب')->numeric()->default(0),
                Toggle::make('is_active')->label('نشط')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('اسم الفرع')->searchable(),
                Tables\Columns\TextColumn::make('governorate')
                    ->label('المحافظة')
                    ->formatStateUsing(fn ($state) => config("agent.governorates.{$state}", $state)),
                Tables\Columns\TextColumn::make('city')->label('المدينة'),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
                Tables\Columns\TextColumn::make('sort')->label('الترتيب')->sortable(),
            ])
            ->defaultSort('sort')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranches::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'edit' => Pages\EditBranch::route('/{record}/edit'),
        ];
    }
}
