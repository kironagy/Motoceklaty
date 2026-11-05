<?php

namespace App\Agent\Runtime;

use App\Models\WhatsappMessage;

/** A catalog lookup is not evidence that the customer chose its first row. */
class MotorcycleSelection
{
    public function confirms(int $conversationId, int $machineId): bool
    {
        return $this->lastNamed($conversationId) === $machineId;
    }

    public function lastDuration(int $conversationId): ?int
    {
        $months=$this->lastDurations($conversationId);
        return count($months)===1 ? $months[0] : null;
    }

    public function lastDurations(int $conversationId): array
    {
        foreach(WhatsappMessage::where('whatsapp_conversation_id',$conversationId)->where('direction','incoming')->latest('id')->limit(60)->get(['text','transcript']) as $message){
            $text=trim((string)$message->text.' '.(string)$message->transcript);
            $normalized=\App\Support\ArabicTextNormalizer::normalize($text);
            if(!preg_match('/قسط|تقسيط|علي|على|(?:سنه|سنتين).*(?:كام|هيكون|بكام)|^(?:طب\s*)?(?:سنه(?: ونص)?|سنتين|3 سنين)[؟?\s]*$/u',$normalized)) continue;
            $months=app(RequestedDurations::class)->months($text);
            if($months!==[]) return $months;
        }
        return [];
    }

    public function lastNamed(int $conversationId): ?int
    {
        $catalog = app(CatalogMentions::class);
        $messages = WhatsappMessage::where('whatsapp_conversation_id', $conversationId)
            ->where('direction', 'incoming')->latest('id')->limit(60)->get(['text','transcript']);
        foreach ($messages as $message) {
            $text = trim((string)$message->text.' '.(string)$message->transcript);
            $ids = $catalog->fullNames($text);
            // "دايو 4 استيراد" must not become the generic "دايو 4" silently.
            if (preg_match('/استيراد|فرز|اصلي|أصلي/u', $text)) {
                $normalized=$catalog->normalize($text);
                $ids=array_values(array_filter($ids,function($id)use($normalized,$catalog){
                    $name=$catalog->normalize((string)\App\Models\Machine::find($id)?->name);
                    foreach(['استيراد','فرز','اصلي'] as $variant) if(str_contains($normalized,$variant) && !str_contains($name,$variant)) return false;
                    return true;
                }));
                if($ids===[]) return null;
            }
            if ($ids === []) continue;
            if (preg_match('/مش عايز|مش عاوز|قارن|الفرق بين/u', $text)) return null;
            return count($ids) === 1 ? (int)$ids[0] : null;
        }
        return null;
    }
}
