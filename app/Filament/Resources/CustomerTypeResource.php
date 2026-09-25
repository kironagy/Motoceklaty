<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerTypeResource\Pages;
use App\Models\CustomerType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerTypeResource extends Resource
{
    protected static ?string $model = CustomerType::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'إعدادات الطلبات';
    protected static ?string $navigationLabel = 'أنواع العملاء';
    protected static ?string $modelLabel = 'نوع عميل';
    protected static ?string $pluralModelLabel = 'أنواع العملاء';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('key')->label('المفتاح')->required()->unique(ignoreRecord: true)
                ->helperText('معرّف بالإنجليزي، مثال: self_employed'),
            TextInput::make('label')->label('الاسم')->required(),
            Select::make('legacy_work_status')
                ->label('الحالة الوظيفية القديمة (لطلب التقسيط القديم)')
                ->helperText('لازم تتحدد قبل ما أي طلب من النوع ده يقدر يتحول لطلب تقسيط قديم (DEC-21).')
                ->options([
                    'employee' => 'موظف',
                    'pension' => 'معاش',
                    'self_employed' => 'صاحب نشاط',
                    'no_income_proof' => 'بدون إثبات دخل',
                ]),
            TextInput::make('sort')->label('الترتيب')->numeric()->default(0),
            Toggle::make('is_active')->label('نشط')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')->label('المفتاح'),
                Tables\Columns\TextColumn::make('label')->label('الاسم'),
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
            'index' => Pages\ListCustomerTypes::route('/'),
            'create' => Pages\CreateCustomerType::route('/create'),
            'edit' => Pages\EditCustomerType::route('/{record}/edit'),
        ];
    }
}
