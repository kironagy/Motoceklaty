<?php

namespace App\Agent\Runtime;

use App\Models\WhatsappMessage;

/** A catalog lookup is not evidence that the customer chose its first row. */
class MotorcycleSelection
{
    public function confirms(int $conversationId, int $machineId): bool
    {
        $catalog = app(CatalogMentions::class);
        $messages = WhatsappMessage::where('whatsapp_conversation_id', $conversationId)
            ->where('direction', 'incoming')->latest('id')->limit(60)->get(['text','transcript']);
        foreach ($messages as $message) {
            $text = trim((string)$message->text.' '.(string)$message->transcript);
            $ids = $catalog->fullNames($text);
            if ($ids === []) continue;
            if (preg_match('/مش عايز|مش عاوز|قارن|الفرق بين/u', $text)) return false;
            return count($ids) === 1 && (int)$ids[0] === $machineId;
        }
        return false;
    }
}
