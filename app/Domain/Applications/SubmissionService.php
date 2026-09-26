<?php

namespace App\Domain\Applications;

use App\Domain\Installments\InstallmentCalculationException;
use App\Domain\Installments\InstallmentCalculator;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\ApplicationEvent;
use App\Models\CustomerAttribute;
use App\Models\InstallmentRequest;
use App\Models\RequirementField;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;

/**
 * T14 §1: finalizes an application into the legacy `installment_requests`
 * table staff already work with (DEC-21). Only the columns DEC-21
 * resolved are set here; the customer/guarantor/work/document columns come
 * from LegacyRequestProjector, and the row is marked request_type=bot.
 *
 * Submission is two-phase and enforced here, not in the prompt:
 *  1. the customer is shown a summary built from the stored data (never
 *     from the model's memory of the chat - a review once showed a name
 *     the DB did not hold), recorded as a `review_presented` event with a
 *     hash of exactly what was shown;
 *  2. only a later turn may submit, and only while the stored data still
 *     hashes the same - anything changed since means a new summary first.
 */
class SubmissionService
{
    public function __construct(
        private readonly SnapshotService $snapshots,
        private readonly ApplicationStateMachine $stateMachine,
        private readonly InstallmentCalculator $calculator,
        private readonly LegacyRequestProjector $projector,
    ) {
    }

    /**
     * @return array{submitted: true, reference: array}|array{submitted: false, review: array, review_text: string, newly_presented: bool}
     *
     * @throws SubmissionException NOT_READY (with blockers), APPLICATION_LOCKED, LEGACY_WORK_STATUS_NOT_MAPPED
     */
    public function submit(Application $application, int $turnId, bool $customerConfirmed): array
    {
        return DB::transaction(function () use ($application, $turnId, $customerConfirmed) {
            $application = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($application->installment_request_id) {
                // Staff paused it and asked him for something: once it is in,
                // the same request goes back to them - no new request.
                if ($application->status === 'needs_more_info' && is_array($application->staff_request)) {
                    return app(StaffDecisionService::class)->resubmit($application);
                }

                return ['submitted' => true, 'reference' => $this->reference($application)];
            }

            if (! in_array($application->status, ['collecting', 'needs_more_info'], true)) {
                throw new SubmissionException('APPLICATION_LOCKED');
            }

            $snapshot = $this->snapshots->for($application);

            if (! $snapshot['can_submit']) {
                throw new SubmissionException('NOT_READY', json_encode(['blockers' => $snapshot['blockers']], JSON_UNESCAPED_UNICODE));
            }

            $hash = $this->stateHash($application);
            $presented = ApplicationEvent::where('application_id', $application->id)
                ->where('type', 'review_presented')
                ->latest('id')
                ->first();

            // The summary must have actually reached the customer: a turn
            // that was superseded or fell back never delivered it.
            $confirmable = $customerConfirmed
                && $presented
                && ($presented->data['hash'] ?? null) === $hash
                && (int) ($presented->data['turn_id'] ?? 0) < $turnId
                && WhatsappMessage::where('turn_id', (int) $presented->data['turn_id'])
                    ->where('direction', 'outgoing')
                    ->where('delivery_status', 'sent')
                    ->where('text', $this->reviewText($this->review($application)))
                    ->exists();

            if (! $confirmable) {
                $alreadyShownThisTurn = $presented
                    && ($presented->data['hash'] ?? null) === $hash
                    && (int) ($presented->data['turn_id'] ?? 0) === $turnId;

                if (! $alreadyShownThisTurn) {
                    ApplicationEvent::create([
                        'application_id' => $application->id,
                        'type' => 'review_presented',
                        'from_status' => $application->status,
                        'to_status' => $application->status,
                        'actor' => 'system',
                        'data' => ['hash' => $hash, 'turn_id' => $turnId],
                    ]);
                }

                $review = $this->review($application);

                return [
                    'submitted' => false,
                    'review' => $review,
                    'review_text' => $this->reviewText($review),
                    'newly_presented' => ! $alreadyShownThisTurn,
                ];
            }

            $legacyWorkStatus = $application->customerType->legacy_work_status;

            if (! $legacyWorkStatus) {
                throw new SubmissionException(
                    'LEGACY_WORK_STATUS_NOT_MAPPED',
                    "Customer type '{$application->customerType->key}' has no legacy_work_status set (DEC-21) - set it from the dashboard before submitting."
                );
            }

            // Customer, guarantor, work and document columns - the bot tab
            // of the deliveries table shows these like any manual request.
            $installmentRequest = InstallmentRequest::create(array_merge(
                $this->projector->attributes($application, $legacyWorkStatus),
                [
                    'application_id' => $application->id,
                    'machine_id' => $application->machine_id,
                    'whatsapp_conversation_id' => $application->origin_conversation_id,
                    // The employee the WhatsApp number belongs to owns the request.
                    'staff_id' => $this->botStaffId($application),
                    'installment_type' => $application->installmentPlan->installmentSystem->name,
                    'months' => $application->installmentPlan->months,
                    'machine_installment_price' => $application->machine->installment_price,
                    'deposit' => $application->down_payment,
                    'status' => 'new',
                ],
            ));

            $this->stateMachine->transition($application, 'submitted', 'submitted', 'ai', [
                'installment_request_id' => $installmentRequest->id,
                'review_hash' => $hash,
            ]);

            $application->update(['installment_request_id' => $installmentRequest->id, 'submitted_at' => now()]);

            return ['submitted' => true, 'reference' => $this->reference($application)];
        });
    }

    /**
     * What the customer is asked to confirm - taken from the DB only.
     * Sensitive values are never echoed, only that they are on file.
     */
    public function review(Application $application): array
    {
        $application->loadMissing(['machine.brand', 'installmentPlan.installmentSystem']);
        // Sensitive values are never echoed; enum values are internal keys
        // (delivery_app ...) and have no customer-facing wording.
        $hidden = RequirementField::where('is_sensitive', true)->orWhere('data_type', 'enum')->pluck('key')->all();
        $labels = RequirementField::pluck('label', 'key')->all();

        $fields = [];

        foreach ($this->applicantValues($application) as $key => $value) {
            $fields[] = ['key' => $key, 'label' => $labels[$key] ?? $key, 'value' => in_array($key, $hidden, true) ? null : $value];
        }

        $numbers = null;

        try {
            $result = $this->calculator->calculate(
                $application->machine,
                $application->installmentPlan->installmentSystem,
                $application->installmentPlan,
                (float) $application->down_payment,
            );
            $numbers = [
                'monthly_payment' => $result->monthlyInstallment,
                'admin_fee' => $result->administrativeFees,
            ];
        } catch (InstallmentCalculationException) {
        }

        return [
            'fields' => $fields,
            'motorcycle' => trim(($application->machine->brand?->name ?? '').' '.$application->machine->name),
            'installment_system' => trim((string) $application->installmentPlan->installmentSystem->name),
            'months' => $application->installmentPlan->months,
            'down_payment' => (float) $application->down_payment,
            'monthly_payment' => $numbers['monthly_payment'] ?? null,
            'admin_fee' => $numbers['admin_fee'] ?? null,
            'documents' => ApplicationDocument::where('application_id', $application->id)->where('status', 'accepted')
                ->with('documentType')->get()->map(fn ($d) => $d->documentType?->label)->filter()->unique()->values()->all(),
        ];
    }

    public function reviewText(array $review): string
    {
        $lines = ['*ملخص طلب التقسيط زي ما هو متسجل عندنا:*'];

        foreach ($review['fields'] as $field) {
            $lines[] = "• {$field['label']}: ".($field['value'] ?? 'متسجل ✓');
        }

        $lines[] = "• الموتوسيكل: {$review['motorcycle']}";
        $lines[] = "• نظام التقسيط: {$review['installment_system']} - {$review['months']} شهر";
        $lines[] = '• المقدم: '.number_format($review['down_payment']).' جنيه';

        if ($review['monthly_payment'] !== null) {
            $lines[] = '• القسط الشهري: '.number_format($review['monthly_payment']).' جنيه';
        }

        if (($review['admin_fee'] ?? 0) > 0) {
            $lines[] = '• المصاريف الإدارية: '.number_format($review['admin_fee']).' جنيه';
        }

        if ($review['documents'] !== []) {
            $lines[] = '• المستندات: '.implode('، ', $review['documents']);
        }

        return implode("\n", $lines);
    }

    /**
     * Everything the customer confirms: selection, customer type, every
     * stored applicant/guarantor value and the accepted documents.
     */
    private function stateHash(Application $application): string
    {
        $data = ApplicationData::where('application_id', $application->id)->orderBy('party')->orderBy('field_key')->get()
            ->map(fn ($r) => [$r->party, $r->field_key, (string) $r->value])->all();
        $attributes = CustomerAttribute::where('customer_id', $application->customer_id)->orderBy('field_key')->get()
            ->map(fn ($r) => [$r->field_key, (string) $r->value])->all();
        $documents = ApplicationDocument::where('application_id', $application->id)->where('status', 'accepted')->orderBy('id')->pluck('id')->all();

        return hash('sha256', json_encode([
            $application->customer_type_id, $application->machine_id, $application->installment_plan_id,
            (string) $application->down_payment, $data, $attributes, $documents,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, string> applicant values, application-scope over customer-scope */
    private function applicantValues(Application $application): array
    {
        $values = CustomerAttribute::where('customer_id', $application->customer_id)->where('status', 'valid')->get()
            ->mapWithKeys(fn ($r) => [$r->field_key => (string) $r->value])->all();

        foreach (ApplicationData::where('application_id', $application->id)->where('status', 'valid')->orderBy('party')->get() as $row) {
            // Guarantor fields have their own keys (guarantor_name, ...).
            $values[$row->field_key] = (string) $row->value;
        }

        return $values;
    }

    private function botStaffId(Application $application): ?int
    {
        $botId = \App\Models\WhatsappConversation::whereKey($application->origin_conversation_id)->value('whatsapp_bot_id');

        return $botId ? \App\Models\WhatsappBot::whereKey($botId)->value('staff_id') : null;
    }

    private function reference(Application $application): array
    {
        return ['installment_request_id' => $application->installment_request_id];
    }
}
