<?php

namespace App\Domain\Documents\DocumentRules;

use App\Domain\Applications\NameTokens;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\CustomerAttribute;

/**
 * DEC-04: name-match threshold is still OPEN (config('agent.documents.name_match_threshold'),
 * no default). Until the owner sets a fuzzy tolerance, this requires an
 * exact normalized match - never invents a tolerance of its own.
 *
 * params: {extracted_field, stored_field, issue_code} - issue_code lets one
 * document_type reuse this for both name and ID matching (NAME_MISMATCH /
 * ID_MISMATCH) purely from data, never a rule-type-specific branch here.
 */
class MatchesApplicationFieldEvaluator implements DocumentRuleEvaluator
{
    public function evaluate(array $params, array $extractedFields, Application $application): ?array
    {
        $extractedValue = $extractedFields[$params['extracted_field']] ?? null;

        if ($extractedValue === null) {
            return null; // Nothing to compare - MISSING_DATA already covers a required-but-absent field.
        }

        $storedValue = $this->storedValue($application, $params['stored_field']);

        if ($storedValue === null) {
            return null; // Nothing on file yet to compare against.
        }

        $threshold = config('agent.documents.name_match_threshold');
        $normalizedExtracted = $this->normalize((string) $extractedValue);
        $normalizedStored = $this->normalize((string) $storedValue);

        $matches = match (true) {
            // A shop sign reads "مطعم الأمل" where the tax card says "مطعم
            // الامل للمأكولات": the same business, one name inside the other.
            ($params['match'] ?? null) === 'contains' => $this->containsEither($normalizedExtracted, $normalizedStored),
            // A salary slip prints "محمد احمد علي" for the ID's "محمد احمد
            // علي حسن": the same person, fewer names - not a mismatch.
            ($params['match'] ?? null) === 'name_tokens' => $this->sameOrShorterName((string) $extractedValue, (string) $storedValue),
            $threshold === null => $normalizedExtracted === $normalizedStored,
            default => $this->similarity($normalizedExtracted, $normalizedStored) >= (float) $threshold,
        };

        if ($matches) {
            return null;
        }

        return ['code' => $params['issue_code'] ?? 'NAME_MISMATCH', 'params' => []];
    }

    private function storedValue(Application $application, string $key): ?string
    {
        $value = ApplicationData::where('application_id', $application->id)
            ->where('party', 'applicant')
            ->where('field_key', $key)
            ->value('value');

        return $value ?? CustomerAttribute::where('customer_id', $application->customer_id)
            ->where('field_key', $key)
            ->value('value');
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($value)));
    }

    private function containsEither(string $a, string $b): bool
    {
        $a = \App\Support\ArabicTextNormalizer::normalize($a);
        $b = \App\Support\ArabicTextNormalizer::normalize($b);

        return $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));
    }

    private function sameOrShorterName(string $a, string $b): bool
    {
        $tokensA = NameTokens::of($a);

        return $tokensA !== [] && ($tokensA === NameTokens::of($b)
            || NameTokens::isStrictSubset($a, $b)
            || NameTokens::isStrictSubset($b, $a));
    }

    /** 0.0-1.0, not similar_text()'s native 0-100 percent - the threshold is a fraction. */
    private function similarity(string $a, string $b): float
    {
        similar_text($a, $b, $percent);

        return $percent / 100;
    }
}
