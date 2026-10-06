<?php

namespace App\Domain\Applications;

use App\Domain\Conversations\CustomerStatements;
use App\Models\WhatsappConversation;

/**
 * Rebuild: the applicant's work, as the agent read it from the conversation
 * and recorded it with record_work_profile - once per new statement, stored
 * on the conversation, read by every rule (WorkClassification) and tool.
 * No model call here: the reading is the agent's, the evidence is checked
 * against his real messages, and the rules are PHP.
 */
class WorkProfiles
{
    public const CUSTOMER_TYPES = ['employee', 'self_employed', 'business_owner', 'pension', 'unknown'];

    public const WORK_TYPES = ['craftsman', 'delivery_app', 'delivery_app_bicycle', 'delivery_company', 'other', 'none'];

    public const QUESTIONS = ['none', 'ask_what_work', 'ask_sector', 'ask_owner_or_worker', 'ask_insured'];

    private const STATE_KEY = 'work_profile';

    public function __construct(private readonly CustomerStatements $statements)
    {
    }

    /** @return array<string, mixed>|null the recorded reading, or null when his work was never recorded */
    public function get(int $conversationId): ?array
    {
        $stored = (WhatsappConversation::find($conversationId, ['id', 'state'])?->state ?? [])[self::STATE_KEY] ?? null;

        return is_array($stored) ? self::clean($stored) : null;
    }

    /**
     * @return array{ok: true, profile: array<string, mixed>}|array{ok: false, code: string}
     */
    public function record(int $conversationId, array $reading): array
    {
        $profile = self::clean($reading);

        // A reading with nothing he said behind it is a guess, not his work.
        if ($profile['evidence'] === '' || $this->statements->messageContainingQuote($conversationId, $profile['evidence']) === null) {
            return ['ok' => false, 'code' => 'EVIDENCE_NOT_IN_HIS_MESSAGES'];
        }

        // "اخ" + "اه" to "قصدك أبوك؟" is no father: a relation stands only on
        // his own words naming that person; otherwise it stays unclear.
        // "اخ اخويا حبيب صاحب" became "أخوك صاحب المحل": his words named more
        // than one person - the agent lists them, the code keeps it unclear.
        $named = array_values(array_unique(array_diff((array) ($reading['people_named'] ?? []), ['unclear', 'other'])));

        if ($profile['applicant'] === 'someone_else' && $profile['applicant_relation'] !== 'unclear'
            && (count($named) > 1 || $profile['applicant_quote'] === '' || $this->statements->messageContainingQuote($conversationId, $profile['applicant_quote']) === null)) {
            $profile['applicant_relation'] = 'unclear';
            $profile['applicant_quote'] = '';
        }

        $conversation = WhatsappConversation::findOrFail($conversationId);
        $conversation->update(['state' => array_merge($conversation->state ?? [], [
            self::STATE_KEY => $profile + ['recorded_at' => now()->toIso8601String()],
        ])]);

        return ['ok' => true, 'profile' => $profile];
    }

    /** @return array<string, mixed> */
    public static function clean(array $r): array
    {
        $pick = fn (string $key, array $allowed, string $default) => in_array($r[$key] ?? null, $allowed, true) ? $r[$key] : $default;

        return [
            'applicant' => $pick('applicant', ['customer', 'someone_else'], 'customer'),
            // who that person is, as the agent read it - 'unclear' until his words say it
            'applicant_relation' => ($r['applicant'] ?? null) === 'someone_else' ? $pick('applicant_relation', Applicant::RELATIONS, 'unclear') : null,
            'applicant_quote' => ($r['applicant'] ?? null) === 'someone_else' ? trim((string) ($r['applicant_quote'] ?? '')) : '',
            'applicant_gender' => $pick('applicant_gender', ['male', 'female', 'unknown'], 'unknown'),
            'applicant_nationality' => $pick('applicant_nationality', ['egyptian', 'foreign', 'unknown'], 'unknown'),
            'work_stated' => (bool) ($r['work_stated'] ?? false),
            'occupation' => trim((string) ($r['occupation'] ?? '')),
            'evidence' => trim((string) ($r['evidence'] ?? '')),
            'working_now' => $pick('working_now', ['yes', 'no', 'not_yet', 'unknown'], 'unknown'),
            'relation_to_workplace' => $pick('relation_to_workplace', ['owner', 'works_for_someone', 'independent', 'unknown'], 'unknown'),
            'insured' => $pick('insured', ['yes', 'no', 'unknown'], 'unknown'),
            'sector' => $pick('sector', ['government', 'private', 'unknown'], 'unknown'),
            'could_be_government' => (bool) ($r['could_be_government'] ?? false),
            'refused_work' => (bool) ($r['refused_work'] ?? false),
            'customer_type' => $pick('customer_type', self::CUSTOMER_TYPES, 'unknown'),
            'work_type' => $pick('work_type', self::WORK_TYPES, 'none'),
            'question' => $pick('question', self::QUESTIONS, 'none'),
            'daily_labour_no_trade' => (bool) ($r['daily_labour_no_trade'] ?? false),
            'cannot_bring_work_papers' => (bool) ($r['cannot_bring_work_papers'] ?? false),
            'stated_monthly_income' => max(0, (float) ($r['stated_monthly_income'] ?? 0)),
            'workplace_name' => trim((string) ($r['workplace_name'] ?? '')),
        ];
    }
}
