<?php

namespace App\Domain\Applications;

use App\Models\Application;

/**
 * Whose application it is. Conversation 206 (2026-10-05): the customer (20)
 * talked about his mother, then his father (pension 2,000), then his
 * brother - all into ONE application: the father's income stayed on it,
 * the brother's ID went into it, and the brother was refused three times
 * for his father's pension.
 *
 * The agent reads who the person is (record_work_profile: applicant +
 * applicant_relation, backed by his words); the code keeps one person per
 * application: an open application for someone else is closed when the
 * applicant changes, and is never reopened for a different person.
 */
class Applicant
{
    public const RELATIONS = ['brother', 'sister', 'father', 'mother', 'son', 'daughter', 'spouse', 'relative', 'friend', 'other', 'unclear'];

    // as the bot says it to him: the model copied "أخوه" into "بدل أخويا / باسم أخوه"
    private const LABELS = [
        'brother' => 'أخوك', 'sister' => 'أختك', 'father' => 'أبوك', 'mother' => 'والدتك', 'son' => 'ابنك', 'daughter' => 'بنتك',
        'spouse' => 'مراتك/جوزك', 'relative' => 'قريبك', 'friend' => 'صاحبك', 'other' => 'حد تاني', 'unclear' => 'حد تاني (مين بالظبط مش واضح لسه)',
    ];

    /** @return array{who: string, relation?: string, quote?: string}|null null = his work was never recorded */
    public static function fromProfile(?array $profile): ?array
    {
        if ($profile === null) {
            return null;
        }

        if (($profile['applicant'] ?? 'customer') !== 'someone_else') {
            return ['who' => 'customer'];
        }

        return array_filter([
            'who' => 'other',
            'relation' => $profile['applicant_relation'] ?? 'unclear',
            'quote' => ($profile['applicant_quote'] ?? '') ?: null,
        ]);
    }

    /** Unknown on either side is no conflict; "unclear" never contradicts a named relation. */
    public static function same(?array $a, ?array $b): bool
    {
        if (! $a || ! $b) {
            return true;
        }

        if (($a['who'] ?? null) !== ($b['who'] ?? null)) {
            return false;
        }

        $ra = $a['relation'] ?? 'unclear';
        $rb = $b['relation'] ?? 'unclear';

        return $ra === 'unclear' || $rb === 'unclear' || $ra === $rb;
    }

    public static function label(?array $applicant): string
    {
        if (! $applicant || ($applicant['who'] ?? null) === 'customer') {
            return 'العميل نفسه';
        }

        return self::LABELS[$applicant['relation'] ?? 'unclear'] ?? self::LABELS['unclear'];
    }

    /** What the agent sees: whose application / whose data. */
    public static function forPrompt(?array $applicant): ?array
    {
        if (! $applicant) {
            return null;
        }

        return ['person' => self::label($applicant)] + (isset($applicant['quote']) ? ['his_words' => $applicant['quote']] : []);
    }

    /**
     * Same person, new word on his work: "لا هو مش شغال دلوقتي" after "شغال
     * نجار". The one who applies must work or be on a pension - his open
     * application cannot go on collecting his name and phone.
     *
     * @return array{closed_application_id: int, reason: string}|null
     */
    public static function closeIfNoWork(int $customerId, array $profile): ?array
    {
        if (! in_array($profile['working_now'] ?? 'unknown', ['no', 'not_yet'], true) || ($profile['customer_type'] ?? null) === 'pension') {
            return null;
        }

        $open = Application::where('customer_id', $customerId)->where('status', 'collecting')->latest('id')->first();

        if (! $open || ! self::same($open->applicant, self::fromProfile($profile))) {
            return null;
        }

        app(ApplicationService::class)->withdraw($open, 'applicant_no_work', 'the person applying does not work', 'ai');

        return ['closed_application_id' => $open->id, 'reason' => 'APPLICANT_HAS_NO_WORK'];
    }

    /**
     * The applicant changed (father -> brother): the application still
     * collecting for the other person is closed, so its income, documents
     * and eligibility never mix with the new person's.
     *
     * @return array{closed_application_id: int, was_for: string}|null
     */
    public static function closeIfOtherPerson(int $customerId, ?array $applicant): ?array
    {
        $open = Application::where('customer_id', $customerId)->where('status', 'collecting')->latest('id')->first();

        if (! $open || self::same($open->applicant, $applicant)) {
            return null;
        }

        app(ApplicationService::class)->withdraw($open, 'applicant_changed', 'now applying: '.self::label($applicant), 'ai');

        return ['closed_application_id' => $open->id, 'was_for' => self::label($open->applicant)];
    }
}
