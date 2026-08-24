<?php

namespace App\Services;

class InstallmentVariablesBuilder
{
    public function build(array $calculations): array
    {
        $valid = collect($calculations)
            ->filter(fn ($item) => ($item['ok'] ?? false) === true)
            ->values();

        if ($valid->isEmpty()) {
            return [
                'ok' => false,
                'variables' => [],
            ];
        }

        $first = $valid->first();

        $installmentList = $valid
            ->map(function ($item) {
                return '- ' . $item['machine_name'] . ': ' . $this->money($item['monthly_payment']) . ' شهريًا';
            })
            ->implode("\n");

        $deposit = (float) ($first['deposit'] ?? 0);
        $system = (string) ($first['system'] ?? '20');
        $freelanceExtraDeposit = (float) ($first['freelance_extra_deposit'] ?? 0);
        $totalDepositDue = $deposit + $freelanceExtraDeposit;

        $adminFeeList = $valid
            ->map(function ($item) use ($totalDepositDue) {
                $adminFee = (float) ($item['admin_fee'] ?? 0);
                $totalAtSigning = $totalDepositDue + $adminFee;

                return '- ' . $item['machine_name'] . ': ' . $this->money($adminFee)
                    . ' (يعني إجمالي المطلوب عند التعاقد ' . $this->money($totalAtSigning) . ')';
            })
            ->implode("\n");

        $freelanceCapText = $freelanceExtraDeposit > 0
            ? "\n\nالسعر ده أعلى من سقف تمويل الدخل الحر (60,000 جنيه)، فالقسط والمصاريف الإدارية متحسبين على 60,000 جنيه بس، والفرق (" . $this->money($freelanceExtraDeposit) . ") لازم يتدفع مقدم إضافي مع باقي المقدم."
            : '';

        return [
            'ok' => true,
            'variables' => [
                'months' => (string) ($first['months'] ?? ''),
                'deposit' => $this->money($deposit),
                'deposit_text' => $totalDepositDue > 0 ? 'بمقدم ' . $this->money($totalDepositDue) : '',

                'system' => $system,
                'system_name' => $system === '30' ? 'نظام 30%' : 'نظام 20%',

                'machine_name' => (string) ($first['machine_name'] ?? ''),
                'monthly_payment' => $this->money($first['monthly_payment'] ?? 0),
                'admin_fee' => $this->money($first['admin_fee'] ?? 0),

                'installment_list' => $installmentList,
                'admin_fee_list' => $adminFeeList,

                'freelance_extra_deposit' => $this->money($freelanceExtraDeposit),
                'freelance_cap_text' => $freelanceCapText,

                'admin_fee_text' => ($system === '20'
                    ? "المصاريف الإدارية (بتتدفع مع المقدم عند التعاقد، مش شهريًا):\n" . $adminFeeList
                    : 'النظام ده بدون مصاريف إدارية.') . $freelanceCapText,
            ],
        ];
    }

    private function money(float|int $amount): string
    {
        return number_format((float) $amount) . ' جنيه';
    }
}