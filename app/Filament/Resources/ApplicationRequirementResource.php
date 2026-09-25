<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApplicationRequirementResource\Pages;
use App\Models\ApplicationRequirement;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\RequirementField;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ApplicationRequirementResource extends Resource
{
    protected static ?string $model = ApplicationRequirement::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationGroup = 'إعدادات الطلبات';
    protected static ?string $navigationLabel = 'متطلبات الطلب';
    protected static ?string $modelLabel = 'متطلب';
    protected static ?string $pluralModelLabel = 'متطلبات الطلب';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('customer_type_id')->label('نوع العميل')
                ->options(CustomerType::pluck('label', 'id'))->required()->searchable(),

            Select::make('requirement_type')->label('النوع')
                ->options(['field' => 'حقل بيانات', 'document' => 'مستند'])
                ->required()->live(),

            Select::make('requirement_field_id')->label('الحقل')
                ->options(fn () => RequirementField::pluck('label', 'id'))
                ->visible(fn (Get $get) => $get('requirement_type') === 'field')
                ->required(fn (Get $get) => $get('requirement_type') === 'field'),

            Select::make('document_type_id')->label('المستند')
                ->options(fn () => DocumentType::pluck('label', 'id'))
                ->visible(fn (Get $get) => $get('requirement_type') === 'document')
                ->required(fn (Get $get) => $get('requirement_type') === 'document'),

            Toggle::make('is_required')->label('إجباري')->default(true),

            Fieldset::make('يظهر فقط لو (اختياري)')
                ->schema([
                    TextInput::make('condition.fact')->label('الحقيقة')
                        ->placeholder('selection.financed_amount'),
                    Select::make('condition.op')->label('المقارنة')
                        ->options(['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'eq' => '=', 'in' => 'ضمن قائمة']),
                    TextInput::make('condition.value')->label('القيمة'),
                ])
                ->columns(3),

            TextInput::make('sort')->label('الترتيب (استرشادي فقط)')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customerType.label')->label('نوع العميل'),
                Tables\Columns\TextColumn::make('requirement_type')->label('النوع'),
                Tables\Columns\TextColumn::make('requirementField.label')->label('الحقل'),
                Tables\Columns\TextColumn::make('documentType.label')->label('المستند'),
                Tables\Columns\IconColumn::make('is_required')->label('إجباري')->boolean(),
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
            'index' => Pages\ListApplicationRequirements::route('/'),
            'create' => Pages\CreateApplicationRequirement::route('/create'),
            'edit' => Pages\EditApplicationRequirement::route('/{record}/edit'),
        ];
    }
}
