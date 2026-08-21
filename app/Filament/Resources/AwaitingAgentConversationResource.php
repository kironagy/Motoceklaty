<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AwaitingAgentConversationResource\Pages;
use App\Models\WhatsappConversation;
use App\Services\WhatsappIntentRouter;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;

class AwaitingAgentConversationResource extends Resource
{
    protected static ?string $model = WhatsappConversation::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';
    protected static ?string $navigationGroup = 'واتساب';
    protected static ?string $navigationLabel = 'الرسائل المنتظر الرد عليها';
    protected static ?string $modelLabel = 'محادثة منتظرة الرد';
    protected static ?string $pluralModelLabel = 'الرسائل المنتظر الرد عليها';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', 'awaiting_agent');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user && ($user->is_admin || $user->is_super_admin);
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('real_phone')
                    ->label('رقم العميل')
                    ->getStateUsing(fn (WhatsappConversation $record) => $record->real_phone ?: $record->phone),
                Tables\Columns\TextColumn::make('last_topic')->label('آخر موضوع'),
                Tables\Columns\TextColumn::make('messages_count')
                    ->counts('messages')
                    ->label('عدد الرسائل'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('chat')
                    ->label('فتح المحادثة')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->url(fn (WhatsappConversation $record) => static::getUrl('chat', ['record' => $record])),

                Tables\Actions\Action::make('close_handoff')
                    ->label('إنهاء التحويل')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('هيرجع الـ AI يرد على العميل تاني بشكل عادي.')
                    ->action(function (WhatsappConversation $record): void {
                        $record->forceFill(['status' => 'open'])->save();
                        static::setArchived($record, false);

                        $answered = static::answerPendingIncomingMessage($record);

                        Notification::make()
                            ->title($answered
                                ? 'اتقفل التحويل، الـ AI رد على آخر رسالة وسابها'
                                : 'اتقفل التحويل، الـ AI هيرد على أي رسالة جديدة من العميل')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('مفيش محادثات منتظرة الرد دلوقتي');
    }

    /**
     * قفل التحويل لوحده بيغيّر الـ status بس - العميل يمكن يكون بعت
     * رسايل وقت ما كانت المحادثة awaiting_agent واتسجلت من غير رد
     * (الـ AI كان ساكت عمدًا). لو مفيش رد بعدها من الموظف نفسه، بنشغّل
     * الراوتر دلوقتي على آخر رسالة عشان العميل ياخد رد بدل ما يفضل مستني
     * لحد ما يبعت حاجة جديدة بنفسه.
     */
    public static function answerPendingIncomingMessage(WhatsappConversation $record): bool
    {
        $last = $record->messages()->latest('id')->first();

        if (! $last || $last->direction !== 'incoming') {
            return false;
        }

        $mediaItems = data_get($last->payload, 'saved_media_items', []);

        try {
            $result = app(WhatsappIntentRouter::class)->handle(
                $record->refresh(),
                (string) $last->message,
                is_array($mediaItems) ? $mediaItems : []
            );
        } catch (\Throwable) {
            return false;
        }

        $reply = trim((string) ($result['reply'] ?? ''));

        if ($reply === '') {
            return false;
        }

        return static::deliverText($record, $reply);
    }

    public static function deliverText(WhatsappConversation $record, string $message): bool
    {
        $lastIncoming = $record->messages()
            ->where('direction', 'incoming')
            ->latest('id')
            ->first();

        $botId = data_get($lastIncoming?->payload, 'bot_id') ?: $record->whatsapp_bot_id;
        $jid = data_get($lastIncoming?->payload, 'reply_jid')
            ?: data_get($lastIncoming?->payload, 'from')
            ?: ($record->phone ? $record->phone . '@s.whatsapp.net' : null);

        if (! $botId || ! $jid) {
            return false;
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout(15)
                ->withHeaders([
                    'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                    'Accept' => 'application/json',
                ])
                ->post(config('gemini.alerts.whatsapp_url'), [
                    'bot_id' => (string) $botId,
                    'jid' => (string) $jid,
                    'message' => $message,
                ]);

            return $response->successful() && ($response->json('ok') ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function setArchived(WhatsappConversation $record, bool $archive): void
    {
        $lastIncoming = $record->messages()
            ->where('direction', 'incoming')
            ->latest('id')
            ->first();

        $botId = data_get($lastIncoming?->payload, 'bot_id') ?: $record->whatsapp_bot_id;
        $jid = data_get($lastIncoming?->payload, 'reply_jid')
            ?: data_get($lastIncoming?->payload, 'from')
            ?: ($record->phone ? $record->phone . '@s.whatsapp.net' : null);

        if (! $botId || ! $jid) {
            return;
        }

        try {
            Http::timeout(10)
                ->withHeaders([
                    'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                    'Accept' => 'application/json',
                ])
                ->post(config('services.whatsapp.worker_url') . '/chats/archive', [
                    'bot_id' => (string) $botId,
                    'jid' => (string) $jid,
                    'archive' => $archive,
                ]);
        } catch (\Throwable) {
            // best effort - closing the handoff itself already succeeded
        }
    }

    public static function sendReply(WhatsappConversation $record, string $message): bool
    {
        $message = trim($message);

        if ($message === '') {
            return false;
        }

        $lastIncoming = $record->messages()
            ->where('direction', 'incoming')
            ->latest('id')
            ->first();

        $botId = data_get($lastIncoming?->payload, 'bot_id') ?: $record->whatsapp_bot_id;
        $jid = data_get($lastIncoming?->payload, 'reply_jid')
            ?: data_get($lastIncoming?->payload, 'from')
            ?: ($record->phone ? $record->phone . '@s.whatsapp.net' : null);

        if (! $botId || ! $jid) {
            return false;
        }

        try {
            $url = (string) config('gemini.alerts.whatsapp_url');

            $response = Http::connectTimeout(5)
                ->timeout(15)
                ->withHeaders([
                    'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                    'Accept' => 'application/json',
                ])
                ->post($url, [
                    'bot_id' => (string) $botId,
                    'jid' => (string) $jid,
                    'message' => $message,
                ]);

            if (! ($response->successful() && ($response->json('ok') ?? false))) {
                return false;
            }

            $record->messages()->create([
                'direction' => 'outgoing',
                'message' => $message,
                'payload' => ['source' => 'agent_dashboard_reply'],
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * بيبعت مرفق (صورة/فيديو/صوت/مستند) للعميل عن طريق نفس بوت الواتساب،
     * ويسجله كرسالة صادرة بـ saved_media_items عشان يظهر في صفحة الشات
     * بنفس شكل مرفقات العميل الواردة.
     */
    public static function sendMediaReply(
        WhatsappConversation $record,
        string $path,
        string $mime,
        ?string $filename = null,
        string $caption = ''
    ): bool {
        $lastIncoming = $record->messages()
            ->where('direction', 'incoming')
            ->latest('id')
            ->first();

        $botId = data_get($lastIncoming?->payload, 'bot_id') ?: $record->whatsapp_bot_id;
        $jid = data_get($lastIncoming?->payload, 'reply_jid')
            ?: data_get($lastIncoming?->payload, 'from')
            ?: ($record->phone ? $record->phone . '@s.whatsapp.net' : null);

        if (! $botId || ! $jid) {
            return false;
        }

        $mime = strtolower($mime);
        $type = str_starts_with($mime, 'video/')
            ? 'video'
            : (str_starts_with($mime, 'audio/')
                ? 'audio'
                : (str_contains($mime, 'pdf') ? 'document' : 'image'));

        /*
         * asset() بيبني الرابط من الدومين اللي فعليًا بيتصفح منه/بيستقبل
         * الريكوست ده - عكس Storage::url() اللي كان بيعتمد على APP_URL
         * الثابت في .env (بيبقى غلط لو الدومين مختلف وقت التشغيل عن وقت
         * التسجيل، ودي كانت بتبعت صورة "بايظة" فعليًا للعميل).
         */
        $url = asset('storage/' . ltrim($path, '/'));

        try {
            $response = Http::connectTimeout(5)
                ->timeout(30)
                ->withHeaders([
                    'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                    'Accept' => 'application/json',
                ])
                ->post(config('services.whatsapp.worker_url') . '/send-media-items', [
                    'bot_id' => (string) $botId,
                    'jid' => (string) $jid,
                    'media_items' => [[
                        'url' => $url,
                        'type' => $type,
                        'mime' => $mime,
                        'filename' => $filename,
                        'caption' => $caption,
                    ]],
                ]);

            if (! ($response->successful() && ($response->json('ok') ?? false))) {
                return false;
            }

            $record->messages()->create([
                'direction' => 'outgoing',
                'message' => $caption !== '' ? $caption : '[مرفق]',
                'payload' => [
                    'source' => 'agent_dashboard_reply',
                    'saved_media_items' => [[
                        'type' => $type,
                        'mime' => $mime,
                        'filename' => $filename,
                        'path' => $path,
                        'url' => $url,
                    ]],
                ],
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAwaitingAgentConversations::route('/'),
            'chat' => Pages\ChatAwaitingAgentConversation::route('/{record}/chat'),
        ];
    }
}
