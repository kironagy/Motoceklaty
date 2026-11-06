<?php

namespace App\Agent\Runtime;

use App\Agent\Tools\ToolContext;
use App\Agent\Tools\ToolRegistry;
use App\Models\Machine;
use App\Models\WhatsappMessage;

/** A real photo request is fulfilled independently of application/work eligibility. */
class CatalogPhotoReply
{
    public function for(string $text, ToolContext $ctx, ToolRegistry $tools): ?array
    {
        $previous = (string) WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)->where('direction', 'outgoing')->latest('id')->value('text');
        if (! app(ConversationIntent::class)->asksForPhotos($text, $previous)) return null;
        $ids = app(CatalogMentions::class)->fullNames($text);
        if ($ids === [] && ($selected = app(MotorcycleSelection::class)->lastNamed($ctx->conversationId))) $ids = [$selected];
        if ($ids === [] && preg_match('/كلهم|كلها|وريني/u', $text)) $ids = app(CatalogMentions::class)->fullNames($previous);
        if ($ids === []) return null;
        $lines = [];
        foreach (array_slice($ids, 0, 4) as $id) {
            $photo = $tools->execute('send_motorcycle_images', ['motorcycle_id' => (int) $id, 'max' => count($ids) > 1 ? 1 : 3, 'different_motorcycle_confirmed' => true, 'resend' => true], $ctx);
            $machine = Machine::find($id);
            if (($photo['ok'] ?? false) && $machine) {
                $lines[$id] = trim($machine->name);
            }
        }
        if ($lines === []) return null; // Preserve the tool's precise failure for normal recovery, never assert global unavailability.
        $result = $ctx->outbound->toArray();
        foreach ($result['media'] as &$item) $item['caption'] = $lines[$item['motorcycle_id'] ?? 0] ?? '';
        unset($item);
        $result['messages'] = ['دي صور '.implode(' و', $lines).' من المعرض.'];
        $result['focus_motorcycle_ids'] = array_map('intval', array_keys($lines));
        return $result;
    }
}
