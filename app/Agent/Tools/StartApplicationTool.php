<?php

namespace App\Agent\Tools;

use App\Domain\Applications\ApplicationSelectionException;
use App\Domain\Applications\ApplicationService;
use App\Domain\Installments\BestOfferService;
use App\Domain\Installments\PlanResolver;
use App\Domain\Installments\PlanResolutionException;
use App\Domain\Applications\SnapshotService;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\InstallmentPlan;
use App\Models\Machine;

/** WRITE — plan §6.10 */
class StartApplicationTool implements Tool
{
    public function __construct(
        private readonly ApplicationService $applications,
        private readonly SnapshotService $snapshots,
    ) {
    }

    public function name(): string
    {
        return 'start_application';
    }

    public function description(): string
    {
        return 'Open an application. Use IMMEDIATELY the first moment the customer says they want to apply or '
            .'buy on installment (e.g. "عايز اقدم", "عايز اقسط") - only customer_type is required, everything '
            .'else (motorcycle, plan, down payment) can be null and filled in later via '
            .'update_application_selection. Do not wait to collect a complete selection first: record_customer_data '
            .'and process_document both require an active application to attach to, so delaying this call means '
            .'customer data and documents they send have nowhere to go and are silently lost. '
            .'Do not use only when they are just asking questions with no intent to apply. If an active '
            .'application exists, this returns it; if he cancelled one recently and wants to continue, it is reopened with his data.';
    }

    public const NO_WORK_HINT = 'The applicant must be working (or on a pension) - these words say he is not working, a housewife, a student '
        .'or about to start work. Do not open or switch an application with these words. Before collecting anything, tell him kindly that any '
        .'other person who works (21 or older, not necessarily a relative) can apply in his own name with his own papers, and the licence can be '
        .'in the customer\'s name. When he says who will apply, ask what THAT person works ("هو بيشتغل إيه؟") and use that person\'s own words.';

    public static function occupationHint(string $say): string
    {
        return 'The finance companies refuse this work (owner\'s rule). Tell him plainly, in these words or very close: "'.$say.'" '
            .'Do not soften it (no "بتتحفظ", no "القرار عندهم"), do not offer to try or apply anyway, do not ask for another income source, '
            .'and do not open an application.';
    }

    public const INSURED_HINT = 'He said he is insured (متأمن عليه): he applies as employee - self_employed is only for an employee who is NOT insured. '
        .'If he has no salary slip yet, tell him it is needed and he can send it when he gets it. Only if he says he cannot get it at all, '
        .'the last resort is the card-only route (route=card_only) on its own terms - never another work type on his word.';

    public const ROUTE_DESCRIPTION = 'card_only = the last resort when he works but cannot bring the papers his work needs (salary slip, app '
        .'screenshots, tax card...): he applies with his ID only, on the free-work terms (its own cap and installment - quote them with '
        .'get_installment_offer customer_type=self_employed first). Only after he said he cannot bring them; never for a woman.';

    public const NOT_OWNER_HINT = 'business_owner only when he said he OWNS the place (صاحب/عندي محل/ورشتي...). '
        .'"شغال في ورشة/محل/مطعم" is working there, not owning it. Ask him "إنت صاحب المكان ولا شغال فيه؟" '
        .'- working there: a craft (ميكانيكي، نجار...) → self_employed with work_type craftsman; with a salary and insurance → employee.';

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['customer_type', 'customer_type_quote'],
            'properties' => [
                'customer_type' => ['type' => 'string'],
                'customer_type_quote' => ['type' => 'string', 'description' => 'The customer\'s own words (copied exactly from their message) that tell their work situation, e.g. "انا موظف في شركة" or "شغال على اوبر". If they have not said it, do not call this tool - ask them first.'],
                'route' => ['type' => 'string', 'enum' => ['card_only'], 'description' => self::ROUTE_DESCRIPTION],
                'motorcycle_id' => ['type' => 'integer'],
                'different_motorcycle_confirmed' => ['type' => 'boolean', 'description' => 'true only when the customer clearly chose a model other than the one he was last quoted.'],
                'plan_id' => ['type' => 'integer'],
                'installment_system' => ['type' => 'string', 'description' => 'System name as shown to the customer, e.g. امان. With months, preferred over plan_id.'],
                'months' => ['type' => 'integer', 'minimum' => 1],
                'down_payment' => ['type' => 'number', 'minimum' => 0],
                'other_applicant_quote' => ['type' => 'string', 'description' => 'Only when someone else (not the customer) is the one applying: the customer\'s exact words saying so, e.g. "اخويا هو اللي هيقدم".'],
            ],
        ];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $cardOnly = ($args['route'] ?? null) === 'card_only';

        if ($cardOnly) {
            $args['customer_type'] = 'self_employed';
            $args['_work_type'] = 'other';
        }

        $customerType = CustomerType::where('key', $args['customer_type'])->where('is_active', true)->first();

        if (! $customerType) {
            return ToolResult::error('UNKNOWN_CUSTOMER_TYPE');
        }

        // An application was opened as "employee" while the same reply was
        // still asking the customer whether they were an employee.
        $typeEvidence = app(\App\Domain\Applications\WorkClassification::class)->statedQuote($ctx->conversationId, (string) $args['customer_type_quote']);
        $args['customer_type_quote'] = $typeEvidence ?? $args['customer_type_quote'];

        if ($typeEvidence === null) {
            return ToolResult::error('CUSTOMER_TYPE_NOT_STATED', 'customer_type_quote is not in the customer\'s messages. If he already said what he works, copy his words EXACTLY as he wrote them (same spelling, no additions, no merging with your own words) and call again. Only if he never said it, ask him "حضرتك بتشتغل إيه؟ ولا على المعاش؟" (never list types like موظف/عامل حر).');
        }

        // Conversation 84: "سني 18" was his second message; the bot opened an
        // application and collected his address, work and phone for twenty
        // minutes before telling him the minimum is 21. An age he stated
        // himself stops it before anything is collected - unless someone
        // else (who works) is the one applying.
        if ($problem = $this->statedAgeProblem($ctx, (string) ($args['other_applicant_quote'] ?? ''))) {
            return ToolResult::error('AGE_BELOW_MINIMUM_STATED', $problem);
        }

        // Owner 2026-10-03: his work is read by the AI from the conversation
        // (no job lists) - not working, refused work, government or private,
        // owner or worker, insured or not.
        if ($problem = app(\App\Domain\Applications\WorkClassification::class)->problem($ctx->conversationId, $customerType->key, (string) $args['customer_type_quote'], $cardOnly)) {
            return ToolResult::error($problem['code'], $problem['hint']);
        }

        // QA 2026-10-04: opened with no motorcycle and no plan although he
        // had just chosen the Boxer on 18 months - the quoted offer fills
        // what the call left out.
        $quoted = \App\Domain\Conversations\QuotedOffer::last($ctx->conversationId, isset($args['motorcycle_id']) ? (int) $args['motorcycle_id'] : null);

        if ($quoted && ! isset($args['plan_id']) && ! isset($args['installment_system']) && ! isset($args['months'])) {
            $args['motorcycle_id'] ??= $quoted['machine_id'];
            $args['plan_id'] = $quoted['plan_id'];
            $args['down_payment'] ??= $quoted['down_payment'];
        } elseif (! isset($args['motorcycle_id']) && ($lastMotorcycle = \App\Domain\Conversations\QuotedMotorcycle::last($ctx->conversationId))) {
            $args['motorcycle_id'] = $lastMotorcycle;
        }

        $machine = null;

        if (isset($args['motorcycle_id'])) {
            $machine = Machine::where('is_active', true)->find($args['motorcycle_id']);

            if (! $machine) {
                return ToolResult::error('UNKNOWN_MOTORCYCLE');
            }

            if ($mismatch = \App\Domain\Conversations\QuotedMotorcycle::mismatch($ctx->conversationId, $machine->id, ($args['different_motorcycle_confirmed'] ?? false) === true)) {
                return ToolResult::error('MOTORCYCLE_DIFFERS_FROM_LAST_QUOTED', $mismatch);
            }
        }

        // "عايز هجن f على سنة" sent months with no system: the whole call
        // failed and no application was opened. The plan is optional here -
        // open without it and say why, the plan can be set later.
        $planProblem = null;

        try {
            $plan = app(PlanResolver::class)->resolveStrict($machine, $args['plan_id'] ?? null, $args['installment_system'] ?? null, $args['months'] ?? null);
        } catch (PlanResolutionException $e) {
            $plan = $e->errorCode === 'SYSTEM_REQUIRED' && isset($args['months']) && $machine
                ? app(BestOfferService::class)->bestPlan($machine, (int) $args['months'], $customerType->id, null, isset($args['down_payment']) ? (float) $args['down_payment'] : null)
                : null;
            $planProblem = $plan ? null : ['code' => $e->errorCode, 'detail' => $e->getMessage()];
        }

        try {
            $outcome = $this->applications->start(
                Customer::findOrFail($ctx->customerId),
                $ctx->conversationId,
                $customerType,
                $machine,
                $plan,
                isset($args['down_payment']) ? (float) $args['down_payment'] : null,
            );
        } catch (ApplicationSelectionException $e) {
            return ToolResult::error($e->errorCode);
        }

        // card-only on an application already open under his real type: the route switches it
        if ($cardOnly && (int) $outcome['application']->customer_type_id !== (int) $customerType->id) {
            try {
                $this->applications->updateSelection($outcome['application'], null, null, null, $customerType);
                \App\Domain\Applications\CardOnlyRoute::reprice($outcome['application']->refresh());
                $outcome['application']->refresh();
            } catch (ApplicationSelectionException $e) {
                return ToolResult::error($e->errorCode);
            }
        }

        // "طلبات" came as customer_type delivery_app: he is self_employed
        // and the work type is saved with his own words.
        // "ترزي ملابس" with no work type from the model: the reading has it.
        $readType = $customerType->key === 'self_employed'
            ? app(\App\Domain\Applications\WorkClassification::class)->workType($ctx->conversationId, (string) $args['customer_type_quote'])
            : null;

        // the reading wins for a day's pay (tuk-tuk is not an app rider), else fills a gap
        if ($readType === 'other' && (app(\App\Domain\Applications\WorkClassification::class)->reading($ctx->conversationId)['daily_labour_no_trade'] ?? false)) {
            $args['_work_type'] = 'other';
        } elseif (! filled($args['_work_type'] ?? null)) {
            $args['_work_type'] = $readType;
        }

        if (filled($args['_work_type'] ?? null) && $customerType->key === 'self_employed') {
            try {
                app(\App\Domain\Applications\CustomerDataService::class)->record(
                    Customer::findOrFail($ctx->customerId),
                    $outcome['application'],
                    [['key' => 'work_type', 'value' => (string) $args['_work_type'], 'quote' => (string) $args['customer_type_quote']]],
                    $ctx->conversationId,
                );
                $outcome['application']->refresh();
            } catch (\Throwable) {
                // the snapshot still asks for the work type
            }
        }

        // the card-only route is also how a day's pay (tuk-tuk...) applies - staff see it the same way
        if ($cardOnly || (app(\App\Domain\Applications\WorkClassification::class)->reading($ctx->conversationId)['daily_labour_no_trade'] ?? false)) {
            \App\Domain\Applications\CardOnlyRoute::mark($outcome['application'], (string) $args['customer_type_quote']);
        }

        return ToolResult::ok([
            'application_id' => $outcome['application']->id,
            'created' => $outcome['created'],
            'snapshot' => $this->snapshots->for($outcome['application']),
        ] + ($planProblem ? ['plan_not_set' => $planProblem] : [])
          + (($outcome['reopened'] ?? false) ? ['reopened' => 'His cancelled application is back with everything he already sent. Tell him so in one line and continue from snapshot next_step - do not ask again for what is already in.'] : []));
    }

    /** "سني 18" / "عندي 20 سنة" / "عمري ١٩" in his own recent messages. */
    private function ageInRecentWords(int $conversationId): ?int
    {
        $texts = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $conversationId)->where('direction', 'incoming')
            ->where('sender_type', 'customer')->latest('id')->limit(30)->get(['text', 'transcript']);

        foreach ($texts as $message) {
            $text = strtr(\App\Support\ArabicTextNormalizer::normalize(trim(($message->text ?? '').' '.($message->transcript ?? ''))),
                ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

            if (preg_match('/(?:سني|عمري)\s*(\d{2})(?!\d)|(?:عندي|انا)\s*(\d{2})\s*(?:سنه|سنين|سنة)(?!\p{L})/u', $text, $m)) {
                $age = (int) ($m[1] !== '' ? $m[1] : $m[2]);

                return $age >= 10 && $age <= 99 ? $age : null;
            }
        }

        return null;
    }

    private function statedAgeProblem(ToolContext $ctx, string $otherApplicantQuote = ''): ?string
    {
        if ($otherApplicantQuote !== '' && app(\App\Domain\Conversations\CustomerStatements::class)->messageContainingQuote($ctx->conversationId, $otherApplicantQuote) !== null) {
            return null;
        }

        $memory = app(\App\Domain\Memory\CustomerMemory::class);
        // His memory is written when the turn's reply goes out; "سني 18
        // وعايز اقدم" in this same turn is read from his words directly.
        $age = $memory->statedAge($ctx->customerId) ?? $this->ageInRecentWords($ctx->conversationId);
        $min = \App\Domain\Memory\CustomerMemory::minimumAge();

        if ($age === null || $min === null || $age >= $min) {
            return null;
        }

        $applicant = $memory->get($ctx->customerId)['facts']['applicant'] ?? null;

        if ($applicant && ($applicant['source'] ?? null) === 'customer_statement'
            && ! preg_match('/^(?:هو|انا|أنا|نفسه|بنفسه|العميل|self)$/u', trim((string) $applicant['value']))) {
            return null;
        }

        return "He said he is {$age}; applying needs {$min} or older. Do not open an application or collect anything in his name. "
            .'Tell him kindly in one line, then offer the only way: any other person who works (21 or older, not necessarily a relative) applies in his own name with his own papers. '
            .'When he says who will apply, call start_application with THAT person\'s work and other_applicant_quote = his words saying it. Cash purchase is always possible.';
    }
}
