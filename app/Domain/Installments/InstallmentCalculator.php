<?php

namespace App\Domain\Installments;

use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;
use App\Models\Machine;

/**
 * DEC-01 (agreed 2026-09-23): reproduces the two formulas already live on
 * the old website calculator (formerly resources/views/layouts/app.blade.php) exactly,
 * unchanged - only the branch selector moved from name-matching
 * ("زيرو مصاريف") to InstallmentSystem::pricing_mode. No other formula,
 * default or rounding rule may be invented here. The MotoGate website now
 * calls this class too (InstallmentController::calculate), so the site and
 * the WhatsApp agent can never disagree on a number.
 */
class InstallmentCalculator
{
    public function calculate(
        Machine $machine,
        InstallmentSystem $system,
        InstallmentPlan $plan,
        float $downPayment,
        // Owner 2026-11-19: "عروض البوت" can lower a plan's rate for a customer type (20% for employees).
        ?int $customerTypeId = null,
    ): InstallmentCalculationResult {
        $cashPrice = (float) $machine->cash_price;
        $installmentPrice = (float) ($machine->installment_price ?: $cashPrice);

        if ($downPayment < 0) {
            throw new InstallmentCalculationException('DOWN_PAYMENT_INVALID', 'Down payment cannot be negative.');
        }

        if ($downPayment >= $cashPrice) {
            throw new InstallmentCalculationException(
                'DOWN_PAYMENT_TOO_HIGH',
                'Down payment must be less than the cash price.'
            );
        }

        if ($system->minimum_down_payment !== null && $downPayment < (float) $system->minimum_down_payment) {
            throw new InstallmentCalculationException(
                'DOWN_PAYMENT_BELOW_MINIMUM',
                "Down payment must be at least {$system->minimum_down_payment}."
            );
        }

        $months = $plan->months;
        [$interest] = app(\App\Domain\Offers\BotOfferService::class)
            ->interest((int) $system->id, (int) $months, (float) $plan->interest_percent, $customerTypeId, $machine->id ? (int) $machine->id : null);

        if ($system->isZeroFees()) {
            $financedAmount = $cashPrice - $downPayment;
            $plus75 = $financedAmount * 1.075;
            $totalWithInterest = $plus75 * (1 + $interest / 100);
            $administrativeFees = 0.0;
        } else {
            $financedAmount = $installmentPrice - $downPayment;
            $totalWithInterest = $financedAmount + ($financedAmount * $interest / 100);
            $administrativeFees = $financedAmount * ((float) $system->administrative_fees / 100);
        }

        $monthlyInstallment = round($totalWithInterest / $months);

        return new InstallmentCalculationResult(
            months: $months,
            interestPercent: $interest,
            downPayment: $downPayment,
            financedAmount: round($financedAmount, 2),
            totalWithInterest: round($totalWithInterest, 2),
            administrativeFees: round($administrativeFees, 2),
            monthlyInstallment: $monthlyInstallment,
        );
    }
}
