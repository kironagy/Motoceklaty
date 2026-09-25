<?php

namespace App\Agent\Tools;

use App\Domain\Installments\BestOfferService;
use App\Domain\Installments\FinancingCapPolicy;
use App\Models\Application;
use App\Models\CustomerType;
use App\Models\Machine;

/** READ - the default answer to any installment question. */
class GetInstallmentOfferTool implements Tool
{
    public function __construct(
        private readonly BestOfferService $offers,
        private readonly FinancingCapPolicy $caps,
    ) {
    }

    public function name(): string
    {
        return 'get_installment_offer';
    }

    public function description(): string
    {
        return 'THE tool for any installment question ("القسط كام", "على سنة", "أقل مقدم", "ينفع أقسط"). The best system for '
            .'this customer is picked automatically; you get, per duration, the cash he pays up front (cash_due_upfront, '
            .'admin fees included) and the monthly payment. Give him those two numbers per duration - do NOT name the '
            .'system/company unless he asks who finances it. Pass months when he named a duration, down_payment when he '
            .'named an amount. Use get_installment_options only when he asks to compare systems/companies.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['motorcycle_id'],
            'properties' => [
                'motorcycle_id' => ['type' => 'integer'],
                'months' => ['type' => 'integer', 'minimum' => 1],
                'down_payment' => ['type' => 'number', 'minimum' => 0],
                'customer_type' => ['type' => 'string', 'description' => 'Only a type the customer stated (or the open application\'s). Omit when unknown.'],
                'governorate' => ['type' => 'string', 'description' => 'Governorate key (cairo, giza, ...) only if the customer said where he lives.'],
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
            ? Application::whereKey($ctx->activeApplicationId)->value('customer_type_id')
            : null;

        if (isset($args['customer_type'])) {
            $customerType = CustomerType::where('key', $args['customer_type'])->where('is_active', true)->first();

            if (! $customerType) {
                return ToolResult::error('UNKNOWN_CUSTOMER_TYPE');
            }

            $customerTypeId = $customerType->id;
        }

        $months = isset($args['months']) ? (int) $args['months'] : null;
        $downPayment = isset($args['down_payment']) ? (float) $args['down_payment'] : null;
        $governorate = $args['governorate'] ?? null;

        $offers = $this->offers->offers($machine, $customerTypeId, $governorate, $months, $downPayment);

        if ($offers === [] && $months !== null) {
            $available = array_column($this->offers->offers($machine, $customerTypeId, $governorate, null, $downPayment), 'months');

            return ToolResult::error('DURATION_NOT_AVAILABLE', 'No plan for '.$months.' months. Available durations: '
                .implode(', ', $available).' - offer the closest ones.');
        }

        if ($offers === []) {
            return ToolResult::error('NO_INSTALLMENT_SYSTEMS');
        }

        \App\Domain\Conversations\QuotedMotorcycle::remember($ctx->conversationId, $machine->id);

        $capped = collect($offers)->firstWhere('cap', '!==', null);

        // Four durations in a row read like a price list: the shortest, one
        // in the middle and the longest, the rest only named.
        $shown = $months === null && count($offers) > 3
            ? [$offers[0], $offers[intdiv(count($offers) - 1, 2)], $offers[count($offers) - 1]]
            : $offers;
        $others = array_values(array_diff(array_column($offers, 'months'), array_column($shown, 'months')));

        return ToolResult::ok([
            'cash_price' => (float) $machine->cash_price,
            'offers' => array_map(fn (array $o) => [
                'months' => $o['months'],
                'cash_due_upfront' => $o['cash_due_upfront'],
                'monthly_payment' => $o['monthly_payment'],
                'say' => $this->line($o),
                // internal: pass these to update_application_selection when he picks this offer - never say them
                'installment_system' => $o['system'],
                'down_payment' => $o['down_payment'],
            ], $shown),
            'how_to_present' => 'Send the `say` lines as they are (one per line, you may reword lightly). No system/company names, '
                .'no word "نظام"/"أنظمة".'.($others !== [] ? ' Say other durations exist ('.implode('/', array_map(fn ($m) => $this->duration($m), $others)).').' : ''),
        ] + ($capped ? ['explain_to_customer' => $this->caps->explanation((float) $capped['cap'], $customerTypeId)] : []));
    }

    private function line(array $offer): string
    {
        $upfront = $offer['cash_due_upfront'] > 0 ? 'مقدم '.number_format($offer['cash_due_upfront']) : 'من غير مقدم';

        return $this->duration($offer['months']).': '.$upfront.' والقسط '.number_format($offer['monthly_payment']).' في الشهر';
    }

    private function duration(int $months): string
    {
        return match ($months) {
            6 => '٦ شهور',
            12 => 'سنة',
            18 => 'سنة ونص',
            24 => 'سنتين',
            36 => '٣ سنين',
            48 => '٤ سنين',
            default => $months.' شهر',
        };
    }
}
