<?php

namespace App\Domain\Conversations;

use App\Models\WhatsappMessage;
use App\Support\ArabicTextNormalizer;

/**
 * Owner 2026-10-01: after "نورتنا، في رعاية الله" the customer wrote
 * "تسلم"، "حبيبي"، "ماشى" eight times and got eight more goodbyes. Once we
 * have said goodbye, a bare thanks/okay from him needs no answer.
 */
class ConversationClosing
{
    /** Our last message closed the conversation: a goodbye with no question left open. */
    public static function isSignOff(?string $text): bool
    {
        $text = (string) $text;

        if ($text === '' || preg_match('/[؟?]/u', $text)) {
            return false;
        }

        return (bool) preg_match('/نورتنا|نورتني|في رعاي[ةه] الله|ف رعاي[ةه] الله|مع السلام[ةه]|في أي وقت|في اي وقت|أنا موجود|انا موجود|تحت أمرك|تحت امرك/u', $text);
    }

    /** Nothing but thanks / okay / endearments / emoji - no question, no data. */
    public static function isBareAcknowledgement(?string $text): bool
    {
        $text = ArabicTextNormalizer::normalize((string) $text);
        $text = trim(preg_replace('/[\p{P}\p{S}\x{200d}\x{fe0f}]+/u', ' ', $text));

        if ($text === '' || mb_strlen($text) > 40) {
            return $text === '';
        }

        // normalize() drops a leading ال: "الله" arrives as "له", "العفو" as "عفو".
        $word = 'تسلم|تسلملي|تسلمي|حبيبي|حبيبى|ماشي|تمام|حاضر|شكرا|متشكر|متشكرين|الله يسلمك|ربنا يخليك|يخليك|يا|باشا|غالي|قلبي|حبي|اخويا|كبير|ريس|معلم|فندم|حضرتك|اوك|اوكي|ok|okay|تمم|ميرسي|thanks|thx|له|يسلمك|وانت|وانتي|كمان|جدا|اوي|ربنا|يكرمك|عفو|نورت|منور';

        return (bool) preg_match('/^(?:(?:'.$word.')\s*)+$/u', $text);
    }

    /**
     * The turn's incoming messages are all bare acknowledgements and the
     * last thing we sent before them was a goodbye.
     */
    public static function onlyThanksAfterGoodbye(int $conversationId, int $turnId): bool
    {
        $incoming = WhatsappMessage::where('whatsapp_conversation_id', $conversationId)
            ->where('turn_id', $turnId)->where('direction', 'incoming')->orderBy('id')->get();

        if ($incoming->isEmpty()) {
            return false;
        }

        foreach ($incoming as $message) {
            if ($message->type !== 'text' || ! self::isBareAcknowledgement($message->text)) {
                return false;
            }
        }

        $lastOutgoing = WhatsappMessage::where('whatsapp_conversation_id', $conversationId)
            ->where('direction', 'outgoing')->where('id', '<', $incoming->first()->id)
            ->latest('id')->first();

        // A colleague writing from the phone holds the conversation; his
        // "تمام" is not ours to judge.
        return $lastOutgoing !== null
            && in_array($lastOutgoing->sender_type, ['bot', 'system'], true)
            && self::isSignOff($lastOutgoing->text);
    }
}
