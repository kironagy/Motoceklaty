<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RequirementFieldResource\Pages;
use App\Models\RequirementField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RequirementFieldResource extends Resource
{
    protected static ?string $model = RequirementField::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';
    protected static ?string $navigationGroup = 'إعدادات الطلبات';
    protected static ?string $navigationLabel = 'حقول البيانات';
    protected static ?string $modelLabel = 'حقل';
    protected static ?string $pluralModelLabel = 'حقول البيانات';

    private const DATA_TYPES = [
        'string' => 'نص',
        'person_name' => 'اسم شخص',
        'national_id' => 'رقم قومي',
        'phone' => 'تليفون',
        'date' => 'تاريخ',
        'money' => 'مبلغ',
        'integer' => 'رقم صحيح',
        'address' => 'عنوان',
        'enum' => 'قائمة خيارات',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('key')->label('المفتاح')->required()->unique(ignoreRecord: true),
            TextInput::make('label')->label('الاسم')->required(),
            Select::make('data_type')->label('نوع البيانات')->options(self::DATA_TYPES)->required()->live(),
            TagsInput::make('enum_options')
                ->label('الخيارات المتاحة')
                ->visible(fn (Get $get) => $get('data_type') === 'enum'),
            Select::make('scope')->label('النطاق')->options([
                'customer' => 'عميل', 'application' => 'طلب', 'guarantor' => 'ضامن',
            ])->default('customer')->required(),
            Toggle::make('is_sensitive')->label('حساس (يتم إخفاؤه في السجلات)'),
            Textarea::make('description_for_ai')->label('شرح للذكاء الاصطناعي')->rows(2),
            Toggle::make('is_active')->label('نشط')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')->label('المفتاح'),
                Tables\Columns\TextColumn::make('label')->label('الاسم'),
                Tables\Columns\TextColumn::make('data_type')->label('النوع')->formatStateUsing(fn ($state) => self::DATA_TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('scope')->label('النطاق'),
                Tables\Columns\IconColumn::make('is_sensitive')->label('حساس')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRequirementFields::route('/'),
            'create' => Pages\CreateRequirementField::route('/create'),
            'edit' => Pages\EditRequirementField::route('/{record}/edit'),
        ];
    }
}
