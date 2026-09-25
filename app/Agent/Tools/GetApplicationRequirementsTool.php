<?php

namespace App\Agent\Tools;

use App\Domain\Applications\RequirementService;
use App\Models\CustomerType;
use App\Models\RequirementField;
use App\Models\ApplicationData;
use App\Models\ApplicationRequirement;

/** READ — plan §6.8 */
class GetApplicationRequirementsTool implements Tool
{
    public function __construct(private readonly RequirementService $requirements)
    {
    }

    public function name(): string
    {
        return 'get_application_requirements';
    }

    public function description(): string
    {
        return 'What a customer type needs (fields, documents). This is the ONLY source for requirements - answer "what do I '
            .'need?" from it, never from memory. Pass what you know about the customer in facts (e.g. work_type) so '
            .'conditional documents are resolved; `conditional` lists requirements that depend on a fact not given yet. '
            .'During an application the facts already recorded are used automatically.';
    }

    public function inputSchema(): array
    {
        $factProperties = RequirementField::where('is_active', true)->where('data_type', 'enum')->get()
            ->mapWithKeys(fn (RequirementField $f) => [$f->key => ['type' => 'string', 'enum' => array_values((array) $f->enum_options)]])
            ->all();

        return [
            'type' => 'object',
            'required' => ['customer_type'],
            'properties' => [
                'customer_type' => ['type' => 'string'],
            ] + ($factProperties === [] ? [] : ['facts' => ['type' => 'object', 'properties' => $factProperties]]),
        ];
    }

    public function permission(): string
    {
        return 'READ';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $customerType = CustomerType::where('key', $args['customer_type'])->where('is_active', true)->first();

        if (! $customerType) {
            return ToolResult::error('UNKNOWN_CUSTOMER_TYPE', "Unknown customer type: {$args['customer_type']}");
        }

        $facts = $this->recordedFacts($ctx);

        foreach ((array) ($args['facts'] ?? []) as $key => $value) {
            $field = RequirementField::where('key', $key)->where('data_type', 'enum')->first();

            if (! $field || ! in_array($value, (array) $field->enum_options, true)) {
                return ToolResult::error('INVALID_ARGUMENTS', "facts.{$key} must be one of: ".implode(', ', (array) $field?->enum_options));
            }

            $facts[$key] = $value;
        }

        $requirements = $this->requirements->requirementsFor($customerType, $facts);

        return ToolResult::ok($requirements + [
            'facts_used' => $facts,
            'conditional' => $this->unresolvedConditional($customerType, $facts),
        ]);
    }

    /** Facts already on the open application (e.g. work_type), so answers match the snapshot. */
    private function recordedFacts(ToolContext $ctx): array
    {
        if (! $ctx->activeApplicationId) {
            return [];
        }

        $enumKeys = RequirementField::where('data_type', 'enum')->pluck('key')->all();

        return ApplicationData::where('application_id', $ctx->activeApplicationId)
            ->whereIn('field_key', $enumKeys)->where('status', 'valid')->get()
            ->mapWithKeys(fn ($row) => [$row->field_key => $row->value])->all();
    }

    /**
     * Requirements hidden only because a fact is not known yet - shown with
     * what they depend on, instead of silently missing from the answer.
     */
    private function unresolvedConditional(CustomerType $customerType, array $facts): array
    {
        return ApplicationRequirement::where('customer_type_id', $customerType->id)
            ->whereNotNull('condition')
            ->with(['requirementField', 'documentType'])
            ->get()
            ->filter(fn (ApplicationRequirement $r) => data_get($facts, $r->condition['fact'] ?? '') === null)
            ->map(fn (ApplicationRequirement $r) => [
                'kind' => $r->requirement_type,
                'key' => $r->documentType?->key ?? $r->requirementField?->key,
                'label' => $r->documentType?->label ?? $r->requirementField?->label,
                'depends_on' => ['fact' => $r->condition['fact'] ?? null, 'op' => $r->condition['op'] ?? null, 'value' => $r->condition['value'] ?? null],
            ])
            ->values()->all();
    }
}
