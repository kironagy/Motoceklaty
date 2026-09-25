<?php

namespace App\Domain\Installments;

use App\Models\EligibilityRule;
use App\Models\InstallmentSystem;
use App\Models\Machine;

/**
 * A financing cap (e.g. عامل حر: 60,000 at most) does not refuse the
 * customer - it moves the rest of the price to the down payment. On a 66,000
 * motorcycle with a 60,000 cap the customer pays the 6,000 difference plus
 * the administrative fees in cash, and finances 60,000. The tools used to
 * only warn FINANCING_CAP_EXCEEDED and the model quoted no numbers at all.
 */
class FinancingCapPolicy
{
    /** The tightest active financing cap for this customer type, if any. */
    public function capFor(?int $customerTypeId): ?float
    {
        $caps = EligibilityRule::query()
            ->where('is_active', true)
            ->where('rule_type', 'financing_cap')
            ->where(function ($q) use ($customerTypeId) {
                $q->whereNull('customer_type_id');

                if ($customerTypeId !== null) {
                    $q->orWhere('customer_type_id', $customerTypeId);
                }
            })
            ->get()
            ->map(fn (EligibilityRule $rule) => (float) ($rule->params['max_amount'] ?? 0))
            ->filter(fn (float $max) => $max > 0);

        return $caps->isEmpty() ? null : $caps->min();
    }

    /** One sentence the agent can say as is - the reason behind the bigger down payment. */
    public function explanation(float $cap, ?int $customerTypeId): string
    {
        $label = $customerTypeId !== null ? \App\Models\CustomerType::whereKey($customerTypeId)->value('label') : null;

        return ($label ? "بما إن شغلك {$label}، " : '').'أقصى مبلغ بيتقسط '.number_format($cap).' جنيه، '
            .'فالفرق عن سعر المكنة بيتدفع كاش في الأول مع المصاريف الإدارية، والباقي بيتقسط.';
    }

    /** The customer-type cap and the system's own cap (dashboard), whichever is tighter. */
    public function capForSystem(InstallmentSystem $system, ?int $customerTypeId): ?float
    {
        $caps = array_filter([$this->capFor($customerTypeId), $system->max_financed_amount ?: null]);

        return $caps === [] ? null : (float) min($caps);
    }

    /** The smallest down payment that keeps the financed amount within the cap. */
    public function minimumDownPayment(Machine $machine, InstallmentSystem $system, float $cap): float
    {
        // mirrors InstallmentCalculator: which price gets financed depends on the system
        $base = $system->isZeroFees()
            ? (float) $machine->cash_price
            : (float) ($machine->installment_price ?: $machine->cash_price);

        return max(0.0, round($base - $cap, 2));
    }
}
