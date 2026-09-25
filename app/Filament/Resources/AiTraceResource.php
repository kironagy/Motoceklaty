<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiTraceResource\Pages;
use App\Filament\Resources\AiTraceResource\RelationManagers\StepsRelationManager;
use App\Models\AiTrace;
use App\Support\DashboardLabels;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
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
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn ($state) => DashboardLabels::get(DashboardLabels::TRACE_STATUS, $state))
                    ->color(fn ($state) => DashboardLabels::color(DashboardLabels::TRACE_STATUS_COLOR, $state)),
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
            Section::make('ملخص')
                ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                ->schema([
                    TextEntry::make('id')->label('#'),
                    TextEntry::make('conversation.phone')->label('العميل')->default('-'),
                    TextEntry::make('status')->label('الحالة')->badge()
                        ->formatStateUsing(fn ($state) => DashboardLabels::get(DashboardLabels::TRACE_STATUS, $state))
                        ->color(fn ($state) => DashboardLabels::color(DashboardLabels::TRACE_STATUS_COLOR, $state)),
                    TextEntry::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i:s'),
                    TextEntry::make('model')->label('الموديل')->default('-'),
                    TextEntry::make('prompt_version')->label('نسخة التعليمات')->default('-'),
                    TextEntry::make('latency_ms')->label('الزمن')->suffix(' ms')->placeholder('-'),
                    TextEntry::make('error_code')->label('الخطأ')->default('-')->color('danger'),
                ]),
            Section::make('الاستهلاك')
                ->columns(['default' => 2, 'lg' => 4])
                ->schema([
                    TextEntry::make('usage_calls')->label('عدد مرات النداء للموديل')
                        ->state(fn (AiTrace $record) => self::usage($record, 'model_calls')),
                    TextEntry::make('input_tokens')->label('توكنز داخل')->numeric()->placeholder('-'),
                    TextEntry::make('output_tokens')->label('توكنز خارج')->numeric()->placeholder('-'),
                    TextEntry::make('usage_cached')->label('توكنز متخزنة (cache)')
                        ->state(fn (AiTrace $record) => self::usage($record, 'cached_tokens')),
                ]),
            Section::make('ردود الحراسة وقفتها')
                ->description('ردود البوت كتبها واتمنعت قبل ما توصل للعميل، وسبب المنع.')
                ->visible(fn (AiTrace $record) => ! empty($record->guard_events))
                ->schema([
                    RepeatableEntry::make('guard_events')->label('ردود اتمنعت')->hiddenLabel()->contained(false)
                        ->schema([
                            TextEntry::make('code')->label('السبب')->hiddenLabel()->badge()->color('danger')
                                ->formatStateUsing(fn ($state) => DashboardLabels::get(DashboardLabels::GUARD_CODE, $state)),
                            TextEntry::make('args.messages')->label('الرد اللي اتمنع')->hiddenLabel()->listWithLineBreaks()
                                ->formatStateUsing(fn ($state) => is_scalar($state) ? (string) $state : json_encode($state, JSON_UNESCAPED_UNICODE))
                                ->placeholder('-'),
                        ]),
                ]),
        ]);
    }

    private static function usage(AiTrace $record, string $key): string
    {
        $value = data_get($record->context_manifest, 'usage.'.$key);

        return is_numeric($value) ? number_format((float) $value) : '-';
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
