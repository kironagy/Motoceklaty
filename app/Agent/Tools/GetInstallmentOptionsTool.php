<?php

namespace App\Agent\Tools;

use App\Domain\Applications\EligibilityService;
use App\Domain\Installments\InstallmentCalculationException;
use App\Domain\Installments\FinancingCapPolicy;
use App\Domain\Installments\InstallmentCalculator;
use App\Domain\Installments\InstallmentOptionsService;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;
use App\Models\CustomerType;
use App\Models\Machine;

/** READ — plan §6.5 */
class GetInstallmentOptionsTool implements Tool
{
    public function __construct(
        private readonly InstallmentOptionsService $options,
        private readonly EligibilityService $eligibility,
        private readonly InstallmentCalculator $calculator,
        private readonly FinancingCapPolicy $caps,
    ) {
    }

    public function name(): string
    {
        return 'get_installment_options';
    }

    public function description(): string
    {
        return 'Compare the installment systems/companies for a motorcycle, with each plan\'s upfront cash and monthly '
            .'payment. Use ONLY when the customer asks which companies/systems there are or wants to compare them '
            .'("مع مين"، "ايه الأنظمة"، "أمان ولا غيره"). For a normal installment question use get_installment_offer.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['motorcycle_id'],
            'properties' => [
                'motorcycle_id' => ['type' => 'integer'],
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

        $systems = $this->options->forMachine($machine);

        if ($systems === []) {
            return ToolResult::error('NO_INSTALLMENT_SYSTEMS');
        }

        $restrictions = $this->eligibility->evaluate([], $customerTypeId)['reasons'];
        $cap = $customerTypeId !== null ? $this->caps->capFor($customerTypeId) : null;

        return ToolResult::ok([
            'systems' => array_map(function (array $s) use ($machine, $customerTypeId, $cap) {
                $minimum = $this->minimumDownPayment($machine, $s, $cap);

                return [
                    'system_id' => $s['installment_system_id'],
                    'name' => $s['name'],
                    'minimum_down_payment' => $minimum,
                    'plans' => array_map(fn (array $p) => $this->planAtMinimum($machine, $s, $p, $customerTypeId, $minimum), $s['plans']),
                    'admin_fee_percent' => $s['administrative_fees_percent'],
                ];
            }, $systems),
            'restrictions' => $restrictions,
        ] + ($cap !== null ? ['financing_cap' => [
            'max_financed_amount' => $cap,
            'explain_to_customer' => $this->caps->explanation($cap, $customerTypeId),
            'note' => 'This customer type can finance at most max_financed_amount. When the price is higher, the '
                .'difference is already included in minimum_down_payment; cash_due_upfront = that down payment + '
                .'the admin fee. Tell the customer the cash_due_upfront and the monthly payment - never say the '
                .'motorcycle cannot be bought in installments.',
        ]] : []));
    }

    /** The system's own minimum, raised to whatever keeps the financed amount within a cap. */
    private function minimumDownPayment(Machine $machine, array $system, ?float $cap): float
    {
        $minimum = (float) ($system['minimum_down_payment'] ?? 0);

        if ($cap === null) {
            return $minimum;
        }

        return max($minimum, $this->caps->minimumDownPayment($machine, InstallmentSystem::findOrFail($system['installment_system_id']), $cap));
    }

    /**
     * Without an amount the model could only ask the customer to pick a plan
     * and a down payment before hearing a single number - "المقدم كام والقسط
     * كام" went unanswered twice in live testing. Same calculator as
     * calculate_installment, so the numbers agree. A plan whose financed
     * amount breaks a restriction for this customer type (e.g. a financing
     * cap) says so, instead of being quoted as if it were available.
     */
    private function planAtMinimum(Machine $machine, array $system, array $plan, ?int $customerTypeId, float $minimum): array
    {
        $row = ['plan_id' => $plan['installment_plan_id'], 'months' => $plan['months']];

        try {
            $result = $this->calculator->calculate(
                $machine,
                InstallmentSystem::findOrFail($system['installment_system_id']),
                InstallmentPlan::findOrFail($plan['installment_plan_id']),
                $minimum,
            );
        } catch (InstallmentCalculationException) {
            return $row + ['monthly_at_minimum_down_payment' => null];
        }

        $warnings = $customerTypeId !== null
            ? $this->eligibility->evaluate(['financed_amount' => $result->financedAmount], $customerTypeId)['reasons']
            : [];

        return $row + [
            'monthly_at_minimum_down_payment' => $result->monthlyInstallment,
            'admin_fee_at_minimum_down_payment' => $result->administrativeFees,
            'cash_due_upfront' => round($minimum + $result->administrativeFees),
        ] + ($warnings === [] ? [] : ['warnings' => $warnings]);
    }
}
