<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiTraceResource\Pages;
use App\Filament\Resources\AiTraceResource\RelationManagers\StepsRelationManager;
use App\Models\AiTrace;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only trace viewer (T07): staff can see what the agent did on a
 * conversation, with every value already redacted at write time (T06).
 * No editing, no creating - traces are only ever written by the runtime.
 */
class AiTraceResource extends Resource
{
    protected static ?string $model = AiTrace::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'البوت الذكي';
    protected static ?string $navigationLabel = 'سجلات الذكاء الاصطناعي';
    protected static ?string $modelLabel = 'سجل';
    protected static ?string $pluralModelLabel = 'سجلات الذكاء الاصطناعي';

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
                Tables\Columns\TextColumn::make('conversation.phone')->label('العميل'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge(),
                Tables\Columns\TextColumn::make('model')->label('الموديل'),
                Tables\Columns\TextColumn::make('input_tokens')->label('توكنز الدخول'),
                Tables\Columns\TextColumn::make('output_tokens')->label('توكنز الخروج'),
                Tables\Columns\TextColumn::make('latency_ms')->label('الزمن (ms)'),
                Tables\Columns\TextColumn::make('error_code')->label('كود الخطأ'),
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
            TextEntry::make('conversation.phone')->label('العميل'),
            TextEntry::make('status')->label('الحالة'),
            TextEntry::make('prompt_version')->label('نسخة التعليمات'),
            TextEntry::make('provider')->label('المزوّد'),
            TextEntry::make('model')->label('الموديل'),
            TextEntry::make('error_code')->label('كود الخطأ'),
            TextEntry::make('input_tokens')->label('توكنز الدخول'),
            TextEntry::make('output_tokens')->label('توكنز الخروج'),
            TextEntry::make('latency_ms')->label('الزمن (ms)'),
            KeyValueEntry::make('context_manifest')->label('ملخص السياق'),
            KeyValueEntry::make('guard_events')->label('أحداث الحراسة'),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            StepsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiTraces::route('/'),
            'view' => Pages\ViewAiTrace::route('/{record}'),
        ];
    }
}
