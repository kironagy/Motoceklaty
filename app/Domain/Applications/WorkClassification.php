<?php

namespace App\Domain\Applications;

use App\Models\EligibilityRule;

/**
 * Owner 2026-10-02: "انا محاسب" was quoted as an insured employee with no
 * question, "شغال في صيدلية" opened as an insured employee, and a teacher
 * mother went on as an employee while "حكومي ولا خاص؟" was never answered.
 * Owner 2026-10-03: the word lists behind those checks could not read
 * "ترزي ملابس" or any job nobody had written down - the work is now read by
 * the agent (record_work_profile) from the conversation, and this class only
 * enforces the reading: a type his words do not support comes back with the
 * one question the owner asks - "حكومي ولا خاص؟", "متأمن عليك ولا لأ؟" or
 * "إنت صاحب المكان ولا شغال فيه؟" - or with the type his words do support.
 */
class WorkClassification
{

    /** Codes that end the installment talk (cash stays open) - never followed by numbers. */
    public const REFUSALS = ['FOREIGNER_NO_INSTALLMENTS', 'APPLICANT_HAS_NO_WORK', 'OCCUPATION_NOT_ACCEPTED',
        'FEMALE_FREE_INCOME_NOT_ACCEPTED', 'STATED_INCOME_BELOW_MINIMUM'];

    private const TYPE_LABELS = [
        'employee' => 'an insured employee',
        'self_employed' => 'self_employed (works, not an insured employee, owns no business)',
        'business_owner' => 'a business owner',
        'pension' => 'on a pension',
    ];

    /**
     * The applicant's work as the agent recorded it (record_work_profile),
     * or null when it was never recorded. No model call.
     *
     * @return array<string, mixed>|null
     */
    public function reading(int $conversationId, ?string $quote = null): ?array
    {
        return app(WorkProfiles::class)->get($conversationId);
    }

    /**
     * Why the given customer type cannot be used yet - a code and the facts
     * behind it (his words, the type they support). What the agent does with
     * each code is in the instructions (rebuild: no prose here) - or null.
     *
     * @return array{code: string, hint: string}|null
     */
    public function problem(int $conversationId, string $typeKey, ?string $quote = null, bool $cardOnly = false): ?array
    {
        if (! isset(self::TYPE_LABELS[$typeKey])) {
            return null;
        }

        $r = $this->reading($conversationId, $quote);

        if ($r === null) {
            return ['code' => 'WORK_NOT_RECORDED', 'hint' => ''];
        }

        $words = $r['evidence'] !== '' ? $r['evidence'] : $r['occupation'];

        if (($r['applicant_nationality'] ?? null) === 'foreign') {
            return ['code' => 'FOREIGNER_NO_INSTALLMENTS', 'hint' => ''];
        }

        if (in_array($r['working_now'], ['no', 'not_yet'], true) && $r['customer_type'] !== 'pension') {
            return ['code' => 'APPLICANT_HAS_NO_WORK', 'hint' => 'his words: "'.$words.'". Say exactly: "'.OtherApplicant::line(($r['applicant'] ?? null) === 'someone_else').'"'];
        }

        // Simulation 2026-10-04: a retired postal manager was refused as
        // "government work". The refused jobs are what he does now - a
        // pension from a government job is what the pension type is for.
        if (($r['refused_work'] || $r['sector'] === 'government') && $r['customer_type'] !== 'pension') {
            return ['code' => 'OCCUPATION_NOT_ACCEPTED', 'hint' => 'refusal: "'.$this->refusalSentence().'"'];
        }

        // Owner 2026-10-04: a day's pay with no trade (tuk-tuk, café by the
        // day, scrap) is taken - on the ID only, the free-work terms.
        if ($r['daily_labour_no_trade'] && $typeKey !== 'self_employed') {
            return ['code' => 'DAILY_WORK_IS_CARD_ONLY', 'hint' => 'his words: "'.$words.'"; use: customer_type=self_employed work_type=other'];
        }

        if (! $r['work_stated'] || $r['question'] === 'ask_what_work') {
            return ['code' => 'CUSTOMER_TYPE_NOT_STATED', 'hint' => ''];
        }

        if ($r['could_be_government'] && $r['sector'] === 'unknown') {
            return ['code' => 'ASK_SECTOR', 'hint' => 'his words: "'.$words.'"'];
        }

        // QA 2026-10-04: "معاشي ٣٥٠٠" opened an application although the
        // pension minimum is 4,000. The figure he said is checked against the
        // type's rules now; the statement or slip still decides later.
        if ($r['stated_monthly_income'] > 0 && ($type = \App\Models\CustomerType::where('key', $typeKey)->first())) {
            $income = app(EligibilityService::class)->evaluate(['monthly_income' => $r['stated_monthly_income']], $type->id);

            if ($income['status'] === 'not_eligible') {
                return ['code' => 'STATED_INCOME_BELOW_MINIMUM', 'hint' => 'stated income: '.number_format($r['stated_monthly_income']).' جنيه'];
            }
        }

        // Owner 2026-10-04: the last resort that still sells - a man who works
        // but cannot bring his work's papers applies with the ID only, on the
        // free-work (عامل حر) terms. Never for a woman (owner's rule), never
        // before he said he cannot bring the papers.
        if ($cardOnly) {
            if (($r['applicant_gender'] ?? null) === 'female') {
                return ['code' => 'FEMALE_FREE_INCOME_NOT_ACCEPTED', 'hint' => ''];
            }

            if (! $r['cannot_bring_work_papers'] && $r['customer_type'] !== 'self_employed') {
                return ['code' => 'CARD_ONLY_LAST_RESORT', 'hint' => ''];
            }

            return null;
        }

        // A craft or app work is the same type whoever he works for; the
        // place only matters for a business he may own or a salaried job.
        $trade = in_array($r['work_type'], ['craftsman', 'delivery_app', 'delivery_app_bicycle', 'delivery_company'], true);

        if (($typeKey === 'business_owner' && $r['relation_to_workplace'] !== 'owner')
            || ($r['relation_to_workplace'] === 'unknown' && ! $trade && $r['customer_type'] === 'unknown' && $r['question'] === 'ask_owner_or_worker')) {
            return ['code' => 'OWNERSHIP_NOT_STATED', 'hint' => 'his words: "'.$words.'"'];
        }

        if ($r['insured'] === 'unknown' && ($typeKey === 'employee' || ($r['relation_to_workplace'] === 'works_for_someone' && ! $trade))) {
            return ['code' => 'ASK_INSURED', 'hint' => 'his words: "'.$words.'"'];
        }

        if ($typeKey === 'employee' && $r['insured'] === 'no') {
            return ['code' => 'NOT_INSURED', 'hint' => 'use: customer_type=self_employed work_type=other'];
        }

        if ($typeKey === 'self_employed' && $r['insured'] === 'yes' && $r['relation_to_workplace'] === 'works_for_someone') {
            return ['code' => 'INSURED_IS_EMPLOYEE', 'hint' => 'use: customer_type=employee'];
        }

        // Owner 2026-10-03: "مفيش بنت بتقدم دخل حر" - a woman applies as an
        // insured employee, a business owner or on a pension, never as
        // self_employed (دخل حر).
        if (($r['applicant_gender'] ?? null) === 'female'
            && ($typeKey === 'self_employed' || $r['customer_type'] === 'self_employed')) {
            return ['code' => 'FEMALE_FREE_INCOME_NOT_ACCEPTED', 'hint' => ''];
        }

        if ($r['customer_type'] !== 'unknown' && $r['customer_type'] !== $typeKey) {
            $workType = $r['customer_type'] === 'self_employed' && $r['work_type'] !== 'none' ? ' work_type='.$r['work_type'] : '';

            return ['code' => 'WORK_TYPE_MISMATCH', 'hint' => 'his words: "'.$words.'"; use: customer_type='.$r['customer_type'].$workType];
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

        // QA 2026-10-04: a tuk-tuk driver came back delivery_app and was asked
        // for a licence and app screenshots. A day's pay is the ID-only route.
        if ($r && $r['daily_labour_no_trade']) {
            return 'other';
        }

        return $r && $r['customer_type'] === 'self_employed' && $r['work_type'] !== 'none' ? $r['work_type'] : null;
    }

    private function refusalSentence(): string
    {
        $params = (array) (EligibilityRule::where('is_active', true)->where('rule_type', OccupationPolicy::RULE_TYPE)->first()?->params ?? []);

        return (string) (($params['message'] ?? null) ?: 'للأسف جهات التمويل مش بتقبل الشغلانة دي، فالطلب هيترفض.');
    }
}
