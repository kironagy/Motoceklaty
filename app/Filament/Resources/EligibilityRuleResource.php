<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EligibilityRuleResource\Pages;
use App\Models\CustomerType;
use App\Models\EligibilityRule;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EligibilityRuleResource extends Resource
{
    protected static ?string $model = EligibilityRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = 'إعدادات الطلبات';
    protected static ?string $navigationLabel = 'قواعد الأهلية';
    protected static ?string $modelLabel = 'قاعدة أهلية';
    protected static ?string $pluralModelLabel = 'قواعد الأهلية';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('customer_type_id')->label('نوع العميل (فارغ = كل الأنواع)')
                ->options(CustomerType::pluck('label', 'id'))->searchable(),

            Select::make('rule_type')->label('نوع القاعدة')
                ->options(array_combine(array_keys(config('agent.eligibility_rules', [])), array_keys(config('agent.eligibility_rules', []))))
                ->required(),

            KeyValue::make('params')
                ->label('القيم')
                ->keyLabel('المتغير')
                ->valueLabel('القيمة')
                ->helperText('مثال لـ age_range: min=21, max=60')
                ->required(),

            Toggle::make('is_active')->label('نشط')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customerType.label')->label('نوع العميل')->default('كل الأنواع'),
                Tables\Columns\TextColumn::make('rule_type')->label('نوع القاعدة'),
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
            'index' => Pages\ListEligibilityRules::route('/'),
            'create' => Pages\CreateEligibilityRule::route('/create'),
            'edit' => Pages\EditEligibilityRule::route('/{record}/edit'),
        ];
    }
}
