<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LegacyStatusMappingResource\Pages;
use App\Models\LegacyStatusMapping;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * DEC-21 (agreed 2026-09-23): the owner fills in a status mapping the
 * implementer must not guess. A NULL application_status means
 * InstallmentRequestObserver leaves the application's status untouched
 * for that legacy status and only logs an event for staff visibility.
 */
class LegacyStatusMappingResource extends Resource
{
    protected static ?string $model = LegacyStatusMapping::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationGroup = 'إعدادات الطلبات';
    protected static ?string $navigationLabel = 'ربط حالات الطلبات القديمة';
    protected static ?string $modelLabel = 'ربط حالة';
    protected static ?string $pluralModelLabel = 'ربط حالات الطلبات القديمة';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('application_status')
                ->label('الحالة المقابلة في نظام الطلبات الجديد')
                ->helperText('سيبها فاضية لو مش متأكد من المعنى - النظام مش هيخمن.')
                ->options([
                    'under_review' => 'تحت المراجعة',
                    'needs_more_info' => 'ناقصة بيانات',
                    'approved' => 'موافق عليها',
                    'rejected' => 'مرفوضة',
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('legacy_status')->label('الحالة القديمة'),
                Tables\Columns\TextColumn::make('application_status')
                    ->label('الحالة الجديدة المقابلة')
                    ->default('لم تحدد بعد')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLegacyStatusMappings::route('/'),
            'edit' => Pages\EditLegacyStatusMapping::route('/{record}/edit'),
        ];
    }
}
