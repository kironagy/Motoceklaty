<?php

namespace App\Filament\Resources;

use App\Domain\Conversations\DeliveryService;
use App\Domain\Handoff\HandoffService;
use App\Filament\Resources\AwaitingAgentConversationResource\Pages;
use App\Models\WhatsappConversation;
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

        return $user && ((bool) $user->is_bot || (bool) $user->is_hitler);
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function shouldRegisterNavigation(): bool
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
                        $result = app(HandoffService::class)->close($record, auth()->user());
                        static::setArchived($record, false);

                        Notification::make()
                            ->title($result['turn_created']
                                ? 'اتقفل التحويل، الـ AI هيرد على آخر رسايل العميل دلوقتي'
                                : 'اتقفل التحويل، الـ AI هيرد على أي رسالة جديدة من العميل')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('مفيش محادثات منتظرة الرد دلوقتي');
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

        try {
            app(DeliveryService::class)->deliverForConversation($record, ['messages' => [$message]]);

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
            app(DeliveryService::class)->deliverForConversation($record, ['media' => [[
                'url' => $url,
                'type' => $type,
                'mime' => $mime,
                'filename' => $filename,
                'path' => $path,
                'caption' => $caption,
            ]]]);

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
