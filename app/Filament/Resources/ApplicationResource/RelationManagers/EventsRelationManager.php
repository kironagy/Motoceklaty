<?php

namespace App\Filament\Resources\ApplicationResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'الأحداث';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                Tables\Columns\TextColumn::make('type')->label('النوع'),
                Tables\Columns\TextColumn::make('from_status')->label('من')->default('-'),
                Tables\Columns\TextColumn::make('to_status')->label('إلى')->default('-'),
                Tables\Columns\TextColumn::make('actor')->label('الفاعل')->badge(),
                Tables\Columns\TextColumn::make('data')
                    ->label('تفاصيل')
                    ->formatStateUsing(fn ($state) => $state ? json_encode($state, JSON_UNESCAPED_UNICODE) : '-')
                    ->wrap()
                    ->limit(200),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
