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

        $adminFeeList = $valid
            ->map(function ($item) {
                return '- ' . $item['machine_name'] . ': ' . $this->money($item['admin_fee'] ?? 0);
            })
            ->implode("\n");

        $deposit = (float) ($first['deposit'] ?? 0);
        $system = (string) ($first['system'] ?? '20');

        return [
            'ok' => true,
            'variables' => [
                'months' => (string) ($first['months'] ?? ''),
                'deposit' => $this->money($deposit),
                'deposit_text' => $deposit > 0 ? 'بمقدم ' . $this->money($deposit) : '',

                'system' => $system,
                'system_name' => $system === '30' ? 'نظام 30%' : 'نظام 20%',

                'machine_name' => (string) ($first['machine_name'] ?? ''),
                'monthly_payment' => $this->money($first['monthly_payment'] ?? 0),
                'admin_fee' => $this->money($first['admin_fee'] ?? 0),

                'installment_list' => $installmentList,
                'admin_fee_list' => $adminFeeList,

                'admin_fee_text' => $system === '20'
                    ? "المصاريف الإدارية:\n" . $adminFeeList
                    : 'النظام ده بدون مصاريف إدارية.',
            ],
        ];
    }

    private function money(float|int $amount): string
    {
        return number_format((float) $amount) . ' جنيه';
    }
}