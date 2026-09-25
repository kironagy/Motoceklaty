<?php

namespace App\Filament\Pages;

use App\Domain\Applications\ApplicationService;
use App\Domain\Applications\SnapshotService;
use App\Domain\Simulation\ConversationSimulator;
use App\Models\AiTrace;
use App\Models\AiTraceStep;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;

/**
 * Chat with the real bot from the dashboard - real model, real tools,
 * real catalog and memory - without WhatsApp. Nothing is sent to anyone.
 */
class ConversationSimulatorPage extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';
    protected static ?string $navigationGroup = 'البوت الذكي';
    protected static ?string $navigationLabel = 'محاكي المحادثات';
    protected static ?string $title = 'محاكي المحادثات';
    protected static ?string $slug = 'bot-simulator';
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.conversation-simulator';

    #[Url(as: 'c')]
    public ?int $conversationId = null;

    public string $message = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $attachments = [];

    public bool $showDebug = true;

    public const QUICK_MESSAGES = [
        'السلام عليكم',
        'بكام الهوجن 4؟',
        'لو قسطتها المقدم كام والقسط كام؟',
        'عايز اشتري كاش، انا في المنصورة',
        'ابعتلي صور الفيجوري 3',
        'عايز اقدم على تقسيط، انا موظف',
        'انت بوت؟',
        'في خصم؟',
    ];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        if ($this->conversationId && ! $this->simulatedConversations()->contains('id', $this->conversationId)) {
            $this->conversationId = null;
        }
    }

    private function simulator(): ConversationSimulator
    {
        return app(ConversationSimulator::class);
    }

    public function newConversation(): void
    {
        $this->conversationId = $this->simulator()->start('محاكاة '.now()->format('d/m H:i'))->id;
        $this->message = '';
        $this->attachments = [];
    }

    public function openConversation(int $id): void
    {
        $this->conversationId = $id;
    }

    public function quick(string $text): void
    {
        $this->message = $text;
        $this->send();
    }

    public function send(): void
    {
        $text = trim($this->message);

        if ($text === '' && $this->attachments === []) {
            return;
        }

        $this->validate(['attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,webp,pdf']);

        if (! $this->conversationId) {
            $this->newConversation();
        }

        @set_time_limit(180);

        $paths = array_map(fn ($file) => $file->getRealPath(), $this->attachments);
        $result = $this->simulator()->send(WhatsappConversation::findOrFail($this->conversationId), $text, $paths);

        $this->message = '';
        $this->attachments = [];

        if ($result['handed_off'] ?? false) {
            Notification::make()
                ->title('المحادثة متحولة لموظف')
                ->body('زي الواتساب بالظبط: البوت مش هيرد لحد ما الموظف يقفل التحويل. دوس "رجّعها للبوت" لو عايز تكمل التجربة.')
                ->info()
                ->send();
        } elseif ($result['error']) {
            Notification::make()->title('البوت ما ردش')->body($result['error'])->danger()->persistent()->send();
        } elseif ($result['reply'] === [] && $result['media'] === []) {
            Notification::make()->title('البوت اختار ما يردش على الرسالة دي')->warning()->send();
        }

        $this->dispatch('simulator-scrolled');
    }

    public function returnToBot(): void
    {
        if ($this->conversationId) {
            $this->simulator()->returnToBot(WhatsappConversation::findOrFail($this->conversationId));
        }
    }

    public function isHandedOff(): bool
    {
        return $this->conversationId
            && WhatsappConversation::whereKey($this->conversationId)->value('status') === 'awaiting_agent';
    }

    public function removeAttachment(int $index): void
    {
        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
    }

    public function simulatedConversations(): Collection
    {
        return WhatsappConversation::query()
            ->where('whatsapp_bot_id', $this->simulator()->bot()->id)
            ->withCount('messages')
            ->latest('id')
            ->take(15)
            ->get();
    }

    /**
     * The conversation as a list of turns: the customer's message(s), the
     * bot's reply, and what the bot did in between (tools, tokens, time).
     */
    public function getTurns(): array
    {
        if (! $this->conversationId) {
            return [];
        }

        $messages = WhatsappMessage::query()
            ->where('whatsapp_conversation_id', $this->conversationId)
            ->with('media')
            ->orderBy('id')
            ->get();

        $traces = AiTrace::query()
            ->where('conversation_id', $this->conversationId)
            ->get()
            ->keyBy('turn_id');

        $steps = AiTraceStep::query()
            ->whereIn('trace_id', $traces->pluck('id'))
            ->orderBy('seq')
            ->get()
            ->groupBy('trace_id');

        $turns = [];

        foreach ($messages->groupBy(fn ($m) => $m->turn_id ?? 'm'.$m->id) as $turnId => $group) {
            $trace = $traces->get($turnId);

            $turns[] = [
                'in' => $group->where('direction', 'incoming')->values(),
                'out' => $group->where('direction', 'outgoing')->values(),
                'trace' => $trace,
                'tools' => $trace ? ($steps->get($trace->id) ?? collect())->where('kind', 'tool_call')->values() : collect(),
            ];
        }

        return $turns;
    }

    public function mediaUrl($media): ?string
    {
        if (! $media?->path) {
            return null;
        }

        return $media->disk === 'public'
            ? Storage::disk('public')->url($media->path)
            : route('staff.media.show', $media->id);
    }

    public function getApplicationSnapshot(): ?array
    {
        $conversation = $this->conversationId ? WhatsappConversation::with('customer')->find($this->conversationId) : null;

        if (! $conversation?->customer) {
            return null;
        }

        $application = app(ApplicationService::class)->activeFor($conversation->customer);

        return $application ? app(SnapshotService::class)->for($application) : null;
    }
}
