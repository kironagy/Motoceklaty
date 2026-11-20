<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AmanExtensionControlResource\Pages;
use App\Models\Staff;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Owner 2026-11-19: "كنترول الاكستنشن" - the owner (Hitler) only. Lists Aman's employees (company
 * employees, and anyone who signed in to the extension) with a switch: off = the extension refuses
 * that employee (AmanExtensionToken::locked) and tells them the admin locked it.
 */
class AmanExtensionControlResource extends Resource
{
    protected static ?string $model = Staff::class;

    protected static ?string $slug = 'aman-extension-control';
    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';
    protected static ?string $navigationGroup = 'الطلبات والعملاء';
    protected static ?string $navigationLabel = 'كنترول الاكستنشن';
    protected static ?string $modelLabel = 'موظف أمان';
    protected static ?string $pluralModelLabel = 'كنترول الاكستنشن';
    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return (bool) (Auth::user()?->is_hitler ?? false);
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return Staff::query()
            ->where(fn (Builder $q) => $q->where('is_company_employee', true)
                ->orWhereIn('id', \App\Models\AmanToken::select('staff_id'))
                ->orWhereIn('id', \App\Models\AmanUpload::select('staff_id')))
            ->addSelect([
                'staff.*',
                'extension_last_used_at' => \App\Models\AmanToken::selectRaw('max(last_used_at)')->whereColumn('staff_id', 'staff.id'),
                'aman_uploads_today' => \App\Models\AmanUpload::selectRaw('count(*)')->whereColumn('staff_id', 'staff.id')->where('created_at', '>=', today()),
                'aman_uploads_total' => \App\Models\AmanUpload::selectRaw('count(*)')->whereColumn('staff_id', 'staff.id'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الموظف')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('email')->label('الإيميل')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('companies')->label('الشركة')
                    ->state(fn (Staff $record) => $record->installmentSystems()->pluck('installment_systems.name')->implode('، ') ?: '—'),
                Tables\Columns\TextColumn::make('extension_last_used_at')->label('آخر استخدام')
                    ->dateTime('d/m/Y h:i A')->since()->placeholder('ماستخدمهاش')->sortable(),
                Tables\Columns\TextColumn::make('aman_uploads_today')->label('رفع النهارده')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('aman_uploads_total')->label('رفع كله')->numeric()->sortable(),
                Tables\Columns\ToggleColumn::make('aman_extension_enabled')->label('الاكستنشن')
                    ->onColor('success')->offColor('danger')
                    ->afterStateUpdated(fn (Staff $record, $state) => Notification::make()
                        ->title($state ? "الاكستنشن اتفتحت لـ {$record->name}" : "الاكستنشن اتقفلت على {$record->name}")
                        ->color($state ? 'success' : 'danger')->send()),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('aman_extension_enabled')->label('الحالة')
                    ->trueLabel('مفتوحة')->falseLabel('مقفولة'),
            ])
            ->defaultSort('name')
            ->paginated(false);
    }

    public static function getWidgets(): array
    {
        return [AmanExtensionControlResource\Widgets\AmanDailyReport::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAmanExtensionControls::route('/'),
        ];
    }
}
