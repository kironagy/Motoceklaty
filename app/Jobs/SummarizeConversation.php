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

        try {
            $response = $ai->chat(new AiRequest(
                system: 'لخّص المحادثة دي بشكل محايد ومختصر بالعربي. اذكر الحقائق المهمة بس (زي نوع الموتوسيكل '
                    .'اللي بيتكلموا عنه أو حالة الطلب)، من غير أي بيانات حساسة زي أرقام قومية أو بيانات مستندات.',
                contents: [
                    ['role' => 'user', 'parts' => [[
                        'type' => 'text',
                        'text' => 'الملخص السابق:'."\n".($conversation->summary ?: '(لا يوجد)')."\n\n"
                            .'الرسائل الجديدة:'."\n".$transcript,
                    ]]],
                ],
                toolMode: 'none',
                temperature: 0.2,
            ));
        } catch (AiProviderException) {
            return;
        }

        $newSummary = trim(implode('', $response->textParts));

        if ($newSummary === '') {
            return;
        }

        $conversation->update([
            'summary' => $newSummary,
            'summary_until_message_id' => $messages->last()->id,
            'summary_updated_at' => now(),
        ]);
    }
}
