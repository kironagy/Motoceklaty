<?php

namespace App\Filament\Resources;

use App\Domain\Applications\SnapshotService;
use App\Filament\Resources\ApplicationResource\Pages;
use App\Filament\Resources\ApplicationResource\RelationManagers\DataRelationManager;
use App\Filament\Resources\ApplicationResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\ApplicationResource\RelationManagers\EventsRelationManager;
use App\Models\Application;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only (T13 §7): applications are only ever created/mutated by the
 * agent tools. Staff can inspect the snapshot, data with provenance, and
 * the event trail, but never edit rows here.
 */
class ApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'واتساب';
    protected static ?string $navigationLabel = 'طلبات التمويل';
    protected static ?string $modelLabel = 'طلب';
    protected static ?string $pluralModelLabel = 'طلبات التمويل';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user && ($user->is_admin || $user->is_super_admin);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('customer.phone')->label('العميل'),
                Tables\Columns\TextColumn::make('customerType.label')->label('نوع العميل'),
                Tables\Columns\TextColumn::make('machine.name')->label('الموتوسيكل')->default('-'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge(),
                Tables\Columns\TextColumn::make('last_activity_at')->label('آخر نشاط')->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('id')->label('#'),
            TextEntry::make('customer.phone')->label('العميل'),
            TextEntry::make('customerType.label')->label('نوع العميل'),
            TextEntry::make('status')->label('الحالة'),
            TextEntry::make('machine.name')->label('الموتوسيكل')->default('-'),
            TextEntry::make('installmentPlan.months')->label('عدد الشهور')->default('-'),
            TextEntry::make('down_payment')->label('المقدم')->default('-'),
            TextEntry::make('last_activity_at')->label('آخر نشاط')->dateTime('Y-m-d H:i'),
            KeyValueEntry::make('snapshot')
                ->label('الحالة الحالية للطلب')
                ->state(fn (Application $record) => self::flattenSnapshot(app(SnapshotService::class)->for($record))),
        ]);
    }

    /** KeyValueEntry only renders scalars - the nested §3.3 snapshot is flattened to dotted keys for display. */
    private static function flattenSnapshot(array $snapshot, string $prefix = ''): array
    {
        $flat = [];

        foreach ($snapshot as $key => $value) {
            $label = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat += self::flattenSnapshot($value, $label);
            } else {
                $flat[$label] = is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '-');
            }
        }

        return $flat;
    }

    public static function getRelations(): array
    {
        return [
            DataRelationManager::class,
            DocumentsRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApplications::route('/'),
            'view' => Pages\ViewApplication::route('/{record}'),
        ];
    }
}
