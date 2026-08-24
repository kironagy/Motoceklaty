<?php

namespace App\Services;

use App\Models\Machine;

class InstallmentCalculator
{
    /**
     * سقف تمويل الدخل الحر (ميموري "قواعد الدخل الحر"): لو سعر التقسيط
     * أعلى من المبلغ ده والعميل دخل حر، الفرق يتدفع مقدم إجباري، والقسط
     * والمصاريف الإدارية بيتحسبوا على السقف بس مش على السعر الكامل.
     */
    private const FREELANCE_FINANCE_CAP = 60000;

    public function calculate(
        Machine $machine,
        int $months,
        float $deposit = 0,
        string $system = '20',
        bool $isFreelance = false
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

        $financeBasePrice = $installmentPrice;
        $freelanceExtraDeposit = 0.0;

        if ($isFreelance && $installmentPrice > self::FREELANCE_FINANCE_CAP) {
            $financeBasePrice = self::FREELANCE_FINANCE_CAP;
            $freelanceExtraDeposit = $installmentPrice - self::FREELANCE_FINANCE_CAP;
        }

        if ($deposit > $financeBasePrice) {
            $deposit = $financeBasePrice;
        }

        $years = $months / 12;

        $annualRate = $system === '30' ? 30 : 20;
        $totalRate = $annualRate * $years;

        $financeAmount = $financeBasePrice - $deposit;
        $interestAmount = $financeAmount * ($totalRate / 100);
        $totalAfterInterest = $financeAmount + $interestAmount;

        $monthlyPayment = (int) round($totalAfterInterest / $months);

        /*
         * المصاريف الإدارية تتحسب على المبلغ بعد المقدم.
         * لو مفيش مقدم، financeAmount هيبقى نفس سعر التقسيط (أو سقف
         * الدخل الحر لو العميل دخل حر ومتخطي السقف).
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
            'finance_base_price' => $financeBasePrice,
            'finance_amount' => $financeAmount,
            'interest_amount' => $interestAmount,
            'total_after_interest' => $totalAfterInterest,

            'monthly_payment' => $monthlyPayment,
            'admin_fee' => $adminFee,
            'has_admin_fee' => $system === '20',

            'is_freelance' => $isFreelance,
            'freelance_extra_deposit' => $freelanceExtraDeposit,
        ];
    }

    public function calculateMany(
        iterable $machines,
        int $months,
        float $deposit = 0,
        string $system = '20',
        bool $isFreelance = false
    ): array {
        $items = [];

        foreach ($machines as $machine) {
            if ($machine instanceof Machine) {
                $items[] = $this->calculate($machine, $months, $deposit, $system, $isFreelance);
            }
        }

        return $items;
    }
}