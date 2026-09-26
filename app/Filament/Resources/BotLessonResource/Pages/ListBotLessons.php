<?php

namespace App\Filament\Resources\BotLessonResource\Pages;

use App\Domain\Teaching\LessonBook;
use App\Filament\Resources\BotLessonResource;
use App\Jobs\RunTeachingRegression;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListBotLessons extends ListRecords
{
    protected static string $resource = BotLessonResource::class;

    public function getSubheading(): ?string
    {
        return 'اللي البوت اتعلمه منك في وضع التعليم بالمحاكي. بيتبعت مع كل رسالة كفكرة، والبوت بيكتبه بأسلوبه.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('البوت شايف إيه؟')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalSubmitAction(false)
                ->modalContent(fn () => new HtmlString('<div dir="rtl" style="white-space: pre-wrap; line-height: 1.9">'
                    .e(app(LessonBook::class)->forPrompt(null)['text'] ?? 'لسه مفيش دروس').'</div>')),
            Actions\Action::make('regression')
                ->label('جرّب كل الدروس')
                ->icon('heroicon-o-play')
                ->action(function () {
                    RunTeachingRegression::dispatch();
                    Notification::make()->title('الاختبار شغال في الخلفية')->body('النتايج هتظهر في "اختبارات التعليم".')->success()->send();
                }),
        ];
    }
}
