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
    /** "اعتبرني عامل حر"، "اقولك اني موظف وخلاص"، "اكتب اني موظف" - a label asked for, not a job described. */
    private const LABEL_REQUEST = '/عتبرن[يى]|عتبره|اكتبن[يى]|اكتب\s*ان[يى]|سجلن[يى]\s*(?:ك|على\s*ان[يى])|(?:اقول|اقولك|نقول|قول)\s*(?:ل[كه]\s*)?ان[يى]\s*(?:موظف|عامل|حر|صاحب|معاش)|وخلاص\s*عشان|عشان\s*(?:ما?\s*)?(?:ادفعش|مدفعش|اتقبل|يتقبل)/u';

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
    public function problem(int $conversationId, string $typeKey, ?string $quote = null, bool $cardOnly = false): ?array
    {
        if (! isset(self::TYPE_LABELS[$typeKey])) {
            return null;
        }

        $r = $this->reading($conversationId, $quote);

        // QA 2026-10-04: the reading timed out and an insured employee who
        // said "اعتبرني عامل حر" got an application as self_employed - every
        // check below was skipped. No reading, no type.
        if ($r === null) {
            return ['code' => 'WORK_CHECK_UNAVAILABLE', 'hint' => 'His work could not be checked this moment. Make the same call '
                .'once more now. If it fails again, do not open or change the application and give no number that depends on his '
                .'work: tell him in one short line you need a minute and ask him to send "تمام" when ready.'];
        }

        $words = $r['evidence'] !== '' ? $r['evidence'] : $r['occupation'];

        // QA 2026-10-04: "اعتبرني عامل حر وخلاص" (an insured factory worker
        // with no salary slip) and "اقولك اني موظف وخلاص عشان مدفعش الفرق"
        // both changed the application's type. Asking to be labeled is not
        // a statement of his work.
        $quotedMessage = filled($quote)
            ? app(\App\Domain\Conversations\CustomerStatements::class)->messageContainingQuote($conversationId, (string) $quote)
            : null;
        // no quote (an offer for a type): his latest message is what asks for it
        $saidWith = (string) ($quotedMessage
            ? \App\Models\WhatsappMessage::whereKey($quotedMessage)->value('text')
            : (blank($quote) ? \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $conversationId)
                ->where('direction', 'incoming')->latest('id')->value('text') : ''));

        if (! $cardOnly && preg_match(self::LABEL_REQUEST, $quote.' '.$saidWith)) {
            // a free worker already applies with the ID only - no other route to offer him
            if ($r['customer_type'] === 'self_employed' && in_array($r['work_type'], ['other', 'craftsman', 'none'], true)) {
                return ['code' => 'TYPE_REQUEST_NOT_A_FACT', 'hint' => 'He asked to be counted as an employee to pay less, but his real work is "'
                    .$words.'" (free work, applies with the ID only) and the numbers he got are the ones for it. Never call him an employee. Say kindly '
                    .'in one line that the application goes by his real work (no "ممنوع", no '
                    .'"النوع"), and offer a cheaper motorcycle or a longer duration if the amount at pickup is the problem.'];
            }

            return ['code' => 'TYPE_REQUEST_NOT_A_FACT', 'hint' => 'He asked to be counted as another kind of worker, which is not '
                .'what his work is. Do not change the type on his word. If the real reason is that he cannot bring the papers his work '
                .'needs, the one honest way left is the card-only route (route=card_only): applying with the ID only, under its own '
                .'terms (a bigger amount at pickup above the cap and its own installment) - offer it plainly with its numbers '
                .'(get_installment_offer customer_type=self_employed). Otherwise tell him kindly the application goes by his real work and its papers.'];
        }

        if (($r['applicant_nationality'] ?? null) === 'foreign') {
            return ['code' => 'FOREIGNER_NO_INSTALLMENTS', 'hint' => self::FOREIGNER_HINT];
        }

        if (in_array($r['working_now'], ['no', 'not_yet'], true) && $r['customer_type'] !== 'pension') {
            return ['code' => 'APPLICANT_HAS_NO_WORK', 'hint' => StartApplicationTool::NO_WORK_HINT];
        }

        if ($r['refused_work'] || $r['sector'] === 'government') {
            return ['code' => 'OCCUPATION_NOT_ACCEPTED', 'hint' => StartApplicationTool::occupationHint($this->refusalSentence())];
        }

        // Owner 2026-10-04: a day's pay with no trade (tuk-tuk, café by the
        // day, scrap) is taken - on the ID only, the free-work terms.
        if ($r['daily_labour_no_trade'] && $typeKey !== 'self_employed') {
            return ['code' => 'DAILY_WORK_IS_CARD_ONLY', 'hint' => 'His work ("'.$words.'") is a day\'s pay: he applies with his ID only, on '
                .'the free-work terms. Call again right away with customer_type self_employed (work_type other) and the same quote - do not ask him again '
                .'and do not tell him his type.'];
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

        // QA 2026-10-04: "معاشي ٣٥٠٠" opened an application although the
        // pension minimum is 4,000. The figure he said is checked against the
        // type's rules now; the statement or slip still decides later.
        if ($r['stated_monthly_income'] > 0 && ($type = \App\Models\CustomerType::where('key', $typeKey)->first())) {
            $income = app(EligibilityService::class)->evaluate(['monthly_income' => $r['stated_monthly_income']], $type->id);

            if ($income['status'] === 'not_eligible') {
                return ['code' => 'STATED_INCOME_BELOW_MINIMUM', 'hint' => 'The income he said ('.number_format($r['stated_monthly_income'])
                    .' جنيه) is below what the finance companies need for this type. Tell him the condition kindly in one line and do not open '
                    .'an application on it. Only if he said he also has a job, that job may apply instead; never offer the card-only route to a pensioner '
                    .'with no job. Otherwise another working person may apply in his own name, or cash at the branch.'];
            }
        }

        // Owner 2026-10-04: the last resort that still sells - a man who works
        // but cannot bring his work's papers applies with the ID only, on the
        // free-work (عامل حر) terms. Never for a woman (owner's rule), never
        // before he said he cannot bring the papers.
        if ($cardOnly) {
            if (($r['applicant_gender'] ?? null) === 'female') {
                return ['code' => 'FEMALE_FREE_INCOME_NOT_ACCEPTED', 'hint' => self::FEMALE_FREE_INCOME_HINT];
            }

            if (! $r['cannot_bring_work_papers'] && $r['customer_type'] !== 'self_employed') {
                return ['code' => 'CARD_ONLY_LAST_RESORT', 'hint' => 'The card-only route is only for a customer who cannot bring the papers '
                    .'his work needs. Ask him first whether he can bring them (name the paper); only if he says he cannot, offer card-only.'];
            }

            return null;
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
