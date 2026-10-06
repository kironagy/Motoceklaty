<?php

namespace App\Agent\Tools;

use App\Models\Machine;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;

/**
 * WRITE (outbound, ends the turn) — plan §6.19. The only way a turn ends
 * normally. Conversational delivery only: never touches applications,
 * customer data, documents, selections or handoff state.
 */
class SendReplyTool implements WriteTool
{
    public function name(): string
    {
        return 'send_reply';
    }

    public function description(): string
    {
        return 'Send the reply (one message) - the only normal end of a turn. memory = only what this turn added.';
    }

    /** Facts the customer may tell about himself - CustomerMemory::FACT_KEYS. */
    private function memorySchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Update his memory with what THIS turn taught you (omit when nothing new).',
            'properties' => [
                'facts' => [
                    'type' => 'array',
                    'maxItems' => 6,
                    'description' => 'Things he said. quote = his exact words; a guess without his words is stored as unconfirmed. about = other_applicant for facts about the person applying instead of him (his mother\'s age is not his age).',
                    'items' => [
                        'type' => 'object',
                        'required' => ['key', 'value', 'quote'],
                        'properties' => [
                            'key' => ['type' => 'string', 'enum' => \App\Domain\Memory\CustomerMemory::FACT_KEYS],
                            'value' => ['type' => 'string'],
                            'quote' => ['type' => 'string'],
                            'about' => ['type' => 'string', 'enum' => ['customer', 'other_applicant']],
                        ],
                    ],
                ],
                'motorcycles' => [
                    'type' => 'array',
                    'maxItems' => 4,
                    'description' => 'asked_about = "بكام؟" (not a choice); interested = "عايز X"; selected = "خلاص هاخد X"; rejected = "مش عايز X" (selected/rejected need quote).',
                    'items' => [
                        'type' => 'object',
                        'required' => ['stage'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'stage' => ['type' => 'string', 'enum' => \App\Domain\Memory\CustomerMemory::STAGES],
                            'quote' => ['type' => 'string'],
                        ],
                    ],
                ],
                'topic' => ['type' => 'string', 'description' => 'A few Arabic words: what you are talking about now.'],
                'open_question' => ['type' => 'string', 'description' => 'What is still open: what he asked that you could not answer, or what you wait from him. Empty string when nothing.'],
                'objections' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'His hesitations still standing (e.g. "القسط عالي"). Empty array when none.'],
            ],
        ];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'messages' => [
                    'type' => 'array',
                    'minItems' => 0,
                    'maxItems' => 3,
                    'items' => ['type' => 'string', 'maxLength' => (int) config('agent.reply.max_chars', 700)],
                ],
                'quote_wa_message_id' => ['type' => 'string'],
                'focus_motorcycle_ids' => [
                    'type' => 'array',
                    'minItems' => 0,
                    'maxItems' => 3,
                    'items' => ['type' => 'integer'],
                ],
                'no_reply' => ['type' => 'boolean', 'description' => 'true with messages [] when his message needs no answer (thanks/emoji after the talk ended).'],
                'ends_conversation' => ['type' => 'boolean', 'description' => 'true when he declined, paused or said goodbye: no reminders until he writes again.'],
                'memory' => $this->memorySchema(),
            ],
            'required' => ['messages'],
        ];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $conversation = WhatsappConversation::findOrFail($ctx->conversationId);

        $focusIds = $args['focus_motorcycle_ids'] ?? [];

        if ($focusIds !== []) {
            $found = Machine::query()->whereIn('id', $focusIds)->pluck('id')->all();
            $unknown = array_diff($focusIds, $found);

            if ($unknown !== []) {
                return ToolResult::error('UNKNOWN_FOCUS_ID', 'Unknown motorcycle id(s): '.implode(', ', $unknown));
            }
        }

        $awaiting = $args['awaiting'] ?? [];

        // QA 2026-10-04: the schema offered field/document kinds the code then
        // refused - a reply with the customer's whole status was lost to
        // UNKNOWN_AWAITING_KEY. What he still owes is the snapshot's next_step;
        // the parameter is no longer offered and a stray one is ignored.
        $awaiting = array_values(array_filter((array) $awaiting, fn ($item) => ($item['kind'] ?? null) === 'confirmation' && filled($item['key'] ?? null)));

        if (! empty($args['quote_wa_message_id'])) {
            $quoteExists = WhatsappMessage::query()
                ->where('whatsapp_conversation_id', $ctx->conversationId)
                ->where('wa_message_id', $args['quote_wa_message_id'])
                ->exists();

            if (! $quoteExists) {
                return ToolResult::error('QUOTE_NOT_FOUND', 'The quoted message does not belong to this conversation.');
            }
        }

        $messages = array_values(array_filter((array) ($args['messages'] ?? []), fn ($m) => trim((string) $m) !== ''));

        // Silence is the model's call; code only refuses it after our own
        // question or when he sent media (mayStaySilent).
        if ($messages === []) {
            if (! ($args['no_reply'] ?? false) || ! self::mayStaySilent($ctx)) {
                return ToolResult::error('REPLY_REQUIRED', 'His message needs an answer - write the reply in messages. no_reply is only for a thanks/emoji/"تمام" after the conversation already ended.');
            }
        }

        $this->rememberSafely($args['memory'] ?? null, $ctx);

        // Owner 2026-10-04: "بيرد عليا برسالتين وتلاتة" - a salesman sends one
        // message. The parts go out as one, a blank line between them; only a
        // reply too long for one message stays split.
        $messages = self::asOneMessage($messages);

        $ctx->outbound->addMessages($messages);
        $ctx->outbound->setQuote($args['quote_wa_message_id'] ?? null);
        $ctx->outbound->setFocus($focusIds);
        $ctx->outbound->finish();

        $state = $conversation->state ?? [];
        $state['focus_motorcycle_ids'] = $focusIds;
        $state['awaiting'] = array_map(
            fn ($item) => ['kind' => $item['kind'], 'key' => $item['key'], 'asked_at' => now()->toIso8601String()],
            $awaiting
        );

        // Rebuild: the model says the conversation ended - no phrase list guesses it.
        if (($args['ends_conversation'] ?? false) === true) {
            $state['ended_at'] = now()->toIso8601String();
        } elseif ($messages !== []) {
            unset($state['ended_at']);
        }

        $conversation->state = $state;
        $conversation->save();

        return ToolResult::ok(['accepted' => true]);
    }

    /** @param  string[]  $messages */
    public static function asOneMessage(array $messages, int $maxChars = 1200): array
    {
        $parts = array_values(array_filter(array_map(fn ($m) => trim((string) $m), $messages), fn ($m) => $m !== ''));

        if (count($parts) < 2) {
            return $parts;
        }

        // a heading line ("عندنا ٣ اختيارات:") belongs on top of its list
        $joined = '';

        foreach ($parts as $part) {
            $joined .= $joined === '' ? $part : (preg_match('/[:：]\s*$/u', $joined) ? "\n" : "\n\n").$part;
        }

        return mb_strlen($joined) <= $maxChars ? [$joined] : $parts;
    }

    /** A memory problem is logged, never a lost reply. */
    private function rememberSafely(mixed $memory, ToolContext $ctx): void
    {
        if (! is_array($memory) || $memory === []) {
            return;
        }

        try {
            app(\App\Domain\Memory\CustomerMemory::class)->applyModelUpdate($ctx->customerId, $ctx->conversationId, $memory);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Customer memory update failed', ['conversation_id' => $ctx->conversationId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Every customer message of this turn is a short text with no question
     * and no media, and our previous message was not a question left open.
     */
    public static function mayStaySilent(ToolContext $ctx): bool
    {
        $messages = WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
            ->where('turn_id', $ctx->turnId)->where('direction', 'incoming')->get(['type', 'text', 'transcript']);

        if ($messages->isEmpty()) {
            return false;
        }

        // Rebuild: structure, not his words - a photo, voice note or document
        // always gets an answer; whether his text needs one is the model's call.
        if ($messages->contains(fn ($m) => ! in_array($m->type, ['text', 'sticker', 'reaction'], true))) {
            return false;
        }

        $lastBot = WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
            ->where('direction', 'outgoing')->where(fn ($q) => $q->whereNull('turn_id')->orWhere('turn_id', '!=', $ctx->turnId))
            ->latest('id')->value('text');

        return $lastBot !== null && ! preg_match('/[؟?]\s*$/u', trim((string) $lastBot));
    }
}
