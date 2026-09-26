<?php

namespace App\Domain\Applications;

use App\Domain\Applications\Validators\FieldValidatorRegistry;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\Customer;
use App\Models\CustomerAttribute;
use App\Models\RequirementField;
use App\Domain\Conversations\CustomerStatements;

/**
 * T13 §4: the only place customer/application field data gets written by
 * the AI path. Resolves each key's scope from the field registry - never
 * from the AI's say-so.
 */
class CustomerDataService
{
    /** Fields only an accepted document may fill (shop sign / tax card). */
    public const DOCUMENT_ONLY = ['business_name'];

    public function __construct(
        private readonly FieldValidatorRegistry $validators,
        private readonly CustomerStatements $statements,
    ) {
    }

    /**
     * @param  array<int, array{key: string, value: string|int|float, quote?: string}>  $fields
     * @return array{saved: string[], rejected: array<int, array{key: string, code: string}>, conflicts: array<int, array{key: string, code: string}>, facts: array<string, mixed>}
     */
    public function record(Customer $customer, ?Application $application, array $fields, int $conversationId): array
    {
        $saved = [];
        $rejected = [];
        $conflicts = [];
        $facts = [];

        foreach ($fields as $item) {
            $key = $item['key'];
            $field = RequirementField::where('key', $key)->where('is_active', true)->first();

            if (! $field) {
                $rejected[] = ['key' => $key, 'code' => 'UNKNOWN_FIELD'];

                continue;
            }

            // Customers' words are not proof: "شركة اسكوبار" typed in the
            // chat became the business on a submitted request with no photo
            // or tax card behind it. These values are read off a document.
            if (in_array($key, self::DOCUMENT_ONLY, true)) {
                $rejected[] = ['key' => $key, 'code' => 'DOCUMENT_ONLY'];

                continue;
            }

            $validator = $this->validators->for($field->data_type);
            $result = $validator?->validate((string) $item['value'], $field);

            if (! $result || ! $result->valid) {
                $rejected[] = ['key' => $key, 'code' => $result?->errorCode ?? 'UNKNOWN_FIELD'];

                continue;
            }

            // Provenance: a customer_stated value must be something the
            // customer actually wrote. An enum is a classification of what
            // they said, so the AI must point at the words it relied on.
            $evidenceMessageId = $field->data_type === 'enum'
                ? (filled($item['quote'] ?? null) ? $this->statements->messageContainingQuote($conversationId, (string) $item['quote']) : null)
                : ($this->statements->messageContainingValue($conversationId, (string) $item['value'], $field->data_type)
                    ?? $this->statements->messageContainingValue($conversationId, (string) $result->normalized, $field->data_type));

            if ($evidenceMessageId === null) {
                $rejected[] = ['key' => $key, 'code' => $field->data_type === 'enum' ? 'QUOTE_NOT_FOUND' : 'NOT_STATED_BY_CUSTOMER'];

                continue;
            }

            $facts = array_merge($facts, $result->facts);

            if ($field->scope === 'customer') {
                $outcome = $this->writeCustomerAttribute($customer, $field, $result->normalized, $evidenceMessageId);
            } else {
                if (! $application) {
                    $rejected[] = ['key' => $key, 'code' => 'NO_ACTIVE_APPLICATION'];

                    continue;
                }

                $party = $field->scope === 'guarantor' ? 'guarantor' : 'applicant';
                $outcome = $this->writeApplicationData($application, $field, $party, $result->normalized, $evidenceMessageId);
            }

            if ($outcome === 'conflict') {
                $conflicts[] = ['key' => $key, 'code' => 'CONFLICTS_WITH_VERIFIED_VALUE'];
            } else {
                $saved[] = $key;
            }
        }

        if ($application) {
            $application->update(['last_activity_at' => now()]);
        } elseif ($saved !== []) {
            $customer->touch();
        }

        return ['saved' => $saved, 'rejected' => $rejected, 'conflicts' => $conflicts, 'facts' => $facts];
    }

    /**
     * T15: writes extracted document fields. Unlike record() above, this
     * always wins (plan T15 §3.6: "document source wins") - it's called
     * only from DocumentPipeline once a document has already been
     * accepted, never directly from a customer-facing tool.
     *
     * @param  array<string, mixed>  $fields  field_key => validated/normalized value
     * @return string[] keys actually written (unknown/invalid keys are silently skipped - the pipeline
     *                   already validated every field before calling this)
     */
    /**
     * A document only fills a field that is empty, customer-stated, or came
     * from an earlier copy of the same document type. It never replaces a
     * value another document (or staff) set: a Talabat profile screenshot
     * carrying someone else's name overwrote the name read from the
     * national ID. Such a field is reported back in `differs` so the agent
     * can ask the customer instead of silently trusting either side.
     *
     * @return array{applied: string[], differs: array<int, array{field: string, document_value: mixed}>}
     */
    public function recordFromDocument(Customer $customer, ?Application $application, array $fields, int $documentId): array
    {
        $applied = [];
        $differs = [];
        $documentTypeId = ApplicationDocument::whereKey($documentId)->value('document_type_id');

        foreach ($fields as $key => $value) {
            $field = RequirementField::where('key', $key)->where('is_active', true)->first();

            if (! $field) {
                continue;
            }

            if ($field->scope === 'customer') {
                $existing = CustomerAttribute::where('customer_id', $customer->id)->where('field_key', $field->key)->first();

                if ($this->heldByOtherDocument($existing, $documentTypeId)) {
                    $this->noteDifference($differs, $key, $existing->value, $value);

                    continue;
                }

                if ($existing && $this->documentConfirmsFullerValue($field, $existing, $value)) {
                    $existing->update(['document_id' => $documentId, 'verified_at' => now()]);
                    $applied[] = $key;

                    continue;
                }

                CustomerAttribute::updateOrCreate(
                    ['customer_id' => $customer->id, 'field_key' => $field->key],
                    ['value' => $value, 'source' => 'document', 'status' => 'valid', 'document_id' => $documentId, 'verified_at' => now()]
                );
            } elseif ($application) {
                $party = $field->scope === 'guarantor' ? 'guarantor' : 'applicant';
                $existing = ApplicationData::where('application_id', $application->id)
                    ->where('party', $party)->where('field_key', $field->key)->first();

                if ($this->heldByOtherDocument($existing, $documentTypeId)) {
                    $this->noteDifference($differs, $key, $existing->value, $value);

                    continue;
                }

                if ($existing && $this->documentConfirmsFullerValue($field, $existing, $value)) {
                    $existing->update(['document_id' => $documentId]);
                    $applied[] = $key;

                    continue;
                }

                ApplicationData::updateOrCreate(
                    ['application_id' => $application->id, 'party' => $party, 'field_key' => $field->key],
                    ['value' => $value, 'source' => 'document', 'status' => 'valid', 'issue_code' => null, 'document_id' => $documentId]
                );
            } else {
                continue;
            }

            $applied[] = $key;
        }

        return ['applied' => $applied, 'differs' => $differs];
    }

    private function heldByOtherDocument(?\Illuminate\Database\Eloquent\Model $existing, ?int $documentTypeId): bool
    {
        if (! $existing || blank($existing->value)) {
            return false;
        }

        if ($existing->source === 'staff') {
            return true;
        }

        if ($existing->source !== 'document') {
            return false;
        }

        $existingTypeId = $existing->document_id
            ? ApplicationDocument::whereKey($existing->document_id)->value('document_type_id')
            : null;

        return $existingTypeId !== $documentTypeId;
    }

    /**
     * The OCR of an ID dropped the first name, printed on its own line
     * ("ناصر درويش عبد المحسن" for "سلام ناصر درويش عبدالمحسن"), and that
     * shorter name replaced the full name the customer gave - the name on
     * the submission would have been wrong. A document name that is a
     * consistent part of a fuller customer-stated name confirms it; it
     * does not replace it.
     */
    private function documentConfirmsFullerValue(RequirementField $field, \Illuminate\Database\Eloquent\Model $existing, mixed $documentValue): bool
    {
        return $field->data_type === 'person_name'
            && $existing->source === 'customer_stated'
            && NameTokens::isStrictSubset((string) $documentValue, (string) $existing->value);
    }

    /**
     * The reverse order: the ID was read first (short name), then the
     * customer wrote the full name. A consistent fuller version completes
     * the document value; anything else stays a conflict.
     */
    private function completesDocumentValue(RequirementField $field, \Illuminate\Database\Eloquent\Model $existing, mixed $statedValue): bool
    {
        return $field->data_type === 'person_name'
            && NameTokens::isStrictSubset((string) $existing->value, (string) $statedValue);
    }

    /**
     * A value set by staff, read from an accepted document, or confirmed by
     * one (document_id linked) cannot be replaced by a mere statement - only
     * restated, or, for a name, completed consistently.
     */
    private function protectedFromCustomerChange(RequirementField $field, ?\Illuminate\Database\Eloquent\Model $existing, mixed $value): bool
    {
        if (! $existing || blank($existing->value)) {
            return false;
        }

        $documentBacked = in_array($existing->source, ['document', 'staff'], true) || $existing->document_id !== null;

        if (! $documentBacked) {
            return false;
        }

        $same = in_array($field->data_type, ['national_id', 'phone', 'money', 'integer'], true)
            ? preg_replace('/\D+/', '', (string) $existing->value) === preg_replace('/\D+/', '', (string) $value)
            : NameTokens::of((string) $existing->value) !== [] && NameTokens::of((string) $existing->value) === NameTokens::of((string) $value);

        return ! $same && ! $this->completesDocumentValue($field, $existing, $value);
    }

    private function noteDifference(array &$differs, string $key, mixed $stored, mixed $fromDocument): void
    {
        $normalize = fn ($v) => trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $v)));

        if ($normalize($stored) !== $normalize($fromDocument)) {
            $differs[] = ['field' => $key, 'document_value' => $fromDocument];
        }
    }

    /** @return 'saved'|'conflict' */
    private function writeCustomerAttribute(Customer $customer, RequirementField $field, mixed $value, ?int $evidenceMessageId): string
    {
        $existing = CustomerAttribute::where('customer_id', $customer->id)->where('field_key', $field->key)->first();

        if ($this->protectedFromCustomerChange($field, $existing, $value)) {
            return 'conflict';
        }

        CustomerAttribute::updateOrCreate(
            ['customer_id' => $customer->id, 'field_key' => $field->key],
            ['value' => $value, 'source' => 'customer_stated', 'status' => 'valid', 'evidence_message_id' => $evidenceMessageId]
                + ($existing?->document_id ? ['document_id' => $existing->document_id, 'verified_at' => now()] : [])
        );

        return 'saved';
    }

    /** @return 'saved'|'conflict' */
    private function writeApplicationData(Application $application, RequirementField $field, string $party, mixed $value, ?int $evidenceMessageId): string
    {
        $existing = ApplicationData::where('application_id', $application->id)
            ->where('party', $party)
            ->where('field_key', $field->key)
            ->first();

        if ($this->protectedFromCustomerChange($field, $existing, $value)) {
            return 'conflict';
        }

        ApplicationData::updateOrCreate(
            ['application_id' => $application->id, 'party' => $party, 'field_key' => $field->key],
            ['value' => $value, 'source' => 'customer_stated', 'status' => 'valid', 'issue_code' => null, 'evidence_message_id' => $evidenceMessageId]
                + ($existing?->document_id ? ['document_id' => $existing->document_id] : [])
        );

        return 'saved';
    }
}
