<?php

namespace App\Agent\Context\Facts;

use App\Domain\Applications\Applicant;
use App\Domain\Applications\ApplicationService;
use App\Domain\Applications\CustomerRequestStatus;
use App\Domain\Applications\SnapshotService;
use App\Domain\Applications\WorkProfiles;
use App\Domain\Conversations\QuotedOffer;
use App\Domain\Handoff\HandoffService;
use App\Domain\Memory\CustomerMemory;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\CustomerAttribute;
use App\Models\DocumentType;
use App\Models\Handoff;
use App\Models\Machine;
use App\Models\MessageMedia;
use App\Models\RequirementField;
use App\Models\WhatsappConversation;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1: the ONE factual view the agent gets of a customer and his
 * conversation. Facts and progress only - no step, no next step, no intent,
 * no question to ask: what to do is the agent's call.
 *
 * Read-only and rebuilt on every turn: the same stored data gives the same
 * facts for any model. Where two stores hold the same fact, FactResolver
 * picks by authority (see the Phase 1 spec, §4) and the other value is
 * logged as a conflict, not shown. A price or an installment appears only
 * from a valid quote-ledger entry; memory, the summary and old messages
 * are never a source for one.
 */
class ConversationFacts
{
    public function __construct(
        private readonly ApplicationService $applications,
        private readonly SnapshotService $snapshots,
        private readonly WorkProfiles $workProfiles,
        private readonly CustomerMemory $memory,
        private readonly CustomerRequestStatus $requestStatus,
        private readonly FactResolver $resolver,
    ) {
    }

    public function for(WhatsappConversation $conversation, ?int $turnId = null): FactsResult
    {
        $customer = $conversation->customer;
        $application = $customer ? $this->applications->activeFor($customer) : null;
        $snapshot = $application ? $this->snapshots->for($application) : null;
        $state = $conversation->state ?? [];
        $work = $this->workProfiles->get($conversation->id);
        $memory = $customer ? $this->memory->current($customer->id) : ['facts' => [], 'motorcycles' => [], 'conversation' => [], 'applicant_facts' => []];

        $applicant = $application?->applicant ?: Applicant::fromProfile($work);
        $other = ($applicant['who'] ?? 'customer') === 'other';

        $conflicts = [];
        $pick = function (string $fact, array $candidates) use (&$conflicts) {
            $result = $this->resolver->resolve($fact, $candidates);
            array_push($conflicts, ...$result['conflicts']);

            return $result['value'];
        };

        // The memory the person APPLYING is described by: his own, or - for
        // someone else's application - what was said about that person.
        $aboutApplicant = $other
            ? ((($memory['applicant_facts']['for'] ?? null) === Applicant::label($applicant)) ? (array) ($memory['applicant_facts']['facts'] ?? []) : [])
            : $memory['facts'];
        $mem = fn (array $facts, string $key) => $this->stated($facts, $key);
        $attributes = $customer ? $this->profileAttributes($customer->id) : [];
        $values = $snapshot['fields']['values'] ?? [];
        $knownWork = $work !== null && ($work['work_stated'] ?? false);
        $known = fn ($value, $unknown) => $value === $unknown ? null : $value;

        $S = FactResolver::SNAPSHOT;
        $C = FactResolver::CONVERSATION;
        $M = FactResolver::MEMORY;

        $person = [
            'name' => $pick('person.name', [
                ['tier' => $S, 'value' => $values['full_name'] ?? null, 'source' => 'application'],
                ['tier' => $M, 'value' => $mem($aboutApplicant, 'name'), 'source' => 'memory'],
                ['tier' => $M, 'value' => $other ? null : ($attributes['full_name'] ?? null), 'source' => 'profile'],
            ]),
            'age' => $pick('person.age', [
                ['tier' => $S, 'value' => $snapshot['eligibility']['applicant_age_now'] ?? null, 'source' => 'application'],
                ['tier' => $M, 'value' => $mem($aboutApplicant, 'age'), 'source' => 'memory', 'free_text' => true],
            ]),
            'gender' => $pick('person.gender', [
                ['tier' => $C, 'value' => $work ? $known($work['applicant_gender'] ?? 'unknown', 'unknown') : null, 'source' => 'work_profile'],
                ['tier' => $C, 'value' => $other ? null : ($state['customer_gender'] ?? null), 'source' => 'conversation'],
                ['tier' => $M, 'value' => $mem($aboutApplicant, 'gender'), 'source' => 'memory', 'free_text' => true],
            ]),
            'nationality' => $pick('person.nationality', [
                ['tier' => $C, 'value' => $work ? $known($work['applicant_nationality'] ?? 'unknown', 'unknown') : null, 'source' => 'work_profile'],
                ['tier' => $M, 'value' => $mem($aboutApplicant, 'nationality'), 'source' => 'memory', 'free_text' => true],
            ]),
        ];

        $workFacts = [
            'occupation' => $pick('work.occupation', [
                ['tier' => $C, 'value' => $knownWork ? ($work['occupation'] ?: null) : null, 'source' => 'work_profile'],
                ['tier' => $M, 'value' => $mem($aboutApplicant, 'job'), 'source' => 'memory', 'free_text' => true],
            ]),
            'workplace' => $pick('work.workplace', [
                ['tier' => $C, 'value' => $knownWork ? ($work['workplace_name'] ?: null) : null, 'source' => 'work_profile'],
                ['tier' => $M, 'value' => $mem($aboutApplicant, 'workplace'), 'source' => 'memory', 'free_text' => true],
            ]),
            'customer_type' => $pick('work.customer_type', [
                ['tier' => $S, 'value' => $snapshot['customer_type'] ?? null, 'source' => 'application'],
                ['tier' => $C, 'value' => $knownWork ? $known($work['customer_type'], 'unknown') : null, 'source' => 'work_profile'],
            ]),
            'work_type' => $pick('work.work_type', [
                ['tier' => $S, 'value' => $values['work_type'] ?? null, 'source' => 'application'],
                ['tier' => $C, 'value' => $knownWork ? $known($work['work_type'], 'none') : null, 'source' => 'work_profile'],
            ]),
            'insured' => $pick('work.insured', [
                ['tier' => $C, 'value' => $knownWork ? $known($work['insured'], 'unknown') : null, 'source' => 'work_profile'],
                ['tier' => $M, 'value' => $mem($aboutApplicant, 'insured'), 'source' => 'memory', 'free_text' => true],
            ]),
            'working_now' => $knownWork ? $known($work['working_now'], 'unknown') : null,
            'monthly_income' => $pick('work.monthly_income', [
                ['tier' => $S, 'value' => $values['monthly_income'] ?? null, 'source' => 'application'],
                ['tier' => $C, 'value' => $knownWork && $work['stated_monthly_income'] > 0 ? $work['stated_monthly_income'] : null, 'source' => 'work_profile'],
                ['tier' => $M, 'value' => $mem($aboutApplicant, 'monthly_income'), 'source' => 'memory', 'free_text' => true],
            ]),
            'his_words' => $knownWork ? ($work['evidence'] ?: null) : null,
        ];

        // the same sentence is not told twice: his words about who applies and about his work
        if (($applicant['quote'] ?? null) !== null && ($workFacts['his_words'] ?? null) === $applicant['quote']) {
            $workFacts['his_words'] = null;
        }

        $quotes = QuotedOffer::classify($conversation);
        $newest = $quotes['valid'][0] ?? null;
        $planMonths = $application?->installmentPlan?->months;
        $interest = $state['interest'] ?? null;
        $bikes = $this->bikesFromMemory($memory['motorcycles']);
        $strongBike = collect($bikes)->first(fn ($b) => in_array($b['stage_key'], ['applied', 'selected', 'preferred'], true) && ! empty($b['id']));

        $motorcycleId = $pick('deal.motorcycle', [
            ['tier' => FactResolver::TOOL_EVIDENCE, 'value' => $newest['motorcycle_id'] ?? null, 'source' => 'quote'],
            ['tier' => $S, 'value' => $application?->machine_id, 'source' => 'application'],
            ['tier' => $C, 'value' => $interest['id'] ?? null, 'source' => 'conversation'],
            ['tier' => $M, 'value' => $strongBike['id'] ?? null, 'source' => 'memory'],
        ]);

        $deal = [
            'motorcycle' => $motorcycleId ? $this->machineRef((int) $motorcycleId) : null,
            'interest' => $interest && ($interest['cc'] ?? null) ? ['label' => $interest['label'] ?? null, 'cc' => (int) $interest['cc']] : null,
            'financing_months' => $pick('deal.financing_months', [
                ['tier' => FactResolver::TOOL_EVIDENCE, 'value' => $newest['months'] ?? null, 'source' => 'quote'],
                ['tier' => $S, 'value' => $planMonths, 'source' => 'application'],
            ]),
        ];

        $self = $memory['facts'];
        $customerFacts = [
            'governorate' => $mem($self, 'governorate'),
            'area' => $mem($self, 'area'),
            'usage' => $mem($self, 'usage'),
            'has_driving_license' => $mem($self, 'has_driving_license'),
            // what HE says he can afford - never an offer
            'his_budget' => [
                'monthly' => $mem($self, 'monthly_budget'),
                'cash' => $mem($self, 'cash_budget'),
                'down_payment' => $mem($self, 'down_payment'),
                'duration' => $mem($self, 'preferred_duration'),
            ],
            'bikes' => array_map(fn ($b) => array_diff_key($b, ['stage_key' => 1]), array_slice($bikes, 0, 5)),
            'profile' => $application ? null : array_diff_key($attributes, ['full_name' => 1]),
            'sensitive_on_file' => $application || ! $customer ? null : $this->sensitiveOnFile($customer->id),
        ];

        if ($other) {
            $customerFacts['himself'] = ['name' => $mem($self, 'name'), 'age' => $mem($self, 'age')];
        }

        $awaiting = $this->openAwaiting($state, $snapshot);
        $age = $customer ? $this->memory->statedAge($customer->id) : null;
        $minimumAge = CustomerMemory::minimumAge();

        $facts = $this->prune([
            'applicant' => Applicant::forPrompt($applicant),
            'person' => $person,
            'work' => $workFacts,
            'customer' => $customerFacts,
            'deal' => $deal,
            'quotes' => array_map(fn ($q) => ['quoted_at' => $q['at']] + array_diff_key($q, ['price_version' => 1, 'motorcycle_id' => 1, 'at' => 1]), $quotes['valid']),
            'cash_prices' => QuotedOffer::cashPricesShown($conversation),
            // expired: no number is shown, so none can be repeated - look it up again
            'needs_new_lookup' => array_map(fn ($q) => ['motorcycle' => $q['motorcycle'], 'months' => $q['months'], 'why' => $q['why']], $quotes['expired']),
            'application' => $application && $snapshot ? $this->applicationFacts($application, $snapshot) : null,
            'requests' => $customer ? $this->requests($customer, $conversation) : [],
            'asked_and_waiting_for' => array_map(fn ($a) => ['kind' => $a['kind'] ?? null, 'key' => $a['key'] ?? null], $awaiting),
            'handoff' => ($handoff = $this->handoff($conversation)) === ['status' => 'none'] ? null : $handoff,
            'unprocessed_media' => $this->unprocessedMedia($conversation, $turnId),
            'notes' => [
                'conversation_ended' => isset($state['ended_at']) ? true : null,
                'back_after' => $customer ? $this->memory->silenceGap($conversation->id) : null,
                'stated_age_below_minimum' => $age !== null && $minimumAge !== null && $age < $minimumAge ? ['age' => $age, 'minimum' => $minimumAge] : null,
            ],
        ]);

        if ($conflicts !== []) {
            Log::info('agent.facts.conflict', ['conversation_id' => $conversation->id, 'conflicts' => $conflicts]);
        }

        return new FactsResult($facts, $conflicts, $application, $snapshot);
    }

    /** @return array<string, mixed> */
    private function applicationFacts(Application $application, array $snapshot): array
    {
        $fields = $snapshot['fields'] ?? [];
        $documents = $snapshot['documents'] ?? [];
        $fieldLabels = RequirementField::whereIn('key', $fields['missing'] ?? [])->pluck('label', 'key');
        $insteadKeys = array_values(array_unique(array_merge(...array_map(fn ($key) => \App\Domain\Documents\DocumentEquivalents::FOR[$key] ?? [], $documents['missing'] ?? []))));
        $documentLabels = DocumentType::whereIn('key', array_merge($documents['missing'] ?? [], $documents['optional_missing'] ?? [], $insteadKeys))->pluck('label', 'key');

        // definitions only: which values a field accepts, what a paper is - never how or when to ask
        $missingFields = array_map(
            fn ($key) => ['key' => $key, 'label' => $fieldLabels[$key] ?? $key] + ($fields['missing_hints'][$key] ?? []),
            $fields['missing'] ?? []
        );
        $missingDocuments = array_map(
            fn ($key) => ['key' => $key, 'label' => $documentLabels[$key] ?? $key]
                + (isset($documents['missing_hints'][$key]) ? ['what_it_is' => $documents['missing_hints'][$key]] : [])
                // the owner's accepted substitute for this paper (insurance print for a salary slip, an inside photo for a tax card)
                + (isset(\App\Domain\Documents\DocumentEquivalents::FOR[$key])
                    ? ['accepted_instead' => array_map(fn ($alt) => $documentLabels[$alt] ?? $alt, \App\Domain\Documents\DocumentEquivalents::FOR[$key])] : []),
            $documents['missing'] ?? []
        );
        // papers asked once "if he has them"; the application can go without
        $optionalDocuments = array_map(fn ($key) => ['key' => $key, 'label' => $documentLabels[$key] ?? $key], $documents['optional_missing'] ?? []);

        $selection = $snapshot['selection'] ?? [];

        return [
            'id' => $snapshot['application_id'] ?? $application->id,
            'status' => $snapshot['status'] ?? null,
            'customer_type' => $snapshot['customer_type'] ?? null,
            'selection' => [
                'motorcycle' => ! empty($selection['motorcycle_id']) ? $this->machineRef((int) $selection['motorcycle_id']) : null,
                'plan_months' => $application->installmentPlan?->months,
                'down_payment' => $selection['down_payment'] ?? null,
            ],
            'collected' => $fields['values'] ?? [],
            // sensitive items we hold (their values never reach the agent); the rest are in `collected`
            'collected_hidden' => array_values(array_diff($fields['collected'] ?? [], array_keys($fields['values'] ?? []))),
            'missing' => ['fields' => $missingFields, 'documents' => $missingDocuments, 'optional_documents' => $optionalDocuments],
            'invalid' => $fields['invalid'] ?? [],
            'documents' => array_filter([
                'accepted' => $documents['accepted'] ?? [],
                'rejected' => $documents['rejected'] ?? [],
                'processing' => $documents['processing'] ?? [],
                'partial' => $documents['partial'] ?? null,
                'list_complete' => ($documents['list_complete'] ?? true) ? null : false,
                'if_unavailable' => $documents['if_unavailable'] ?? null,
            ], fn ($v) => $v !== null && $v !== []),
            'eligibility' => $snapshot['eligibility'] ?? null,
            'blockers' => $snapshot['blockers'] ?? [],
            'can_submit' => $snapshot['can_submit'] ?? false,
            'staff_request' => $snapshot['staff_request'] ?? null,
        ];
    }

    /** @return array{id: int, name: string}|null */
    private function machineRef(int $id): ?array
    {
        $name = Machine::whereKey($id)->value('name');

        return $name === null ? null : ['id' => $id, 'name' => trim((string) $name)];
    }

    /** One value per memory fact; guesses (`ai_inference`) are not facts. */
    private function stated(array $facts, string $key): mixed
    {
        $fact = $facts[$key] ?? null;

        if (! is_array($fact) || blank($fact['value'] ?? null) || ($fact['source'] ?? null) === 'ai_inference') {
            return null;
        }

        return $fact['value'];
    }

    /** @return array<string, mixed> non-sensitive profile attributes by field key */
    private function profileAttributes(int $customerId): array
    {
        $sensitive = RequirementField::pluck('is_sensitive', 'key');

        return CustomerAttribute::where('customer_id', $customerId)->get()
            ->filter(fn (CustomerAttribute $a) => ! ($sensitive[$a->field_key] ?? true) && filled($a->value))
            ->mapWithKeys(fn (CustomerAttribute $a) => [$a->field_key => $a->value])
            ->all();
    }

    /** @return list<string> keys of sensitive attributes we hold - their values never reach the agent */
    private function sensitiveOnFile(int $customerId): array
    {
        $sensitive = RequirementField::pluck('is_sensitive', 'key');

        return CustomerAttribute::where('customer_id', $customerId)->get()
            ->filter(fn (CustomerAttribute $a) => ($sensitive[$a->field_key] ?? true) && filled($a->value))
            ->pluck('field_key')->unique()->values()->all();
    }

    /** @return list<array{id?: int, name: string, stage: string, stage_key: string}> newest first */
    private function bikesFromMemory(array $motorcycles): array
    {
        uasort($motorcycles, fn ($a, $b) => strcmp((string) ($b['last_at'] ?? ''), (string) ($a['last_at'] ?? '')));

        return array_values(array_map(fn ($bike) => array_filter([
            'id' => $bike['id'] ?? null,
            'name' => trim((string) ($bike['name'] ?? '?')),
            'stage' => CustomerMemory::STAGE_LABELS[$bike['stage'] ?? ''] ?? ($bike['stage'] ?? null),
            'stage_key' => $bike['stage'] ?? '',
        ], fn ($v) => $v !== null), $motorcycles));
    }

    /**
     * What he was asked and has not given, minus what the application now
     * holds (a field collected, a document accepted). Computed here, never
     * written back here (ContextBuilder tidies the stored list on its own).
     *
     * @return list<array<string, mixed>>
     */
    public function openAwaiting(array $state, ?array $snapshot): array
    {
        $awaiting = (array) ($state['awaiting'] ?? []);

        if ($awaiting === [] || ! $snapshot) {
            return $awaiting;
        }

        $collected = $snapshot['fields']['collected'] ?? [];
        $accepted = $snapshot['documents']['accepted'] ?? [];

        return array_values(array_filter($awaiting, fn ($entry) => match ($entry['kind'] ?? null) {
            'field' => ! in_array($entry['key'] ?? null, $collected, true),
            'document' => ! in_array($entry['key'] ?? null, $accepted, true),
            default => true,
        }));
    }

    /** @return list<array<string, mixed>> his submitted requests; the amounts are the record as submitted, not a quote */
    private function requests($customer, WhatsappConversation $conversation): array
    {
        return array_map(function (array $row) {
            $asSubmitted = array_intersect_key($row, array_flip(['installment_price', 'monthly_installment', 'down_payment']));

            // owner 2026-10-07: "اول قسط امتى؟" after applying had no source and the true answer was refused
            return array_diff_key($row, $asSubmitted) + ($asSubmitted === [] ? [] : ['as_submitted' => $asSubmitted])
                + ['first_payment' => \App\Agent\Tools\GetInstallmentOfferTool::firstPaymentLine()];
        }, $this->requestStatus->for($customer, $conversation));
    }

    /** @return array<string, mixed> */
    private function handoff(WhatsappConversation $conversation): array
    {
        $handoff = ['status' => $conversation->status === 'awaiting_agent' ? 'awaiting_agent' : 'none'];

        if ($handoff['status'] === 'awaiting_agent') {
            $handoff['reason'] = Handoff::where('conversation_id', $conversation->id)
                ->whereNull('closed_at')->latest('opened_at')->value('reason');

            if (HandoffService::botKeepsAnswering($conversation)) {
                return ['status' => 'call_requested'];
            }

            return $handoff;
        }

        // Returned by the timeout with no staff reply: the conversation is his again.
        $last = Handoff::where('conversation_id', $conversation->id)->latest('opened_at')->first();

        if ($last && $last->closed_at && $last->closed_by === null && $last->reason !== 'call_request' && $last->closed_at->gt(now()->subDay())) {
            $handoff['last_handoff'] = 'returned_to_you_without_staff_reply';
        }

        return $handoff;
    }

    /**
     * Customer images from earlier messages that no tool ever looked at -
     * a license sent in a burst, a pension statement sent while a colleague
     * had the chat. This turn's own images are already in front of the model.
     *
     * @return list<array{media_id: int, sent_at: ?string, caption: ?string}>
     */
    private function unprocessedMedia(WhatsappConversation $conversation, ?int $currentTurnId): array
    {
        return MessageMedia::query()
            ->whereHas('message', fn ($q) => $q->where('whatsapp_conversation_id', $conversation->id)
                ->where('direction', 'incoming')
                ->where('created_at', '>=', now()->subDays(7))
                ->where(fn ($q) => $q->whereNull('turn_id')->orWhere('turn_id', '!=', (int) $currentTurnId)))
            ->whereIn('media_type', ['image', 'document'])
            ->whereNotIn('id', ApplicationDocument::whereNotNull('media_id')->select('media_id'))
            ->with('message:id,text,created_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->filter(fn (MessageMedia $m) => ! isset(($m->analysis ?? [])['band']) && ! isset(($m->analysis ?? [])['not_a_document']))
            ->map(fn (MessageMedia $m) => [
                'media_id' => $m->id,
                'sent_at' => $m->message?->created_at?->toIso8601String(),
                'caption' => $m->message?->text,
            ])
            ->values()
            ->all();
    }

    /** Drop null, '' and [] at every depth; false and 0 are facts and stay. */
    private function prune(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = array_is_list($value) ? array_map(fn ($v) => is_array($v) ? $this->prune($v) : $v, $value) : $this->prune($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
