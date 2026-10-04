<?php

namespace App\Agent\Runtime;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiRequest;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Log;

/**
 * Reads every draft reply for the mistakes that cost a sale, before it is
 * sent (owner 2026-10-04: "عاوز البوت يبقى بياع مصري شاطر"): a misread
 * message ("جمبو"), a figure, paper or option no source gives ("30% على
 * سنتين", "كشف حساب بدل المفردات"), a claim nothing did. Word lists cannot
 * read meaning; style stays with them and the instructions - judged here at
 * low effort it raised false problems ("ابعتي" vs "تبعتِ").
 *
 * It only judges: a real mistake goes back to the reply model with what is
 * wrong (verdict "redo"), which fixes it with the whole conversation in front
 * of it. Its own rewrites read worse ("أحسبلك القسط على قدام") and every
 * reply waited for them.
 */
class ReplyReviewer
{
    public function __construct(private readonly AiProvider $ai)
    {
    }

    /**
     * @param  array<int, array{name: string, ok: bool, data: array}>  $outcomes  this turn's tool calls
     * @return array{verdict: string, messages: string[], problems: string[]}
     */
    public function review(array $args, WhatsappConversation $conversation, object $turn, array $outcomes, string $knows): array
    {
        $draft = array_values(array_filter(array_map('strval', (array) ($args['messages'] ?? [])), fn ($m) => trim($m) !== ''));
        $pass = ['verdict' => 'ok', 'messages' => $draft, 'problems' => []];

        if ($draft === []) {
            return $pass;
        }

        try {
            $response = $this->ai->chat(new AiRequest(
                system: self::CHECKLIST,
                contents: [['role' => 'user', 'parts' => [['type' => 'text', 'text' => json_encode([
                    'recent_chat' => $this->recentChat($conversation),
                    'what_the_salesman_knows' => $this->knowledge($knows),
                    'tool_results_this_turn' => $this->toolResults($outcomes),
                    'draft_reply' => $draft,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]]]],
                maxOutputTokens: 1500,
                timeoutSeconds: 25,
                responseSchema: [
                    'type' => 'object',
                    'properties' => [
                        'problems' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'verdict' => ['type' => 'string', 'enum' => ['ok', 'redo']],
                    ],
                    'required' => ['problems', 'verdict'],
                ],
                thinkingLevel: (string) config('agent.reviewer.effort', 'low'),
                purpose: 'reply',
            ));

            $parsed = json_decode(implode('', $response->textParts), true);
        } catch (\Throwable $e) {
            // The reviewer is a second pair of eyes, never a reason to stay silent.
            Log::warning('ReplyReviewer failed', ['turn_id' => $turn->id, 'error' => $e->getMessage()]);

            return $pass;
        }

        $verdict = $parsed['verdict'] ?? 'ok';
        $problems = array_values(array_filter(array_map('strval', (array) ($parsed['problems'] ?? []))));
        return $verdict === 'redo' && $problems !== []
            ? ['verdict' => 'redo', 'messages' => $draft, 'problems' => $problems]
            : $pass;
    }

    /**
     * The owner's standing guidance (dashboard) and the live state, memory,
     * knowledge and turn notes from his context - not his instructions or
     * the catalog index.
     */
    private function knowledge(string $system): string
    {
        $part = function (string $from, ?string $to) use ($system): string {
            $start = mb_strpos($system, $from);

            if ($start === false) {
                return '';
            }

            $end = $to !== null ? mb_strpos($system, $to, $start) : false;

            return mb_substr($system, $start, $end === false ? null : $end - $start);
        };

        $text = trim($part('## إرشادات ثابتة', '## فهرس')."\n\n".$part('## الحالة الحالية', null));

        return mb_substr($text !== '' ? $text : mb_substr($system, -12000), 0, 20000);
    }

    private function recentChat(WhatsappConversation $conversation): array
    {
        return WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->latest('id')
            ->limit(12)
            ->get()
            ->reverse()
            ->map(fn (WhatsappMessage $m) => [
                'from' => match (true) {
                    $m->direction === 'incoming' => 'customer',
                    $m->sender_type === 'bot' => 'us (bot)',
                    default => 'us (staff)',
                },
                'text' => mb_substr((string) ($m->text ?: $m->transcript ?: '['.$m->type.']'), 0, 600),
            ])
            ->values()
            ->all();
    }

    private function toolResults(array $outcomes): array
    {
        return array_map(function ($o) {
            $data = json_encode($o['data'] ?? [], JSON_UNESCAPED_UNICODE) ?: '';

            return ['tool' => $o['name'], 'ok' => $o['ok'], 'data' => mb_strlen($data) > 3000 ? mb_substr($data, 0, 3000).'…' : $data];
        }, array_values(array_filter($outcomes, fn ($o) => $o['name'] !== 'send_reply')));
    }

    /**
     * 2026-10-04: 21 of its 40 redos were facts the code checks exactly
     * (numbers, model names, saved data, sent photos, opened applications) -
     * those moved to ReplyGuard, which runs first. What is left is what only
     * reading can catch.
     */
    private const CHECKLIST = <<<'PROMPT'
You check one reply our WhatsApp salesman (a bot at an Egyptian motorcycle showroom) is about to send. You look for two kinds of mistakes only.

Inputs: recent_chat (oldest first), what_the_salesman_knows (the customer's live state and the showroom's knowledge), tool_results_this_turn, draft_reply.

1. Misunderstanding: the draft does not answer what the customer said in his last messages:
   - ignores a clear question ("ينفع ابني يبقى الضامن؟", "عايز حساب 48 شهر" - if a tool said the duration is not available, the draft must say so);
   - ignores something he told us ("الشركة مش بتطلع مفردات", "أيوه متأمنة");
   - asks for something he already sent;
   - misreads Egyptian slang (جمبو/جمبه = جنبه "next to it");
   - asks "what do you mean?" when he was clear.
2. Invented reason / procedure / promise / option: a reason, step, procedure, promise or choice that is written in neither tool_results_this_turn nor what_the_salesman_knows. Examples: "تيجي الفرع تمضي بدل الصور", "الفرع هيكلمك", "البطاقة بتملى البيانات لوحدها", "حتى لو الضامن ابنك هيترفض".

Numbers, model names, saved data, sent photos, opened applications, and WHICH documents or data to ask for (and how many at once) are checked by code - never raise them.
Style, wording, gender, length are not your job. Never ask the draft to acknowledge, confirm or repeat what he sent or said - "تمام" is enough.
Write the problems in English.

Verdict "redo" only when you are sure one of these two is in the draft; problems = short English notes saying exactly what is wrong and what to do (which tool to call, what to drop). Otherwise "ok" with problems = []. When in doubt: ok.
PROMPT;
}
