<?php

namespace App\Agent\Tools;

use App\Domain\Applications\ApplicationSelectionException;
use App\Domain\Applications\ApplicationService;
use App\Domain\Installments\BestOfferService;
use App\Domain\Installments\PlanResolver;
use App\Domain\Installments\PlanResolutionException;
use App\Domain\Conversations\CustomerStatements;
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
            .'application exists, this returns it.';
    }

    /** A work statement mentions work, a job, an employer or an income source. */
    public static function talksAboutWork(string $quote): bool
    {
        $text = \App\Support\ArabicTextNormalizer::normalize($quote);

        // The pattern is normalized like the text: "كهربائي" became
        // "كهربايي" in the text only, and an electrician was asked his work
        // four times.
        return (bool) preg_match(\App\Support\ArabicTextNormalizer::normalize('/موظف|وظيف|حكوم|شرك|قطاع|مرتب|راتب|تامين|متامن|معاش|متقاعد|حر|شغال|بشتغل|اشتغل|شغلي|شغلانه|صنايعي|حرفي|'
            .'سواق|سايق|دليفري|ديليفري|توصيل|طيار|مندوب|اوبر|طلبات|كريم|اندرايف|تطبيق|ابلكيشن|تاجر|تجاره|محل|ورشه|معرض|مصنع|مقاول|فلاح|'
            .'مزارع|نجار|سباك|كهربائي|نقاش|حداد|ميكانيكي|سمكري|ترزي|حلاق|بياع|عامل|فني|مهندس|دكتور|مدرس|محاسب|ممرض|عسكري|جيش|شرطه|'
            .'صاحب|بملك|مطعم|كافيه|كافتيري|قهوه|سوبر ?ماركت|بقال|فرن|مخبز|حلواني|جزار|فكهاني|خضري|مكتب|عقار|صيدل|محامي|مبيض|محاره|'
            .'عربيه فول|كشك|سوق|بضاعه|صنعه|صنعتي|ظابط|ضابط|امين شرطه|مطار|شحن|نقل|جبس|بورد|سباكه|كهربا|نقاشه|دهان|مباني|سيراميك|الوميتال|'
            .'مصنع|مخزن|امن|حارس|بواب|خدمه|نضافه|فندق|مستشفي|مدرسه|جامعه|طالب|يوميه|يوميات|اجري|'
            .'employee|freelanc|driver|delivery|uber|job|work/u'), $text)
            // "انا مبيض محارة": the customer describing himself names his
            // work even when the job is not in the list above - a whitelist
            // of jobs never ends, and "صاحب مطعم" was sent back to be asked again.
            || (bool) preg_match('/^\s*انا\s+(?!عايز|عاوز|عايزه|عاوزه|محتاج|موافق|تمام|جاهز|هقدم|مش|كنت|بسال|عارف|فاهم|معاك|هنا|اسف|شاكر|متشكر|سالت|قلت)\S{3,}/u', $text);
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['customer_type', 'customer_type_quote'],
            'properties' => [
                'customer_type' => ['type' => 'string'],
                'customer_type_quote' => ['type' => 'string', 'description' => 'The customer\'s own words (copied exactly from their message) that tell their work situation, e.g. "انا موظف في شركة" or "شغال على اوبر". If they have not said it, do not call this tool - ask them first.'],
                'motorcycle_id' => ['type' => 'integer'],
                'different_motorcycle_confirmed' => ['type' => 'boolean', 'description' => 'true only when the customer clearly chose a model other than the one he was last quoted.'],
                'plan_id' => ['type' => 'integer'],
                'installment_system' => ['type' => 'string', 'description' => 'System name as shown to the customer, e.g. امان. With months, preferred over plan_id.'],
                'months' => ['type' => 'integer', 'minimum' => 1],
                'down_payment' => ['type' => 'number', 'minimum' => 0],
            ],
        ];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $customerType = CustomerType::where('key', $args['customer_type'])->where('is_active', true)->first();

        if (! $customerType) {
            return ToolResult::error('UNKNOWN_CUSTOMER_TYPE');
        }

        // An application was opened as "employee" while the same reply was
        // still asking the customer whether they were an employee.
        $typeEvidence = app(CustomerStatements::class)->messageContainingQuote($ctx->conversationId, (string) $args['customer_type_quote']);

        // "عايز اقسط هوجن ٤ على سنة" was passed as the quote and the customer
        // was opened as عامل حر without ever saying what he works.
        if ($typeEvidence !== null && ! self::talksAboutWork((string) $args['customer_type_quote'])) {
            return ToolResult::error('CUSTOMER_TYPE_NOT_STATED', 'customer_type_quote says nothing about his work. Ask him only "حضرتك بتشتغل إيه؟ ولا على المعاش؟" - never list types like موظف/عامل حر.');
        }

        if ($typeEvidence === null) {
            return ToolResult::error('CUSTOMER_TYPE_NOT_STATED', 'customer_type_quote is not in the customer\'s messages. Ask him only "حضرتك بتشتغل إيه؟ ولا على المعاش؟" (never list types like موظف/عامل حر) and wait for their answer.');
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

        return ToolResult::ok([
            'application_id' => $outcome['application']->id,
            'created' => $outcome['created'],
            'snapshot' => $this->snapshots->for($outcome['application']),
        ] + ($planProblem ? ['plan_not_set' => $planProblem] : []));
    }
}
