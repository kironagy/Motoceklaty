<?php

namespace App\Domain\Documents;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\WhatsappMessage;
use App\Support\ArabicTextNormalizer;

/**
 * Owner 2026-10-07: the tax card said "أكلات المعلم", he had said his café is "جبل الحلال", and the
 * request went to staff with nobody asking. A business name on his papers that he never wrote himself
 * is put to him once ("ده نفس نشاطك؟") before the request is sent; his answer goes on the request.
 * No name lists: the paper's name either is in his own messages, or he is asked.
 */
class BusinessNameCheck
{
    /** @return array{name: string, document_at: string}|null the paper's business name he never wrote, or null */
    public function unstated(Application $application): ?array
    {
        $document = ApplicationDocument::where('application_id', $application->id)->where('status', 'accepted')
            ->whereIn('detected_type_key', ['tax_card', 'commercial_register'])->latest('id')->first();
        $name = trim((string) ($document?->extracted['business_name'] ?? ''));

        if ($name === '' || ! $application->origin_conversation_id) {
            return null;
        }

        $his = $this->clean(WhatsappMessage::where('whatsapp_conversation_id', $application->origin_conversation_id)
            ->where('direction', 'incoming')->pluck('text')->implode(' '));

        return str_contains($his, $this->clean($name)) ? null : ['name' => $name, 'document_at' => (string) $document->created_at];
    }

    /** Asked about it (a reply of ours naming it) and he answered after: his answer, else null. */
    public function answer(Application $application): ?string
    {
        $unstated = $this->unstated($application);

        if ($unstated === null) {
            return null;
        }

        $asked = WhatsappMessage::where('whatsapp_conversation_id', $application->origin_conversation_id)
            ->where('direction', 'outgoing')->where('created_at', '>=', $unstated['document_at'])
            ->get(['id', 'text'])->first(fn ($m) => str_contains($this->clean((string) $m->text), $this->clean($unstated['name'])));

        return $asked ? WhatsappMessage::where('whatsapp_conversation_id', $application->origin_conversation_id)
            ->where('direction', 'incoming')->where('id', '>', $asked->id)->orderBy('id')->value('text') : null;
    }

    /** Still to be put to him before sending. */
    public function pending(Application $application): ?string
    {
        $unstated = $this->unstated($application);

        return $unstated !== null && $this->answer($application) === null ? $unstated['name'] : null;
    }

    private function clean(string $text): string
    {
        $text = ArabicTextNormalizer::normalize($text);

        return trim(preg_replace('/\s+/u', ' ', preg_replace('/(?<!\p{L})ال(?=\p{L}{2,})|[^\p{L}\p{N}\s]/u', '', $text)));
    }
}
