<?php

namespace App\Domain\Conversations;

use App\Jobs\TranscribeVoiceMessage;
use App\Models\Customer;
use App\Models\MessageMedia;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Ingests one v2 WhatsApp payload from Node. No reply generation, no
 * interpretation of the text - see plan principle 2 and T04.
 */
class IngestionService
{
    public function __construct(private readonly TurnSchedulerHook $turnScheduler)
    {
    }

    /**
     * @return array{ok: bool, duplicate?: bool, ignored?: bool, errors?: array}
     */
    public function ingest(array $payload): array
    {
        $validator = Validator::make($payload, [
            'bot_id' => ['required'],
            'wa_message_id' => ['required', 'string'],
            'chat_jid' => ['required', 'string'],
            'customer_jid' => ['nullable', 'string'],
            'push_name' => ['nullable', 'string'],
            'timestamp' => ['required'],
            'type' => ['required', 'in:text,image,document,audio,video,location,sticker,unknown'],
            'text' => ['nullable', 'string'],
            // DEC-17: a message the staff sent from the phone itself, not
            // the bot. Defaults to 'incoming' (the normal customer path).
            'direction' => ['nullable', 'in:incoming,outgoing'],
            'media' => ['array'],
            'media.*.media_type' => ['required_with:media', 'string'],
            'media.*.mime' => ['required_with:media', 'string'],
            'media.*.base64' => ['required_with:media', 'string'],
            'location' => ['nullable', 'array'],
            'quoted' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            Log::warning('whatsapp ingestion: invalid payload shape', [
                'errors' => $validator->errors()->keys(),
            ]);

            return ['ok' => false, 'errors' => $validator->errors()->keys()];
        }

        $data = $validator->validated();
        $data['media'] = $data['media'] ?? [];
        $data['direction'] = $data['direction'] ?? 'incoming';

        $bot = WhatsappBot::find($data['bot_id']);

        if (! $bot) {
            return ['ok' => true, 'ignored' => true];
        }

        return DB::transaction(function () use ($bot, $data) {
            $existing = WhatsappMessage::where('wa_message_id', $data['wa_message_id'])->lockForUpdate()->first();

            if ($existing) {
                return ['ok' => true, 'duplicate' => true];
            }

            $isIncoming = $data['direction'] === 'incoming';

            $customer = $this->upsertCustomer($bot, $data);
            $conversation = $this->findOrCreateConversation($bot, $customer, $data);

            $sessionGapExceeded = $isIncoming && $this->sessionGapExceeded($conversation);

            [$quotedMessageId, $quotedMeta] = $this->resolveQuoted($data['quoted'] ?? null);

            $message = $conversation->messages()->create([
                'whatsapp_bot_id' => $bot->id,
                'wa_message_id' => $data['wa_message_id'],
                'direction' => $data['direction'],
                'sender_type' => $isIncoming ? 'customer' : 'human_phone',
                'type' => $data['type'],
                'text' => $data['text'] ?? null,
                'message' => $data['text'] ?: ($data['media'] !== [] ? '[media]' : ''),
                'quoted_message_id' => $quotedMessageId,
                'delivery_status' => $isIncoming ? 'received' : 'sent',
                'metadata' => array_filter([
                    'quoted' => $quotedMeta,
                    'location' => $data['location'] ?? null,
                ]),
            ]);

            $mediaRows = $this->storeMedia($message, $data['media']);

            if ($mediaRows !== []) {
                $message->update(['payload' => ['saved_media_items' => array_map(
                    fn (MessageMedia $m) => [
                        'type' => $m->media_type,
                        'mime' => $m->mime,
                        'filename' => $m->original_filename,
                        'path' => $m->path,
                        'media_id' => $m->id,
                        'size' => $m->size,
                    ],
                    $mediaRows
                )]]);
            }

            if ($isIncoming && $data['type'] === 'audio' && $mediaRows !== []) {
                $message->update(['transcription_status' => 'pending']);
                TranscribeVoiceMessage::dispatch($message->id)->afterCommit();
            }

            if ($sessionGapExceeded) {
                $conversation->state = array_merge($conversation->state ?? [], [
                    'session_started_at' => now()->toIso8601String(),
                ]);
            }

            if ($isIncoming) {
                $conversation->last_inbound_at = now();
            } else {
                $this->turnScheduler->staffTookOver($conversation);
            }

            $conversation->save();

            if ($isIncoming) {
                $this->turnScheduler->onMessageIngested($message);
            }

            return ['ok' => true, 'duplicate' => false];
        });
    }

    private function upsertCustomer(WhatsappBot $bot, array $data): Customer
    {
        // DEC-09: customer identity is scoped per bot, keyed on the chat jid.
        $customer = Customer::firstOrNew([
            'whatsapp_bot_id' => $bot->id,
            'jid' => $data['chat_jid'],
        ]);

        if (! empty($data['customer_jid'])) {
            $customer->lid_jid = str_ends_with($data['chat_jid'], '@lid') ? $data['chat_jid'] : $customer->lid_jid;
            $customer->phone = $this->cleanPhoneFromJid($data['customer_jid']);
        } elseif (! $customer->exists) {
            $customer->phone = $this->cleanPhoneFromJid($data['chat_jid']);
        }

        if (! empty($data['push_name'])) {
            $customer->push_name = $data['push_name'];
        }

        $customer->save();

        return $customer;
    }

    private function findOrCreateConversation(WhatsappBot $bot, Customer $customer, array $data): WhatsappConversation
    {
        $phone = $this->cleanPhoneFromJid($data['chat_jid']);

        $conversation = WhatsappConversation::firstOrCreate(
            ['whatsapp_bot_id' => $bot->id, 'phone' => $phone],
            ['status' => 'open']
        );

        if ($conversation->customer_id !== $customer->id) {
            $conversation->customer_id = $customer->id;
        }

        if (! empty($data['customer_jid'])) {
            $realPhone = $this->cleanPhoneFromJid($data['customer_jid']);

            if ($realPhone !== '' && $realPhone !== $conversation->real_phone) {
                $conversation->real_phone = $realPhone;
            }
        }

        if ($conversation->isDirty()) {
            $conversation->save();
        }

        return $conversation;
    }

    /**
     * @return array{0: ?int, 1: ?array}
     */
    private function resolveQuoted(?array $quoted): array
    {
        if (empty($quoted['wa_message_id'])) {
            return [null, null];
        }

        $quotedMessage = WhatsappMessage::where('wa_message_id', $quoted['wa_message_id'])->first();

        if ($quotedMessage) {
            return [$quotedMessage->id, null];
        }

        return [null, $quoted];
    }

    /**
     * @param  array<int, array{media_type: string, mime: string, filename?: ?string, base64: string, size?: int}>  $items
     * @return MessageMedia[]
     */
    private function storeMedia(WhatsappMessage $message, array $items): array
    {
        $maxBytes = (int) config('agent.media.max_bytes', 20 * 1024 * 1024);
        $disk = Storage::disk('local');
        $rows = [];

        foreach ($items as $index => $item) {
            $binary = base64_decode((string) $item['base64'], true);

            if ($binary === false) {
                continue;
            }

            $size = strlen($binary);

            if ($size > $maxBytes) {
                $message->update(['metadata' => array_merge($message->metadata ?? [], [
                    'media_rejected' => ['index' => $index, 'reason' => 'TOO_LARGE', 'size' => $size],
                ])]);

                continue;
            }

            $extension = $this->extensionFromMime($item['mime'], $item['media_type']);
            $path = sprintf(
                'whatsapp-media/conversation-%d/%s_%s.%s',
                $message->whatsapp_conversation_id,
                now()->format('Ymd_His'),
                Str::random(12),
                $extension
            );

            $disk->put($path, $binary);

            $rows[] = MessageMedia::create([
                'message_id' => $message->id,
                'media_type' => $item['media_type'],
                'mime' => $item['mime'],
                'disk' => 'local',
                'path' => $path,
                'size' => $size,
                'sha256' => hash('sha256', $binary),
                'original_filename' => $item['filename'] ?? null,
            ]);
        }

        return $rows;
    }

    private function sessionGapExceeded(WhatsappConversation $conversation): bool
    {
        if (! $conversation->last_inbound_at) {
            return true;
        }

        $gapHours = config('agent.session_gap_hours');

        if ($gapHours === null) {
            return false;
        }

        return $conversation->last_inbound_at->lt(now()->subHours((float) $gapHours));
    }

    private function extensionFromMime(string $mime, string $type = 'file'): string
    {
        $mime = strtolower($mime);

        return match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'mp4') => 'mp4',
            str_contains($mime, 'quicktime') => 'mov',
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'ogg'), str_contains($mime, 'opus') => 'ogg',
            str_contains($mime, 'mpeg') && $type === 'audio' => 'mp3',
            str_contains($mime, 'mp3') => 'mp3',
            default => match ($type) {
                'image' => 'jpg',
                'video' => 'mp4',
                'audio' => 'ogg',
                default => 'bin',
            },
        };
    }

    private function cleanPhoneFromJid(string $jid): string
    {
        return str_replace(['@s.whatsapp.net', '@lid', '@c.us'], '', $jid);
    }
}
