<?php

namespace App\Filament\Resources\ApplicationResource\RelationManagers;

use App\Models\ApplicationDocument;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** T15 §5: document list with status/issues; extracted values masked unless the staff user has permission. */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'المستندات';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('detected_type_key')
            ->columns([
                Tables\Columns\TextColumn::make('detected_type_key')->label('النوع المكتشف')->default('-'),
                Tables\Columns\TextColumn::make('expected_type_key')->label('النوع المتوقع')->default('-'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge(),
                Tables\Columns\TextColumn::make('confidence')->label('الثقة')->default('-'),
                Tables\Columns\TextColumn::make('issues')
                    ->label('المشاكل')
                    ->formatStateUsing(fn ($state) => $state ? implode(', ', array_column($state, 'code')) : '-'),
                Tables\Columns\TextColumn::make('extracted')
                    ->label('البيانات المستخرجة')
                    ->formatStateUsing(fn (ApplicationDocument $record, $state) => self::displayExtracted($state)),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    private static function displayExtracted(mixed $state): string
    {
        if (! $state) {
            return '-';
        }

        $user = auth()->user();
        $canSeeSensitive = $user && ($user->is_admin || $user->is_super_admin);

        if (! $canSeeSensitive) {
            return '••••••';
        }

        return json_encode($state, JSON_UNESCAPED_UNICODE);
    }
}
