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
            .'this customer is picked automatically; you get, per duration, the down payment (usually none), the admin fee paid '
            .'at pickup, and the exact monthly payment - the `say` line has them worded correctly. Do NOT name the '
            .'system/company unless he asks who finances it. Pass months when he named a duration, down_payment when he '
            .'named an amount, no_upfront=true only when he insists on paying nothing at all at pickup (not even the fees). '
            .'Use get_installment_options only when he asks to compare systems/companies.';
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
                'no_upfront' => ['type' => 'boolean', 'description' => 'true only when the customer insists on paying nothing at pickup - no down payment and no admin fees.'],
                'customer_type' => ['type' => 'string', 'description' => 'Only a type the customer stated (or the open application\'s). Omit when unknown.'],
                'governorate' => ['type' => 'string', 'description' => 'Governorate key (cairo, giza, ...) only if the customer said where he lives.'],
                'age' => ['type' => 'integer', 'description' => 'The customer\'s age if he stated it - durations that end past the age limit are left out.'],
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

        // He already chose a duration: a freelancer's financing cap then
        // listed three durations again and asked him to pick. He gets the
        // numbers for the duration he chose.
        if ($months === null && $ctx->activeApplicationId) {
            $months = Application::whereKey($ctx->activeApplicationId)->first()?->installmentPlan?->months;
        }
        $downPayment = isset($args['down_payment']) ? (float) $args['down_payment'] : null;
        $governorate = $args['governorate'] ?? null;
        $noUpfront = ($args['no_upfront'] ?? false) === true;

        $offers = $this->offers->offers($machine, $customerTypeId, $governorate, $months, $downPayment, $noUpfront);

        // A 62-year-old was offered 3 years; the last installment must fall
        // by the age limit (eligibility rule max_age_at_end).
        $maxMonths = $this->maxMonthsForAge(isset($args['age']) ? (int) $args['age'] : $this->applicantAge($ctx));

        if ($maxMonths !== null) {
            $tooLong = array_filter($offers, fn ($o) => $o['months'] > $maxMonths);
            $offers = array_values(array_filter($offers, fn ($o) => $o['months'] <= $maxMonths));

            if ($offers === [] && $tooLong !== []) {
                return ToolResult::error('DURATION_EXCEEDS_AGE_LIMIT', 'At his age the longest allowed duration is '.$maxMonths
                    .' months. Tell him politely; offer only durations up to that.');
            }
        }

        if ($offers === [] && $noUpfront) {
            return ToolResult::error('NO_ZERO_UPFRONT_PLAN', 'No plan without any payment at pickup for this motorcycle. '
                .'Tell him the admin fees are the only thing paid at pickup (no down payment) and quote the normal offer.');
        }

        if ($offers === [] && $months !== null) {
            $available = array_column($this->offers->offers($machine, $customerTypeId, $governorate, null, $downPayment, $noUpfront), 'months');

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
            'installment_price' => (float) ($machine->installment_price ?: $machine->cash_price),
            'offers' => array_map(fn (array $o) => [
                'months' => $o['months'],
                'cash_due_upfront' => $o['cash_due_upfront'],
                'monthly_payment' => $o['monthly_payment'],
                'admin_fee_at_pickup' => $o['admin_fee'],
                // what he pays in all: fees/down payment + every installment
                'total_paid' => round($o['cash_due_upfront'] + $o['monthly_payment'] * $o['months']),
                'breakdown' => $this->breakdown($machine, $o),
                'say' => $this->line($o),
                // internal: pass these to update_application_selection when he picks this offer - never say them
                'installment_system' => $o['system'],
                'down_payment' => $o['down_payment'],
            ], $shown),
            'first_payment_after_days' => $this->firstPaymentAfterDays(),
            // the owner's wording of why installments cost more than cash
            'price_difference_policy' => (string) config('agent.installments.price_difference_explanation'),
            'how_to_present' => 'Send the `say` lines as they are (one per line, you may reword lightly, but keep every number and '
                .'keep saying whether there are admin fees). Admin fees are NOT a down payment - never call them "مقدم". '
                .'If he asks why installments cost more than cash, explain it in your own short words from price_difference_policy (never copied word for word, never adding reasons it does not give), '
                .'then offer the cash price. If he asks what he pays in the end, send the offer\'s `breakdown` as it is - '
                .'installment_price is the price the installment is calculated on, never the total he pays. '
                .'Add once: "'.$this->firstPaymentLine().'". No system/company names, no word "نظام"/"أنظمة".'.($others !== [] ? ' Say other durations exist ('.implode('/', array_map(fn ($m) => $this->duration($m), $others)).').' : ''),
        ] + ($capped ? ['explain_to_customer' => $this->caps->explanation((float) $capped['cap'], $customerTypeId)] : []));
    }

    /**
     * "مقدم 1,750" was the admin fee: the customer said he would not pay a
     * down payment and was told the plan needs one. The showroom takes none -
     * the fee is paid at pickup and is named as such.
     */
    private function line(array $offer): string
    {
        $down = (float) $offer['down_payment'];
        $fee = (float) $offer['admin_fee'];

        $upfront = match (true) {
            $down > 0 && $fee > 0 => 'مقدم '.number_format($down).' + مصاريف إدارية '.number_format($fee).' وقت الاستلام',
            $down > 0 => 'مقدم '.number_format($down).' ومن غير مصاريف إدارية',
            $fee > 0 => 'من غير مقدم، بس فيه مصاريف إدارية '.number_format($fee).' بتدفعها وقت الاستلام',
            default => 'من غير مقدم ومن غير أي مصاريف',
        };

        return $this->duration($offer['months']).': '.$upfront.'، والقسط '.number_format($offer['monthly_payment']).' جنيه في الشهر';
    }

    /**
     * "الـ46 ألف ده إجمالي اللي بتدفعه" - the installment price was sold as
     * the total. When he asks why it costs more or what he pays in the end,
     * this sentence is the answer, built from the same numbers.
     */
    private function breakdown(Machine $machine, array $offer): string
    {
        $installmentPrice = (float) ($machine->installment_price ?: $machine->cash_price);
        $upfront = (float) $offer['cash_due_upfront'];

        return 'سعرها كاش '.number_format((float) $machine->cash_price).' جنيه، وسعرها بالتقسيط '.number_format($installmentPrice)
            .' جنيه وده السعر اللي القسط بيتحسب عليه. على '.$this->duration($offer['months']).': '
            .($upfront > 0 ? number_format($upfront).' جنيه وقت الاستلام + ' : '')
            .$offer['months'].' قسط × '.number_format($offer['monthly_payment']).' جنيه = '
            .number_format(round($upfront + $offer['monthly_payment'] * $offer['months'])).' جنيه في الآخر.';
    }

    private function maxMonthsForAge(?int $age): ?int
    {
        if ($age === null) {
            return null;
        }

        $maxAtEnd = \App\Models\EligibilityRule::where('rule_type', 'age_range')->where('is_active', true)->get()
            ->map(fn ($r) => $r->params['max_age_at_end'] ?? null)->filter()->min();

        return $maxAtEnd === null ? null : max(0, ((int) $maxAtEnd - $age) * 12);
    }

    /** Age from the national ID on the open application, if read already. */
    private function applicantAge(ToolContext $ctx): ?int
    {
        $application = $ctx->activeApplicationId ? Application::find($ctx->activeApplicationId) : null;

        if (! $application) {
            return null;
        }

        $nationalId = \App\Models\ApplicationData::where('application_id', $application->id)->where('party', 'applicant')
            ->where('field_key', 'national_id')->where('status', 'valid')->value('value')
            ?? \App\Models\CustomerAttribute::where('customer_id', $application->customer_id)
                ->where('field_key', 'national_id')->where('status', 'valid')->value('value');

        $parsed = $nationalId ? app(\App\Support\EgyptianNationalId::class)->parse((string) $nationalId) : [];

        return isset($parsed['age']) ? (int) $parsed['age'] : null;
    }

    private function firstPaymentAfterDays(): int
    {
        return (int) config('agent.installments.first_payment_after_days', 45);
    }

    private function firstPaymentLine(): string
    {
        return 'أول قسط بيبدأ بعد '.$this->firstPaymentAfterDays().' يوم من الاستلام';
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
