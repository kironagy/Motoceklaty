<?php

namespace App\Agent\Tools;

use App\Domain\Applications\EligibilityService;
use App\Domain\Installments\InstallmentCalculationException;
use App\Domain\Installments\InstallmentCalculator;
use App\Models\CustomerType;
use App\Models\InstallmentPlan;
use App\Models\Machine;

/** READ — plan §6.7. The tool contains no rules; everything goes through EligibilityService (T11). */
class CheckEligibilityTool implements Tool
{
    public function __construct(
        private readonly EligibilityService $eligibility,
        private readonly InstallmentCalculator $calculator,
    ) {
    }

    public function name(): string
    {
        return 'check_eligibility';
    }

    public function description(): string
    {
        return 'Hypothetical eligibility check before or outside an application (e.g. "would a 20 year-old '
            .'qualify?"). Call it whenever the customer states his age, before saying anything about it - with months '
            .'when a duration is on the table (the age at the last installment is limited too). Do not use when an application exists and you only need its status - read '
            .'`eligibility` in the application snapshot instead. When he says what he works (before any application), pass '
            .'work = his own words: some work is refused by the finance companies and he must be told so plainly.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_type' => ['type' => 'string'],
                'work' => ['type' => 'string', 'description' => 'His own words about his work, e.g. "انا امين شرطة".'],
                'age' => ['type' => 'integer'],
                'months' => ['type' => 'integer', 'description' => 'Installment duration, to check the age at the last installment.'],
                'motorcycle_id' => ['type' => 'integer'],
                'plan_id' => ['type' => 'integer'],
                'down_payment' => ['type' => 'number', 'minimum' => 0],
            ],
        ];
    }

    public function permission(): string
    {
        return 'READ';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $customerTypeId = null;

        if (isset($args['customer_type'])) {
            $customerType = CustomerType::where('key', $args['customer_type'])->where('is_active', true)->first();

            if (! $customerType) {
                return ToolResult::error('UNKNOWN_CUSTOMER_TYPE');
            }

            $customerTypeId = $customerType->id;
        }

        $facts = [];

        if (filled($args['work'] ?? null)) {
            $facts['work_statement'] = (string) $args['work'];
        }

        // "age": 0 came with a work question and the age rule failed him -
        // the bot then made up "بتتحفظ على المهنة". No age = not checked.
        if (array_key_exists('age', $args) && (int) $args['age'] > 0) {
            $facts['age'] = $args['age'];
        }

        if (isset($args['months'])) {
            $facts['months'] = (int) $args['months'];
        }

        if (isset($args['motorcycle_id'])) {
            $machine = Machine::where('is_active', true)->find($args['motorcycle_id']);

            if (! $machine) {
                return ToolResult::error('UNKNOWN_MOTORCYCLE');
            }

            $facts['financed_amount'] = $this->financedAmount($machine, $args);
        }

        $result = $this->eligibility->evaluate($facts, $customerTypeId);

        if (filled($facts['work_statement'] ?? null)
            && ($problem = app(\App\Domain\Applications\WorkClassification::class)->problem($ctx->conversationId, 'employee', $facts['work_statement']))) {
            if ($problem['code'] === 'OCCUPATION_NOT_ACCEPTED') {
                $result['occupation_not_accepted'] = $problem['hint'];
            } elseif ($problem['code'] === 'ASK_SECTOR') {
                // "انا مدرس" was told teachers are refused: a private school is fine.
                $result['ask_sector'] = $problem['hint'].' Do not say his work is refused or accepted before he answers.';
            }
        }

        return ToolResult::ok($result);
    }

    private function financedAmount(Machine $machine, array $args): ?float
    {
        if (! isset($args['plan_id'])) {
            return null;
        }

        $plan = InstallmentPlan::with('installmentSystem')->where('is_active', true)->find($args['plan_id']);
        $linked = $plan && $machine->installmentSystems()->where('installment_systems.id', $plan->installment_system_id)->exists();

        if (! $plan || ! $linked) {
            return null;
        }

        try {
            $result = $this->calculator->calculate(
                $machine,
                $plan->installmentSystem,
                $plan,
                (float) ($args['down_payment'] ?? 0)
            );
        } catch (InstallmentCalculationException) {
            return null;
        }

        return $result->financedAmount;
    }
}
