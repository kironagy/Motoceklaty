<?php

namespace App\Filament\Resources\WhatsappBotResource\Pages;

use App\Domain\Bots\BotActivity;
use App\Filament\Resources\WhatsappBotResource;
use App\Models\WhatsappBot;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;

/**
 * One bot's day: chats it answered, messages and AI usage per chat, hourly activity, and a
 * read-only viewer of any chat. Defaults to today; another day can be picked.
 */
class BotActivityPage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = WhatsappBotResource::class;

    protected static string $view = 'filament.resources.whatsapp-bots.activity';

    #[Url(as: 'day')]
    public string $day = '';

    #[Url(as: 'chat')]
    public ?int $chat = null;

    public string $search = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(WhatsappBotResource::canViewAny(), 403);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->day)) {
            $this->day = today()->toDateString();
        }
    }

    public function getTitle(): string
    {
        return 'نشاط '.$this->record->name;
    }

    public function getBreadcrumbs(): array
    {
        return [WhatsappBotResource::getUrl('index') => 'بوتات واتساب', 'التفاصيل'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')->label('إعدادات البوت')->icon('heroicon-o-cog-6-tooth')->color('gray')
                ->url(fn () => WhatsappBotResource::getUrl('edit', ['record' => $this->record]))
                ->visible(fn () => WhatsappBotResource::canEdit($this->record)),
        ];
    }

    public function dayDate(): Carbon
    {
        try {
            $day = Carbon::createFromFormat('Y-m-d', $this->day)->startOfDay();
        } catch (\Throwable) {
            $day = today();
        }

        return $day->isFuture() ? today() : $day;
    }

    public function shiftDay(int $days): void
    {
        $day = $this->dayDate()->addDays($days);
        $this->day = ($day->isFuture() ? today() : $day)->toDateString();
        $this->chat = null;
    }

    public function updatedDay(): void
    {
        $this->day = $this->dayDate()->toDateString();
        $this->chat = null;
    }

    public function openChat(int $id): void
    {
        $this->chat = $id;
        $this->dispatch('bot-chat-opened');
    }

    public function closeChat(): void
    {
        $this->chat = null;
    }

    protected function getViewData(): array
    {
        /** @var WhatsappBot $bot */
        $bot = $this->record;
        $activity = app(BotActivity::class);
        $day = $this->dayDate();
        $conversations = $activity->conversations($bot, $day, trim($this->search));
        $selected = $this->chat ? $conversations->firstWhere('id', $this->chat) : null;

        return [
            'bot' => $bot,
            'activity' => $activity,
            'dayLabel' => $day->isToday() ? 'النهارده' : ($day->isYesterday() ? 'امبارح' : $day->copy()->locale('ar')->translatedFormat('l j F')),
            'isToday' => $day->isToday(),
            'summary' => $activity->summary($bot, $day),
            'hourly' => $activity->hourly($bot, $day),
            'conversations' => $conversations,
            'selected' => $selected,
            'chatMessages' => $this->chat ? $activity->messages($bot, $this->chat) : collect(),
        ];
    }
}
