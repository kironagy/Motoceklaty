<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InstallmentSystemResource\Pages;
use App\Models\InstallmentSystem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InstallmentSystemResource extends Resource
{
    protected static ?string $model = InstallmentSystem::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';
    protected static ?string $navigationLabel = 'أنظمة التقسيط';
    protected static ?string $pluralLabel = 'أنظمة التقسيط';
    protected static ?string $label = 'نظام التقسيط';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('اسم النظام')
                ->required()
                ->columnSpanFull(),

            // DEC-01: يحدد المعادلة اللي هتستخدم فعليًا - مش اسم النظام.
            Forms\Components\Select::make('pricing_mode')
                ->label('طريقة الحساب')
                ->options([
                    'standard' => 'عادي (سعر التقسيط)',
                    'zero_fees' => 'زيرو مصاريف (سعر الكاش + 7.5%)',
                ])
                ->default('standard')
                ->required()
                ->columnSpanFull(),

            // 🟢 المصاريف الإدارية
            Forms\Components\TextInput::make('administrative_fees')
                ->label('المصاريف الإدارية (%)')
                ->numeric()
                ->suffix('%')
                ->default(0)
                ->required()
                ->columnSpanFull(),

            Forms\Components\TextInput::make('minimum_down_payment')
                ->label('أقل مقدم (اختياري)')
                ->numeric()
                ->suffix('جنيه')
                ->columnSpanFull(),

            // البوت بيختار للعميل أوفر نظام تلقائيًا من الأنظمة اللي شروطها تنطبق عليه
            Forms\Components\Section::make('شروط النظام (البوت بيختار أوفر نظام للعميل من الأنظمة اللي تنطبق عليه)')
                ->schema([
                    Forms\Components\Toggle::make('is_active')
                        ->label('النظام شغال')
                        ->default(true),

                    Forms\Components\Toggle::make('no_upfront_only')
                        ->label('نظام "من غير ولا مليم وقت الاستلام"')
                        ->helperText('من غير مقدم ولا مصاريف. البوت مش بيعرضه في العرض العادي - بس للعميل اللي مصمم ما يدفعش أي حاجة وقت الاستلام')
                        ->default(false),

                    Forms\Components\TextInput::make('priority')
                        ->label('الأولوية لو التكلفة متساوية (الأكبر يتقدم)')
                        ->numeric()
                        ->default(0),

                    Forms\Components\Select::make('customer_type_ids')
                        ->label('متاح لأنواع العملاء دي بس (فاضي = الكل)')
                        ->multiple()
                        ->options(fn () => \App\Models\CustomerType::where('is_active', true)->pluck('label', 'id')->all()),

                    Forms\Components\Select::make('governorates')
                        ->label('متاح في المحافظات دي بس (فاضي = الكل)')
                        ->multiple()
                        ->options(config('agent.governorates')),

                    Forms\Components\TextInput::make('max_financed_amount')
                        ->label('أقصى مبلغ يتقسط في النظام ده (اختياري)')
                        ->numeric()
                        ->suffix('جنيه'),
                ])
                ->columns(2)
                ->columnSpanFull(),

            // 🔵 الخطط
            Forms\Components\Repeater::make('plans')
                ->label('خطط التقسيط')
                ->schema([
                    Forms\Components\TextInput::make('months')
                        ->label('عدد الشهور')
                        ->numeric()
                        ->required(),

                    Forms\Components\TextInput::make('interest')
                        ->label('نسبة الفايدة (%)')
                        ->numeric()
                        ->suffix('%')
                        ->required(),
                ])
                ->columns(2)
                ->createItemButtonLabel('إضافة خطة جديدة')
                ->columnSpanFull(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم النظام')
                    ->searchable(),

                Tables\Columns\TextColumn::make('pricing_mode')
                    ->label('طريقة الحساب')
                    ->formatStateUsing(fn (string $state) => $state === 'zero_fees' ? 'زيرو مصاريف' : 'عادي')
                    ->badge(),

                // 🟡 عرض المصاريف الإدارية
                Tables\Columns\TextColumn::make('administrative_fees')
                    ->label('المصاريف الإدارية')
                    ->suffix('%')
                    ->formatStateUsing(fn($state) => number_format($state, 2)),

                Tables\Columns\TextColumn::make('plans')
                    ->label('الخطط')
                    ->formatStateUsing(function ($state) {
                        if (blank($state)) return '-';

                        if (is_string($state)) {
                            $decoded = json_decode($state, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $state = $decoded;
                            } else {
                                return '-';
                            }
                        }

                        if (!is_array($state)) return '-';

                        return collect($state)
                            ->map(fn($plan) => "{$plan['months']} شهر: {$plan['interest']}%")
                            ->join(', ');
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->date(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstallmentSystems::route('/'),
            'create' => Pages\CreateInstallmentSystem::route('/create'),
            'edit' => Pages\EditInstallmentSystem::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return true;
        }

        return $user->is_admin ?? false;
    }
}
