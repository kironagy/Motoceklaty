<?php

namespace App\Domain\Bots;

use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a WhatsApp bot did on one day: how many chats it answered, how many messages each chat took
 * (customer / bot / staff), the AI calls and tokens each chat consumed, and the chat itself.
 * Read-only - used by the bots list cards and the bot details page.
 */
class BotActivity
{
    /** [bot_id => ['conversations' => n, 'messages' => n]] for the day, for the list cards. */
    public function todayTotals(?CarbonInterface $day = null): array
    {
        [$from, $to] = $this->range($day);

        return DB::table('whatsapp_messages')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('whatsapp_bot_id')
            ->selectRaw('whatsapp_bot_id, COUNT(DISTINCT whatsapp_conversation_id) as conversations, COUNT(*) as messages')
            ->groupBy('whatsapp_bot_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->whatsapp_bot_id => ['conversations' => (int) $row->conversations, 'messages' => (int) $row->messages]])
            ->all();
    }

    /** Headline numbers of the day for one bot. */
    public function summary(WhatsappBot $bot, CarbonInterface $day): array
    {
        [$from, $to] = $this->range($day);

        $row = DB::table('whatsapp_messages')
            ->where('whatsapp_bot_id', $bot->id)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("
                COUNT(*) as messages,
                COUNT(DISTINCT whatsapp_conversation_id) as conversations,
                COUNT(DISTINCT CASE WHEN direction = 'outgoing' AND sender_type = 'bot' THEN whatsapp_conversation_id END) as answered,
                SUM(direction = 'incoming') as incoming,
                SUM(direction = 'outgoing' AND sender_type = 'bot') as bot,
                SUM(direction = 'outgoing' AND sender_type NOT IN ('bot', 'system')) as staff,
                SUM(direction = 'incoming' AND type = 'audio') as voice,
                SUM(direction = 'incoming' AND type IN ('image', 'document', 'video')) as files
            ")
            ->first();

        $conversationIds = DB::table('whatsapp_messages')->where('whatsapp_bot_id', $bot->id)
            ->whereBetween('created_at', [$from, $to])->distinct()->pluck('whatsapp_conversation_id');

        $ai = DB::table('ai_calls')
            ->whereIn('conversation_id', $conversationIds)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as calls, COALESCE(SUM(input_tokens), 0) as input_tokens, COALESCE(SUM(output_tokens), 0) as output_tokens, AVG(latency_ms) as latency')
            ->first();

        $newChats = WhatsappConversation::query()->where('whatsapp_bot_id', $bot->id)->whereBetween('created_at', [$from, $to])->count();
        $conversations = (int) ($row->conversations ?? 0);

        return [
            'conversations' => $conversations,
            'answered' => (int) ($row->answered ?? 0),
            'new_chats' => $newChats,
            'messages' => (int) ($row->messages ?? 0),
            'incoming' => (int) ($row->incoming ?? 0),
            'bot' => (int) ($row->bot ?? 0),
            'staff' => (int) ($row->staff ?? 0),
            'voice' => (int) ($row->voice ?? 0),
            'files' => (int) ($row->files ?? 0),
            'avg_per_chat' => $conversations ? round(((int) $row->messages) / $conversations, 1) : 0,
            'ai_calls' => (int) ($ai->calls ?? 0),
            'tokens' => (int) ($ai->input_tokens ?? 0) + (int) ($ai->output_tokens ?? 0),
            'avg_latency' => $ai->latency ? round($ai->latency / 1000, 1) : null,
        ];
    }

    /** Messages per hour (0..23): customer vs bot, for the day's activity chart. */
    public function hourly(WhatsappBot $bot, CarbonInterface $day): array
    {
        [$from, $to] = $this->range($day);

        $rows = DB::table('whatsapp_messages')
            ->where('whatsapp_bot_id', $bot->id)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("HOUR(created_at) as h, SUM(direction = 'incoming') as incoming, SUM(direction = 'outgoing') as outgoing")
            ->groupBy('h')
            ->get()
            ->keyBy('h');

        return collect(range(0, 23))->map(fn ($h) => [
            'hour' => $h,
            'incoming' => (int) ($rows[$h]->incoming ?? 0),
            'outgoing' => (int) ($rows[$h]->outgoing ?? 0),
        ])->all();
    }

    /** The day's chats of the bot, busiest first, with message and AI usage per chat. */
    public function conversations(WhatsappBot $bot, CarbonInterface $day, string $search = ''): Collection
    {
        [$from, $to] = $this->range($day);

        $stats = DB::table('whatsapp_messages')
            ->where('whatsapp_bot_id', $bot->id)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("
                whatsapp_conversation_id as id,
                COUNT(*) as messages,
                SUM(direction = 'incoming') as incoming,
                SUM(direction = 'outgoing' AND sender_type = 'bot') as bot,
                SUM(direction = 'outgoing' AND sender_type NOT IN ('bot', 'system')) as staff,
                MIN(created_at) as first_at,
                MAX(created_at) as last_at,
                MAX(id) as last_message_id
            ")
            ->groupBy('whatsapp_conversation_id')
            ->get()
            ->keyBy('id');

        if ($stats->isEmpty()) {
            return collect();
        }

        $ai = DB::table('ai_calls')
            ->whereIn('conversation_id', $stats->keys())
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('conversation_id, COUNT(*) as calls, COALESCE(SUM(input_tokens), 0) + COALESCE(SUM(output_tokens), 0) as tokens')
            ->groupBy('conversation_id')
            ->get()
            ->keyBy('conversation_id');

        $lastMessages = WhatsappMessage::query()->whereIn('id', $stats->pluck('last_message_id'))->get()->keyBy('id');

        return WhatsappConversation::query()
            ->with('customer:id,push_name')
            ->whereIn('id', $stats->keys())
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('phone', 'like', "%{$search}%")
                ->orWhere('real_phone', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('push_name', 'like', "%{$search}%"))))
            ->get()
            ->map(function (WhatsappConversation $conversation) use ($stats, $ai, $lastMessages) {
                $s = $stats[$conversation->id];
                $last = $lastMessages[$s->last_message_id] ?? null;

                return [
                    'id' => $conversation->id,
                    'name' => $conversation->customer?->push_name ?: null,
                    'phone' => $conversation->real_phone ?: $conversation->phone,
                    'status' => $conversation->status,
                    'topic' => $conversation->last_topic,
                    'messages' => (int) $s->messages,
                    'incoming' => (int) $s->incoming,
                    'bot' => (int) $s->bot,
                    'staff' => (int) $s->staff,
                    'ai_calls' => (int) ($ai[$conversation->id]->calls ?? 0),
                    'tokens' => (int) ($ai[$conversation->id]->tokens ?? 0),
                    'first_at' => \Carbon\Carbon::parse($s->first_at),
                    'last_at' => \Carbon\Carbon::parse($s->last_at),
                    'preview' => $last ? $this->preview($last) : '',
                    'last_from' => $last?->direction === 'incoming' ? 'customer' : ($last?->sender_type === 'bot' ? 'bot' : 'staff'),
                ];
            })
            ->sortByDesc('messages')
            ->values();
    }

    /** One chat of the bot, oldest first (the whole chat, not only the day). */
    public function messages(WhatsappBot $bot, int $conversationId, int $limit = 400): Collection
    {
        return WhatsappMessage::query()
            ->with('media')
            ->where('whatsapp_bot_id', $bot->id)
            ->where('whatsapp_conversation_id', $conversationId)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /** Short text for a message: its text, the voice note transcript, or the kind of attachment. */
    public function preview(WhatsappMessage $message): string
    {
        $text = trim((string) ($message->text ?: $message->message));

        if ($text !== '' && $text !== '[media]') {
            return $text;
        }

        if ($message->transcript) {
            return '🎙️ '.$message->transcript;
        }

        return match ($message->type) {
            'audio' => '🎙️ رسالة صوتية',
            'image' => '📷 صورة',
            'video' => '🎬 فيديو',
            'document' => '📎 ملف',
            default => '…',
        };
    }

    /** Media of a message: the saved items in its payload, else its media rows. */
    public function mediaFor(WhatsappMessage $message): array
    {
        $items = collect(data_get($message->payload, 'saved_media_items', []))->filter(fn ($item) => is_array($item))->map(fn ($item) => [
            'type' => strtolower((string) ($item['type'] ?? '')),
            'mime' => strtolower((string) ($item['mime'] ?? '')),
            'name' => $item['filename'] ?? null,
            'url' => ! empty($item['media_id']) ? route('staff.media.show', $item['media_id']) : asset('storage/'.ltrim((string) ($item['path'] ?? ''), '/')),
        ]);

        if ($items->isEmpty()) {
            $items = $message->media->map(fn ($media) => [
                'type' => strtolower((string) $media->media_type),
                'mime' => strtolower((string) $media->mime),
                'name' => $media->original_filename,
                'url' => route('staff.media.show', $media->id),
            ]);
        }

        return $items->values()->all();
    }

    /** @return array{0: CarbonInterface, 1: CarbonInterface} */
    private function range(?CarbonInterface $day): array
    {
        $day = ($day ?? now())->copy();

        return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
    }
}
