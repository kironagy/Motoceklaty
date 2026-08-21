<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AwaitingAgentConversationResource\Pages;
use App\Models\WhatsappConversation;
use Filament\Forms;
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
                Tables\Columns\TextColumn::make('phone')->label('رقم العميل'),
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
                Tables\Actions\Action::make('view_messages')
                    ->label('عرض المحادثة')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->modalHeading('آخر رسائل المحادثة')
                    ->modalContent(fn (WhatsappConversation $record) => view(
                        'filament.awaiting-agent.messages',
                        ['messages' => $record->messages()->latest()->take(30)->get()->reverse()]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق'),

                Tables\Actions\Action::make('reply')
                    ->label('رد على العميل')
                    ->icon('heroicon-o-paper-airplane')
                    ->form([
                        Forms\Components\Textarea::make('message')
                            ->label('الرسالة')
                            ->required()
                            ->rows(4),
                    ])
                    ->action(function (WhatsappConversation $record, array $data): void {
                        $sent = static::sendReply($record, (string) $data['message']);

                        if ($sent) {
                            Notification::make()->title('اتبعتت الرسالة')->success()->send();
                        } else {
                            Notification::make()->title('فشل إرسال الرسالة')->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('close_handoff')
                    ->label('إنهاء التحويل')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('هيرجع الـ AI يرد على العميل تاني بشكل عادي.')
                    ->action(function (WhatsappConversation $record): void {
                        $record->forceFill(['status' => 'open'])->save();

                        Notification::make()->title('اتقفل التحويل، الـ AI رجع يرد')->success()->send();
                    }),
            ])
            ->emptyStateHeading('مفيش محادثات منتظرة الرد دلوقتي');
    }

    private static function sendReply(WhatsappConversation $record, string $message): bool
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
            $url = rtrim((string) env('WHATSAPP_WORKER_URL', 'http://127.0.0.1:3080'), '/') . '/send-message';

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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAwaitingAgentConversations::route('/'),
        ];
    }
}
