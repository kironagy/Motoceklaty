<?php

namespace App\Agent\Tools;

use App\Domain\Applications\EligibilityService;
use App\Domain\Installments\InstallmentCalculationException;
use App\Domain\Installments\InstallmentCalculator;
use App\Models\CustomerType;
use App\Models\InstallmentPlan;
use App\Models\Machine;

/** READ — plan §6.7. The tool contains no rules; everything goes through EligibilityService (T11). */
class CheckEligibilityTool implements ReadTool
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
                'about' => ['type' => 'string', 'enum' => ['customer', 'other_applicant'], 'description' => 'Whose age/work this is: customer = the one chatting; other_applicant = the person applying instead of him (his mother, brother...). Default customer.'],
                'customer_type' => ['type' => 'string'],
                'work' => ['type' => 'string', 'description' => 'His own words about his work, e.g. "انا امين شرطة".'],
                'age' => ['type' => 'integer'],
                'monthly_income' => ['type' => 'number', 'description' => 'Net monthly income or pension he stated, e.g. "المعاش ٢٠٠٠" = 2000.'],
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
            // real-customer run 2026-10-07: an age he never wrote ("يا ابني" read as 65) is no fact
            if (! app(\App\Domain\Conversations\CustomerStatements::class)->saidNumber($ctx->conversationId, (int) $args['age'])) {
                return ToolResult::error('AGE_NOT_STATED', 'He never wrote this age. Do not guess it: his ID decides; leave age out.');
            }

            $facts['age'] = $args['age'];
        }

        if (isset($args['months'])) {
            $facts['months'] = (int) $args['months'];
        }

        // Replay of conversation 206: "المعاش ٢٠٠٠ج هينفع؟" could not be
        // checked (no income input) and got "عمره كام؟" instead of an answer.
        if (isset($args['monthly_income']) && (float) $args['monthly_income'] > 0) {
            $facts['monthly_income'] = (float) $args['monthly_income'];
        }

        if (isset($args['motorcycle_id'])) {
            $machine = Machine::where('is_active', true)->find($args['motorcycle_id']);

            if (! $machine) {
                return ToolResult::error('UNKNOWN_MOTORCYCLE');
            }

            $facts['financed_amount'] = $this->financedAmount($machine, $args);
        }

        $result = $this->eligibility->evaluate($facts, $customerTypeId);

        // QA 2026-10-04: "١٩ سنه" was refused, then "لا انا ٢٢، اكتب ٢٢" got
        // "أنت مؤهل للتقسيط". A stated age only screens; the ID decides.
        // Conversation 206: his mother's 45 and his brother's 21 were each
        // taken as HIM changing his age after the refusal at 20.
        $aboutOther = ($args['about'] ?? 'customer') === 'other_applicant';

        if (isset($facts['age']) && ($result['status'] ?? null) === 'eligible') {
            if (! $aboutOther && $this->ageRefusedEarlier($ctx->conversationId)) {
                // what to do with it is in the instructions (rebuild: facts only here)
                return ToolResult::ok([
                    'status' => 'needs_id',
                    'reasons' => [['code' => 'AGE_CHANGED_AFTER_REFUSAL']],
                ]);
            }

            $result['decided_by'] = 'stated_age';
        }

        // Replayed 2026-10-04: "سبت التدريس وبشتغل سواق اوبر" was checked for
        // his work and answered "عندك عمرك كام؟" - the missing age in the
        // result read as a question to ask. The age comes from his ID.
        if (! isset($facts['age'])) {
            $result['age_checked'] = false;
        }

        if (filled($facts['work_statement'] ?? null)
            && ($problem = app(\App\Domain\Applications\WorkClassification::class)->problem($ctx->conversationId, 'employee', $facts['work_statement']))) {
            if ($problem['code'] === 'APPLICANT_HAS_NO_WORK') {
                // "امي مش شغاله بس عندها شقه تمليك" was asked her age and ID:
                // the one who applies must work or be on a pension.
                $result['status'] = 'not_eligible';
                $result['reasons'] = array_merge($result['reasons'] ?? [], [['code' => 'APPLICANT_HAS_NO_WORK']]);
            } elseif ($problem['code'] === 'OCCUPATION_NOT_ACCEPTED') {
                $result['occupation_not_accepted'] = $problem['hint'];
            } elseif ($problem['code'] === 'ASK_SECTOR') {
                // "انا مدرس" was told teachers are refused: a private school is fine.
                $result['ask_sector'] = $problem['hint'];
            }
        }

        if (($result['status'] ?? null) === 'not_eligible' && \App\Domain\Applications\OtherApplicant::appliesTo(array_column($result['reasons'] ?? [], 'code'))) {
            $result['other_applicant_line'] = \App\Domain\Applications\OtherApplicant::line(
                $aboutOther || (app(\App\Domain\Applications\WorkProfiles::class)->get($ctx->conversationId)['applicant'] ?? null) === 'someone_else'
            );
        }

        return ToolResult::ok($result);
    }

    private function ageRefusedEarlier(int $conversationId): bool
    {
        return \App\Models\AiTraceStep::where('tool_name', 'check_eligibility')
            ->whereIn('trace_id', \App\Models\AiTrace::where('conversation_id', $conversationId)->select('id'))
            ->where('result_redacted', 'like', '%AGE_OUT_OF_RANGE%')
            ->exists();
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
