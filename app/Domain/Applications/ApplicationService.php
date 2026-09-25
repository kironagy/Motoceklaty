<?php

namespace App\Domain\Applications;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationEvent;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\InstallmentPlan;
use App\Models\Machine;
use App\Domain\Installments\InstallmentCalculationException;
use App\Domain\Installments\InstallmentCalculator;

class ApplicationService
{
    public function __construct(
        private readonly ApplicationStateMachine $stateMachine,
        private readonly RequirementService $requirements,
        private readonly InstallmentCalculator $calculator,
    ) {
    }

    public function activeFor(Customer $customer): ?Application
    {
        return Application::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', Application::ACTIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    /**
     * @return array{application: Application, created: bool}
     * @throws ApplicationSelectionException
     */
    public function start(
        Customer $customer,
        int $conversationId,
        CustomerType $customerType,
        ?Machine $machine = null,
        ?InstallmentPlan $plan = null,
        ?float $downPayment = null,
    ): array {
        $this->assertSelectionValid($machine, $plan);
        $this->assertDownPaymentValid($machine, $plan, $downPayment);

        $existing = $this->activeFor($customer);

        if ($existing) {
            // DEC-14 (still open): project.md §28 already states a customer may
            // make more than one application, and §6.10 of the plan describes
            // "returns it" as start_application's default path - reuse unless
            // the owner explicitly configures a stricter policy from the
            // dashboard.
            if (config('agent.applications.concurrent_active_policy') === 'reject') {
                throw new ApplicationSelectionException('ACTIVE_APPLICATION_EXISTS');
            }

            return ['application' => $existing, 'created' => false];
        }

        $application = Application::create([
            'customer_id' => $customer->id,
            'origin_conversation_id' => $conversationId,
            'customer_type_id' => $customerType->id,
            'machine_id' => $machine?->id,
            'installment_plan_id' => $plan?->id,
            'down_payment' => $downPayment,
            'status' => 'collecting',
            'last_activity_at' => now(),
        ]);

        ApplicationEvent::create([
            'application_id' => $application->id,
            'type' => 'started',
            'from_status' => null,
            'to_status' => 'collecting',
            'actor' => 'ai',
            'data' => ['customer_type' => $customerType->key],
        ]);

        return ['application' => $application, 'created' => true];
    }

    /**
     * @return string[] field/document keys invalidated by the new selection (T13 §3, tool §6.11)
     * @throws ApplicationSelectionException
     */
    public function updateSelection(
        Application $application,
        ?Machine $machine = null,
        ?InstallmentPlan $plan = null,
        ?float $downPayment = null,
        ?CustomerType $customerType = null,
    ): array {
        if ($application->status !== 'collecting') {
            throw new ApplicationSelectionException('APPLICATION_LOCKED');
        }

        $selectionInvalidated = [];
        $effectiveMachine = $machine ?? $application->machine;
        $effectivePlan = $plan ?? $application->installmentPlan;
        $effectiveDown = $downPayment ?? ($application->down_payment !== null ? (float) $application->down_payment : null);
        $clear = [];

        // A new motorcycle can make the earlier plan/down payment meaningless
        // (system not offered for it, down payment above its price). They are
        // cleared and reported - not kept silently, and not a reason to
        // refuse the customer's motorcycle change.
        if ($machine && ! $plan && $effectivePlan && ! $this->planOffered($machine, $effectivePlan)) {
            $clear['installment_plan_id'] = null;
            $selectionInvalidated[] = ['kind' => 'selection', 'key' => 'plan', 'reason' => 'not_offered_for_new_motorcycle'];
            $effectivePlan = null;
        }

        $this->assertSelectionValid($effectiveMachine, $effectivePlan);

        if ($machine && $downPayment === null && $effectiveDown !== null && ! $this->downPaymentFits($effectiveMachine, $effectivePlan, $effectiveDown)) {
            $clear['down_payment'] = null;
            $selectionInvalidated[] = ['kind' => 'selection', 'key' => 'down_payment', 'reason' => 'not_valid_for_new_motorcycle'];
            $effectiveDown = null;
        }

        $this->assertDownPaymentValid($effectiveMachine, $effectivePlan, $downPayment ?? null);

        $requiredBefore = collect($this->requirements->requirementsFor($application->customerType)['documents'])
            ->where('required', true)->pluck('key');

        $application->update(array_filter([
            'machine_id' => $machine?->id,
            'installment_plan_id' => $plan?->id,
            'down_payment' => $downPayment,
            'customer_type_id' => $customerType?->id,
        ], fn ($v) => $v !== null) + $clear + ['last_activity_at' => now()]);

        ApplicationEvent::create([
            'application_id' => $application->id,
            'type' => 'selection_updated',
            'from_status' => $application->status,
            'to_status' => $application->status,
            'actor' => 'ai',
            'data' => [
                'machine_id' => $machine?->id,
                'installment_plan_id' => $plan?->id,
                'down_payment' => $downPayment,
                'customer_type_id' => $customerType?->id,
            ],
        ]);

        $requiredAfter = collect($this->requirements->requirementsFor($application->refresh()->customerType)['documents'])
            ->where('required', true)->pluck('key');
        $noLongerRequired = $requiredBefore->diff($requiredAfter);

        return array_merge($selectionInvalidated, $this->supersedeDocumentsForKeys($application, $noLongerRequired->values()->all()));
    }

    public function withdraw(Application $application, string $reasonCode, ?string $note, string $actor = 'customer'): void
    {
        $this->stateMachine->transition($application, 'withdrawn', 'withdrawn', $actor, [
            'reason_code' => $reasonCode,
            'note' => $note,
        ]);
    }

    /** @throws ApplicationSelectionException */
    private function assertSelectionValid(?Machine $machine, ?InstallmentPlan $plan): void
    {
        // A customer can ask about (and get informational details on) any
        // catalog motorcycle, in or out of stock - but selling one requires
        // it actually being in stock. Checked here, not just in the tools,
        // so start_application/update_application_selection can never be
        // bypassed by calling them with a stale or hand-picked machine_id.
        if ($machine && $machine->availability !== 'in_stock') {
            throw new ApplicationSelectionException('MOTORCYCLE_NOT_AVAILABLE');
        }

        if (! $plan) {
            return;
        }

        if (! $machine) {
            throw new ApplicationSelectionException('PLAN_NOT_AVAILABLE_FOR_MOTORCYCLE');
        }

        $linked = $machine->installmentSystems()
            ->where('installment_systems.id', $plan->installment_system_id)
            ->exists();

        if (! $linked) {
            throw new ApplicationSelectionException('PLAN_NOT_AVAILABLE_FOR_MOTORCYCLE');
        }
    }

    private function planOffered(Machine $machine, InstallmentPlan $plan): bool
    {
        return $machine->installmentSystems()->where('installment_systems.id', $plan->installment_system_id)->exists();
    }

    private function downPaymentFits(?Machine $machine, ?InstallmentPlan $plan, float $downPayment): bool
    {
        try {
            $this->assertDownPaymentValid($machine, $plan, $downPayment);

            return true;
        } catch (ApplicationSelectionException) {
            return false;
        }
    }

    /**
     * The same calculator that quotes the numbers decides whether a down
     * payment is usable (negative, at/above the price, below a system's
     * minimum) - an application must never hold a down payment no quote
     * could be produced for. Without a motorcycle there is nothing to
     * check against yet.
     *
     * @throws ApplicationSelectionException
     */
    private function assertDownPaymentValid(?Machine $machine, ?InstallmentPlan $plan, ?float $downPayment): void
    {
        if ($downPayment === null || ! $machine) {
            return;
        }

        if ($downPayment < 0) {
            throw new ApplicationSelectionException('DOWN_PAYMENT_INVALID');
        }

        if ($downPayment >= (float) $machine->cash_price) {
            throw new ApplicationSelectionException('DOWN_PAYMENT_TOO_HIGH', 'Down payment must be less than the cash price.');
        }

        if (! $plan) {
            return;
        }

        try {
            $this->calculator->calculate($machine, $plan->installmentSystem, $plan, $downPayment);
        } catch (InstallmentCalculationException $e) {
            throw new ApplicationSelectionException($e->errorCode, $e->getMessage());
        }
    }

    /**
     * @param  string[]  $noLongerRequiredKeys
     * @return array<int, array{kind: string, key: string, reason: string}>
     */
    private function supersedeDocumentsForKeys(Application $application, array $noLongerRequiredKeys): array
    {
        if ($noLongerRequiredKeys === []) {
            return [];
        }

        $accepted = ApplicationDocument::where('application_id', $application->id)
            ->where('status', 'accepted')
            ->whereIn('detected_type_key', $noLongerRequiredKeys)
            ->get();

        $invalidated = [];

        foreach ($accepted as $document) {
            app(\App\Domain\Documents\DocumentLifecycle::class)->supersede($document, 'selection_changed');
            $invalidated[] = ['kind' => 'document', 'key' => $document->detected_type_key, 'reason' => 'selection_changed'];
        }

        return $invalidated;
    }
}
