<?php

namespace App\Filament\Resources;

use App\Domain\Teaching\RegressionRunner;
use App\Filament\Resources\TeachingCaseResource\Pages;
use App\Models\TeachingCase;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TeachingCaseResource extends Resource
{
    protected static ?string $model = TeachingCase::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';
    protected static ?string $navigationGroup = 'البوت الذكي';
    protected static ?string $navigationLabel = 'اختبارات التعليم';
    protected static ?string $modelLabel = 'اختبار';
    protected static ?string $pluralModelLabel = 'اختبارات التعليم';
    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('history')->label('العميل قال')
                    ->state(fn (TeachingCase $r) => \Illuminate\Support\Str::limit((string) (collect($r->history)->last()['text'] ?? ''), 80)),
                Tables\Columns\TextColumn::make('expectation')->label('المتوقع')->wrap()->limit(160),
                Tables\Columns\TextColumn::make('last_result')->label('آخر نتيجة')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'pass' ? 'نجح' : 'فشل')
                    ->color(fn ($state) => $state === 'pass' ? 'success' : 'danger')
                    ->description(fn (TeachingCase $r) => $r->last_result === 'fail' ? $r->last_reason : null)
                    ->placeholder('لسه'),
                Tables\Columns\TextColumn::make('last_run_at')->label('امتى')->since(),
                Tables\Columns\ToggleColumn::make('is_active')->label('شغال'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('last_result')->label('النتيجة')->options(['pass' => 'نجح', 'fail' => 'فشل']),
            ])
            ->actions([
                Tables\Actions\Action::make('reply')->label('الرد')->icon('heroicon-o-eye')->color('gray')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (TeachingCase $r) => new \Illuminate\Support\HtmlString(
                        '<div dir="rtl" style="white-space:pre-wrap;line-height:1.8"><b>كان:</b> '.e((string) $r->bad_reply)
                        ."\n\n<b>آخر رد:</b> ".e((string) $r->last_reply)."\n\n<b>السبب:</b> ".e((string) $r->last_reason).'</div>'
                    )),
                Tables\Actions\Action::make('run')->label('جرّب')->icon('heroicon-o-play')
                    ->action(function (TeachingCase $r) {
                        @set_time_limit(180);
                        $result = app(RegressionRunner::class)->run($r);
                        Notification::make()->title($result['pass'] ? 'نجح' : 'فشل')->body($result['reason'])->{$result['pass'] ? 'success' : 'danger'}()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTeachingCases::route('/')];
    }
}
