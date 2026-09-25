<?php

namespace App\Agent\Tools;

use App\Domain\Applications\EligibilityService;
use App\Domain\Installments\InstallmentCalculationException;
use App\Domain\Installments\FinancingCapPolicy;
use App\Domain\Installments\InstallmentCalculator;
use App\Domain\Installments\PlanResolver;
use App\Domain\Installments\PlanResolutionException;
use App\Models\CustomerType;
use App\Models\InstallmentPlan;
use App\Models\Machine;

/** READ — plan §6.6 */
class CalculateInstallmentTool implements Tool
{
    public function __construct(
        private readonly InstallmentCalculator $calculator,
        private readonly EligibilityService $eligibility,
        private readonly PlanResolver $plans,
        private readonly FinancingCapPolicy $caps,
    ) {
    }

    public function name(): string
    {
        return 'calculate_installment';
    }

    public function description(): string
    {
        return 'Deterministic installment numbers for a motorcycle, plan and down payment. Use when the '
            .'customer asks "how much per month", or for a given down payment or duration. Name the plan with '
            .'installment_system + months exactly as the customer chose it (plan_id only if copied from a tool '
            .'result this turn). Never compute these numbers yourself.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['motorcycle_id'],
            'properties' => [
                'motorcycle_id' => ['type' => 'integer'],
                'plan_id' => ['type' => 'integer'],
                'installment_system' => ['type' => 'string', 'description' => 'System name as shown to the customer, e.g. امان. With months, preferred over plan_id.'],
                'months' => ['type' => 'integer', 'minimum' => 1],
                'down_payment' => ['type' => 'number', 'minimum' => 0],
                'customer_type' => ['type' => 'string', 'description' => 'Only a type the customer actually stated (or the open application\'s). Omit when unknown - never guess "employee".'],
            ],
        ];
    }

    public function permission(): string
    {
        return 'READ';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $machine = Machine::where('is_active', true)->find($args['motorcycle_id']);

        if (! $machine) {
            return ToolResult::error('UNKNOWN_MOTORCYCLE');
        }

        if ($machine->availability !== 'in_stock') {
            return ToolResult::error('MOTORCYCLE_NOT_AVAILABLE');
        }

        try {
            $plan = $this->plans->resolveStrict($machine, $args['plan_id'] ?? null, $args['installment_system'] ?? null, $args['months'] ?? null);
        } catch (PlanResolutionException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        }

        if (! $plan) {
            return ToolResult::error('SYSTEM_REQUIRED', 'Give installment_system + months (see get_installment_options).');
        }

        $plan->loadMissing('installmentSystem');

        $downPayment = (float) ($args['down_payment'] ?? 0);

        // Restrictions (e.g. a financing cap) depend on who is asking: use
        // the open application's customer type when none was given.
        $customerTypeId = $ctx->activeApplicationId
            ? \App\Models\Application::whereKey($ctx->activeApplicationId)->value('customer_type_id')
            : null;

        if (isset($args['customer_type'])) {
            $customerType = CustomerType::where('key', $args['customer_type'])->where('is_active', true)->first();

            if (! $customerType) {
                return ToolResult::error('UNKNOWN_CUSTOMER_TYPE');
            }

            $customerTypeId = $customerType->id;
        }

        // Over a financing cap the difference is paid up front rather than the
        // plan being refused: quote the numbers at the down payment that fits.
        $cap = $customerTypeId !== null ? $this->caps->capFor($customerTypeId) : null;
        $capMinimum = $cap !== null ? $this->caps->minimumDownPayment($machine, $plan->installmentSystem, $cap) : 0.0;
        $requestedDownPayment = $downPayment;
        $downPayment = max($downPayment, $capMinimum);

        try {
            $result = $this->calculator->calculate($machine, $plan->installmentSystem, $plan, $downPayment);
        } catch (InstallmentCalculationException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        }

        \App\Domain\Conversations\QuotedMotorcycle::remember($ctx->conversationId, $machine->id);

        $warnings = $this->eligibility
            ->evaluate(['financed_amount' => $result->financedAmount], $customerTypeId)['reasons'];

        return ToolResult::ok([
            'installment_system' => trim((string) $plan->installmentSystem->name),
            'plan_id' => $plan->id,
            'months' => $result->months,
            'base_price' => (float) ($machine->installment_price ?: $machine->cash_price),
            'down_payment' => $result->downPayment,
            'financed_amount' => $result->financedAmount,
            'interest_amount' => round($result->totalWithInterest - $result->financedAmount, 2),
            'total_payable' => $result->totalWithInterest,
            'monthly_payment' => $result->monthlyInstallment,
            'admin_fee' => $result->administrativeFees,
            'cash_due_upfront' => round($result->downPayment + $result->administrativeFees),
            'first_payment_after_days' => (int) config('agent.installments.first_payment_after_days', 45),
            'warnings' => $warnings,
        ] + ($downPayment > $requestedDownPayment ? ['financing_cap' => [
            'max_financed_amount' => $cap,
            'requested_down_payment' => $requestedDownPayment,
            'explain_to_customer' => $this->caps->explanation($cap, $customerTypeId),
            'note' => 'This customer type can finance at most max_financed_amount, so the down payment was raised '
                .'to cover the difference. Tell the customer: cash_due_upfront (down payment + admin fee) and '
                .'monthly_payment. Do not say the motorcycle cannot be bought in installments.',
        ]] : []));
    }
}
