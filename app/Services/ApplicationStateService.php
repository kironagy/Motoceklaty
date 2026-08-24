<?php

namespace App\Services;

use App\Support\AddressParser;

/**
 * Deterministic field-completeness logic for the installment application
 * flow, replacing the binary complete/incomplete address guessing that
 * used to live entirely inside an LLM prompt (AiIntentClassifier::extractApplicationData's
 * line-order heuristic). Address completeness is now computed from actual
 * structured components (see AddressParser), so a partial address only
 * ever gets asked about for the specific piece that's missing.
 */
class ApplicationStateService
{
    public const REQUIRED_FIELDS = [
        'full_name',
        'national_id',
        'phone',
        'job_type',
        'income_proof',
        'work_address',
        'home_address',
        'installment_months',
    ];

    private const ADDRESS_FIELDS = ['work_address', 'home_address'];

    /**
     * Fields where a changed value is unambiguously a conflict worth
     * pausing for, not a legitimate in-place correction/addition. Scoped
     * deliberately narrow: unlike a name or an address (which customers
     * legitimately extend across messages), a phone number or national ID
     * that changes value is either a typo-fix or two different real
     * numbers - either way, silently picking one is the wrong call.
     */
    private const CONFLICT_FIELDS = ['phone', 'national_id'];

    private const MISSING_COMPONENT_LABELS = [
        'area_or_governorate' => 'المنطقة',
        'street' => 'اسم الشارع',
        'building' => 'رقم العمارة',
        'floor' => 'الدور',
        'apartment' => 'رقم الشقة',
        'landmark' => 'علامة مميزة قريبة من العنوان',
        'ownership' => 'السكن ده ملكك ولا إيجار',
    ];

    public function __construct(private readonly AddressParser $addressParser)
    {
    }

    /**
     * Re-derives address components/status for every address field that
     * has a value, merging any newly-mentioned components on top of
     * whatever was already known (so a customer can fill in an address
     * across several messages without earlier components being lost).
     * Also tracks which components were newly added *this specific turn*
     * (`{$field}_newly_received_components`) so questionForMissing() can
     * say "حضرتك بعت اسم الشارع" instead of only listing what's still
     * missing. Call this right after merging freshly-extracted values in,
     * before missingFields()/questionForMissing().
     */
    public function refreshAddressComponents(array $application): array
    {
        foreach (self::ADDRESS_FIELDS as $field) {
            $text = (string) ($application[$field] ?? '');

            if (trim($text) === '') {
                continue;
            }

            $componentsKey = "{$field}_components";
            $known = $application[$componentsKey] ?? [];
            $extracted = $this->addressParser->parse($text);

            // Newly extracted, non-empty components win; previously known
            // components are kept when this turn didn't mention them.
            $merged = $known;
            $newlyReceived = [];

            foreach ($extracted as $component => $value) {
                if (! filled($value)) {
                    continue;
                }

                if (! isset($known[$component]) || $known[$component] !== $value) {
                    // city/area/governorate all map to the single
                    // "المنطقة" label used everywhere else (status(),
                    // MISSING_COMPONENT_LABELS) - report under that same
                    // grouped name instead of leaking the raw parser key.
                    $reportedComponent = in_array($component, ['city', 'area', 'governorate'], true)
                        ? 'area_or_governorate'
                        : $component;

                    if (! in_array($reportedComponent, $newlyReceived, true)) {
                        $newlyReceived[] = $reportedComponent;
                    }
                }

                $merged[$component] = $value;
            }

            /*
             * "ملك ولا إيجار" مطلوب لعنوان السكن بس - مش ليه علاقة
             * بمكان الشغل.
             */
            $status = $this->addressParser->status($merged, $field === 'home_address');

            $application[$componentsKey] = $merged;
            $application["{$field}_status"] = $status['status'] === 'complete' ? 'complete' : 'incomplete';
            $application["{$field}_missing_components"] = $status['missing'];
            $application["{$field}_newly_received_components"] = $newlyReceived;
        }

        return $application;
    }

    /**
     * Reported bug: a customer already mid-way through one address field
     * (e.g. work_address = "٦ أكتوبر", still incomplete) sends a further
     * address-shaped line with no explicit field marker ("عمارة 4 الدور
     * 2"). The extraction LLM only sees each field's raw text - it has no
     * concept of "this field already has SOME value but is still
     * incomplete", so it tends to route new address text to whichever
     * field is still literally null, which is wrong when that null field
     * was never the one being filled in.
     *
     * This is a deterministic safety net, not a prompt-engineering fix:
     * when exactly one address field is known-but-incomplete and the
     * extraction left that field's own text untouched while putting new
     * text into the other (previously empty) address field, redirect it
     * back to the field actually in progress.
     */
    public function reconcileAddressAssignment(array $application, array $extracted): array
    {
        $incomplete = array_values(array_filter(self::ADDRESS_FIELDS, function ($field) use ($application) {
            return filled($application[$field] ?? null) && ($application["{$field}_status"] ?? null) === 'incomplete';
        }));

        if (count($incomplete) !== 1) {
            return $extracted;
        }

        $target = $incomplete[0];
        $other = $target === 'work_address' ? 'home_address' : 'work_address';

        $targetTouched = array_key_exists($target, $extracted)
            && filled($extracted[$target])
            && $extracted[$target] !== $application[$target];

        $otherGivenNewText = array_key_exists($other, $extracted)
            && filled($extracted[$other])
            && empty($application[$other] ?? null);

        if (! $targetTouched && $otherGivenNewText) {
            $extracted[$target] = $extracted[$other];
            $extracted[$other] = null;
            unset($extracted["{$other}_status"]);
        }

        return $extracted;
    }

    /**
     * Same required-field list and freelance exemption as before, but
     * address completeness now comes from refreshAddressComponents()'s
     * deterministic status instead of an LLM-assigned string. $isFreelance
     * is computed by the caller (ApplicationHandler::categorizeIncome) so
     * the "who counts as freelance" keyword list keeps a single owner
     * instead of drifting between two copies.
     */
    public function missingFields(array $application, bool $isFreelance): array
    {
        return array_values(array_filter(self::REQUIRED_FIELDS, function ($key) use ($application, $isFreelance) {
            if ($key === 'income_proof' && $isFreelance) {
                return false;
            }

            if (empty($application[$key])) {
                return true;
            }

            if (in_array($key, self::ADDRESS_FIELDS, true) && ($application["{$key}_status"] ?? null) === 'incomplete') {
                return true;
            }

            return false;
        }));
    }

    private const FIELD_LABELS = [
        'full_name' => 'الاسم بالكامل',
        'national_id' => 'الرقم القومي',
        'phone' => 'رقم الموبايل',
        'job_type' => 'طبيعة شغلك',
        'income_proof' => 'إثبات الدخل',
        'work_address' => 'عنوان الشغل',
        'home_address' => 'عنوان السكن',
        'installment_months' => 'مدة التقسيط',
    ];

    private const FIELD_LABELS_DETAILED = [
        'full_name' => 'الاسم بالكامل',
        'national_id' => 'الرقم القومي',
        'phone' => 'رقم الموبايل',
        'job_type' => 'طبيعة شغلك',
        'income_proof' => 'إثبات دخل (مفردات مرتب أو غيرها لو متاح)',
        'work_address' => 'عنوان الشغل بالتفصيل',
        'home_address' => 'عنوان السكن بالتفصيل',
        'installment_months' => 'مدة التقسيط اللي تحبها',
    ];

    /**
     * Opening line used only when nothing new was received this turn
     * (no acknowledgment to prepend) AND this isn't the very first ask
     * (streak > 0) - e.g. the customer sent something irrelevant, or a
     * message that failed to extract any field. The very first ask
     * (streak === 0, nothing received yet) always uses index 0's plain
     * opener - there is nothing to vary yet, varying it would be noise.
     */
    private const NO_PROGRESS_OPENERS = [
        'تمام يا فندم، ناقصني البيانات دي عشان أكمل طلب التقديم:',
        'لسه محتاج منك البيانات دي يا فندم عشان أقدر أكمل طلب التقديم:',
        'عشان نكمل الطلب يا فندم، لسه ناقصني البيانات دي:',
    ];

    /**
     * Builds the missing-info question. Three things make this progress-
     * aware instead of a static template repeated every turn:
     *
     * 1. $newlyFilled - fields that were missing last turn and got
     *    completed this turn - produces an opening acknowledgment
     *    ("تمام يا فندم، استلمت..."). Without this the bot silently
     *    absorbed the customer's data and re-asked as if nothing had
     *    happened, which reads as not having listened at all.
     * 2. When exactly one address field is the only thing missing AND
     *    it's partial (some components already known), asks only for the
     *    specific missing component(s) instead of "عنوان السكن بالتفصيل"
     *    again - this is what makes "محتاج رقم العمارة بس" possible
     *    instead of re-asking for the whole address.
     * 3. $noProgressStreak - how many turns in a row produced nothing new
     *    - rotates the opening line so a stuck conversation doesn't read
     *    the exact same sentence over and over (see Section 5/6 of
     *    AI_MEMORY_CONVERSATION_IMPROVEMENT_PLAN.md: vary wording only
     *    when repetition isn't logically necessary, never touch the
     *    actual missing-field list itself).
     */
    public function questionForMissing(
        array $missing,
        array $application,
        array $newlyFilled = [],
        int $noProgressStreak = 0,
        array $labelOverrides = []
    ): string {
        $acknowledgment = '';

        if (! empty($newlyFilled)) {
            $newlyFilledLabels = array_map(
                fn ($key) => self::FIELD_LABELS[$key] ?? $key,
                $newlyFilled
            );

            $acknowledgment = 'تمام يا فندم، استلمت ' . implode(' و', $newlyFilledLabels) . '.';

            if (empty($missing)) {
                return $acknowledgment;
            }
        }

        if (count($missing) === 1 && in_array($missing[0], self::ADDRESS_FIELDS, true)) {
            $field = $missing[0];
            $addressLabel = $field === 'home_address' ? 'عنوان السكن' : 'عنوان الشغل';
            $missingComponents = $application["{$field}_missing_components"] ?? [];

            if (! empty($application[$field]) && ! empty($missingComponents)) {
                $componentLabels = array_map(
                    fn ($component) => self::MISSING_COMPONENT_LABELS[$component] ?? $component,
                    $missingComponents
                );

                /*
                 * استلمت X، بس محتاج Y - مش بس "محتاج Y" - عشان العميل
                 * يعرف إن اللي بعته اتسجل فعلاً، مش بس اتجاهل وطلعله نفس
                 * سؤال العنوان تاني. صيغة جملة طبيعية بدل قوسين تقنيين.
                 */
                $newlyReceivedComponents = $application["{$field}_newly_received_components"] ?? [];
                $missingText = implode(' و', $componentLabels);

                if (! empty($newlyReceivedComponents)) {
                    $newlyReceivedLabels = array_map(
                        fn ($component) => self::MISSING_COMPONENT_LABELS[$component] ?? $component,
                        $newlyReceivedComponents
                    );

                    $receivedText = implode(' و', $newlyReceivedLabels);
                    $line = "استلمت منك {$receivedText} في {$addressLabel}، بس لسه محتاج {$missingText}.";
                } else {
                    $line = "لسه محتاج {$missingText} في {$addressLabel}.";
                }

                return $acknowledgment !== ''
                    ? "{$acknowledgment} {$line}"
                    : "تمام يا فندم، {$line}";
            }
        }

        /*
         * لو أكتر من حقل ناقص وواحد منهم عنوان اتبعت بيانات جزئية له
         * (زي "٦ أكتوبر" لما عنوان الشغل والسكن كانوا فاضيين، فاتحطت في
         * الأول بس)، السطر بتاعه في القايمة لازم يوضح المكوّن الناقص
         * بالظبط بدل ما يرجع للتسمية العامة "بالتفصيل" اللي كأنها متجاهلة
         * إن العميل بعت حاجة أصلاً.
         */
        $items = array_map(fn ($key) => $this->missingFieldLine($key, $application, $labelOverrides), $missing);

        if (count($items) === 1) {
            return $acknowledgment !== ''
                ? "{$acknowledgment} ولسه ناقصني {$items[0]} عشان أكمل طلب التقديم."
                : 'تمام يا فندم، ناقصني ' . $items[0] . ' عشان أكمل طلب التقديم.';
        }

        $list = implode("\n", array_map(fn ($item) => '- ' . $item, $items));

        if ($acknowledgment !== '') {
            return "{$acknowledgment} ولسه ناقصني:\n{$list}";
        }

        $opener = $noProgressStreak > 0
            ? self::NO_PROGRESS_OPENERS[$noProgressStreak % count(self::NO_PROGRESS_OPENERS)]
            : self::NO_PROGRESS_OPENERS[0];

        return "{$opener}\n{$list}";
    }

    /**
     * One missing-field's display line. An address field that already
     * has some components known (e.g. the customer answered a single,
     * ambiguous line like "٦ أكتوبر" that landed in only one of the two
     * address fields) names the specific missing component(s) instead of
     * the generic "بالتفصيل" label, so the customer can see the message
     * actually understood what they sent.
     */
    private function missingFieldLine(string $key, array $application, array $labelOverrides = []): string
    {
        if (isset($labelOverrides[$key])) {
            return $labelOverrides[$key];
        }


        if (in_array($key, self::ADDRESS_FIELDS, true)) {
            $missingComponents = $application["{$key}_missing_components"] ?? [];

            if (! empty($application[$key]) && ! empty($missingComponents)) {
                $addressLabel = $key === 'home_address' ? 'عنوان السكن' : 'عنوان الشغل';
                $componentLabels = array_map(
                    fn ($component) => self::MISSING_COMPONENT_LABELS[$component] ?? $component,
                    $missingComponents
                );

                $newlyReceived = $application["{$key}_newly_received_components"] ?? [];

                if (! empty($newlyReceived)) {
                    $receivedLabels = array_map(
                        fn ($component) => self::MISSING_COMPONENT_LABELS[$component] ?? $component,
                        $newlyReceived
                    );

                    return "{$addressLabel}: استلمت منك {$this->componentList($receivedLabels)}، بس لسه محتاج {$this->componentList($componentLabels)}";
                }

                return "{$addressLabel}: لسه محتاج {$this->componentList($componentLabels)}";
            }
        }

        return self::FIELD_LABELS_DETAILED[$key] ?? $key;
    }

    private function componentList(array $labels): string
    {
        return implode(' و', $labels);
    }

    /**
     * Compares already-known, previously-COMPLETE values against freshly
     * extracted ones for the conflict-sensitive fields. Only a genuinely
     * different, non-empty new value on a field that already had a
     * genuinely different, non-empty known value counts - a field being
     * filled in for the first time is not a conflict.
     *
     * Returns [field => ['previous' => ..., 'new' => ...]].
     */
    public function detectConflicts(array $known, array $extracted): array
    {
        $conflicts = [];

        foreach (self::CONFLICT_FIELDS as $field) {
            $previous = $this->normalizeForComparison($known[$field] ?? null);
            $new = $this->normalizeForComparison($extracted[$field] ?? null);

            if ($previous === null || $new === null) {
                continue;
            }

            if ($previous !== $new) {
                $conflicts[$field] = [
                    'previous' => $known[$field],
                    'new' => $extracted[$field],
                ];
            }
        }

        return $conflicts;
    }

    private function normalizeForComparison(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : preg_replace('/\s+/u', '', $value);
    }

    private function containsAnyPhrase(string $haystack, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (mb_stripos($haystack, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The single disambiguation question for all pending conflicts at
     * once (there's rarely more than one field in conflict on the same
     * turn, but the phrasing scales cleanly if there is).
     */
    public function conflictQuestion(array $conflicts): string
    {
        $labels = [
            'phone' => 'رقم الموبايل',
            'national_id' => 'الرقم القومي',
        ];

        $lines = [];

        foreach ($conflicts as $field => $values) {
            $label = $labels[$field] ?? $field;
            $lines[] = "{$label}: حضرتك بعت \"{$values['previous']}\" قبل كده وبعدين بعت \"{$values['new']}\" - أعتمد أنهي واحد؟";
        }

        return implode("\n", $lines);
    }

    /**
     * Attempts to resolve a pending conflict from the customer's reply.
     * Recognises explicit "القديم"/"الجديد" answers, or the customer
     * simply re-sending one of the two values verbatim. Returns
     * [field => resolved_value] for whatever it could resolve - fields
     * not resolved this turn stay pending (caller keeps asking).
     */
    private const CONFLICT_FIRST_WORDS = ['قديم', 'الاول', 'الأول', 'اللي فات', 'اللى فات'];
    private const CONFLICT_SECOND_WORDS = [
        'جديد', 'التاني', 'التانى', 'الثاني', 'الثانى', 'الاخير', 'الأخير',
        'آخر رقم', 'اخر رقم', 'آخر واحد', 'اخر واحد',
    ];

    public function resolveConflicts(array $pendingConflicts, string $message): array
    {
        $normalizedMessage = $this->normalizeForComparison($message) ?? '';
        $resolved = [];

        /*
         * conflictQuestion() بيقول "بعت X قبل كده وبعدين بعت Y" - يعني X
         * هو "الأول/القديم" وY هو "التاني/الأخير/الجديد". العميل غالبًا
         * بيرد بترتيب مش بكلمة "قديم"/"جديد" الحرفية ("التاني"، "الأخير")
         * - المفروض تتفهم زي "جديد" بالظبط، مش تتجاهل وتتسأل نفس السؤال
         * تاني.
         */
        $wantsOld = $this->containsAnyPhrase($message, self::CONFLICT_FIRST_WORDS);
        $wantsNew = $this->containsAnyPhrase($message, self::CONFLICT_SECOND_WORDS);

        foreach ($pendingConflicts as $field => $values) {
            if ($wantsOld && ! $wantsNew) {
                $resolved[$field] = $values['previous'];

                continue;
            }

            if ($wantsNew && ! $wantsOld) {
                $resolved[$field] = $values['new'];

                continue;
            }

            $previousNormalized = $this->normalizeForComparison((string) $values['previous']);
            $newNormalized = $this->normalizeForComparison((string) $values['new']);

            if ($normalizedMessage !== '' && str_contains($normalizedMessage, $previousNormalized ?? "\0")) {
                $resolved[$field] = $values['previous'];
            } elseif ($normalizedMessage !== '' && str_contains($normalizedMessage, $newNormalized ?? "\0")) {
                $resolved[$field] = $values['new'];
            }
        }

        return $resolved;
    }
}
