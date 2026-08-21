<?php

namespace App\Filament\Resources\AwaitingAgentConversationResource\Pages;

use App\Filament\Resources\AwaitingAgentConversationResource;
use App\Models\WhatsappConversation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Livewire\WithFileUploads;

class ChatAwaitingAgentConversation extends Page
{
    use WithFileUploads;

    protected static string $resource = AwaitingAgentConversationResource::class;

    protected static string $view = 'filament.awaiting-agent.chat';

    public WhatsappConversation $record;

    public string $messageText = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $attachment = null;

    public function mount(WhatsappConversation $record): void
    {
        abort_unless(AwaitingAgentConversationResource::canViewAny(), 403);

        $this->record = $record;
    }

    public function getTitle(): string
    {
        return 'محادثة ' . ($this->record->real_phone ?: $this->record->phone);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close_handoff')
                ->label('إنهاء التحويل')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('هيرجع الـ AI يرد على العميل تاني بشكل عادي.')
                ->action('closeHandoff'),
        ];
    }

    /**
     * @return Collection<int, \App\Models\WhatsappMessage>
     */
    public function getMessagesProperty(): Collection
    {
        return $this->record->messages()->orderBy('id')->get();
    }

    /**
     * الروابط المخزّنة مع كل رسالة اتبنت وقت الاستلام باستخدام APP_URL
     * وقتها - لو الدومين اتغيّر بعدين (تجربة محلية بعد استلام على
     * السيرفر الشغّال، أو العكس) الرابط القديم يبقى غلط. بنبني الرابط
     * من مسار الملف مباشرة كل مرة، فبيتظبط لوحده مع أي دومين بتتصفح منه.
     */
    public function mediaUrl(string $path): string
    {
        return asset('storage/' . ltrim($path, '/'));
    }

    public function sendMessage(): void
    {
        $text = trim($this->messageText);
        $hasAttachment = $this->attachment !== null;

        if ($text === '' && ! $hasAttachment) {
            return;
        }

        if ($hasAttachment) {
            $mime = $this->attachment->getMimeType();
            $directory = 'whatsapp-documents/conversation-' . $this->record->id;
            $path = $this->attachment->store($directory, 'public');
            $filename = $this->attachment->getClientOriginalName();

            $sent = AwaitingAgentConversationResource::sendMediaReply(
                $this->record,
                $path,
                (string) $mime,
                $filename,
                $text
            );

            if (! $sent) {
                Notification::make()->title('فشل إرسال المرفق')->danger()->send();
            }

            $this->attachment = null;
            $this->messageText = '';

            return;
        }

        $sent = AwaitingAgentConversationResource::sendReply($this->record, $text);

        if ($sent) {
            $this->messageText = '';
        } else {
            Notification::make()->title('فشل إرسال الرسالة')->danger()->send();
        }
    }

    public function closeHandoff(): void
    {
        $this->record->forceFill(['status' => 'open'])->save();
        AwaitingAgentConversationResource::setArchived($this->record, false);

        $answered = AwaitingAgentConversationResource::answerPendingIncomingMessage($this->record);

        Notification::make()
            ->title($answered
                ? 'اتقفل التحويل، الـ AI رد على آخر رسالة وسابها'
                : 'اتقفل التحويل، الـ AI هيرد على أي رسالة جديدة من العميل')
            ->success()
            ->send();

        $this->redirect(AwaitingAgentConversationResource::getUrl('index'));
    }
}
