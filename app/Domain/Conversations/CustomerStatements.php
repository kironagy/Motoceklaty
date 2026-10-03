<?php

namespace App\Domain\Conversations;

use App\Models\WhatsappMessage;
use App\Support\ArabicTextNormalizer;

/**
 * Provenance for "the customer said so". A value may only be stored as
 * customer_stated if it is actually present in something the customer
 * wrote (text or a voice-note transcript) in this conversation. This does
 * not interpret meaning - the AI still decides what a message means - it
 * only verifies that the value, or the quote the AI relies on, exists.
 *
 * Why: an address read off an ID card ("مدينة نصر") was saved as the
 * customer's own statement, and a national ID was typed in by the model
 * from a photo. Values seen only in images must go through the document
 * pipeline, which records their real source.
 */
class CustomerStatements
{
    private const MAX_MESSAGES = 300;

    /** @var array<int, array<int, array{id: int, text: string}>> */
    private array $cache = [];

    /**
     * The newest customer message that contains every significant token of
     * $value (numbers must match digit-for-digit), or null.
     */
    public function messageContainingValue(int $conversationId, string $value, string $dataType): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $numeric = in_array($dataType, ['national_id', 'phone', 'money', 'integer'], true);

        foreach ($this->messages($conversationId) as $message) {
            if ($numeric ? $this->containsDigits($message['text'], $value) : $this->containsTokens($message['text'], $value)) {
                return $message['id'];
            }
        }

        // A value split over several messages ("رقمي 010..." then the rest of
        // the address in the next message) - accept it when the customer's
        // recent messages together contain it.
        $recent = implode(' ', array_column(array_slice($this->messages($conversationId), 0, 8), 'text'));

        if ($recent !== '' && ($numeric ? $this->containsDigits($recent, $value) : $this->containsTokens($recent, $value))) {
            return $this->messages($conversationId)[0]['id'] ?? null;
        }

        return null;
    }

    /** The newest customer message containing $quote (normalized), or null. */
    public function messageContainingQuote(int $conversationId, string $quote): ?int
    {
        $needle = $this->compact($quote);

        if (mb_strlen($needle) < 3) {
            return null;
        }

        foreach ($this->messages($conversationId) as $message) {
            if (str_contains($this->compact($message['text']), $needle)) {
                return $message['id'];
            }
        }

        // "بشتغل ظابط حركة ف مطار القاهرة" quoted as "بشتغل ظابط حركة في
        // مطار القاهرة": one letter off and the customer was asked his work
        // four times. The quote only has to be his words, not a byte copy.
        foreach ($this->messages($conversationId) as $message) {
            if ($this->containsTokens($message['text'], $quote)) {
                return $message['id'];
            }
        }

        // "انا شغال شيف" / "ف مجال السياحه وكدا" and "حكومه" / "متامن" /
        // "عليه": customers answer in several short messages and the quote
        // joins them. 53 openings were refused in a week, and the customer
        // was asked his work again after he had said it.
        foreach ($this->consecutiveRuns($conversationId) as $run) {
            if (str_contains($this->compact($run['text']), $needle) || $this->containsTokens($run['text'], $quote)) {
                return $run['id'];
            }
        }

        return null;
    }

    /**
     * Up to four consecutive recent customer messages joined, newest runs
     * first; id = the newest message of the run.
     *
     * @return array<int, array{id: int, text: string}>
     */
    private function consecutiveRuns(int $conversationId): array
    {
        $recent = array_reverse(array_slice($this->messages($conversationId), 0, 30));
        $runs = [];

        for ($end = count($recent) - 1; $end >= 1; $end--) {
            for ($length = 2; $length <= 4 && $end - $length + 1 >= 0; $length++) {
                $slice = array_slice($recent, $end - $length + 1, $length);
                $runs[] = ['id' => $recent[$end]['id'], 'text' => implode(' ', array_column($slice, 'text'))];
            }
        }

        return $runs;
    }

    /** The newest customer message matching $pattern (run on the normalized text), or null. */
    public function messageMatching(int $conversationId, string $pattern, int $recent = self::MAX_MESSAGES): ?int
    {
        foreach (array_slice($this->messages($conversationId), 0, $recent) as $message) {
            if (preg_match($pattern, \App\Support\ArabicTextNormalizer::normalize($message['text']))) {
                return $message['id'];
            }
        }

        return null;
    }

    /** Whether any recent customer message matches $pattern (run on the normalized text). */
    public function anyMessageMatches(int $conversationId, string $pattern): bool
    {
        foreach ($this->messages($conversationId) as $message) {
            if (preg_match($pattern, \App\Support\ArabicTextNormalizer::normalize($message['text']))) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] recent customer messages, normalized, newest first */
    public function recentTexts(int $conversationId): array
    {
        return array_map(fn (array $message) => \App\Support\ArabicTextNormalizer::normalize((string) $message['text']), $this->messages($conversationId));
    }

    /** @return array<int, array{id: int, text: string}> newest first */
    private function messages(int $conversationId): array
    {
        return $this->cache[$conversationId] ??= WhatsappMessage::where('whatsapp_conversation_id', $conversationId)
            ->where('direction', 'incoming')
            ->where('sender_type', 'customer')
            ->latest('id')
            ->limit(self::MAX_MESSAGES)
            ->get(['id', 'text', 'transcript'])
            ->map(fn ($m) => ['id' => $m->id, 'text' => trim(($m->text ?? '').' '.($m->transcript ?? ''))])
            ->filter(fn ($m) => $m['text'] !== '')
            ->values()
            ->all();
    }

    private function containsDigits(string $haystack, string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $this->normalize($value));

        if ($digits === '') {
            return false;
        }

        // Phone numbers are stored normalized (01X...) but may have been
        // written with +20 / 20.
        $candidates = [$digits, ltrim($digits, '0')];

        $haystackDigits = preg_replace('/\D+/', '', $this->normalize($haystack));

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && strlen($candidate) >= min(4, strlen($digits)) && str_contains($haystackDigits, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every word of the value (2+ letters, ignoring spacing differences like
     * "عبدالمحسن" / "عبد المحسن") appears in the customer's text. A value the
     * customer never wrote - text copied from a photo - does not.
     */
    private function containsTokens(string $haystack, string $value): bool
    {
        $compactHaystack = $this->compact($haystack);
        $normalizedValue = $this->normalize($value);
        $tokens = array_filter(explode(' ', $normalizedValue), fn ($t) => mb_strlen($t) >= 2);

        // "رقم 7": a one-digit building number or floor has no 2-letter
        // token, so it was reported as never said. It counts as a whole number.
        if ($tokens === [] && preg_match('/^\d+$/', $normalizedValue)) {
            return (bool) preg_match('/(?<!\d)'.$normalizedValue.'(?!\d)/u', $this->normalize($haystack));
        }

        if ($tokens === []) {
            return false;
        }

        $found = count(array_filter($tokens, fn ($t) => str_contains($compactHaystack, $t)));

        return $found / count($tokens) >= 0.8;
    }

    private function compact(string $text): string
    {
        return str_replace(' ', '', $this->normalize($text));
    }

    private function normalize(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = ArabicTextNormalizer::normalize($text);

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }
}
