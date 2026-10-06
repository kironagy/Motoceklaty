<?php

namespace App\Agent\Tools;

use App\Domain\Applications\ApplicationSelectionException;
use App\Domain\Applications\ApplicationService;
use App\Domain\Installments\BestOfferService;
use App\Domain\Installments\PlanResolver;
use App\Domain\Installments\PlanResolutionException;
use App\Domain\Applications\SnapshotService;
use App\Models\Application;
use App\Models\CustomerType;
use App\Models\InstallmentPlan;
use App\Models\Machine;

/** WRITE — plan §6.11 */
class UpdateApplicationSelectionTool implements WriteTool
{
    public function __construct(
        private readonly ApplicationService $applications,
        private readonly SnapshotService $snapshots,
    ) {
    }

    public function name(): string
    {
        return 'update_application_selection';
    }

    public function description(): string
    {
        return 'Change the open application\'s motorcycle, plan (installment_system + months), down payment, customer type or route, '
            .'when he picks or changes one. The only way the selection changes.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'motorcycle_id' => ['type' => 'integer'],
                'different_motorcycle_confirmed' => ['type' => 'boolean', 'description' => 'true only when the customer clearly chose a model other than the one he was last quoted.'],
                'plan_id' => ['type' => 'integer'],
                'installment_system' => ['type' => 'string', 'description' => 'System name as shown to the customer, e.g. امان. With months, preferred over plan_id.'],
                'months' => ['type' => 'integer', 'minimum' => 1],
                'down_payment' => ['type' => 'number', 'minimum' => 0],
                'customer_type' => ['type' => 'string'],
                'customer_type_quote' => ['type' => 'string', 'description' => 'Required with customer_type: the customer\'s exact words stating their work situation.'],
                'route' => ['type' => 'string', 'enum' => ['card_only'], 'description' => StartApplicationTool::ROUTE_DESCRIPTION],
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
        }

        if (array_intersect_key($args, array_flip(['motorcycle_id', 'plan_id', 'installment_system', 'months', 'down_payment', 'customer_type'])) === []) {
            return ToolResult::error('INVALID_ARGUMENTS', 'At least one field must be given.');
        }

        $application = $ctx->activeApplicationId ? Application::find($ctx->activeApplicationId) : null;

        if (! $application) {
            return ToolResult::error('NO_ACTIVE_APPLICATION');
        }

        $customerType = null;

        if (isset($args['customer_type'])) {
            $customerType = CustomerType::where('key', $args['customer_type'])->where('is_active', true)->first();

            if (! $customerType) {
                return ToolResult::error('UNKNOWN_CUSTOMER_TYPE');
            }

            if (($quote = app(\App\Domain\Applications\WorkClassification::class)->statedQuote($ctx->conversationId, (string) ($args['customer_type_quote'] ?? ''))) === null) {
                return ToolResult::error('CUSTOMER_TYPE_NOT_STATED', 'A customer type change needs customer_type_quote with the customer\'s own words. Ask them first.');
            }

            $args['customer_type_quote'] = $quote;

            if ($problem = app(\App\Domain\Applications\WorkClassification::class)->problem($ctx->conversationId, $customerType->key, (string) ($args['customer_type_quote'] ?? ''), $cardOnly)) {
                return ToolResult::error($problem['code'], $problem['hint']);
            }
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

        try {
            $plan = app(PlanResolver::class)->resolveStrict(
                $machine ?? $application->machine,
                $args['plan_id'] ?? null,
                $args['installment_system'] ?? null,
                $args['months'] ?? null,
                $application->installmentPlan,
            );
        } catch (PlanResolutionException $e) {
            // "على سنة" with no system: the customer never picks a finance
            // company - the best one for him is picked here.
            $best = $e->errorCode === 'SYSTEM_REQUIRED' && isset($args['months'])
                ? app(BestOfferService::class)->offers(
                    $machine ?? $application->machine,
                    $customerType?->id ?? $application->customer_type_id,
                    null,
                    (int) $args['months'],
                    isset($args['down_payment']) ? (float) $args['down_payment'] : null,
                )[0] ?? null
                : null;

            if (! $best) {
                return ToolResult::error($e->errorCode, $e->getMessage());
            }

            $plan = InstallmentPlan::with('installmentSystem')->find($best['plan_id']);

            if (! isset($args['down_payment']) && $application->down_payment === null) {
                $args['down_payment'] = $best['down_payment'];
            }
        }

        try {
            $invalidatedKeys = $this->applications->updateSelection(
                $application,
                $machine,
                $plan,
                isset($args['down_payment']) ? (float) $args['down_payment'] : null,
                $customerType,
            );
        } catch (ApplicationSelectionException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage() !== $e->errorCode ? $e->getMessage() : '');
        }

        $application->refresh();

        if ($cardOnly) {
            \App\Domain\Applications\CardOnlyRoute::mark($application, (string) ($args['customer_type_quote'] ?? ''));

            if (! isset($args['plan_id']) && ! isset($args['months']) && ! isset($args['down_payment'])) {
                \App\Domain\Applications\CardOnlyRoute::reprice($application);
                $application->refresh();
            }

            try {
                app(\App\Domain\Applications\CustomerDataService::class)->record(
                    $application->customer, $application,
                    [['key' => 'work_type', 'value' => 'other', 'quote' => (string) ($args['customer_type_quote'] ?? '')]],
                    $ctx->conversationId,
                );
                $application->refresh();
            } catch (\Throwable) {
                // the snapshot still asks for the work type
            }
        }

        $selectedPlan = $application->installment_plan_id
            ? InstallmentPlan::with('installmentSystem')->find($application->installment_plan_id)
            : null;

        return ToolResult::ok([
            // Echoed so a guessed plan_id is visible: "امان ١٢ شهر" was saved
            // as plan 1 (عبد اللطيف جميل 18 months) and confirmed to the customer.
            'selected_plan' => $selectedPlan ? [
                'plan_id' => $selectedPlan->id,
                'system' => trim((string) $selectedPlan->installmentSystem?->name),
                'months' => $selectedPlan->months,
            ] : null,
            // TOOL-006: the compact status, not the whole snapshot again
            'application_now' => SnapshotService::compact($this->snapshots->for($application)),
            'invalidated' => $invalidatedKeys,
        ]);
    }
}
