<?php

namespace App\Agent\Runtime;

use App\Support\ArabicTextNormalizer;

/** Intent of the complete customer burst, not its last acknowledgment. */
class ConversationIntent
{
    public function refusesApplication(string $text): bool
    {
        // "مش عايز اقدم دلوقتي" yes; "مش عارف افتح رابط التقديم" / "مش لاقي طلبي" no (he wants to apply / asks about it)
        $text = ArabicTextNormalizer::normalize($text);

        return (bool) preg_match('/(?:مش|ما|لا)\s*(?:عايز|عاوز|محتاج|ناوي)\s*(?:ا|ن)?(?:قدم|تقديم|اقسط|قسط)|(?:لا|مش)\s+(?:هقدم|هنقدم|هقسط)|(?:بلاش|خليها)\s+(?:التقديم|تقديم|بعدين)|مش\s+دلوقتي/u', $text)
            && ! preg_match('/مش\s+(?:عارف|قادر|لاقي|بيفتح|شغال|ظاهر|واضح)/u', $text);
    }

    public function asksToApply(string $text, string $previous = ''): bool
    {
        $text = ArabicTextNormalizer::normalize($text);
        if ($this->refusesApplication($text) || preg_match('/صور|صوره|شكل|مش عايز/u', $text)) return false;
        return (bool) (preg_match('/(?:عايز|عاوز|محتاج)\s*اقدم|(?:لينك|رابط)\s*تقديم|فين\s*(?:فورم|لينك|رابط)|ابعت.*(?:فورم|لينك|رابط)|^(?:نبدأ|نبدا|ابدأ|ابدا)[.!؟?\s]*$/u', $text)
            || (preg_match('/^(?:يلا|تمام|اه|ايوه|اتفضل|نكمل)[.!؟?\s]*$/u', $text) && preg_match('/نبدأ|نبدا|التقديم|رابط الاستماره/u', ArabicTextNormalizer::normalize($previous))));
    }

    public function asksForPhotos(string $text, string $previous = ''): bool
    {
        $text = ArabicTextNormalizer::normalize($text);
        if (preg_match('/(?:مش|متبعتش|ما تبعتش).{0,12}(?:صور|صوره)/u', $text)) return false;
        if (preg_match('/صور|صوره|وريني|اشوفها|اشوفهم|شكل المكنه/u', $text)) return true;
        // The model name is an answer to our photo clarification. The capability must survive into this turn.
        return (bool) (preg_match('/صور|صوره/u', ArabicTextNormalizer::normalize($previous))
            && ! preg_match('/قسط|تقسيط|مقدم|فايد|ورق|اقدم/u', $text)
            && app(CatalogMentions::class)->fullNames($text) !== []);
    }

    public function questionKind(string $reply): ?string
    {
        $reply = ArabicTextNormalizer::normalize($reply);
        if (! preg_match('/[؟?]/u', $reply)) return null;
        if (preg_match('/(?:انهي|اي|اسم).{0,25}(?:موديل|موتوسيكل|مكنه)|موديل.{0,15}(?:انهي|اي)/u', $reply)) return 'model';
        if (preg_match('/كام سنه|تحب.*(?:القسط|التقسيط).*(?:علي|على)|سنه.*سنتين/u', $reply)) return 'duration';
        if (preg_match('/بتشتغل ايه|حضرتك موظف ولا|موظف ولا معاش|نوع دخلك/u', $reply)) return 'work';
        if (preg_match('/متامن عليك|عليك تامين/u', $reply)) return 'insurance';
        return null;
    }
}
