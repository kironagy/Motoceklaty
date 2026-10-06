<?php

namespace App\Agent\Runtime;

use App\Models\Application;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;

/**
 * Owner 2026-10-05: "مساء الخير" got "مساء النور يا باشا، بتدور على موتوسيكل
 * ولا سكوتر ولا بس سلام؟" - a greeting is answered the way a salesman in the
 * showroom answers it, every time, not however the model words it that day.
 *
 * Only a message that is nothing but a greeting (no question, no photo) is
 * answered here; anything more goes to the agent as before.
 */
class GreetingReply
{
    /** greeting => its answer, checked in this order ("السلام عليكم مساء الخير" is answered as a salam) */
    private const GREETINGS = [
        'salam' => ['/^(?:ال)?سلام(?: عليكم| عليكو| عليك)?(?: ورحم[هة] الله)?(?: وبركات[هة])?$/u', 'وعليكم السلام ورحمة الله وبركاته'],
        'masa' => ['/^مسا[ءء]? ?(?:ال)?(?:خير|نور|فل|ورد|سعاده|سعادة)$/u', 'مساء النور'],
        'sabah' => ['/^صباح ?(?:ال)?(?:خير|نور|فل|ورد|سعاده|سعادة)$/u', 'صباح النور'],
        'ahlan' => ['/^(?:اهلا|أهلا|هاي|هالو|hi|hello)(?: بيك| بيكم| وسهلا)?$/u', 'أهلا بيك'],
        'how' => ['/^(?:ازيك|ازيكم|إزيك|عامل ايه|عامل إيه|اخبارك ايه|أخبارك إيه)$/u', 'الحمد لله'],
    ];

    /** The reply, or null when the message is more than a greeting. */
    public function for(WhatsappConversation $conversation, string $text): ?string
    {
        $parts = $this->parts($text);

        if ($parts === null) {
            return null;
        }

        $matched = [];

        foreach ($parts as $part) {
            $key = $this->greetingOf($part);

            if ($key === null) {
                return null;
            }

            $matched[$key] = true;
        }

        $first = array_key_first(array_intersect_key(self::GREETINGS, $matched));
        // no "يا باشا": the model copies its earlier replies, and every
        // reply opening with it is what made it sound like a bot (conversation 206)
        return self::GREETINGS[$first][1].'، نورتنا. '.$this->question($conversation);
    }

    /** "السلام عليكم ، مساء الخير 🌹" -> ['السلام عليكم', 'مساء الخير']; null when there is nothing to read. */
    private function parts(string $text): ?array
    {
        $text = mb_strtolower(trim($text));
        // emoji, punctuation and tatweel are not words
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}\x{0640}\x{064B}-\x{0652}]/u', '', $text);
        $text = preg_replace('/[إأآ]/u', 'ا', $text);

        if ($text === '' || mb_strlen($text) > 60 || preg_match('/[?؟\d]/u', $text)) {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[\n،,.!\-]+|\s+و(?=(?:مسا|صباح|ازي))/u', $text)), fn ($p) => $p !== ''));

        return $parts === [] ? null : array_map(fn ($p) => preg_replace('/\s+/u', ' ', $p), $parts);
    }

    private function greetingOf(string $part): ?string
    {
        // "السلام عليكم مساء الخير" in one line: split on the second greeting's start
        foreach (self::GREETINGS as $key => [$pattern]) {
            if (preg_match($pattern, $part)) {
                return $key;
            }
        }

        if (preg_match('/^(.+?) ((?:مسا|صباح|ازي|عامل|اخبار).*)$/u', $part, $m)) {
            $a = $this->greetingOf($m[1]);
            $b = $this->greetingOf($m[2]);

            return $a !== null && $b !== null ? $a : null;
        }

        return null;
    }

    /** A new customer is asked what he is after; one we talked to before is invited to carry on. */
    private function question(WhatsappConversation $conversation): string
    {
        $hasApplication = $conversation->customer_id
            && Application::where('customer_id', $conversation->customer_id)->whereIn('status', Application::ACTIVE_STATUSES)->exists();

        if ($hasApplication) {
            return 'نكمّل في طلبك؟';
        }

        $talkedBefore = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')->exists();

        return $talkedBefore ? 'قولّي أقدر أساعدك في إيه؟' : 'بتدور على موتوسيكل ولا سكوتر؟';
    }
}
