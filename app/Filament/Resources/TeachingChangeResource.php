<?php

namespace App\Filament\Resources;

use App\Domain\Teaching\ChangeApplier;
use App\Domain\Teaching\EditableEntities;
use App\Filament\Resources\TeachingChangeResource\Pages;
use App\Models\TeachingChange;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TeachingChangeResource extends Resource
{
    protected static ?string $model = TeachingChange::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationGroup = 'البوت الذكي';
    protected static ?string $navigationLabel = 'سجل تغييرات التعليم';
    protected static ?string $modelLabel = 'تغيير';
    protected static ?string $pluralModelLabel = 'سجل تغييرات التعليم';
    protected static ?int $navigationSort = 5;

    public const KINDS = ['lesson' => 'درس', 'instruction' => 'التعليمات', 'data_update' => 'تعديل بيانات', 'data_create' => 'إضافة بيانات', 'setting' => 'إعداد'];

    public const STATUSES = ['proposed' => 'مستني موافقة', 'applied' => 'متطبق', 'rejected' => 'اتلغى', 'reverted' => 'اترجع فيه', 'invalid' => 'مينفعش', 'failed_check' => 'فشل في الاختبار'];

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
        $json = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('امتى')->since(),
                Tables\Columns\TextColumn::make('kind')->label('النوع')->formatStateUsing(fn ($state) => self::KINDS[$state] ?? $state)->badge(),
                Tables\Columns\TextColumn::make('target_type')->label('على')
                    ->formatStateUsing(fn ($state, TeachingChange $r) => EditableEntities::label($state).($r->target_id ? " #{$r->target_id}" : '')),
                Tables\Columns\TextColumn::make('summary')->label('إيه اللي اتغير')->wrap(),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'applied' => 'success', 'proposed' => 'warning', 'invalid', 'failed_check' => 'danger', default => 'gray' }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(self::STATUSES),
                Tables\Filters\SelectFilter::make('kind')->label('النوع')->options(self::KINDS),
            ])
            ->actions([
                Tables\Actions\Action::make('diff')->label('قبل وبعد')->icon('heroicon-o-eye')->color('gray')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (TeachingChange $r) => new \Illuminate\Support\HtmlString(
                        '<pre dir="ltr" style="white-space:pre-wrap;font-size:12px">قبل: '.e($json($r->diff()[0]))."\n\nبعد: ".e($json($r->diff()[1])).($r->error ? "\n\n".e($r->error) : '').'</pre>'
                    )),
                Tables\Actions\Action::make('revert')->label('ارجع فيه')->icon('heroicon-o-arrow-uturn-right')->color('danger')
                    ->visible(fn (TeachingChange $r) => $r->status === 'applied')
                    ->requiresConfirmation()
                    ->action(function (TeachingChange $r) {
                        try {
                            app(ChangeApplier::class)->revert($r);
                            Notification::make()->title('رجعنا فيه')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('ما نفعش')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTeachingChanges::route('/')];
    }
}
