<?php

namespace App\Agent\Runtime;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiRequest;
use App\Domain\Applications\CustomerDataService;
use App\Models\Application;
use App\Models\Customer;
use App\Models\RequirementField;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Log;

/**
 * Reads what the customer just wrote before the agent answers, and saves
 * every fact in it itself. Owner 2026-10-04 (conversation 865): the work
 * address came in five times and was never saved - saving depended on the
 * reply model remembering to call a tool. Here the code saves it, through
 * the same provenance checks as record_customer_data (every value must be
 * in his own words).
 *
 * It also settles how he is addressed (conversation 865: a man was told
 * "تبعتي"), kept on the conversation once he or she makes it clear.
 */
class TurnUnderstanding
{
    /**
     * Read off his ID card, never asked in writing - and his work, which
     * WorkClassifier reads with its own rules.
     */
    private const NOT_EXTRACTED = ['full_name', 'national_id', 'work_type'];

    public function __construct(
        private readonly AiProvider $ai,
        private readonly CustomerDataService $customerData,
    ) {
    }

    /**
     * @return array{saved: array<string, string>, rejected: array, gender: ?string}|null
     *   null when there was nothing to read (no text this turn) or the read failed
     */
    public function understand(object $turn, WhatsappConversation $conversation, ?Customer $customer, ?Application $application): ?array
    {
        $said = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('turn_id', $turn->id)
            ->where('direction', 'incoming')
            ->orderBy('id')
            ->get()
            ->map(fn (WhatsappMessage $m) => trim((string) ($m->text ?: $m->transcript)))
            ->filter()
            ->values();

        if (! $customer) {
            return null;
        }

        // What he said before the application was open (sector still
        // unknown, conversation 166): kept, and saved the moment it opens.
        $flushed = $application ? $this->flushPending($conversation, $customer, $application) : [];

        if ($said->isEmpty()) {
            return $flushed === [] ? null : ['saved' => $flushed, 'rejected' => [], 'gender' => $conversation->state['customer_gender'] ?? null];
        }

        $asked = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')
            ->where('id', '<', WhatsappMessage::where('turn_id', $turn->id)->min('id') ?? PHP_INT_MAX)
            ->latest('id')
            ->value('text');

        $fields = $this->fieldCatalog();

        try {
            $response = $this->ai->chat(new AiRequest(
                system: $this->instructions($fields),
                contents: [['role' => 'user', 'parts' => [['type' => 'text', 'text' => json_encode([
                    'last_message_from_us' => $asked,
                    'customer_wrote_now' => $said->all(),
                    'already_known_gender' => $conversation->state['customer_gender'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]]]],
                maxOutputTokens: 1200,
                timeoutSeconds: 25,
                responseSchema: $this->schema(array_keys($fields)),
                thinkingLevel: 'low',
                purpose: 'reply',
            ));

            $parsed = json_decode(implode('', $response->textParts), true);
        } catch (\Throwable $e) {
            Log::warning('TurnUnderstanding failed', ['turn_id' => $turn->id, 'error' => $e->getMessage()]);

            return null;
        }

        if (! is_array($parsed)) {
            return null;
        }

        $gender = $this->rememberGender($conversation, $parsed);
        $saved = $flushed;
        $rejected = [];

        $found = array_values(array_filter((array) ($parsed['fields'] ?? []), fn ($f) => isset($fields[$f['key'] ?? ''])
            && trim((string) ($f['value'] ?? '')) !== ''));

        if ($found !== [] && $application) {
            $result = $this->customerData->record($customer, $application, array_map(fn ($f) => [
                'key' => $f['key'],
                'value' => trim((string) $f['value']),
                'quote' => trim((string) ($f['quote'] ?? '')),
            ], $found), $conversation->id);

            foreach ($found as $f) {
                if (in_array($f['key'], $result['saved'], true)) {
                    $saved[$f['key']] = trim((string) $f['value']);
                }
            }

            $rejected = array_merge($result['rejected'], $result['conflicts']);
        } elseif ($found !== []) {
            $this->keepPending($conversation, $found);
        }

        return ['saved' => $saved, 'rejected' => $rejected, 'gender' => $gender];
    }

    /** @param  array<int, array{key: string, value: string, quote?: string}>  $found */
    private function keepPending(WhatsappConversation $conversation, array $found): void
    {
        $pending = collect($conversation->state['pending_fields'] ?? [])->keyBy('key');

        foreach ($found as $f) {
            $pending[$f['key']] = ['key' => $f['key'], 'value' => trim((string) $f['value']), 'quote' => trim((string) ($f['quote'] ?? ''))];
        }

        $conversation->state = array_merge($conversation->state ?? [], ['pending_fields' => $pending->values()->all()]);
        $conversation->save();
    }

    /** @return array<string, string> what was saved from the kept facts */
    public function flushPending(WhatsappConversation $conversation, Customer $customer, Application $application): array
    {
        $pending = $conversation->state['pending_fields'] ?? [];

        if ($pending === []) {
            return [];
        }

        $state = $conversation->state;
        unset($state['pending_fields']);
        $conversation->state = $state;
        $conversation->save();

        $result = $this->customerData->record($customer, $application, $pending, $conversation->id);

        return collect($pending)->filter(fn ($f) => in_array($f['key'], $result['saved'], true))->mapWithKeys(fn ($f) => [$f['key'] => $f['value']])->all();
    }

    /** Context for the reply model: what is already saved, and how to address him. */
    public static function note(?array $understood, WhatsappConversation $conversation): ?string
    {
        $gender = $understood['gender'] ?? ($conversation->state['customer_gender'] ?? null);
        $lines = [];

        if (($understood['saved'] ?? []) !== []) {
            $labels = RequirementField::whereIn('key', array_keys($understood['saved']))->pluck('label', 'key');
            $lines[] = 'Already saved from his message just now (do NOT call record_customer_data for these, do NOT read them back to him - say "تمام" and go to what is still missing): '
                .collect($understood['saved'])->map(fn ($v, $k) => ($labels[$k] ?? $k).' = '.$v)->implode('؛ ');
        }

        if (($understood['rejected'] ?? []) !== []) {
            $lines[] = 'Not saved from his message: '.json_encode($understood['rejected'], JSON_UNESCAPED_UNICODE)
                .' - if one of these is a real answer, ask him only for that part again in plain words.';
        }

        $lines[] = match ($gender) {
            'female' => 'The customer is a woman: address her in the feminine (تبعتي، عندك يا فندم).',
            default => 'Address the customer in the masculine (تبعت، عندك) - talking about someone else ("بنت عمتي") does not change that.',
        };

        return "## فهم رسالته الأخيرة\n- ".implode("\n- ", $lines);
    }

    /** @return array<string, RequirementField> fields he can state himself, keyed by key */
    private function fieldCatalog(): array
    {
        return RequirementField::where('is_active', true)
            ->whereNotIn('key', array_merge(self::NOT_EXTRACTED, CustomerDataService::DOCUMENT_ONLY))
            ->orderBy('id')
            ->get()
            ->keyBy('key')
            ->all();
    }

    private function instructions(array $fields): string
    {
        $catalog = collect($fields)->map(function (RequirementField $f) {
            $options = $f->data_type === 'enum' && is_array($f->enum_options) ? ' (one of: '.implode(', ', $f->enum_options).')' : '';

            return "- {$f->key}: {$f->label}{$options}".($f->description_for_ai ? " - {$f->description_for_ai}" : '');
        })->implode("\n");

        return <<<PROMPT
You read the WhatsApp messages an Egyptian customer just sent to a motorcycle showroom, and pull out facts. You write nothing to him.

Rules:
- Take only what HE wrote in customer_wrote_now. Never guess, never complete a value, never take it from our message.
- value = his words for that field, trimmed (Arabic as he wrote it; digits as he wrote them). quote = the exact piece of his message it came from.
- Use last_message_from_us to know what he is answering: "255" after we asked for the building number is work_building_no / address_building_no accordingly.
- An address he gives may hold several fields at once: the street/area/governorate part is the address field, "بجوار / جنب / جمبو / جمبه / قدام / ورا X" is the landmark (جمبو = جنبه, not a place name), a number before the street is the building number, "الدور التاني" is the floor.
- Work address (where he works, his shop, workshop, company) goes in the work_* fields; where he lives goes in address / address_*. If it is not clear which one, use the one our last message asked for.
- "مفيش رقم" / "معرفش" for a part we asked = value "لا يوجد" for that part.
- Questions, objections, greetings and chit-chat are not facts - return no field for them.
- customer_gender: "female" only when he/she makes it clear she is a woman (feminine self-reference like "أنا عايزة"، "أنا شغالة"، a woman's name for herself), "male" when clearly a man, else "unknown". Talking about someone else (بنت عمتي، مراتي) says nothing about the writer. Keep already_known_gender unless the new messages clearly say otherwise.

Fields:
{$catalog}
PROMPT;
    }

    private function schema(array $keys): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fields' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => $keys === [] ? ['type' => 'string'] : ['type' => 'string', 'enum' => $keys],
                            'value' => ['type' => 'string'],
                            'quote' => ['type' => 'string'],
                        ],
                        'required' => ['key', 'value', 'quote'],
                    ],
                ],
                'customer_gender' => ['type' => 'string', 'enum' => ['male', 'female', 'unknown']],
                'gender_quote' => ['type' => 'string'],
            ],
            'required' => ['fields', 'customer_gender', 'gender_quote'],
        ];
    }

    private function rememberGender(WhatsappConversation $conversation, array $parsed): ?string
    {
        $gender = $parsed['customer_gender'] ?? 'unknown';
        $known = $conversation->state['customer_gender'] ?? null;

        if (! in_array($gender, ['male', 'female'], true) || $gender === $known) {
            return $known;
        }

        $conversation->state = array_merge($conversation->state ?? [], ['customer_gender' => $gender]);
        $conversation->save();

        return $gender;
    }
}
