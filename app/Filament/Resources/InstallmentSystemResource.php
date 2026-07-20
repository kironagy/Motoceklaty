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

            // 🟢 المصاريف الإدارية
            Forms\Components\TextInput::make('administrative_fees')
                ->label('المصاريف الإدارية (%)')
                ->numeric()
                ->suffix('%')
                ->default(0)
                ->required()
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
