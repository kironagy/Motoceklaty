<?php

namespace App\Domain\Applications;

use App\Models\EligibilityRule;
use App\Support\ArabicTextNormalizer;

/**
 * Work the finance companies do not accept (owner: government jobs and
 * lawyers). A police sergeant got "مهنة من المهن اللي جهات التمويل بتتحفظ
 * عليها... لو حابب نجرب" - the owner wants him told plainly it will be
 * refused. The words and the sentence are an eligibility rule the owner
 * edits (dashboard or teach mode), never the model's own judgement.
 */
class OccupationPolicy
{
    public const RULE_TYPE = 'excluded_occupation';

    /** The sentence to tell him, when his own words name refused work. */
    public function rejection(?string $workText): ?string
    {
        $text = ArabicTextNormalizer::normalize((string) $workText);

        if ($text === '') {
            return null;
        }

        foreach (EligibilityRule::where('is_active', true)->where('rule_type', self::RULE_TYPE)->get() as $rule) {
            if (self::matches($text, self::words((array) $rule->params))) {
                return (string) (($rule->params['message'] ?? null) ?: 'للأسف جهات التمويل مش بتقبل الشغلانة دي، فالطلب هيترفض.');
            }
        }

        return null;
    }

    /**
     * A word at the start of a word of $text, allowing و/ف/ب/ل/ك and ال in
     * front ("والمحاماه") - not after "مش"/"غير": "شركه خاصه مش حكوميه".
     */
    public static function matches(string $normalizedText, array $words): bool
    {
        foreach ($words as $word) {
            if ($word !== '' && preg_match('/(?<!مش )(?<!غير )(?<!مو )(?<![\p{Arabic}])(?:[وفبلك])?(?:ال|لل)?'.preg_quote($word, '/').'/u', $normalizedText)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] normalized words, from "words" written with commas */
    public static function words(array $params): array
    {
        return array_values(array_filter(array_map(
            fn ($w) => ArabicTextNormalizer::normalize(trim($w)),
            preg_split('/[,،\n]+/u', (string) ($params['words'] ?? ''))
        )));
    }
}
