<?php

namespace App\Filament\Resources\AiTraceResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StepsRelationManager extends RelationManager
{
    protected static string $relationship = 'steps';

    protected static ?string $title = 'الخطوات';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('tool_name')
            ->columns([
                Tables\Columns\TextColumn::make('seq')->label('#'),
                Tables\Columns\TextColumn::make('kind')->label('النوع')->formatStateUsing(fn ($state) => $state === 'tool_call' ? 'نداء أداة' : $state),
                Tables\Columns\TextColumn::make('tool_name')->label('الأداة'),
                Tables\Columns\TextColumn::make('permission')->label('الصلاحية'),
                Tables\Columns\TextColumn::make('result_code')->label('كود النتيجة'),
                Tables\Columns\TextColumn::make('latency_ms')->label('الزمن (ms)'),
                Tables\Columns\TextColumn::make('args_redacted')
                    ->label('المدخلات')
                    ->formatStateUsing(fn ($state) => $state ? json_encode($state, JSON_UNESCAPED_UNICODE) : '-')
                    ->wrap()
                    ->limit(200),
                Tables\Columns\TextColumn::make('result_redacted')
                    ->label('النتيجة')
                    ->formatStateUsing(fn ($state) => $state ? json_encode($state, JSON_UNESCAPED_UNICODE) : '-')
                    ->wrap()
                    ->limit(200),
            ])
            ->defaultSort('seq')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
