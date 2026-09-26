<?php

namespace App\Domain\Applications;

use App\Domain\Documents\DocumentLifecycle;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\ApplicationEvent;
use App\Models\CustomerAttribute;
use App\Models\DocumentType;
use App\Models\Handoff;
use App\Models\InstallmentRequest;

/**
 * What happens to the customer when staff change a bot request's status in
 * the deliveries page: he is told right away (approved / paused and why /
 * rejected / canceled), and when staff need something from him - a clearer
 * ID photo, corrected data - the bot asks for exactly that, then puts the
 * same request back in the staff queue. No second request, no second review.
 */
class StaffDecisionService
{
    public const ACTION_DATA = 'data';

    public const ACTION_BRANCH = 'visit_branch';

    public function __construct(
        private readonly DocumentLifecycle $lifecycle,
        private readonly LegacyRequestProjector $projector,
        private readonly ApplicationStateMachine $stateMachine,
    ) {
    }

    /** Options for "المطلوب من العميل" in the deliveries form. */
    public static function actionOptions(): array
    {
        $documents = DocumentType::where('is_active', true)->orderBy('id')->get()
            ->mapWithKeys(fn (DocumentType $t) => [$t->key => 'يبعت '.$t->label.' تاني'])
            ->all();

        return $documents + [
            self::ACTION_DATA => 'يصحح بيانات (اكتب إيه في السبب)',
            self::ACTION_BRANCH => 'ييجي الفرع',
        ];
    }

    /**
     * Staff paused the request asking for a document or a data fix: the
     * application waits for exactly that, and the conversation goes back to
     * the bot so the customer's reply is handled.
     */
    public function requestFromCustomer(InstallmentRequest $request, ?string $reason, ?string $previousStatus): void
    {
        $application = $request->application;
        $action = (string) $request->customer_action;
        $documentType = $action !== '' ? DocumentType::where('key', $action)->first() : null;

        if (! $application || (! $documentType && $action !== self::ACTION_DATA)) {
            return;
        }

        // The copy staff rejected stops counting, so the bot asks for it.
        if ($documentType) {
            ApplicationDocument::where('application_id', $application->id)
                ->where('document_type_id', $documentType->id)
                ->where('status', 'accepted')
                ->get()
                ->each(fn (ApplicationDocument $d) => $this->lifecycle->supersede($d, 'staff_requested_again'));
        }

        if ($application->status !== 'needs_more_info' && $this->stateMachine->canTransition($application, 'needs_more_info')) {
            $this->stateMachine->transition($application, 'needs_more_info', 'staff_requested_from_customer', 'staff', [
                'installment_request_id' => $request->id, 'action' => $action,
            ]);
        }

        $application->update(['staff_request' => [
            'type' => $documentType ? 'document' : 'data',
            'document' => $documentType?->key,
            'label' => $documentType?->label,
            'reason' => $reason,
            'requested_at' => now()->toIso8601String(),
            'previous_status' => $previousStatus,
            'installment_request_id' => $request->id,
        ]]);

        $conversation = $request->whatsappConversation;

        if ($conversation && $conversation->status === 'awaiting_agent') {
            $conversation->update(['status' => 'open']);
            Handoff::where('conversation_id', $conversation->id)->whereNull('closed_at')->update(['closed_at' => now()]);
        }
    }

    /** Whether what staff asked for has come in since they asked. */
    public function resolved(Application $application): bool
    {
        $staffRequest = $application->staff_request;

        if (! is_array($staffRequest)) {
            return false;
        }

        $since = \Illuminate\Support\Carbon::parse($staffRequest['requested_at'] ?? now());

        if (($staffRequest['type'] ?? null) === 'document') {
            return ApplicationDocument::where('application_id', $application->id)
                ->where('status', 'accepted')
                ->whereHas('documentType', fn ($q) => $q->where('key', $staffRequest['document']))
                ->where('created_at', '>=', $since)
                ->exists();
        }

        return ApplicationData::where('application_id', $application->id)->where('updated_at', '>=', $since)->exists()
            || CustomerAttribute::where('customer_id', $application->customer_id)->where('updated_at', '>=', $since)->exists();
    }

    /**
     * Sends the fix to the same request and back to where staff had it.
     *
     * @return array{submitted: true, resubmitted: true, reference: array}
     *
     * @throws SubmissionException NOT_READY while the requested thing is missing
     */
    public function resubmit(Application $application): array
    {
        $staffRequest = (array) $application->staff_request;

        if (! $this->resolved($application)) {
            throw new SubmissionException('NOT_READY', json_encode(['blockers' => [[
                'type' => 'staff_request', 'key' => $staffRequest['document'] ?? 'data', 'code' => 'PENDING', 'reason' => $staffRequest['reason'] ?? null,
            ]]], JSON_UNESCAPED_UNICODE));
        }

        $request = InstallmentRequest::find($application->installment_request_id);

        if ($request) {
            $attributes = $this->projector->attributes($application, $request->work_status ?: (string) $application->customerType?->legacy_work_status);
            $columns = ($staffRequest['type'] ?? null) === 'document'
                ? array_intersect_key($attributes, array_flip($this->projector->documentColumns((string) $staffRequest['document'])))
                : array_filter($attributes, fn ($value, $column) => preg_match('/^(applicant|work|guarantor|free_work)_/', $column)
                    && ! str_ends_with($column, '_image') && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH);

            $what = ($staffRequest['type'] ?? null) === 'document' ? ($staffRequest['label'] ?? $staffRequest['document']) : 'تصحيح البيانات';

            $request->forceFill($columns + [
                'status' => $staffRequest['previous_status'] ?: 'pending',
                'status_updated_at' => now(),
                'customer_action' => null,
                'notes' => trim((string) $request->notes."\n".'العميل بعت المطلوب ('.$what.') على الواتساب - '.now()->format('Y-m-d H:i')),
            ])->saveQuietly();
        }

        foreach (['collecting', 'submitted'] as $status) {
            if ($this->stateMachine->canTransition($application, $status)) {
                $this->stateMachine->transition($application, $status, 'resubmitted_after_staff_request', 'ai', [
                    'installment_request_id' => $application->installment_request_id,
                ]);
            }
        }

        $application->update(['staff_request' => null]);

        return ['submitted' => true, 'resubmitted' => true, 'reference' => ['installment_request_id' => $application->installment_request_id]];
    }

    /** The WhatsApp message for a status staff just set, or null for none. */
    public function message(InstallmentRequest $request, string $status, ?string $reason): ?string
    {
        $reason = trim((string) $reason);
        $name = trim((string) strtok(trim((string) $request->applicant_name), ' ')) ?: 'يا فندم';
        $to = $name === 'يا فندم' ? $name : "يا {$name}";
        $action = (string) $request->customer_action;
        $documentType = $action !== '' ? DocumentType::where('key', $action)->first() : null;

        return match ($status) {
            'approved' => "ألف مبروك {$to} 🎉\nطلب التقسيط بتاعك رقم #{$request->id} اتوافق عليه.\n"
                .'شرّفنا في أقرب فرع ليك عشان تستلم مكنتك. لو محتاج عنوان أقرب فرع قولي إنت ساكن فين وأنا أبعتهولك.',
            'paused' => match (true) {
                $documentType !== null => "{$to}، طلبك رقم #{$request->id} واقف على حاجة بسيطة: "
                    .($reason !== '' ? $reason : $documentType->label.' مش واضحة').".\n"
                    ."ابعتلي {$documentType->label} تاني هنا، بصورة واضحة في نور كويس ومن غير انعكاس، وأنا أكمّل الطلب على طول.",
                $action === self::ACTION_DATA => "{$to}، طلبك رقم #{$request->id} محتاج تصحيح في البيانات"
                    .($reason !== '' ? ": {$reason}" : '').".\nابعتلي البيانات الصح هنا وأنا أعدّلها وأكمّل الطلب.",
                $action === self::ACTION_BRANCH => "{$to}، طلبك رقم #{$request->id} محتاج تشرّفنا في الفرع"
                    .($reason !== '' ? ": {$reason}" : '').".\nلو محتاج عنوان أقرب فرع قولي إنت ساكن فين.",
                default => "{$to}، طلبك رقم #{$request->id} متوقف مؤقتًا"
                    .($reason !== '' ? ": {$reason}" : '').".\nلو عندك أي سؤال ابعتلي هنا.",
            },
            'rejected' => "للأسف {$to}، جهة التمويل ما وافقتش على طلب التقسيط رقم #{$request->id}"
                .($reason !== '' ? ".\nالسبب: {$reason}" : '').".\nلو حابب تشتري كاش أو نشوف حل تاني، أنا موجود معاك.",
            'canceled' => "{$to}، طلب التقسيط رقم #{$request->id} اتلغى"
                .($reason !== '' ? ".\nالسبب: {$reason}" : '').".\nلو حابب تبدأ طلب جديد أو عندك أي سؤال، أنا موجود.",
            default => null,
        };
    }

    public function logDecision(InstallmentRequest $request, string $status, ?string $reason): void
    {
        if (! $request->application_id) {
            return;
        }

        ApplicationEvent::create([
            'application_id' => $request->application_id,
            'type' => 'staff_decision_notified',
            'from_status' => $request->application?->status,
            'to_status' => $request->application?->status,
            'actor' => 'staff',
            'data' => ['legacy_status' => $status, 'reason' => $reason, 'customer_action' => $request->customer_action],
        ]);
    }
}
