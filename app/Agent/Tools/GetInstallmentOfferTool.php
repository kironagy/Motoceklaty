<?php

namespace App\Agent\Tools;

use App\Domain\Installments\BestOfferService;
use App\Domain\Installments\FinancingCapPolicy;
use App\Models\Application;
use App\Models\CustomerType;
use App\Models\Machine;

/** READ - the default answer to any installment question. */
class GetInstallmentOfferTool implements ReadTool
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
        return 'Installment numbers for a motorcycle (the best plan for him is picked). No months = the durations to ask about; '
            .'months / months_list / all_durations = per duration the amount at pickup, admin fee, monthly payment, total and a `say` line. '
            .'no_upfront=true when he wants nothing paid at pickup (NO_ZERO_UPFRONT_PLAN = none exists).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['motorcycle_id'],
            'properties' => [
                'motorcycle_id' => ['type' => 'integer'],
                'his_words' => ['type' => 'string', 'description' => 'Needed unless he has an open application: his exact words, copied from any of his messages, that name this motorcycle ("هوجن 4 فرز تاني", "اللي بـ46 ألف", "الزد", "KTX"). A model you suggested that he never named is not his pick - ask.'],
                'months' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The one duration he named, now or earlier in the chat ("على سنة" = 12, "سنة ونص" = 18, "سنتين" = 24, "٣ سنين" = 36). Never ask a duration he already said.'],
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

    /**
     * Incident 11-06: shorthand the name regex missed ("X ROAD", "KTX", "اللي بـ46 ألف")
     * refused the offer, and he was asked for the model again. The model now quotes his
     * words; they only count when he really wrote (or said) them.
     */
    public function hisWordsPick(array $args, ToolContext $ctx, Machine $machine): bool
    {
        // Owner 2026-11-09: 331 of 365 refusals in a day were a missing his_words - every one cost
        // a model call, slowed the reply, and ended in "مش هقدر أحسبلك قسطها". The model he talks
        // about is also proven by his recent messages or by the model this chat is focused on.
        return $this->quotedPick($args, $ctx, $machine) || $this->pickedInChat($ctx, $machine);
    }

    private function pickedInChat(ToolContext $ctx, Machine $machine): bool
    {
        $id = (int) $machine->id;
        $state = (array) (\App\Models\WhatsappConversation::whereKey($ctx->conversationId)->value('state') ?? []);
        if (is_string($state)) {
            $state = (array) json_decode($state, true);
        }

        if (in_array($id, array_map('intval', (array) ($state['focus_motorcycle_ids'] ?? [])), true)) {
            return true;
        }

        $catalog = app(\App\Agent\Runtime\CatalogMentions::class);
        $texts = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
            ->where('direction', 'incoming')->latest('id')->limit(20)->get(['text', 'transcript'])
            ->map(fn ($m) => trim(($m->text ?? '').' '.($m->transcript ?? '')))->filter();

        foreach ($texts as $text) {
            if (in_array($id, array_map('intval', $catalog->fullNames($text)), true)) {
                return true;
            }
        }

        // Live 2026-11-09 (conversation 3500): "تقصد هوجن ٤ استيراد فرز تاني؟" - "ايواه هوا" was refused
        // three times because he never typed the name, and he was asked the same question again and
        // again. The model our last replies named, answered by him since, is his pick.
        $ours = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
            ->where('direction', 'outgoing')->latest('id')->limit(3)->pluck('text')->filter();

        foreach ($ours as $text) {
            if (in_array($id, array_map('intval', $catalog->fullNames((string) $text)), true)) {
                return true;
            }
        }

        return \App\Models\AiTraceStep::where('turn_id', $ctx->turnId)
            ->whereIn('tool_name', ['search_motorcycles', 'get_motorcycle_details', 'identify_motorcycle_from_image'])
            ->get(['result_redacted'])
            ->contains(fn ($step) => preg_match('/"(?:id|motorcycle_id)":'.$id.'[,}]/', json_encode($step->result_redacted) ?: ''));
    }

    private function quotedPick(array $args, ToolContext $ctx, Machine $machine): bool
    {
        $quote = trim((string) ($args['his_words'] ?? ''));
        $words = \App\Domain\Conversations\DeliveryService::sameText($quote);

        if (mb_strlen($words) < 2) {
            return false;
        }

        $said = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
            ->where('direction', 'incoming')->latest('id')->limit(20)->get(['text', 'transcript'])
            ->contains(fn ($m) => str_contains(\App\Domain\Conversations\DeliveryService::sameText(trim($m->text.' '.$m->transcript)), $words));

        if (! $said) {
            return false;
        }

        // Simulation 2026-11-08: "هوجن 4 فرز تاني" (catalog: "هوجن ٤ استيراد فرز تاني") and "كيواي سوبر لايت"
        // (catalog: "Superlight") were refused five times because the FULL name had to match, and he was
        // asked for the model he had named. Which model his words mean is the AI's reading; code only
        // checks the words name something of this motorcycle - its name, a word of it or its brand, or its
        // price - so a bare "عايز" is still no pick.
        $catalog = app(\App\Agent\Runtime\CatalogMentions::class);
        if (in_array((int) $machine->id, array_map('intval', $catalog->fullNames($quote)), true)) {
            return true;
        }
        foreach ($catalog->words($quote) as $machineIds) {
            if (in_array((int) $machine->id, array_map('intval', (array) $machineIds), true)) {
                return true;
            }
        }

        return $this->namesPrice($quote, $machine);
    }

    private function namesPrice(string $quote, Machine $machine): bool
    {
        $text = \App\Support\ArabicTextNormalizer::normalize($quote);
        preg_match_all('/(\d+(?:[.,]\d+)?)\s*(الف|ألف|k)?/u', $text, $m, PREG_SET_ORDER);

        foreach ($m as $hit) {
            $amount = (float) str_replace(',', '', $hit[1]) * (! empty($hit[2]) || (float) $hit[1] < 1000 ? 1000 : 1);
            foreach ([$machine->cash_price, $machine->offer_price ?? null, $machine->installment_price ?? null] as $price) {
                if (is_numeric($price) && (float) $price > 0 && abs((float) $price - $amount) <= 1000) {
                    return true;
                }
            }
        }

        return false;
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $machine = Machine::where('is_active', true)->find($args['motorcycle_id']);

        if (! $machine) {
            return ToolResult::error('UNKNOWN_MOTORCYCLE');
        }
        // His pick is read by the AI (his_words); no regex guesses it from his messages.
        if (! $ctx->activeApplicationId && ! $this->hisWordsPick($args, $ctx, $machine)) {
            return ToolResult::error('MODEL_SELECTION_REQUIRED', trim(($args['his_words'] ?? '') === ''
                ? 'his_words is missing. '
                : 'his_words «'.$args['his_words'].'» is not in his messages word for word, or does not name «'.$machine->name.'» (its name, brand or price). ')
                .'If he did name this motorcycle, copy his own words exactly into his_words. If his words fit more than one model, or he only accepted your suggestion without naming it, ask him which one.');
        }

        $conversation = \App\Models\WhatsappConversation::find($ctx->conversationId);
        if ($conversation && ($age = app(\App\Domain\Applications\AgeEligibilityGate::class)->for($conversation))) {
            return ToolResult::ok(['status'=>'not_eligible','age_blocked'=>true,'work_questions_required'=>false,'customer_message'=>$age['customer_message']]);
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

            // Owner 2026-10-02: "انا محاسب" was quoted as an insured employee
            // with no question. Above the cap he is asked the owner's question
            // first; below it the numbers come without his type.
            if (! $ctx->activeApplicationId && ($problem = app(\App\Domain\Applications\WorkClassification::class)->problem($ctx->conversationId, $customerType->key))) {
                // QA 2026-10-04: a girl on free income was quoted 5,993 a month
                // and asked "تقدّمي؟" before the refusal came. A refusal comes
                // before any number, whatever the price.
                if ($this->cappedWorkTypes($machine) !== null || in_array($problem['code'], \App\Domain\Applications\WorkClassification::REFUSALS, true)) {
                    return ToolResult::error($problem['code'], $problem['hint']);
                }

                $customerTypeId = null;
            }
        }

        // The owner: above the freelancer cap (60,000) the offer depends on
        // his work, so the work comes first; below it the numbers come first.
        // QA 2026-10-04: "شغال دليفري على اوبر" and "انا عندي قهوه" were
        // followed by calls with no customer_type, and the customer was asked
        // his work two and three times. The work he already said is read here.
        if ($customerTypeId === null && ! isset($args['customer_type']) && $this->cappedWorkTypes($machine) !== null) {
            $classification = app(\App\Domain\Applications\WorkClassification::class);
            $reading = $classification->reading($ctx->conversationId);
            $stated = $reading && $reading['work_stated'] && $reading['customer_type'] !== 'unknown'
                ? CustomerType::where('key', $reading['customer_type'])->where('is_active', true)->first()
                : null;

            if ($stated) {
                if ($problem = $classification->problem($ctx->conversationId, $stated->key)) {
                    return ToolResult::error($problem['code'], $problem['hint']);
                }

                $customerTypeId = $stated->id;
            }
        }

        if ($customerTypeId === null && ($capLabels = $this->cappedWorkTypes($machine)) !== null) {
            return ToolResult::error('ASK_WORK_FIRST', 'Before any installment number, tell him the cash price ('
                .number_format($this->cashPrice($machine)).' جنيه) and ask only "حضرتك بتشتغل إيه؟" - for this motorcycle the '
                .'installment depends on his work ('.$capLabels.' pay part of the price at pickup). When he answers, call again with customer_type.');
        }

        // The duration he said is read by the AI from his words (months); no regex fills it in.
        $months = isset($args['months']) ? (int) $args['months'] : null;

        // He already chose a duration: a freelancer's financing cap then
        // listed three durations again and asked him to pick. He gets the
        // numbers for the duration he chose.
        if ($months === null && $ctx->activeApplicationId) {
            $months = Application::whereKey($ctx->activeApplicationId)->first()?->installmentPlan?->months;
        }
        $downPayment = isset($args['down_payment']) ? (float) $args['down_payment'] : null;
        // Conversation 742: he chose 34,000 down, asked "لو سنة ونص؟" and was
        // quoted the minimum down payment - he had to say 34 again.
        if ($downPayment === null && ($args['no_upfront'] ?? false) !== true && $ctx->activeApplicationId) {
            $selected = Application::whereKey($ctx->activeApplicationId)->first(['machine_id', 'down_payment']);
            if ($selected && (int) $selected->machine_id === (int) $machine->id && (float) $selected->down_payment > 0) {
                $downPayment = (float) $selected->down_payment;
            }
        }
        $governorate = $args['governorate'] ?? null;
        $noUpfront = ($args['no_upfront'] ?? false) === true;

        // QA 2026-10-04: he picked مايلو for two years and was quoted the
        // cheapest other company's fees and installment, while his
        // application held the مايلو plan. A chosen company is priced as is.
        $chosenSystemId = $ctx->activeApplicationId
            ? Application::with('installmentPlan')->whereKey($ctx->activeApplicationId)->where('machine_id', $machine->id)->first()?->installmentPlan?->installment_system_id
            : null;
        $offers = $chosenSystemId
            ? $this->offers->offers($machine, $customerTypeId, $governorate, $months, $downPayment, $noUpfront, (int) $chosenSystemId)
            : [];

        if ($offers === []) {
            $offers = $this->offers->offers($machine, $customerTypeId, $governorate, $months, $downPayment, $noUpfront);
        }

        // A 62-year-old was offered 3 years; the last installment must fall
        // by the age limit (eligibility rule max_age_at_end).
        // an age he never wrote is a guess (real-customer run 2026-10-07): only his words or his ID count
        $statedAge = isset($args['age']) && app(\App\Domain\Conversations\CustomerStatements::class)->saidNumber($ctx->conversationId, (int) $args['age'])
            ? (int) $args['age'] : null;
        $maxMonths = $this->maxMonthsForAge($statedAge ?? $this->applicantAge($ctx));

        if ($maxMonths !== null) {
            $tooLong = array_filter($offers, fn ($o) => $o['months'] > $maxMonths);
            $offers = array_values(array_filter($offers, fn ($o) => $o['months'] <= $maxMonths));

            if ($offers === [] && $tooLong !== []) {
                return ToolResult::error('DURATION_EXCEEDS_AGE_LIMIT', 'At his age the longest allowed duration is '.$maxMonths
                    .' months. Tell him politely; offer only durations up to that.');
            }
        }

        if ($offers === [] && $noUpfront) {
            // Owner 2026-11-09: the bot told customers "مفيش أي موتوسيكل من غير مقدم". This refusal is
            // about THIS model only (above the financing limit for his work, a down payment covers the
            // difference); a cheaper model goes with nothing at pickup on the no-fees system.
            return ToolResult::error('NO_ZERO_UPFRONT_PLAN', trim((string) config('agent.safety.no_zero_upfront_hint', ''))
                .' This is about «'.$this->label($machine).'» only: its price is above what is financed for his work, so it needs a down payment '
                .'(call again without no_upfront for the amount). Never say that no motorcycle comes without a down payment - '
                .'a model within the limit goes with nothing at pickup on the no-fees system; offer him one if he wants that.');
        }

        if ($offers === [] && $months !== null) {
            $available = array_column($this->offers->offers($machine, $customerTypeId, $governorate, null, $downPayment, $noUpfront), 'months');

            return ToolResult::error('DURATION_NOT_AVAILABLE', 'No plan for '.$months.' months. Available durations: '
                .implode(', ', $available).' - offer the closest ones.');
        }

        if ($offers === []) {
            return ToolResult::error('NO_INSTALLMENT_SYSTEMS');
        }

        $capped = collect($offers)->firstWhere('cap', '!==', null);
        $cap = $capped ? ['cap_reason' => $this->caps->explanation((float) $capped['cap'], $customerTypeId)] : [];

        // The owner (2026-09-29, conversation 708): "القسط كام؟" is not
        // answered with every duration - he is asked which one first, and
        // gets several only when he asks for more than one.
        $asked = array_values(array_unique(array_map('intval', (array) ($args['months_list'] ?? []))));

        if ($months === null && $asked === [] && ($args['all_durations'] ?? false) !== true) {
            return ToolResult::ok([
                // conversation 639: id 4 was priced as "هوجن 4" - the result names what it priced
                'motorcycle' => $this->label($machine),
                'cash_price' => $this->cashPrice($machine),
                'durations' => array_map(fn (array $o) => $this->duration($o['months']), $offers),
                'minimum_down_payment' => collect($offers)->pluck('minimum_down_payment')->filter(fn ($v) => is_numeric($v))->min() ?? 0,
                'presentation' => 'اذكر كل المدد الموجودة في durations دون حذف أي مدة. لو minimum_down_payment أكبر من صفر اذكر الفرق المطلوب مقدماً فوراً قبل سؤال المدة. لا تعد بحساب لاحق: عند اختيار مدة احسبها بالأداة وأرسل الأرقام في نفس الرد.',
            ] + $cap);
        }

        $shown = $asked === [] ? $offers : array_values(array_filter($offers, fn ($o) => in_array($o['months'], $asked, true)));

        // What he was quoted is recorded by the runner (ToolOutcomeRecorder);
        // the application changes only through update_application_selection.
        if ($shown === []) {
            return ToolResult::error('DURATION_NOT_AVAILABLE', 'No plan for '.implode(', ', $asked).' months. Available durations: '
                .implode(', ', array_column($offers, 'months')).' - offer the closest ones.');
        }

        return ToolResult::ok([
            'motorcycle' => $this->label($machine),
            'cash_price' => $this->cashPrice($machine),
            'offers' => array_map(fn (array $o) => [
                'months' => $o['months'],
                'cash_due_upfront' => $o['cash_due_upfront'],
                'monthly_payment' => $o['monthly_payment'],
                'admin_fee_at_pickup' => $o['admin_fee'],
                // what he pays in all: fees/down payment + every installment
                // the owner: the total is the motorcycle's - down payment (if any) + every installment.
                // The admin fee is said on its own, never added to it.
                'total_paid' => round($o['down_payment'] + $o['monthly_payment'] * $o['months']),
                // Real customers 2026-10-07: "ايه أقل مقدم؟" was answered with the admin fee (2,730) and the
                // next offer changed without a word. The minimum is the plan's, the admin fee is a fee.
                'minimum_down_payment' => $o['minimum_down_payment'] ?? 0,
                'breakdown' => $this->breakdown($machine, $o),
                'line' => $this->line($o),
                // internal: pass these to update_application_selection when he picks this offer - never say them
                'installment_system' => $o['system'],
                'down_payment' => $o['down_payment'],
                'plan_id' => $o['plan_id'],
            ], $shown),
            // the one honest sentence for "what is the minimum down payment / what if I pay some now"
            'down_payment_note' => 'المصاريف الإدارية رسوم مش مقدم. أقل مقدم هو minimum_down_payment؛ لو العميل حب يدفع مقدم أكتر القسط بيقل - احسبها بـ down_payment وقوله القسط الجديد والمصاريف الجديدة صراحة.',
            // Owner 2026-11-09: the quote is the down payment, admin fee and monthly payment - the total
            // ("= 58,080 جنيه في الآخر") only when he asks for it.
            'total_rule' => 'Do not say total_paid or the total part of breakdown unless he asked for the total (الإجمالي / هدفع كام في الآخر).',
            // once with the offer, or when he asks (owner's instructions)
            'first_payment' => self::firstPaymentLine(),
            // the owner's wording of why installments cost more than cash
            'why_installment_costs_more' => (string) config('agent.installments.price_difference_explanation'),
        ] + $cap
        );
    }


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
        return 'سعرها كاش '.number_format($this->cashPrice($machine)).' جنيه. على '.$this->duration($offer['months']).': '
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

    /** Brand + model, as the reply must name it - two brands share model names. */
    private function label(Machine $machine): string
    {
        return trim(($machine->brand?->name ? trim($machine->brand->name).' ' : '').trim((string) $machine->name));
    }
    private function cashPrice(Machine $machine): float
    {
        return (float) ($machine->type === 'offer' && $machine->new_price > 0 ? $machine->new_price : $machine->cash_price);
    }

}
