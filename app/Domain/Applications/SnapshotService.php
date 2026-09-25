<?php

namespace App\Domain\Applications;

use App\Domain\Applications\Validators\FieldValidatorRegistry;
use App\Domain\Installments\InstallmentCalculationException;
use App\Domain\Installments\InstallmentCalculator;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
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
        ] + ($partial === [] ? [] : ['partial' => $partial]);

        $eligibility = $this->eligibility->evaluate($facts, $customerType->id);

        $identityConflicts = $this->identityConflicts($application);

        foreach ($identityConflicts as $key) {
            $invalid[] = ['key' => $key, 'code' => 'IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER'];
        }

        $selectionMissing = array_keys(array_filter([
            'motorcycle' => $application->machine_id === null,
            'plan' => $application->installment_plan_id === null,
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

        return [
            'application_id' => $application->id,
            'status' => $application->status,
            'customer_type' => $customerType->key,
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
            'documents' => $documents + $this->missingDocumentHints($requirements['documents'], $documents['missing']),
            'eligibility' => $eligibility,
            'can_submit' => $canSubmit,
            'blockers' => $blockers,
        ] + $this->guidance($application, $requirementsSnapshot['fields']['missing'], $invalid, $documents, $selectionMissing, $eligibility, $canSubmit) + [
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
        $fieldLabels = RequirementField::whereIn('key', array_merge($missingFields, array_column($invalid, 'key')))->pluck('label', 'key');
        $documentLabels = \App\Models\DocumentType::whereIn('key', $documents['missing'])->pluck('label', 'key');
        $idMissing = in_array('national_id_front', $documents['missing'], true);
        $readFromId = $idMissing ? ['full_name', 'national_id'] : [];

        $steps = [];

        foreach ($invalid as $issue) {
            if ($issue['code'] !== 'IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER') {
                $steps[] = ['type' => 'field', 'key' => $issue['key'], 'label' => $fieldLabels[$issue['key']] ?? $issue['key'], 'why' => 'fix: '.$issue['code']];
            }
        }

        if ($idMissing) {
            $steps[] = ['type' => 'document', 'key' => 'national_id_front', 'label' => $documentLabels['national_id_front'] ?? 'صورة البطاقة',
                'why' => 'the photo fills full_name and national_id by itself - do not ask him to type them'];
        }

        $order = ['work_type', 'phone', 'address', 'work_address'];
        $fields = array_values(array_diff($missingFields, $readFromId, array_column($invalid, 'key')));
        usort($fields, fn ($a, $b) => (array_search($a, $order, true) === false ? 99 : array_search($a, $order, true))
            <=> (array_search($b, $order, true) === false ? 99 : array_search($b, $order, true)));

        foreach ($fields as $key) {
            $steps[] = ['type' => 'field', 'key' => $key, 'label' => $fieldLabels[$key] ?? $key];
        }

        foreach (array_diff($documents['missing'], ['national_id_front']) as $key) {
            $steps[] = ['type' => 'document', 'key' => $key, 'label' => $documentLabels[$key] ?? $key];
        }

        if (in_array('motorcycle', $selectionMissing, true)) {
            $steps[] = ['type' => 'selection', 'key' => 'motorcycle', 'label' => 'الموتوسيكل'];
        }

        if (array_intersect(['plan', 'down_payment'], $selectionMissing) !== []) {
            $steps[] = ['type' => 'selection', 'key' => 'duration', 'label' => 'مدة التقسيط',
                'why' => 'offer the durations from get_installment_offer, then update_application_selection with months + down_payment'];
        }

        if ($steps === [] && $eligibility['status'] === 'unknown') {
            foreach ($eligibility['missing_inputs'] ?: [] as $input) {
                $steps[] = ['type' => 'eligibility', 'key' => $input, 'label' => $input];
            }
        }

        if ($steps === []) {
            return ['next_step' => $canSubmit ? ['type' => 'submit', 'why' => 'everything is in - call submit_application'] : null];
        }

        // Asked for the same thing twice and it did not come: asking a third
        // time is what makes people give up. Take the next thing instead;
        // the first one can come later.
        $index = count($steps) > 1 && $this->askedTwiceWithoutAnswer($application, $steps[0]) ? 1 : 0;
        $next = $steps[$index] + ($index === 1
            ? ['why' => 'he has not sent "'.$steps[0]['label'].'" after two asks - ask for this instead, that one can come later']
            : []);

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

    private function askedTwiceWithoutAnswer(Application $application, array $step): bool
    {
        $word = $step['key'] === 'national_id_front' ? 'بطاق' : mb_substr(explode(' ', (string) $step['label'])[0], 0, 5);

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
            ->mapWithKeys(fn ($d) => [$d['key'] => $d['description'] ?? $d['label']])
            ->all();

        return $hints === [] ? [] : ['missing_hints' => $hints];
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

        return [$counted, $partial];
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
