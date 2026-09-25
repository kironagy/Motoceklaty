<?php

namespace App\Domain\Installments;

final class InstallmentCalculationResult
{
    public function __construct(
        public readonly int $months,
        public readonly float $interestPercent,
        public readonly float $downPayment,
        public readonly float $financedAmount,
        public readonly float $totalWithInterest,
        public readonly float $administrativeFees,
        public readonly float $monthlyInstallment,
    ) {
    }

    public function toArray(): array
    {
        return [
            'months' => $this->months,
            'interest_percent' => $this->interestPercent,
            'down_payment' => $this->downPayment,
            'financed_amount' => $this->financedAmount,
            'total_with_interest' => $this->totalWithInterest,
            'administrative_fees' => $this->administrativeFees,
            'monthly_installment' => $this->monthlyInstallment,
        ];
    }
}
