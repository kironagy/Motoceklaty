<?php

namespace App\Domain\Applications;

use App\Models\ApplicationRequirement;
use App\Models\CustomerType;

class RequirementService
{
    public function __construct(private readonly ConditionEvaluator $conditions)
    {
    }

    /**
     * @return array{fields: array, documents: array, rules: array}
     */
    public function requirementsFor(CustomerType $customerType, array $facts = []): array
    {
        $requirements = ApplicationRequirement::query()
            ->where('customer_type_id', $customerType->id)
            ->with(['requirementField', 'documentType'])
            ->orderBy('sort')
            ->get()
            ->filter(fn (ApplicationRequirement $r) => $this->conditions->evaluate($r->condition, $facts));

        $fields = $requirements->where('requirement_type', 'field')
            ->filter(fn (ApplicationRequirement $r) => $r->requirementField !== null)
            ->map(fn (ApplicationRequirement $r) => [
                'key' => $r->requirementField->key,
                'label' => $r->requirementField->label,
                'required' => $r->is_required,
            ])
            ->values()
            ->all();

        $documents = $requirements->where('requirement_type', 'document')
            ->filter(fn (ApplicationRequirement $r) => $r->documentType !== null)
            ->map(fn (ApplicationRequirement $r) => [
                'key' => $r->documentType->key,
                'label' => $r->documentType->label,
                'description' => $r->documentType->description_for_ai,
                'required' => $r->is_required,
            ])
            ->values()
            ->all();

        return ['fields' => $fields, 'documents' => $documents, 'rules' => []];
    }

    /**
     * Prepared for T13: application field/document status is application
     * data T13 owns; this fills in what a full application snapshot (§3.3)
     * needs from the requirement side once that data exists.
     *
     * @param  array<string, mixed>  $collectedFields  key => value already on the application
     * @param  string[]  $acceptedDocumentKeys
     */
    public function snapshot(CustomerType $customerType, array $collectedFields, array $acceptedDocumentKeys, array $facts): array
    {
        $requirements = $this->requirementsFor($customerType, $facts);

        $requiredFieldKeys = collect($requirements['fields'])->where('required', true)->pluck('key')->all();
        $requiredDocumentKeys = collect($requirements['documents'])->where('required', true)->pluck('key')->all();

        return [
            'fields' => [
                'required' => $requiredFieldKeys,
                'collected' => array_values(array_intersect($requiredFieldKeys, array_keys($collectedFields))),
                'missing' => array_values(array_diff($requiredFieldKeys, array_keys($collectedFields))),
            ],
            'documents' => [
                'required' => $requiredDocumentKeys,
                'accepted' => array_values(array_intersect($requiredDocumentKeys, $acceptedDocumentKeys)),
                'missing' => array_values(array_diff($requiredDocumentKeys, $acceptedDocumentKeys)),
            ],
        ];
    }
}
