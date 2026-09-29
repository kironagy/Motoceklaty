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
        return 'THE tool for any installment question ("القسط كام", "على سنة", "أقل مقدم", "ينفع أقسط", "إجمالي السعر كام", "هدفع كام في الآخر" - the offer\'s `breakdown` is the total). The best system for '
            .'this customer is picked automatically. With no duration named you get the durations to ask him about (no numbers); '
            .'with months (or months_list / all_durations when he asked for more than one) you get, per duration, the down payment '
            .'(usually none), the admin fee paid at pickup, and the exact monthly payment - the `say` line has them worded correctly. Do NOT name the '
            .'system/company unless he asks who finances it. Pass months when he named a duration, down_payment when he '
            .'named an amount, no_upfront=true when he asks for a plan without the admin fees / paying nothing at pickup '
            .'("مفيش نظام من غير مصاريف؟", "بدون مصاريف") - never answer that question without this call; only its '
            .'NO_ZERO_UPFRONT_PLAN error means there is none. '
            .'Use get_installment_options only when he asks to compare systems/companies.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['motorcycle_id'],
            'properties' => [
                'motorcycle_id' => ['type' => 'integer'],
                'months' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The one duration he named ("على سنة" = 12, "سنة ونص" = 18, "سنتين" = 24, "٣ سنين" = 36).'],
                'months_list' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'description' => 'Only when he asked for more than one duration ("احسبهالي على سنة وسنتين").'],
                'all_durations' => ['type' => 'boolean', 'description' => 'true only when he asked for every duration ("قولي كل المدد", "على كل الفترات"). Without months, months_list or this, you get the durations to ask him about - no numbers.'],
                'down_payment' => ['type' => 'number', 'minimum' => 0],
                'no_upfront' => ['type' => 'boolean', 'description' => 'true when the customer asks for a plan with nothing paid at pickup - no down payment and no admin fees.'],
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

        // The owner: above the freelancer cap (60,000) the offer depends on
        // his work, so the work comes first; below it the numbers come first.
        if ($customerTypeId === null && ($capLabels = $this->cappedWorkTypes($machine)) !== null) {
            return ToolResult::error('ASK_WORK_FIRST', 'Before any installment number, tell him the cash price ('
                .number_format((float) $machine->cash_price).' جنيه) and ask only "حضرتك بتشتغل إيه؟" - for this motorcycle the '
                .'installment depends on his work ('.$capLabels.' pay part of the price at pickup). When he answers, call again with customer_type.');
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
        $cap = $capped ? ['explain_to_customer' => $this->caps->explanation((float) $capped['cap'], $customerTypeId)] : [];

        // The owner (2026-09-29, conversation 708): "القسط كام؟" is not
        // answered with every duration - he is asked which one first, and
        // gets several only when he asks for more than one.
        $asked = array_values(array_unique(array_map('intval', (array) ($args['months_list'] ?? []))));

        if ($months === null && $asked === [] && ($args['all_durations'] ?? false) !== true) {
            return ToolResult::ok([
                'cash_price' => (float) $machine->cash_price,
                'durations' => array_map(fn (array $o) => $this->duration($o['months']), $offers),
                'how_to_present' => 'He did not name a duration: ask him which duration he wants, naming these durations only - '
                    .'no installment, fee or total numbers yet (e.g. "حابب تقسطها على قد إيه؟ سنة، ولا سنة ونص، ولا سنتين، ولا ٣ سنين؟"). '
                    .'When he answers, call again with months (or months_list if he wants more than one compared).',
            ] + $cap);
        }

        $shown = $asked === [] ? $offers : array_values(array_filter($offers, fn ($o) => in_array($o['months'], $asked, true)));

        if ($shown === []) {
            return ToolResult::error('DURATION_NOT_AVAILABLE', 'No plan for '.implode(', ', $asked).' months. Available durations: '
                .implode(', ', array_column($offers, 'months')).' - offer the closest ones.');
        }

        return ToolResult::ok([
            'cash_price' => (float) $machine->cash_price,
            'offers' => array_map(fn (array $o) => [
                'months' => $o['months'],
                'cash_due_upfront' => $o['cash_due_upfront'],
                'monthly_payment' => $o['monthly_payment'],
                'admin_fee_at_pickup' => $o['admin_fee'],
                // what he pays in all: fees/down payment + every installment
                // the owner: the total is the motorcycle's - down payment (if any) + every installment.
                // The admin fee is said on its own, never added to it.
                'total_paid' => round($o['down_payment'] + $o['monthly_payment'] * $o['months']),
                'breakdown' => $this->breakdown($machine, $o),
                'say' => $this->line($o),
                // internal: pass these to update_application_selection when he picks this offer - never say them
                'installment_system' => $o['system'],
                'down_payment' => $o['down_payment'],
            ], $shown),
            // once with the offer, or when he asks (owner's instructions)
            'first_payment' => self::firstPaymentLine(),
            // the owner's wording of why installments cost more than cash
            'price_difference_policy' => (string) config('agent.installments.price_difference_explanation'),
            'how_to_present' => 'Send the `say` lines as they are (one per line, you may reword lightly, but keep every number and '
                .'keep saying whether there are admin fees). Admin fees are NOT a down payment - never call them "مقدم". '
                .'If he asks why installments cost more than cash, explain it in your own short words from price_difference_policy (never copied word for word, never adding reasons it does not give), '
                .'then offer the cash price. If he asks what he pays in the end, send the offer\'s `breakdown` as it is. '
                .'Never tell him the installment price of the motorcycle (the price the installment is calculated on). '
                .'Say `first_payment` once with the offer (or when he asks). Never write the words "من غير مقدم" / "بدون مقدم" - say what is paid at pickup instead. No system/company names, no word "نظام"/"أنظمة". Quote only the offers below (the duration(s) he asked for), each with its numbers.',
        ] + $cap
        );
    }

    /**
     * A plasterer was quoted "من غير مقدم" and, once his application was
     * opened as عامل حر, got a 15,000 down payment. While his work is not
     * known, a motorcycle above a work-type cap is not quoted at all.
     */
    private function cappedWorkTypes(Machine $machine): ?string
    {
        $price = (float) ($machine->installment_price ?: $machine->cash_price);
        $capped = \App\Models\EligibilityRule::where('is_active', true)->where('rule_type', 'financing_cap')->whereNotNull('customer_type_id')->get()
            ->filter(fn ($r) => ($cap = (float) ($r->params['max_amount'] ?? 0)) > 0 && $cap < $price);

        return $capped->isEmpty() ? null : \App\Models\CustomerType::whereIn('id', $capped->pluck('customer_type_id'))->pluck('label')->implode(' أو ');
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
            // The owner banned the words "من غير مقدم" - a lesson could not
            // win against this line, which the bot is told to send as is.
            $fee > 0 => 'مصاريف إدارية '.number_format($fee).' بتدفعها مرة واحدة وقت الاستلام',
            default => 'مفيش أي مبلغ بيتدفع وقت الاستلام',
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
        $down = (float) $offer['down_payment'];
        $fee = (float) $offer['admin_fee'];

        // no installment price: the owner never tells it to the customer.
        // The owner: "متضفش المصاريف الاداريه علي اجمالي قسط المكنه" - the
        // fee is its own sentence, the total is the motorcycle's only.
        return 'سعرها كاش '.number_format((float) $machine->cash_price).' جنيه. على '.$this->duration($offer['months']).': '
            .($down > 0 ? 'مقدم '.number_format($down).' جنيه + ' : '')
            .$offer['months'].' قسط × '.number_format($offer['monthly_payment']).' جنيه = '
            .number_format(round($down + $offer['monthly_payment'] * $offer['months'])).' جنيه في الآخر.'
            .($fee > 0 ? ' والمصاريف الإدارية '.number_format($fee).' جنيه لوحدها، بتدفعها مرة واحدة وقت الاستلام.' : '');
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

    /** The owner (teach mode, 2026-09-28): "بعد ٤٥ يوم من الاستلام" - not "لحد ٤٥ يوم". The days are a bot setting. */
    public static function firstPaymentLine(): string
    {
        return 'أول قسط بيبدأ بعد '.(int) config('agent.installments.first_payment_after_days', 45).' يوم من الاستلام';
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
