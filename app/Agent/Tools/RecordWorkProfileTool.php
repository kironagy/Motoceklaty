<?php

namespace App\Agent\Tools;

use App\Domain\Applications\WorkProfiles;
use App\Models\EligibilityRule;

/**
 * Rebuild: the agent reads the applicant's work itself and records it here
 * once per new statement. The installment, eligibility and application
 * tools then apply the owner's rules to this record - no model call inside
 * any of them (WorkClassifier is gone).
 */
class RecordWorkProfileTool implements WriteTool
{
    public function __construct(private readonly WorkProfiles $profiles)
    {
    }

    public function name(): string
    {
        return 'record_work_profile';
    }

    public function description(): string
    {
        return 'Record the applicant\'s work as you understood it from HIS words (evidence = his exact words), each time he says something new about it, '
            .'before any tool that depends on his work. Unknown when not said; a request to be labelled is not a fact.';
    }

    public function inputSchema(): array
    {
        $refused = EligibilityRule::where('is_active', true)->where('rule_type', \App\Domain\Applications\OccupationPolicy::RULE_TYPE)->get()
            ->map(fn ($rule) => (string) (((array) $rule->params)['words'] ?? ''))->filter()->implode('، ');

        return [
            'type' => 'object',
            'required' => ['evidence', 'work_stated', 'customer_type', 'working_now', 'applicant_gender'],
            'properties' => [
                'evidence' => ['type' => 'string', 'description' => 'His exact words about the work (checked against his messages).'],
                'occupation' => ['type' => 'string', 'description' => 'The work in a few Arabic words, as he described it.'],
                'work_stated' => ['type' => 'boolean', 'description' => 'His real work (or pension / not working) was said.'],
                'applicant' => ['type' => 'string', 'enum' => ['customer', 'someone_else'], 'description' => 'someone_else = another person applies; then describe that person.'],
                'applicant_relation' => ['type' => 'string', 'enum' => \App\Domain\Applications\Applicant::RELATIONS, 'description' => 'Who that person is to him, ONLY when his own words name that person (applicant_quote). Your guess, a bare "اه" to your own question, or two different people in his words ("اخ اخويا حبيب صاحب") = unclear - then ask him one short question: مين اللي هيقدّم؟'],
                'applicant_quote' => ['type' => 'string', 'description' => 'His exact words naming that person (checked against his messages).'],
                'people_named' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => \App\Domain\Applications\Applicant::RELATIONS],
                    'description' => 'Every person his latest message names as the one who might apply, as he wrote them ("اخ اخويا حبيب صاحب" = brother, friend). A word that can be a person or a job ("صاحب" = friend, or owner) is a person unless he said what that person owns. A person he denies ("مش ابويا") is not listed.'],
                'applicant_gender' => ['type' => 'string', 'enum' => ['male', 'female', 'unknown'], 'description' => 'Of the person APPLYING. female when the customer is a woman or the applicant is (انا ست / عايزة / بشتغلي / مراتي / أمي...) - read it from how he or she writes (feminine verb and adjective forms) as well as from what is said; male likewise (عايز / انا راجل / أبويا...). unknown ONLY when nothing in the conversation shows it. The owner\'s rule for women depends on it.'],
                'applicant_nationality' => ['type' => 'string', 'enum' => ['egyptian', 'foreign', 'unknown'], 'description' => 'foreign = not Egyptian.'],
                'working_now' => ['type' => 'string', 'enum' => ['yes', 'no', 'not_yet', 'unknown'], 'description' => 'no = not working/housewife/student; not_yet = has clearly not started.'],
                'customer_type' => ['type' => 'string', 'enum' => WorkProfiles::CUSTOMER_TYPES, 'description' => 'employee = salaried AND insured; business_owner = OWNS the place ("شغال في محل" is not owning); self_employed = works, neither; pension; unknown.'],
                'work_type' => ['type' => 'string', 'enum' => WorkProfiles::WORK_TYPES, 'description' => 'self_employed only, else none.'],
                'relation_to_workplace' => ['type' => 'string', 'enum' => ['owner', 'works_for_someone', 'independent', 'unknown']],
                'insured' => ['type' => 'string', 'enum' => ['yes', 'no', 'unknown']],
                'sector' => ['type' => 'string', 'enum' => ['government', 'private', 'unknown']],
                'could_be_government' => ['type' => 'boolean', 'description' => 'The job is commonly both government and private and he did not say which.'],
                'refused_work' => ['type' => 'boolean', 'description' => 'Government work, army/police, lawyers'.($refused !== '' ? ' - the owner\'s list: '.$refused : '').'.'],
                'daily_labour_no_trade' => ['type' => 'boolean', 'description' => 'Daily pay, no craft (tuk-tuk, scrap...).'],
                'cannot_bring_work_papers' => ['type' => 'boolean', 'description' => 'He said he cannot get his work papers at all (not "later").'],
                'stated_monthly_income' => ['type' => 'number', 'description' => 'Monthly income or pension he stated in EGP; 0 when none.'],
                'workplace_name' => ['type' => 'string', 'description' => 'The company/shop/app name as he wrote it, or "".'],
                'question' => ['type' => 'string', 'enum' => WorkProfiles::QUESTIONS, 'description' => 'The one thing still to ask, in this order; none when nothing is missing.'],
            ],
        ];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $result = $this->profiles->record($ctx->conversationId, $args);

        if (! $result['ok']) {
            return ToolResult::error($result['code'], 'evidence must be his own words as he wrote them.');
        }

        // the person changed (father -> brother): the other person's open application closes
        $applicant = \App\Domain\Applications\Applicant::fromProfile($result['profile']);
        $closed = \App\Domain\Applications\Applicant::closeIfOtherPerson($ctx->customerId, $applicant)
            ?? \App\Domain\Applications\Applicant::closeIfNoWork($ctx->customerId, $result['profile']);
        // Replay of conversation 206: the brother "مش شغال لسه هيشتغل" was recorded,
        // no application was open, and the reply offered "نبدأ التقديم باسم أخويا؟".
        // The person applying must work or be on a pension - said here, every time.
        $noWork = ($closed['reason'] ?? null) === 'APPLICANT_HAS_NO_WORK'
            || (($applicant['who'] ?? null) === 'other' && in_array($result['profile']['working_now'], ['no', 'not_yet'], true) && $result['profile']['customer_type'] !== 'pension');

        return ToolResult::ok(array_filter([
            'recorded' => true,
            'customer_type' => $result['profile']['customer_type'],
            'question' => $result['profile']['question'],
            'applicant' => \App\Domain\Applications\Applicant::forPrompt($applicant),
            // nothing of that application (income, papers, refusal) is about the new person
            'previous_application_closed' => $closed,
            // say this, nothing about his papers or data
            'other_applicant_line' => $noWork ? \App\Domain\Applications\OtherApplicant::line(($applicant['who'] ?? null) === 'other') : null,
        ], fn ($v) => $v !== null));
    }
}
