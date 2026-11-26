<?php

namespace App\Filament\Pages;

use App\Domain\Asfoora\AsfooraAgent;
use App\Models\AsfooraChat;
use App\Domain\Asfoora\AsfooraExcel;
use App\Domain\Conversations\VoiceTranscriber;
use App\Models\AsfooraMessage;
use App\Models\MessageMedia;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;

/**
 * Owner 2026-11-26: «عصفورة» - a chat with the AI about the system, for is_hitler staff only
 * (no role opens it: it reads every table, customers' phones included).
 */
class Asfoora extends Page
{
    use WithFileUploads;

    /** a voice record from the browser's microphone (MediaRecorder: webm in Chrome, mp4 on iPhone) */
    public $voice = null;
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'عصفورة';

    protected static ?string $title = 'عصفورة';

    protected static ?string $slug = 'asfoora';

    protected static ?int $navigationSort = -10;

    protected static string $view = 'filament.pages.asfoora';

    public ?int $chatId = null;

    public string $question = '';

    /** an answer is being made - the page polls and the composer waits */
    public bool $thinking = false;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_hitler;
    }

    public function mount(): void
    {
        // ?chat= opens one chat (the daily report's notification links to its own)
        $wanted = (int) request()->query('chat');
        $this->chatId = ($wanted ? $this->myChats()->find($wanted)?->id : null) ?? $this->myChats()->first()?->id;
    }

    public function deleteMemory(int $id): void
    {
        \App\Models\AsfooraMemory::whereKey($id)->delete();
    }

    public function ask(?string $preset = null): void
    {
        $question = trim($preset ?? $this->question);
        if ($question === '') {
            return;
        }

        $chat = $this->chatId ? $this->myChats()->find($this->chatId) : null;
        $chat ??= AsfooraChat::create(['staff_id' => auth()->id()]);
        $this->chatId = $chat->id;
        $this->question = '';

        // the answer is made after this response is sent (FPM finishes the request first), so a
        // question needing many lookups never hits the web timeout; the page polls until it is in
        $answer = app(AsfooraAgent::class)->start($chat, mb_substr($question, 0, 2000));
        app()->terminating(fn () => app(AsfooraAgent::class)->run($answer->id));
    }

    /** wire:poll while an answer is being made - re-rendering is all it needs */
    public function refreshAnswers(): void
    {
    }

    /** A voice record: the bot's own transcriber turns it into words, then it is asked like typed text. */
    public function askVoice(): void
    {
        $this->validate(['voice' => 'required|file|max:12288']);
        @set_time_limit(240);

        $path = $this->voice->store('asfoora-voice', 'local');
        $mime = (string) ($this->voice->getMimeType() ?: 'audio/webm');
        // MediaRecorder's webm sometimes comes back as video/webm
        $mime = str_replace('video/', 'audio/', $mime);
        $this->voice = null;

        try {
            $result = app(VoiceTranscriber::class)->transcribe(new MessageMedia(['disk' => 'local', 'path' => $path, 'mime' => $mime]));
        } finally {
            Storage::disk('local')->delete($path);
        }

        $text = trim((string) ($result->transcript ?? ''));
        if ($text === '') {
            Notification::make()->title('ما قدرتش أسمع التسجيل كويس')->body('سجّل تاني أو اكتب سؤالك.')->warning()->send();

            return;
        }

        $this->ask('🎙️ '.$text);
    }

    /** One answer's data as a tidy Excel file. */
    public function exportExcel(int $messageId, ?int $table = null)
    {
        $message = AsfooraMessage::whereKey($messageId)->where('role', 'assistant')
            ->whereIn('asfoora_chat_id', AsfooraChat::where('staff_id', auth()->id())->select('id'))->first();
        if (! $message) {
            return null;
        }

        $question = (string) AsfooraMessage::where('asfoora_chat_id', $message->asfoora_chat_id)->where('role', 'user')
            ->where('id', '<', $message->id)->latest('id')->value('content');

        return response()->download(AsfooraExcel::write($message, $question, $table), AsfooraExcel::filename($message, $table))->deleteFileAfterSend();
    }

    public function newChat(): void
    {
        $this->chatId = null;
        $this->question = '';
    }

    public function openChat(int $id): void
    {
        $this->chatId = $this->myChats()->find($id)?->id;
    }

    public function deleteChat(int $id): void
    {
        $chat = $this->myChats()->find($id);
        if ($chat) {
            $chat->messages()->delete();
            $chat->delete();
        }
        if ($this->chatId === $id) {
            $this->chatId = $this->myChats()->first()?->id;
        }
    }

    private function myChats()
    {
        return AsfooraChat::where('staff_id', auth()->id())->latest('updated_at');
    }

    /** The AI's markdown, safe (no HTML of its own), with request numbers as links to the request. */
    public static function render_answer(string $markdown): string
    {
        [$markdown, $charts] = \App\Domain\Asfoora\AsfooraCharts::extract($markdown);
        // Latin names inside Arabic ("Manga وEl Hawi") are isolated, or the line's direction scrambles them
        $markdown = preg_replace('/(?<![A-Za-z0-9_\/#])([A-Za-z][A-Za-z0-9._-]*(?: [A-Za-z][A-Za-z0-9._-]*)*)/u', "\u{2068}$1\u{2069}", $markdown);
        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        $html = preg_replace_callback('/(?<![\w\/&])#(\d{1,7})\b/u', fn ($m) => '<a class="mg-asf-req" href="'
            .e(url('/admin/deliveries/'.$m[1].'/edit')).'" target="_blank" dir="ltr">#'.$m[1].'</a>', $html);

        return \App\Domain\Asfoora\AsfooraCharts::put($html, $charts);
    }

    protected function getViewData(): array
    {
        $chat = $this->chatId ? $this->myChats()->with('messages')->find($this->chatId) : null;

        // an answer still "thinking" long after it started: the process making it died
        foreach ($chat?->messages ?? [] as $m) {
            if (($m->meta['pending'] ?? false) && $m->created_at->lt(now()->subMinutes(AsfooraAgent::PENDING_MINUTES))) {
                $m->update(['content' => 'معلش، الرد ده ما كملش. اسأل تاني.', 'meta' => ['error' => 'TIMED_OUT']]);
            }
        }

        return [
            'chat' => $chat,
            'thinking' => $this->thinking = (bool) $chat?->messages->contains(fn ($m) => $m->meta['pending'] ?? false),
            'chats' => $this->myChats()->limit(30)->get(['id', 'title', 'updated_at']),
            'memories' => \App\Models\AsfooraMemory::orderBy('id')->get(['id', 'content']),
            'suggestions' => [
                'تقرير الطلبات اللي اترفعت النهارده لكل موظف',
                'الطلبات المتوقفة برقم الطلب والسبب ورقم تليفون العميل',
                'كام طلب اترفض الأسبوع ده ولمين؟',
                'أحسن موظف الشهر ده في الموافقات',
                'البوت رد على كام عميل النهارده؟',
            ],
        ];
    }
}
