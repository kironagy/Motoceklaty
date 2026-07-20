<?php

namespace App\Services;

use App\Models\Machine;

class InstallmentCalculator
{
    public function calculate(
        Machine $machine,
        int $months,
        float $deposit = 0,
        string $system = '20'
    ): array {
        $months = max(1, $months);
        $deposit = max(0, $deposit);
        $system = $system === '30' ? '30' : '20';

        $installmentPrice = (float) ($machine->installment_price ?? 0);

        if ($installmentPrice <= 0) {
            return [
                'ok' => false,
                'machine_id' => $machine->id,
                'machine_name' => $machine->name,
                'error' => 'missing_installment_price',
            ];
        }

        if ($deposit > $installmentPrice) {
            $deposit = $installmentPrice;
        }

        $years = $months / 12;

        $annualRate = $system === '30' ? 30 : 20;
        $totalRate = $annualRate * $years;

        $financeAmount = $installmentPrice - $deposit;
        $interestAmount = $financeAmount * ($totalRate / 100);
        $totalAfterInterest = $financeAmount + $interestAmount;

        $monthlyPayment = (int) round($totalAfterInterest / $months);

        /*
         * المصاريف الإدارية تتحسب على المبلغ بعد المقدم.
         * لو مفيش مقدم، financeAmount هيبقى نفس سعر التقسيط.
         */
        $adminFee = $system === '20'
            ? (int) round($financeAmount * 0.07)
            : 0;

        return [
            'ok' => true,

            'machine_id' => $machine->id,
            'machine_name' => $machine->name,

            'months' => $months,
            'deposit' => $deposit,

            'system' => $system,
            'annual_rate' => $annualRate,
            'total_rate' => $totalRate,

            'installment_price' => $installmentPrice,
            'finance_amount' => $financeAmount,
            'interest_amount' => $interestAmount,
            'total_after_interest' => $totalAfterInterest,

            'monthly_payment' => $monthlyPayment,
            'admin_fee' => $adminFee,
            'has_admin_fee' => $system === '20',
        ];
    }

    public function calculateMany(
        iterable $machines,
        int $months,
        float $deposit = 0,
        string $system = '20'
    ): array {
        $items = [];

        foreach ($machines as $machine) {
            if ($machine instanceof Machine) {
                $items[] = $this->calculate($machine, $months, $deposit, $system);
            }
        }

        return $items;
    }
}