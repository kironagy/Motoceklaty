<?php

namespace App\Domain\Applications;

use App\Agent\Tools\StartApplicationTool;
use App\Models\EligibilityRule;

/**
 * Owner 2026-10-02: "انا محاسب" was quoted as an insured employee with no
 * question, "شغال في صيدلية" opened as an insured employee, and a teacher
 * mother went on as an employee while "حكومي ولا خاص؟" was never answered.
 * Owner 2026-10-03: the word lists behind those checks could not read
 * "ترزي ملابس" or any job nobody had written down - the work is now read by
 * the AI (WorkClassifier) from the conversation, and this class only
 * enforces the reading: a type his words do not support comes back with the
 * one question the owner asks - "حكومي ولا خاص؟", "متأمن عليك ولا لأ؟" or
 * "إنت صاحب المكان ولا شغال فيه؟" - or with the type his words do support.
 */
class WorkClassification
{
    private const TYPE_LABELS = [
        'employee' => 'an insured employee',
        'self_employed' => 'self_employed (works, not an insured employee, owns no business)',
        'business_owner' => 'a business owner',
        'pension' => 'on a pension',
    ];

    /**
     * The AI's reading of the applicant's work (cached per message), or
     * null when it could not be read.
     *
     * @return array<string, mixed>|null
     */
    public function reading(int $conversationId, ?string $quote = null): ?array
    {
        return app(WorkClassifier::class)->classify($conversationId, $quote);
    }

    /**
     * Why the given customer type cannot be used yet, with the hint for the
     * model - or null when his words support it.
     *
     * @return array{code: string, hint: string}|null
     */
    public function problem(int $conversationId, string $typeKey, ?string $quote = null): ?array
    {
        if (! isset(self::TYPE_LABELS[$typeKey])) {
            return null;
        }

        $r = $this->reading($conversationId, $quote);

        if ($r === null) {
            return null;
        }

        $words = $r['evidence'] !== '' ? $r['evidence'] : $r['occupation'];

        if (($r['applicant_nationality'] ?? null) === 'foreign') {
            return ['code' => 'FOREIGNER_NO_INSTALLMENTS', 'hint' => self::FOREIGNER_HINT];
        }

        if (in_array($r['working_now'], ['no', 'not_yet'], true) && $r['customer_type'] !== 'pension') {
            return ['code' => 'APPLICANT_HAS_NO_WORK', 'hint' => StartApplicationTool::NO_WORK_HINT];
        }

        if ($r['refused_work'] || $r['sector'] === 'government') {
            return ['code' => 'OCCUPATION_NOT_ACCEPTED', 'hint' => StartApplicationTool::occupationHint($this->refusalSentence())];
        }

        if (! $r['work_stated'] || $r['question'] === 'ask_what_work') {
            return ['code' => 'CUSTOMER_TYPE_NOT_STATED', 'hint' => 'His work is not clear yet. Ask only "حضرتك بتشتغل إيه بالظبط؟" '
                .'(about the person who will apply) - never list types like موظف/عامل حر, and give no numbers that depend on it.'];
        }

        if ($r['could_be_government'] && $r['sector'] === 'unknown') {
            return ['code' => 'ASK_SECTOR', 'hint' => 'His work ("'.$words.'") can be government or private, and the finance companies '
                .'refuse government work. Ask only "حكومي ولا خاص؟" (about the person who will apply) and wait for the answer: no numbers, '
                .'do not open or change the application, and do not say the work is accepted or makes anything easier.'];
        }

        // A craft or app work is the same type whoever he works for; the
        // place only matters for a business he may own or a salaried job.
        $trade = in_array($r['work_type'], ['craftsman', 'delivery_app', 'delivery_app_bicycle', 'delivery_company'], true);

        if (($typeKey === 'business_owner' && $r['relation_to_workplace'] !== 'owner')
            || ($r['relation_to_workplace'] === 'unknown' && ! $trade && $r['customer_type'] === 'unknown' && $r['question'] === 'ask_owner_or_worker')) {
            return ['code' => 'OWNERSHIP_NOT_STATED', 'hint' => StartApplicationTool::NOT_OWNER_HINT];
        }

        if ($r['insured'] === 'unknown' && ($typeKey === 'employee' || ($r['relation_to_workplace'] === 'works_for_someone' && ! $trade))) {
            return ['code' => 'ASK_INSURED', 'hint' => self::ASK_INSURED_HINT];
        }

        if ($typeKey === 'employee' && $r['insured'] === 'no') {
            return ['code' => 'NOT_INSURED', 'hint' => 'He said he is not insured, and employee means an insured employee. Use self_employed '
                .'with work_type other (his own words as the quote). Do not tell him which type is easier or accepted.'];
        }

        if ($typeKey === 'self_employed' && $r['insured'] === 'yes' && $r['relation_to_workplace'] === 'works_for_someone') {
            return ['code' => 'INSURED_IS_EMPLOYEE', 'hint' => StartApplicationTool::INSURED_HINT];
        }

        // Owner 2026-10-03: "مفيش بنت بتقدم دخل حر" - a woman applies as an
        // insured employee, a business owner or on a pension, never as
        // self_employed (دخل حر).
        if (($r['applicant_gender'] ?? null) === 'female'
            && ($typeKey === 'self_employed' || $r['customer_type'] === 'self_employed')) {
            return ['code' => 'FEMALE_FREE_INCOME_NOT_ACCEPTED', 'hint' => self::FEMALE_FREE_INCOME_HINT];
        }

        if ($r['customer_type'] !== 'unknown' && $r['customer_type'] !== $typeKey) {
            $workType = $r['customer_type'] === 'self_employed' && $r['work_type'] !== 'none' ? ' with work_type '.$r['work_type'] : '';

            return ['code' => 'WORK_TYPE_MISMATCH', 'hint' => 'His own words ("'.$words.'") make him '.self::TYPE_LABELS[$r['customer_type']]
                .', not '.$typeKey.'. Call again right away with customer_type '.$r['customer_type'].$workType.' and the same quote - '
                .'do not ask him again and do not tell him his type.'];
        }

        return null;
    }

    /**
     * The customer's own words about his work: the quote the model gave
     * when it is really in his messages, otherwise the words the reading
     * found ("محاسب في قطاع خاص" was the model merging "انا محاسب" and
     * "خاص" - he was asked his work again). Null when he never said it.
     */
    public function statedQuote(int $conversationId, ?string $quote): ?string
    {
        $statements = app(\App\Domain\Conversations\CustomerStatements::class);

        if (filled($quote) && $statements->messageContainingQuote($conversationId, (string) $quote) !== null) {
            return (string) $quote;
        }

        $r = $this->reading($conversationId);

        return $r && $r['work_stated'] && $r['evidence'] !== '' && $statements->messageContainingQuote($conversationId, $r['evidence']) !== null
            ? $r['evidence']
            : null;
    }

    /** The work type his words give, for a self_employed applicant - or null. */
    public function workType(int $conversationId, ?string $quote = null): ?string
    {
        $r = $this->reading($conversationId, $quote);

        return $r && $r['customer_type'] === 'self_employed' && $r['work_type'] !== 'none' ? $r['work_type'] : null;
    }

    private function refusalSentence(): string
    {
        $params = (array) (EligibilityRule::where('is_active', true)->where('rule_type', OccupationPolicy::RULE_TYPE)->first()?->params ?? []);

        return (string) (($params['message'] ?? null) ?: 'للأسف جهات التمويل مش بتقبل الشغلانة دي، فالطلب هيترفض.');
    }

    public const FOREIGNER_HINT = 'The applicant is not Egyptian. There are NO installments for foreigners at all, whatever the residence, '
        .'job or papers (owner\'s rule). Tell him clearly and kindly in one line that installments are for Egyptians only, and that he can '
        .'buy cash at the branch (get_branch_information). No numbers, no application, no residence or document questions, no "it depends".';

    public const FEMALE_FREE_INCOME_HINT = 'The applicant is a woman whose work is free work (not an insured employee, not owning a business, not on a '
        .'pension). The finance companies do not take a woman on free income (دخل حر). Tell her kindly and clearly that installments for her need '
        .'an insured job, her own business with its papers, or a pension - so with this work the application will not go through - and that cash '
        .'at the branch is open to her. Do not open an application, do not suggest she changes her work or data, and do not quote installments.';

    private const ASK_INSURED_HINT = 'Before choosing his type ask only "متأمن عليك ولا لأ؟" (عليها / عليه for the person who will apply) and '
        .'wait for the answer. When he answers: insured = call again with employee (salary slip needed); not insured = call again right away '
        .'with customer_type self_employed and his own words about his work as the quote (work_type other) - ask nothing else about his '
        .'work or income proof (no bank statement, contract or letter). Give no numbers that depend on it, and do not say which is easier, '
        .'accepted or better.';
}
