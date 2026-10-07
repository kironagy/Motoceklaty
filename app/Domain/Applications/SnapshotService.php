<?php

namespace App\Domain\Applications;

use App\Domain\Applications\Validators\FieldValidatorRegistry;
use App\Domain\Installments\InstallmentCalculationException;
use App\Domain\Installments\InstallmentCalculator;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\ApplicationRequirement;
use App\Models\CustomerType;
use App\Models\CustomerAttribute;
use App\Models\RequirementField;
use App\Models\DocumentType;
use App\Domain\Documents\PeriodCoverage;
use App\Support\IdentityLookup;

/** T13 §5 / T15: the §3.3 application snapshot, including document status. */
class SnapshotService
{
    public function __construct(
        private readonly RequirementService $requirements,
        private readonly EligibilityService $eligibility,
        private readonly FieldValidatorRegistry $validators,
        private readonly InstallmentCalculator $calculator,
        private readonly PeriodCoverage $coverage,
    ) {
    }

    public function for(Application $application): array
    {
        $customerType = $application->customerType;
        $collectedRows = $this->collectedFieldRows($application);
        $facts = $this->factsFrom($application, $collectedRows);

        $requirementsSnapshot = $this->requirements->snapshot(
            $customerType,
            collect($collectedRows)->pluck('value', 'field_key')->all(),
            $this->acceptedDocumentKeys($application),
            $facts,
        );

        $invalid = collect($collectedRows)
            ->where('status', 'invalid')
            ->map(fn ($row) => ['key' => $row['field_key'], 'code' => $row['issue_code']])
            ->values()
            ->all();

        $requirements = $this->requirements->requirementsFor($customerType, $facts);
        $requiredDocumentKeys = collect($requirements['documents'])->where('required', true)->pluck('key')->values()->all();

        $documentRows = ApplicationDocument::where('application_id', $application->id)->latest('id')->get();
        [$acceptedKeys, $partial] = $this->countedAcceptedKeys($application);

        $documents = [
            'required' => $requiredDocumentKeys,
            'accepted' => $acceptedKeys,
            'missing' => array_values(array_diff($requiredDocumentKeys, $acceptedKeys)),
            'rejected' => $this->unresolvedRejections($documentRows, $acceptedKeys),
            'processing' => $documentRows->where('status', 'processing')->pluck('expected_type_key')->filter()->values()->all(),
            // Owner 2026-09-29: "المطلوب ايه؟" is answered with all of it -
            // a rider was told "البطاقة بس" and his license was never asked.
            'list' => collect($requirements['documents'])->where('required', true)->pluck('label')->values()->all(),
            // asked once "if he has it" (the owner's note); he can finish without it
            'optional_missing' => collect($requirements['documents'])->where('required', false)->pluck('key')->diff($acceptedKeys)->values()->all(),
            // false until his job is known: the job adds documents (license, app screenshots...)
            'list_complete' => ! $this->workTypeUnknown($customerType, $facts),
        ] + ($partial === [] ? [] : ['partial' => $partial]);

        $eligibility = $this->eligibility->evaluate($facts, $customerType->id);

        // QA 2026-10-04: after his father's ID was replaced by his own, the
        // bot kept telling a 21-year-old he is 64 - from its own old messages.
        // The age on the ID that counts now is stated here, every turn.
        if (isset($facts['age'])) {
            $eligibility['applicant_age_now'] = (int) $facts['age'];
        }

        $identityConflicts = $this->identityConflicts($application);

        foreach ($identityConflicts as $key) {
            $invalid[] = ['key' => $key, 'code' => 'IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER'];
        }

        // A plan of a system the owner switched off ("مايلو") must be
        // re-chosen before submission - it is treated as not chosen.
        $plan = $application->installmentPlan;
        $planUsable = $plan !== null && $plan->is_active && ($plan->installmentSystem?->is_active ?? false);

        $selectionMissing = array_keys(array_filter([
            'motorcycle' => $application->machine_id === null,
            'plan' => ! $planUsable,
            'down_payment' => $application->down_payment === null,
        ]));

        // One authoritative list of everything standing between this
        // application and submission. can_submit is derived from it and
        // submit_application refuses with it - an application with no plan
        // chosen used to report can_submit=true and then crash on submit.
        $blockers = array_merge(
            array_map(fn ($k) => ['type' => 'field', 'key' => $k, 'code' => 'MISSING'], $requirementsSnapshot['fields']['missing']),
            array_map(fn ($i) => ['type' => 'field', 'key' => $i['key'], 'code' => $i['code'] ?? 'INVALID'], $invalid),
            array_map(fn ($k) => ['type' => 'document', 'key' => $k, 'code' => 'MISSING'], $documents['missing']),
            array_map(fn ($k) => ['type' => 'selection', 'key' => $k, 'code' => 'NOT_CHOSEN'], $selectionMissing),
            $this->eligibilityBlockers($eligibility),
        );

        $canSubmit = $blockers === [];
        $staffStep = null;

        // Already submitted; staff paused it and asked for one thing. That
        // thing is all that is asked, and all that stands in the way.
        if ($application->status === 'needs_more_info' && is_array($application->staff_request)) {
            $staffRequest = $application->staff_request;
            $resolved = app(StaffDecisionService::class)->resolved($application);
            $blockers = $resolved ? [] : [['type' => 'staff_request', 'key' => $staffRequest['document'] ?? 'data', 'code' => 'PENDING']];
            $canSubmit = $resolved;
            $staffStep = match (true) {
                // rebuild: facts only - what the agent does with a staff step is in the instructions (§٨)
                $resolved => ['type' => 'submit', 'reason' => 'staff_request_resolved'],
                ($staffRequest['type'] ?? null) === 'document' => ['type' => 'document', 'key' => $staffRequest['document'], 'label' => $staffRequest['label'] ?? $staffRequest['document'],
                    'reason' => 'staff_request'] + array_filter(['staff_reason' => $staffRequest['reason'] ?? null]),
                // Staff wrote only the reason ("ضهر البطاقه", "مطلوب مفردات مرتب").
                ($staffRequest['type'] ?? null) === 'reason' => ['type' => 'staff_request', 'key' => 'reason', 'label' => (string) ($staffRequest['reason'] ?? ''),
                    'reason' => 'staff_request', 'staff_reason' => (string) ($staffRequest['reason'] ?? '')],
                default => ['type' => 'staff_request', 'key' => 'data', 'label' => 'تصحيح البيانات',
                    'reason' => 'staff_correction', 'staff_reason' => (string) ($staffRequest['reason'] ?? '')],
            };
        }

        return [
            'application_id' => $application->id,
            'status' => $application->status,
            'customer_type' => $customerType->key,
            // whose application: every field, paper and refusal here is about this person
            'applicant' => Applicant::forPrompt($application->applicant),
            'selection' => [
                'motorcycle_id' => $application->machine_id,
                'plan_id' => $application->installment_plan_id,
                'down_payment' => $application->down_payment,
            ],
            'fields' => [
                'required' => $requirementsSnapshot['fields']['required'],
                'collected' => $requirementsSnapshot['fields']['collected'],
                'missing' => $requirementsSnapshot['fields']['missing'],
                'invalid' => $invalid,
                // Non-sensitive values as stored - reviewing the data with the
                // customer from memory showed a name the DB did not hold.
                'values' => $this->nonSensitiveValues($collectedRows),
            ] + $this->missingFieldHints($requirementsSnapshot['fields']['missing']),
            'documents' => $documents + $this->missingDocumentHints($requirements['documents'], $documents['missing'])
                + $this->ifUnavailable($customerType, $documents['missing'], $application),
            'eligibility' => $eligibility,
            'can_submit' => $canSubmit,
            'blockers' => $blockers,
            // Owner 2026-10-05: everything he needs in ONE message the first
            // time; after that "تمام" + the next missing thing only. The
            // context drops this once that first message went (ContextBuilder).
            'full_list' => array_filter([
                'papers' => array_values(array_intersect_key(
                    collect($requirements['documents'])->where('required', true)->pluck('label', 'key')->all(),
                    array_flip($documents['missing'] ?? [])
                )),
                'data' => array_values(RequirementField::whereIn('key', $requirementsSnapshot['fields']['missing'])->pluck('label')->all()),
            ]),
        ] + ($staffStep !== null
            ? ['next_step' => $staffStep, 'staff_request' => $application->staff_request]
            : $this->guidance($application, $requirementsSnapshot['fields']['missing'], $invalid, $documents, $selectionMissing, $eligibility, $canSubmit)) + [
            'last_activity_at' => $application->last_activity_at?->toIso8601String(),
        ];
    }

    /**
     * Customers dropped off when handed five fields and a photo at once, and
     * were asked again in other words when they said "عايز اقدم" twice. This
     * is the ONE thing to ask next, in the order that costs the customer
     * least - the ID photo first because reading it fills the name and the
     * national ID by itself - plus how much is left, for "فاضل حاجتين".
     */
    private function guidance(Application $application, array $missingFields, array $invalid, array $documents, array $selectionMissing, array $eligibility, bool $canSubmit): array
    {
        // QA 2026-10-04: a 64-year-old was told "مش متاح" and in the same
        // reply asked for the back of his ID and his pension statement, then
        // offered "موديل تاني يناسب عمرك". Not eligible ends the paperwork.
        if ($eligibility['status'] === 'not_eligible') {
            $codes = array_column($eligibility['reasons'] ?? [], 'code');

            return ['next_step' => ['type' => 'not_eligible', 'reasons' => $codes]
                + (OtherApplicant::appliesTo($codes) ? ['other_applicant_line' => OtherApplicant::line(($application->applicant['who'] ?? null) === 'other')] : [])];
        }

        $fieldLabels = RequirementField::whereIn('key', array_merge($missingFields, array_column($invalid, 'key')))->pluck('label', 'key');
        $documentLabels = \App\Models\DocumentType::whereIn('key', $documents['missing'])->pluck('label', 'key');
        $idMissing = in_array('national_id_front', $documents['missing'], true);
        $readFromId = $idMissing ? ['full_name', 'national_id'] : [];

        $steps = [];

        foreach ($invalid as $issue) {
            if ($issue['code'] !== 'IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER') {
                $steps[] = ['type' => 'field', 'key' => $issue['key'], 'label' => $fieldLabels[$issue['key']] ?? $issue['key'], 'issue' => $issue['code']];
            }
        }

        $backMissing = in_array('national_id_back', $documents['missing'], true);

        // The job decides which documents he needs: asking for the ID first
        // left a rider's license and app screenshots out of the list.
        if (in_array('work_type', $missingFields, true)) {
            $steps[] = ['type' => 'field', 'key' => 'work_type', 'label' => $fieldLabels['work_type'] ?? 'work_type',
                'reason' => 'decides_documents'];
            $missingFields = array_values(array_diff($missingFields, ['work_type']));
        }

        if ($idMissing) {
            $steps[] = ['type' => 'document', 'key' => 'national_id_front', 'label' => $documentLabels['national_id_front'] ?? 'صورة البطاقة']
                + ($backMissing ? ['both_sides' => true] : []);
        } elseif ($backMissing) {
            // The back was never asked for: only the front was accepted and
            // the back photos the customer sent were dropped as unsupported.
            $steps[] = ['type' => 'document', 'key' => 'national_id_back', 'label' => $documentLabels['national_id_back'] ?? 'ضهر البطاقة'];
        }

        $order = ['work_type', 'phone', ...self::HOME_ADDRESS, ...self::WORK_ADDRESS];
        $fields = array_values(array_diff($missingFields, $readFromId, array_column($invalid, 'key')));
        usort($fields, fn ($a, $b) => (array_search($a, $order, true) === false ? 99 : array_search($a, $order, true))
            <=> (array_search($b, $order, true) === false ? 99 : array_search($b, $order, true)));

        foreach ($fields as $key) {
            $steps[] = ['type' => 'field', 'key' => $key, 'label' => $fieldLabels[$key] ?? $key]
                + $this->askAddressTogether($key, $fields, $fieldLabels);
        }

        foreach (array_diff($documents['missing'], ['national_id_front', 'national_id_back']) as $key) {
            $steps[] = ['type' => 'document', 'key' => $key, 'label' => $documentLabels[$key] ?? $key];
        }

        if (in_array('motorcycle', $selectionMissing, true)) {
            $steps[] = ['type' => 'selection', 'key' => 'motorcycle', 'label' => 'الموتوسيكل'];
        }

        if (array_intersect(['plan', 'down_payment'], $selectionMissing) !== []) {
            $steps[] = ['type' => 'selection', 'key' => 'duration', 'label' => 'مدة التقسيط'];
        }

        if ($steps === [] && $eligibility['status'] === 'unknown') {
            foreach ($eligibility['missing_inputs'] ?: [] as $input) {
                $steps[] = ['type' => 'eligibility', 'key' => $input, 'label' => $input];
            }
        }

        if ($steps === []) {
            return ['next_step' => $canSubmit ? ['type' => 'submit'] : null];
        }

        // Asked for the same thing twice and it did not come: asking a third
        // time is what makes people give up. Take the next thing instead;
        // the first one can come later.
        $index = count($steps) > 1 && $this->askedTwiceWithoutAnswer($application, $steps[0]) ? 1 : 0;
        $next = $steps[$index] + ($index === 1 ? ['skipped_after_two_asks' => $steps[0]['label']] : []);

        return [
            'next_step' => $next,
            // "فاضل ٥ خطوات" scares people off: only what comes after this,
            // and the count once it is small.
            'progress' => array_filter([
                'then' => $steps[$index + 1]['label'] ?? null,
                'remaining' => count($steps) <= 3 ? count($steps) : null,
            ], fn ($v) => $v !== null),
        ];
    }

    /** This type asks for work_type (or depends on it) and it is not recorded yet. */
    private function workTypeUnknown(CustomerType $customerType, array $facts): bool
    {
        return ($facts['work_type'] ?? null) === null
            && ApplicationRequirement::where('customer_type_id', $customerType->id)
                ->where(fn ($q) => $q->where('condition->fact', 'work_type')
                    ->orWhereHas('requirementField', fn ($f) => $f->where('key', 'work_type')))
                ->exists();
    }

    /** Home address parts, asked in this order. */
    private const HOME_ADDRESS = ['address', 'address_building_no', 'address_floor', 'address_apartment', 'address_landmark', 'residence_ownership'];

    /** Work address parts - no floor, no rented/owned. */
    private const WORK_ADDRESS = ['work_address', 'work_building_no', 'work_landmark'];

    /**
     * Asking street, then number, then floor one message at a time is five
     * round trips. The customer is asked for everything still missing of
     * that address at once; whatever he leaves out comes back as the next step.
     */
    private function askAddressTogether(string $key, array $missing, $labels): array
    {
        foreach ([self::HOME_ADDRESS, self::WORK_ADDRESS] as $group) {
            if (in_array($key, $group, true)) {
                $parts = array_values(array_intersect($group, $missing));

                return count($parts) > 1 ? ['ask_together' => array_map(fn ($k) => $labels[$k] ?? $k, $parts)] : [];
            }
        }

        return [];
    }

    private function askedTwiceWithoutAnswer(Application $application, array $step): bool
    {
        $word = in_array($step['key'], ['national_id_front', 'national_id_back'], true) ? 'بطاق' : mb_substr(explode(' ', (string) $step['label'])[0], 0, 5);

        if ($word === '' || ! $application->origin_conversation_id) {
            return false;
        }

        $recent = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $application->origin_conversation_id)
            ->latest('id')->limit(6)->get(['direction', 'text', 'type']);

        $asks = 0;

        foreach ($recent as $message) {
            if ($message->direction === 'incoming') {
                if ($message->type !== 'text') {
                    return false; // he sent something - give it a chance
                }

                continue;
            }

            if (str_contains(\App\Support\ArabicTextNormalizer::normalize((string) $message->text), \App\Support\ArabicTextNormalizer::normalize($word))) {
                $asks++;
            } else {
                break;
            }
        }

        return $asks >= 2;
    }

    /**
     * A rejection only matters while its type is still not accepted, and
     * only the newest attempt per type: a rejected ID back next to an
     * accepted ID front made the agent ask for the front three more times.
     */
    private function unresolvedRejections(\Illuminate\Support\Collection $documentRows, array $acceptedKeys): array
    {
        return $documentRows
            ->filter(fn ($d) => in_array($d->status, ['rejected', 'failed'], true))
            ->filter(fn ($d) => ! in_array($d->detected_type_key ?? $d->expected_type_key, $acceptedKeys, true))
            // the same photo read again later and accepted is not still "rejected"
            ->reject(fn ($d) => $d->media_id && $documentRows->contains(fn ($o) => $o->media_id === $d->media_id && $o->status === 'accepted'))
            ->unique(fn ($d) => $d->detected_type_key ?? $d->expected_type_key ?? 'unknown')
            ->map(fn ($d) => array_filter([
                'type' => $d->detected_type_key ?? $d->expected_type_key,
                'code' => $d->issues[0]['code'] ?? null,
                'media_id' => $d->media_id,
            ], fn ($v) => $v !== null))
            ->values()->all();
    }

    private function eligibilityBlockers(array $eligibility): array
    {
        if ($eligibility['status'] === 'not_eligible') {
            return array_map(fn ($r) => ['type' => 'eligibility', 'key' => $r['code'] ?? 'NOT_ELIGIBLE', 'code' => 'NOT_ELIGIBLE'] + (isset($r['params']) ? ['params' => $r['params']] : []), $eligibility['reasons']);
        }

        if ($eligibility['status'] === 'unknown') {
            return array_map(fn ($k) => ['type' => 'eligibility', 'key' => $k, 'code' => 'INPUT_MISSING'], $eligibility['missing_inputs'] ?: ['unknown']);
        }

        return [];
    }

    /**
     * Identity values of this applicant that another customer already
     * holds. The duplicate-ID policy itself is still an owner decision
     * (DEC-04), so the safe default is to stop submission for staff review
     * rather than accept or reject silently.
     *
     * @return string[]
     */
    private function identityConflicts(Application $application): array
    {
        $keys = IdentityLookup::identityFieldKeys();

        $hashes = CustomerAttribute::where('customer_id', $application->customer_id)->whereIn('field_key', $keys)->whereNotNull('lookup_hash')->pluck('lookup_hash', 'field_key')
            ->merge(ApplicationData::where('application_id', $application->id)->where('party', 'applicant')->whereIn('field_key', $keys)->whereNotNull('lookup_hash')->pluck('lookup_hash', 'field_key'));

        return $hashes->filter(fn ($hash) => IdentityLookup::otherCustomersWith($hash, $application->customer_id) !== [])
            ->keys()->values()->all();
    }

    private function nonSensitiveValues(array $collectedRows): array
    {
        $sensitive = RequirementField::where('is_sensitive', true)->pluck('key')->all();
        $values = [];

        foreach ($collectedRows as $row) {
            if ($row['status'] === 'valid' && ! in_array($row['field_key'], $sensitive, true) && $row['value'] !== null) {
                $values[$row['field_key']] = $row['value'];
            }
        }

        return $values;
    }

    /**
     * The model only saw missing keys, so it could neither ask for an enum
     * field (work_type) with a value the validator accepts nor explain what
     * a document like delivery_app_earnings actually is. Only for what is
     * still missing, to keep the context small.
     */
    private function missingFieldHints(array $missingKeys): array
    {
        $hints = RequirementField::whereIn('key', $missingKeys)->get()
            ->filter(fn (RequirementField $f) => $f->enum_options || $f->description_for_ai)
            ->mapWithKeys(fn (RequirementField $f) => [$f->key => array_filter([
                'options' => $f->enum_options ?: null,
                'hint' => $f->description_for_ai,
            ])])
            ->all();

        return $hints === [] ? [] : ['missing_hints' => $hints];
    }

    private function missingDocumentHints(array $requiredDocuments, array $missingKeys): array
    {
        $hints = collect($requiredDocuments)
            ->whereIn('key', $missingKeys)
            // QA 2026-10-04: the whole reader description (~400 tokens) rode
            // along every turn. The first sentence says what the paper is.
            ->mapWithKeys(fn ($d) => [$d['key'] => \Illuminate\Support\Str::limit(
                preg_split('/(?<=[.:؛])\s/u', (string) ($d['description'] ?? $d['label']))[0], 160)])
            ->all();

        return $hints === [] ? [] : ['missing_hints' => $hints];
    }

    /**
     * Owner 2026-10-01: a warehouse worker whose company issues no salary
     * slip was refused for an hour, offered a bank statement, a contract and
     * "someone else applies in his name" - and a colleague then took him
     * with the ID and his work address. The showroom rule already said an
     * uninsured employee with no slip applies as self_employed; the bot
     * never used it. This says how, where the bot looks for the slip.
     */
    /**
     * QA 2026-10-04: after a message that filled the whole address the reply
     * still listed the address as missing - read off older messages. The one
     * thing to say next, said plainly with the write tool's result.
     */
    /**
     * TOOL-006 / CTX-002: what a write tool hands back instead of the whole
     * snapshot. The turn already carries one full snapshot (L4, or the
     * start_application result); every write after it repeated ~2-4k chars
     * of lists and prose ("قدم الطلب" turn: 86,719 input tokens). This keeps
     * only what the next action needs: progress, what is still missing, the
     * documents in, eligibility, can_submit and next_step with its hints.
     */
    public static function compact(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $fields = $snapshot['fields'] ?? [];
        $documents = $snapshot['documents'] ?? [];
        $next = $snapshot['next_step'] ?? null;
        $nextKeys = array_filter([$next['key'] ?? null]);
        $eligibility = $snapshot['eligibility'] ?? [];

        return array_filter([
            'application_id' => $snapshot['application_id'] ?? null,
            'status' => $snapshot['status'] ?? null,
            'customer_type' => $snapshot['customer_type'] ?? null,
            'applicant' => $snapshot['applicant'] ?? null,
            'selection' => $snapshot['selection'] ?? null,
            'progress' => 'fields '.count($fields['collected'] ?? []).'/'.count($fields['required'] ?? [])
                .', documents '.count($documents['accepted'] ?? []).'/'.count($documents['required'] ?? []),
            // Simulator 2026-10-05: with the whole missing list in every write
            // result, each reply read out four or five items. next_step is the
            // one to ask; progress says how much is left.
            'invalid' => $fields['invalid'] ?? [],
            'documents' => ['accepted' => $documents['accepted'] ?? []] + array_filter([
                'rejected' => $documents['rejected'] ?? [],
                'processing' => $documents['processing'] ?? [],
                'partial' => $documents['partial'] ?? null,
                'list_complete' => ($documents['list_complete'] ?? true) ? null : false,
            ], fn ($v) => $v !== null && $v !== []),
            'eligibility' => array_filter([
                'status' => $eligibility['status'] ?? null,
                'reasons' => $eligibility['reasons'] ?? [],
                'missing_inputs' => $eligibility['missing_inputs'] ?? [],
                'applicant_age_now' => $eligibility['applicant_age_now'] ?? null,
            ], fn ($v) => $v !== null && $v !== []),
            'can_submit' => $snapshot['can_submit'] ?? false,
            'next_step' => $next,
            // the options/hint of what is asked now - the rest are in the full snapshot
            'next_step_hints' => array_intersect_key(($fields['missing_hints'] ?? []) + ($documents['missing_hints'] ?? []), array_flip($nextKeys)),
            // the substitute rule is about his work papers, never the ID
            'if_unavailable' => ($next['type'] ?? null) === 'document' && ! str_starts_with((string) ($next['key'] ?? ''), 'national_id')
                ? ($documents['if_unavailable'] ?? null) : null,
            'staff_request' => $snapshot['staff_request'] ?? null,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    private function ifUnavailable(CustomerType $customerType, array $missingDocuments, Application $application): array
    {
        // The ID itself has no substitute; everything his work needs beyond it may.
        $workPapers = array_values(array_diff($missingDocuments, ['national_id_front', 'national_id_back']));

        if ($workPapers === []) {
            return [];
        }

        $labels = \App\Models\DocumentType::whereIn('key', $workPapers)->pluck('label')->implode('، ');

        // Owner 2026-10-04: no salary slip from the company = the insurance
        // print instead; only with neither, the card-only route.
        // Owner 2026-10-04: the job on the back of his ID decides. A job title
        // = the salary slip is compulsory; "بدون عمل" / "طالب" = no salary
        // slip asked of him, he applies with his ID.
        $occupation = in_array('salary_slip', $workPapers, true) ? IdOccupation::of($application) : null;

        // rebuild: a code + the facts; the owner's rule for each is in the instructions (§٧)
        if ($occupation !== null && IdOccupation::isNoJob($occupation)) {
            return ['if_unavailable' => ['rule' => 'no_salary_slip_needed', 'occupation_on_id' => $occupation]];
        }

        if ($occupation !== null) {
            return ['if_unavailable' => ['rule' => 'insurance_print_only', 'occupation_on_id' => $occupation]];
        }

        if (in_array('salary_slip', $workPapers, true)) {
            return ['if_unavailable' => ['rule' => 'insurance_print_then_card_only']];
        }

        // Owner 2026-10-04: "the important thing is that he applies" - the
        // last resort for a working man who cannot bring these papers.
        return ['if_unavailable' => ['rule' => 'card_only_last_resort', 'papers' => $labels]];
    }

    private function acceptedDocumentKeys(Application $application): array
    {
        return $this->countedAcceptedKeys($application)[0];
    }

    /**
     * Accepted document keys, except a period_coverage type (app earnings
     * screenshots) counts only once its screenshots together cover enough
     * time; until then it is reported under `partial` with what arrived.
     *
     * @return array{0: string[], 1: array<string, array>}
     */
    private function countedAcceptedKeys(Application $application): array
    {
        $keys = ApplicationDocument::where('application_id', $application->id)
            ->where('status', 'accepted')
            ->pluck('detected_type_key')
            ->filter()
            ->unique()
            ->values();

        $counted = [];
        $partial = [];

        foreach ($keys as $key) {
            $type = DocumentType::where('key', $key)->first();

            if (PeriodCoverage::ruleFor($type) === null) {
                $counted[] = $key;

                continue;
            }

            $summary = $this->coverage->summarize($application, $type);

            if ($summary['satisfied']) {
                $counted[] = $key;
            } else {
                $partial[$key] = $summary;
            }
        }

        // an insurance print counts for the salary slip (DocumentEquivalents)
        return [\App\Domain\Documents\DocumentEquivalents::satisfied($counted), $partial];
    }

    /** @return array<int, array{field_key: string, value: mixed, status: string, issue_code: ?string}> */
    private function collectedFieldRows(Application $application): array
    {
        $customerRows = CustomerAttribute::where('customer_id', $application->customer_id)
            ->get(['field_key', 'value', 'status'])
            ->map(fn ($row) => ['field_key' => $row->field_key, 'value' => $row->value, 'status' => $row->status, 'issue_code' => null])
            ->all();

        $applicationRows = ApplicationData::where('application_id', $application->id)
            ->get(['field_key', 'value', 'status', 'issue_code'])
            ->map(fn ($row) => ['field_key' => $row->field_key, 'value' => $row->value, 'status' => $row->status, 'issue_code' => $row->issue_code])
            ->all();

        return array_merge($customerRows, $applicationRows);
    }

    /**
     * Re-derives eligibility facts (e.g. age from a stored national ID) by
     * replaying each collected value through its validator - validators are
     * pure, so this reproduces exactly what was computed when the value was
     * first accepted, with no separate fact-storage table needed.
     */
    private function factsFrom(Application $application, array $collectedRows): array
    {
        $facts = [];

        foreach ($collectedRows as $row) {
            if ($row['status'] !== 'valid' || $row['value'] === null) {
                continue;
            }

            $field = RequirementField::where('key', $row['field_key'])->first();

            if (! $field) {
                continue;
            }

            $result = $this->validators->for($field->data_type)?->validate((string) $row['value'], $field);

            if ($result?->valid) {
                $facts = array_merge($facts, $result->facts);
            }
        }

        if ($application->installmentPlan) {
            $facts['months'] = $application->installmentPlan->months;
        }

        $facts['selection'] = [
            'motorcycle_id' => $application->machine_id,
            'plan_id' => $application->installment_plan_id,
            'down_payment' => $application->down_payment,
            'financed_amount' => $this->financedAmount($application),
        ];

        if (array_key_exists('financed_amount', $facts['selection']) && $facts['selection']['financed_amount'] !== null) {
            $facts['financed_amount'] = $facts['selection']['financed_amount'];
        }

        return $facts;
    }

    private function financedAmount(Application $application): ?float
    {
        if (! $application->machine || ! $application->installmentPlan) {
            return null;
        }

        try {
            $result = $this->calculator->calculate(
                $application->machine,
                $application->installmentPlan->installmentSystem,
                $application->installmentPlan,
                (float) ($application->down_payment ?? 0),
            );
        } catch (InstallmentCalculationException) {
            return null;
        }

        return $result->financedAmount;
    }
}
