<?php

namespace App\Jobs;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiProviderException;
use App\Agent\Providers\AiRequest;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * T16 §4: rewrites (never appends to) the rolling summary once enough
 * messages have fallen out of the L7 window. A failure leaves the old
 * summary untouched (plan constraint) - there is no partial/half-written
 * state, since the DB update only happens after a successful AI call.
 */
class SummarizeConversation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @param  int  $upToMessageId  exclusive upper bound - summarize everything strictly before this id */
    public function __construct(public int $conversationId, public int $upToMessageId)
    {
    }

    public function handle(AiProvider $ai): void
    {
        $conversation = WhatsappConversation::find($this->conversationId);

        if (! $conversation) {
            return;
        }

        $since = $conversation->summary_until_message_id ?? 0;

        $messages = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('id', '>', $since)
            ->where('id', '<', $this->upToMessageId)
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return;
        }

        $transcript = $messages
            ->map(fn (WhatsappMessage $m) => ($m->sender_type === 'customer' ? 'العميل' : 'الرد')
                .': '.($m->text ?? $m->transcript ?? '[وسائط]'))
            ->implode("\n");

        $list = ['type' => 'array', 'items' => ['type' => 'string']];

        try {
            $response = $ai->chat(new AiRequest(
                purpose: \App\Services\GeminiKeyManager::isTestPhone($conversation->phone) ? 'simulator' : 'summary',
                label: 'summary',
                // CTX-010: free prose mixed what he said with chat filler; the
                // model could not tell a decision from a passing remark.
                system: 'اكتب ملخص المحادثة دي بالعربي في 3 قوايم، كل بند سطر قصير محايد:'."\n"
                    .'facts: حقايق عن العميل قالها أو اتأكدت (شغله، سنه، منطقته، الموتوسيكل اللي عايزه، ميزانيته).'."\n"
                    .'decisions: اللي اتفق عليه أو اختاره (موديل، مدة، مقدم، فتح طلب، طلب يكلم حد).'."\n"
                    .'still_open: اللي لسه مستني رد أو ورق أو سؤال ماتجاوبش.'."\n"
                    .'اكتب الملخص كله من جديد من الملخص السابق والرسائل الجديدة؛ الجديد يغلب القديم، وشيل اللي اتقفل من still_open. '
                    .'من غير أي بيانات حساسة زي أرقام قومية أو بيانات مستندات، ومن غير أرقام أسعار أو أقساط (دي بتتجاب من الأدوات).',
                contents: [
                    ['role' => 'user', 'parts' => [[
                        'type' => 'text',
                        'text' => 'الملخص السابق:'."\n".($conversation->summary ?: '(لا يوجد)')."\n\n"
                            .'الرسائل الجديدة:'."\n".$transcript,
                    ]]],
                ],
                toolMode: 'none',
                temperature: 0.2,
                responseSchema: ['type' => 'object', 'properties' => ['facts' => $list, 'decisions' => $list, 'still_open' => $list]],
            ));
        } catch (AiProviderException) {
            return;
        }

        $structured = self::decode(implode('', $response->textParts));

        if ($structured === null || array_merge(...array_values($structured)) === []) {
            return;
        }

        $conversation->update([
            'summary' => json_encode($structured, JSON_UNESCAPED_UNICODE),
            'summary_until_message_id' => $messages->last()->id,
            'summary_updated_at' => now(),
        ]);
    }

    /**
     * The structured summary, or null for an older prose summary.
     *
     * @return array{facts: string[], decisions: string[], still_open: string[]}|null
     */
    public static function decode(?string $summary): ?array
    {
        $data = json_decode((string) $summary, true);

        if (! is_array($data) || array_intersect_key($data, array_flip(['facts', 'decisions', 'still_open'])) === []) {
            return null;
        }

        $max = (int) config('agent.summary.max_items', 8);
        $clean = fn ($items) => array_slice(array_values(array_filter(array_map(
            fn ($item) => is_scalar($item) ? mb_substr(trim((string) $item), 0, 160) : '',
            (array) $items
        ), fn ($item) => $item !== '')), 0, $max);

        return [
            'facts' => $clean($data['facts'] ?? []),
            'decisions' => $clean($data['decisions'] ?? []),
            'still_open' => $clean($data['still_open'] ?? []),
        ];
    }
}
