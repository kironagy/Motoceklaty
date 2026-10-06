<?php

namespace App\Domain\Applications;

use App\Domain\Installments\InstallmentCalculationException;
use App\Domain\Installments\InstallmentCalculator;
use App\Models\Application;
use App\Models\Customer;
use App\Models\InstallmentRequest;
use App\Models\WhatsappConversation;

/**
 * What a customer may hear about his own submitted request when he asks
 * "طلبي وصل لفين؟" (owner 2026-10-04): its number, the bike, its price, what
 * he pays, and where it stands in plain words. Nothing of the review itself
 * - no staff names, notes, checks or who changed what.
 */
class CustomerRequestStatus
{
    private const STATUS = [
        // what the agent says for each status: the instructions (§٨)
        'new_request' => ['وصل وفي انتظار المراجعة', null],
        'new' => ['وصل وفي انتظار المراجعة', null],
        'transferred' => ['تحت المراجعة', null],
        'pending' => ['تحت الاستعلام', null],
        'work_check' => ['تحت الاستعلام عن الشغل', null],
        'paused' => ['متوقف على حاجة ناقصة', null],
        'approved' => ['اتوافق عليه', null],
        'rejected' => ['اترفض', null],
        'delivered' => ['استلم المكنة', null],
        'canceled' => ['اتلغى', null],
    ];

    public function __construct(private readonly InstallmentCalculator $calculator)
    {
    }

    /** @return array<int, array<string, mixed>> newest first, at most 3 */
    public function for(?Customer $customer, WhatsappConversation $conversation): array
    {
        $ids = collect([$conversation->id]);
        $requestIds = $customer
            ? Application::where('customer_id', $customer->id)->whereNotNull('installment_request_id')->pluck('installment_request_id')
            : collect();

        $phones = collect([$customer?->phone, $conversation->real_phone, $conversation->phone])
            ->map(fn ($p) => preg_replace('/\D+/', '', (string) $p))
            ->map(fn ($p) => preg_match('/^201[0125]\d{8}$/', $p) ? '0'.substr($p, 2) : $p)
            ->filter(fn ($p) => preg_match('/^01[0125]\d{8}$/', $p))
            ->unique()->values();

        $requests = InstallmentRequest::query()
            ->with(['machine', 'application.installmentPlan.installmentSystem', 'application.machine'])
            ->where(fn ($q) => $q->whereIn('whatsapp_conversation_id', $ids)
                ->orWhereIn('id', $requestIds)
                ->when($phones->isNotEmpty(), fn ($q) => $q->orWhereIn('applicant_phone', $phones)))
            ->latest('id')
            ->limit(3)
            ->get();

        return $requests->map(fn (InstallmentRequest $r) => $this->describe($r))->all();
    }

    private function describe(InstallmentRequest $request): array
    {
        $status = (string) $request->status;
        [$label] = self::STATUS[$status] ?? ['تحت المراجعة', null];
        $reason = $this->reason($request->checks_report);

        $row = [
            'request_number' => $request->id,
            'status' => $label,
            'motorcycle' => trim((string) $request->machine?->name) ?: null,
            'installment_price' => $request->machine_installment_price !== null ? (float) $request->machine_installment_price : null,
            'down_payment' => $request->deposit !== null ? (float) $request->deposit : null,
            'months' => $request->months,
        ] + $this->monthly($request);

        if ($status === 'paused' && $reason !== null) {
            $row['missing'] = $reason;
        }

        if ($status === 'rejected') {
            $row['refusal'] = StaffDecisionService::isCreditRejection($reason) ? 'credit_record_final' : 'finance_company';
            $row['refusal_reason'] = StaffDecisionService::isCreditRejection($reason) ? null : $reason;
        }

        return array_filter($row, fn ($v) => $v !== null);
    }

    /** @return array{monthly_installment?: float} */
    private function monthly(InstallmentRequest $request): array
    {
        $application = $request->application;
        $plan = $application?->installmentPlan;

        if (! $application?->machine || ! $plan?->installmentSystem) {
            return [];
        }

        try {
            $result = $this->calculator->calculate($application->machine, $plan->installmentSystem, $plan, (float) ($application->down_payment ?? 0));
        } catch (InstallmentCalculationException) {
            return [];
        }

        return ['monthly_installment' => (float) $result->monthlyInstallment];
    }

    private function reason(mixed $checksReport): ?string
    {
        $text = is_array($checksReport)
            ? implode("\n", array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $checksReport)))
            : (string) $checksReport;

        return trim($text) === '' ? null : trim($text);
    }
}
