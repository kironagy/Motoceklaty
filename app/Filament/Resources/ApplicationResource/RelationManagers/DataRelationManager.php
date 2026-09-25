<?php

namespace App\Filament\Resources\ApplicationResource\RelationManagers;

use App\Models\ApplicationData;
use App\Models\RequirementField;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * T13 §7: data with provenance. Sensitive values are masked unless the
 * staff user has permission (admin), same rule as the L4 context
 * redaction (T16) - values here come from requirement_fields.is_sensitive,
 * never a hardcoded key list.
 */
class DataRelationManager extends RelationManager
{
    protected static string $relationship = 'data';

    protected static ?string $title = 'البيانات المسجلة';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('field_key')
            ->columns([
                Tables\Columns\TextColumn::make('field_key')->label('الحقل'),
                Tables\Columns\TextColumn::make('party')->label('الطرف'),
                Tables\Columns\TextColumn::make('value')
                    ->label('القيمة')
                    ->formatStateUsing(fn (ApplicationData $record, $state) => self::displayValue($record, $state)),
                Tables\Columns\TextColumn::make('source')->label('المصدر')->badge(),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge(),
                Tables\Columns\TextColumn::make('issue_code')->label('كود المشكلة')->default('-'),
                Tables\Columns\TextColumn::make('updated_at')->label('آخر تحديث')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('field_key')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    private static function displayValue(ApplicationData $record, mixed $state): string
    {
        if ($state === null) {
            return '-';
        }

        $isSensitive = RequirementField::where('key', $record->field_key)->value('is_sensitive') ?? true;
        $user = auth()->user();
        $canSeeSensitive = $user && ($user->is_admin || $user->is_super_admin);

        if ($isSensitive && ! $canSeeSensitive) {
            return '••••••';
        }

        return (string) $state;
    }
}
