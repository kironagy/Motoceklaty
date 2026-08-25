<?php

namespace App\Services;

use App\Support\AddressParser;
use App\Support\EgyptianNationalId;

/**
 * One pass over the application's identity fields, run every turn right
 * after extraction merges new values in and before missingFields() gets a
 * say. Until now a field counted as answered the moment it was non-empty,
 * which is how a two-word name, a national ID that decodes to nothing, and
 * a song lyric typed as a street all reached staff as a finished request.
 *
 * Design rules this follows deliberately:
 *
 *  - Verify a value once. Each verdict is cached in the application state
 *    keyed by a hash of the exact value, so re-sending the same name for
 *    the fifth turn costs nothing and, more importantly, cannot flip
 *    verdict halfway through a conversation.
 *  - Never trap a customer. A value the checks keep disliking is accepted
 *    after MAX_ATTEMPTS tries and flagged for human review instead
 *    (`*_needs_review`) - a real person living on a street no model
 *    recognises must still be able to finish. The only checks with no
 *    escape hatch are the ones that are objectively verifiable from the
 *    data itself: national-ID structure and the financing age window.
 *  - Ask about everything wrong at once, in the same message shape the
 *    rest of the flow uses, instead of one rejection per turn.
 */
class ApplicantDataVerifier
{
    /**
     * How many times the same field may be rejected before it is accepted
     * with a review flag. Counted per field, not per value - a customer
     * sending three different unrecognisable streets has still spent three
     * turns on it.
     */
    public const MAX_ATTEMPTS = 2;

    private const ADDRESS_FIELDS = ['home_address', 'work_address'];

    public function __construct(
        private readonly ApplicantNameValidator $nameValidator,
        private readonly AddressPlausibilityValidator $addressValidator,
        private readonly EgyptianNationalId $nationalId,
        private readonly AddressParser $addressParser,
    ) {
    }

    /**
     * @return array{
     *     application: array<string, mixed>,
     *     issues: array<string, string>,
     *     blocking_message: ?string
     * }
     *   `issues` is keyed by field name so the caller can present each
     *   problem in place of that field's generic "ناقصني ..." line rather
     *   than asking twice for the same thing.
     *   `blocking_message` marks a hard stop (age outside the financing
     *   window): reply with it and go no further, the way the flow already
     *   does for an excluded profession.
     */
    public function verify(array $application): array
    {
        $issues = [];

        [$application, $idIssue, $blocking] = $this->verifyNationalId($application);

        if ($blocking) {
            return ['application' => $application, 'issues' => [], 'blocking_message' => $idIssue];
        }

        if ($idIssue !== null) {
            $issues['national_id'] = $idIssue;
        }

        [$application, $nameIssue] = $this->verifyName($application);

        if ($nameIssue !== null) {
            $issues['full_name'] = $nameIssue;
        }

        foreach (self::ADDRESS_FIELDS as $field) {
            [$application, $addressIssue] = $this->verifyAddress($application, $field);

            if ($addressIssue !== null) {
                $issues[$field] = $addressIssue;
            }
        }

        return [
            'application' => $application,
            'issues' => $issues,
            'blocking_message' => null,
        ];
    }

    /**
     * Decodes the number the customer typed and keeps the derived birth
     * date / age on the application, so the request reaches staff with the
     * age already known instead of waiting for the ID-card OCR at the very
     * end (by which point the customer has uploaded everything for an
     * application that was never eligible).
     *
     * @return array{0: array<string, mixed>, 1: ?string, 2: bool}
     */
    private function verifyNationalId(array $application): array
    {
        $raw = trim((string) ($application['national_id'] ?? ''));

        if ($raw === '') {
            return [$application, null, false];
        }

        $parsed = $this->nationalId->parse($raw);

        if ($parsed['valid']) {
            // Store the canonical 14 digits, not whatever spacing the
            // customer used, so the dashboard's uniqueness check works.
            $application['national_id'] = $parsed['digits'];
            $application['birthdate'] = $parsed['birthdate'];
            $application['age'] = $parsed['age'];
            $application['age_ok'] = $parsed['age_ok'];
            $application['national_id_governorate'] = $parsed['governorate'];

            if ($parsed['age_ok']) {
                return [$application, null, false];
            }

            /*
             * Outside 21-62 is the same kind of answer as an excluded
             * profession: nothing later in the flow can change it, so
             * saying it now is kinder than collecting six documents first.
             */
            return [$application, $this->nationalId->problemMessage($parsed), true];
        }

        /*
         * A number that does not decode is objectively wrong - there is no
         * "maybe it is a rare valid ID" case the way there is for a rare
         * street name, so this one has no attempt escape hatch. The field
         * is cleared so it reads as unanswered everywhere else.
         */
        unset(
            $application['birthdate'],
            $application['age'],
            $application['age_ok'],
            $application['national_id_governorate'],
        );

        $application['national_id'] = null;
        $application['national_id_rejected'] = $parsed['digits'] ?? $raw;

        return [$application, $this->nationalId->problemMessage($parsed), false];
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function verifyName(array $application): array
    {
        $raw = trim((string) ($application['full_name'] ?? ''));

        if ($raw === '') {
            return [$application, null];
        }

        $fingerprint = md5($raw);

        /*
         * Already judged this exact string - reuse the verdict instead of
         * spending another call. Re-applying the rejection (not just
         * re-reporting it) matters: the customer re-sends the same name,
         * extraction puts it back on the application, and if we only
         * returned the cached message while leaving the value in place the
         * field would read as answered and the flow would walk straight on
         * to documents with a name it had just rejected.
         */
        $alreadyJudged = ($application['full_name_checked'] ?? null) === $fingerprint;

        /*
         * A value already judged is not re-sent to the model - but it IS
         * re-applied. The customer re-sends the same name, extraction puts
         * it back on the application, and if the cached verdict only
         * repeated its message while leaving the value in place, the field
         * would read as answered and the flow would walk on to documents
         * with a name it had just rejected.
         */
        if ($alreadyJudged && ($application['full_name_issue'] ?? null) === null) {
            return [$application, null];
        }

        if ($alreadyJudged) {
            $message = $application['full_name_issue'];
            $cleanName = $raw;
        } else {
            $result = $this->nameValidator->validate($raw);
            $application['full_name_checked'] = $fingerprint;

            if ($result['status'] === 'ok') {
                $application['full_name'] = $result['name'];
                $application['full_name_issue'] = null;
                unset($application['full_name_needs_review']);

                return [$application, null];
            }

            $message = $result['message'];
            $cleanName = $result['name'] !== '' ? $result['name'] : $raw;
        }

        /*
         * The counter climbs on every turn the customer spends on this
         * field, including turns where they simply re-sent the same value -
         * otherwise someone insisting on a name we keep disliking would
         * never reach the escape hatch below.
         */
        $attempts = (int) ($application['full_name_attempts'] ?? 0) + 1;
        $application['full_name_attempts'] = $attempts;

        /*
         * Two rejections is enough. Some legal names really are two words,
         * and some real names read as gibberish to a model - past this
         * point the honest move is to take what the customer gave, tell
         * staff it was not verified (applicationNotes()), and let the ID
         * card settle it.
         */
        if ($attempts > self::MAX_ATTEMPTS) {
            $application['full_name'] = $cleanName;
            $application['full_name_issue'] = null;
            $application['full_name_needs_review'] = true;

            return [$application, null];
        }

        /*
         * Clearing the field is what makes missingFields() keep full_name
         * in the missing list; the raw text is kept alongside so nothing
         * the customer typed is silently lost.
         */
        $application['full_name'] = null;
        $application['full_name_rejected'] = $raw;
        $application['full_name_issue'] = $message;

        return [$application, $message];
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function verifyAddress(array $application, string $field): array
    {
        $raw = trim((string) ($application[$field] ?? ''));

        if ($raw === '' || $raw === ApplicationStateService::NO_WORKPLACE) {
            return [$application, null];
        }

        $fingerprint = md5($raw);

        // Same reasoning as the name check above: a cached rejection is
        // re-applied, not merely repeated, and it still counts as a turn
        // spent on this field.
        $alreadyJudged = ($application["{$field}_checked"] ?? null) === $fingerprint;

        if ($alreadyJudged && ($application["{$field}_issue"] ?? null) === null) {
            return [$application, null];
        }

        if ($alreadyJudged) {
            $message = $application["{$field}_issue"];
        } else {
            $result = $this->addressValidator->validate(
                $raw,
                $field,
                $this->addressParser->parse($raw)
            );

            $application["{$field}_checked"] = $fingerprint;
            $application["{$field}_verdict"] = $result['verdict'];

            /*
             * "unclear" is not a rejection. The address is kept and the
             * flow carries on asking for whatever components are still
             * missing - the landmark question the flow already asks IS the
             * clarification this verdict wants. Only "fake" (lyrics, mashed
             * letters, a sentence answering something else) actually holds
             * the field.
             */
            if ($result['verdict'] !== AddressPlausibilityValidator::VERDICT_FAKE) {
                $application["{$field}_issue"] = null;

                if ($result['verdict'] === AddressPlausibilityValidator::VERDICT_UNCLEAR && $result['checked']) {
                    $application["{$field}_needs_review"] = true;
                }

                return [$application, null];
            }

            $message = $result['question'];
        }

        $attempts = (int) ($application["{$field}_attempts"] ?? 0) + 1;
        $application["{$field}_attempts"] = $attempts;

        if ($attempts > self::MAX_ATTEMPTS) {
            $application["{$field}_issue"] = null;
            $application["{$field}_needs_review"] = true;

            return [$application, null];
        }

        $application[$field] = null;
        $application["{$field}_components"] = [];
        $application["{$field}_rejected"] = $raw;
        $application["{$field}_issue"] = $message;

        return [$application, $message];
    }
}
