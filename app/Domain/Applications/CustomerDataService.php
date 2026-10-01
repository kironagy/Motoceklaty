<?php

namespace App\Domain\Applications;

use App\Domain\Applications\Validators\FieldValidatorRegistry;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\Customer;
use App\Models\CustomerAttribute;
use App\Models\RequirementField;
use App\Models\WhatsappMessage;
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

            // Conversation 731: "لا يوجد رقم" for a school was saved as "مفيش
            // رقم" - not his words - refused, and he was asked for the
            // building number again and again. "There is none" is an answer
            // in any wording, once he actually said it.
            if (self::isAddressPart($key) && self::saysNone((string) $item['value'])
                && ($noneEvidence = $this->statements->messageMatching($conversationId, self::NONE_PATTERN, 4)) !== null) {
                $outcome = $this->writeApplicationOrCustomer($customer, $application, $field, 'لا يوجد', $noneEvidence);

                if ($outcome === null) {
                    $rejected[] = ['key' => $key, 'code' => 'NO_ACTIVE_APPLICATION'];
                } elseif ($outcome === 'conflict') {
                    $conflicts[] = ['key' => $key, 'code' => 'CONFLICTS_WITH_VERIFIED_VALUE'];
                } else {
                    $saved[] = $key;
                }

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

            // A workshop worker's home address was saved as his work address
            // too - the model copied the one address he gave into both.
            if ($key === 'work_address' && $this->copiesResidence($application, $fields, (string) $result->normalized, $conversationId)) {
                $rejected[] = ['key' => $key, 'code' => 'SAME_AS_RESIDENCE'];

                continue;
            }

            // Request 4276: "عماره 2 شقه 41" - 41 was saved as the floor.
            if (str_ends_with($key, '_floor') && $this->isApartmentNumber((string) $result->normalized, $evidenceMessageId)) {
                $rejected[] = ['key' => $key, 'code' => 'NOT_A_FLOOR'];

                continue;
            }

            // Request 4278: "شغل في ورشه" was saved as the work building number
            // and the work landmark - the word "ورشة" is neither.
            if ($code = $this->notAnAddressPart($key, (string) $result->normalized)) {
                $rejected[] = ['key' => $key, 'code' => $code];

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

    /** He said the building/floor/apartment/landmark has none, or he does not know it. */
    private const NONE_PATTERN = '/(?<!\p{L})(?:مفيش|مافيش|مفيهاش|مفيهوش|لا يوجد|مش موجود|ملهاش|مالهاش|ملوش|مالوش|بدون|من غير|مش عارف|معرفش|ماعرفش|مش فاكر|غير معروف)(?!\p{L})/u';

    private static function isAddressPart(string $key): bool
    {
        return (bool) preg_match('/_(?:building_no|floor|apartment|landmark)$/', $key);
    }

    private static function saysNone(string $value): bool
    {
        $text = \App\Support\ArabicTextNormalizer::normalize($value);

        return (bool) preg_match(self::NONE_PATTERN, $text) || (bool) preg_match('/^(?:لا|لا شي|غير محدد|لم يذكر|غير مذكور|none|n\/a|-)$/u', $text);
    }

    /** @return string|null 'conflict', 'saved'-like outcome, or null when there is no application */
    private function writeApplicationOrCustomer($customer, ?Application $application, RequirementField $field, string $value, int $evidenceMessageId): ?string
    {
        if ($field->scope === 'customer') {
            return $this->writeCustomerAttribute($customer, $field, $value, $evidenceMessageId);
        }

        if (! $application) {
            return null;
        }

        return $this->writeApplicationData($application, $field, $field->scope === 'guarantor' ? 'guarantor' : 'applicant', $value, $evidenceMessageId);
    }

    private function isApartmentNumber(string $value, ?int $messageId): bool
    {
        $digits = preg_replace('/\D+/', '', \App\Support\ArabicTextNormalizer::normalize($value));
        $text = $messageId ? \App\Support\ArabicTextNormalizer::normalize((string) WhatsappMessage::whereKey($messageId)->value('text')) : '';

        return $digits !== '' && preg_match('/شق[هة]\s*(?:رقم\s*)?'.$digits.'(?!\d)/u', $text)
            && ! preg_match('/(?:دور|الدور)\s*(?:رقم\s*)?'.$digits.'(?!\d)/u', $text);
    }

    /** A building number with no number in it, or a landmark that is only the kind of place. */
    private function notAnAddressPart(string $key, string $value): ?string
    {
        $text = \App\Support\ArabicTextNormalizer::normalize($value);

        // "عمارة السندباد" names the building - "ورشة" does not
        if (str_ends_with($key, '_building_no')
            && ! preg_match('/عمار|برج|فيلا|مبني|بيت|بلوك|\d|اول|تاني|ثاني|تالت|ثالث|رابع|خامس|سادس|سابع|تامن|ثامن|تاسع|عاشر|مفيش|مافيش|بدون|من غير|مش عارف|ملهاش|مالهاش|لا يوجد/u', $text)) {
            return 'NOT_A_BUILDING_NUMBER';
        }

        // "الشقه ملك" (he owns it) was saved as the apartment number.
        if ((str_ends_with($key, '_apartment') || str_ends_with($key, '_floor'))
            && ! preg_match('/\d|اول|تاني|ثاني|تالت|ثالث|رابع|خامس|سادس|سابع|تامن|ثامن|تاسع|عاشر|ارضي|بدروم|روف|سطح|اخير|مفيش|مافيش|لا يوجد|بيت مستقل|كله|كلها/u', $text)) {
            return str_ends_with($key, '_apartment') ? 'NOT_AN_APARTMENT_NUMBER' : 'NOT_A_FLOOR';
        }

        if (str_ends_with($key, '_landmark') && preg_match('/^(?:في |ف )?(?:ورشه|ورشتي|محل|شغل|شغلي|بيت|بيتي|عماره|مكان|شركه|مصنع)$/u', $text)) {
            return 'NOT_A_LANDMARK';
        }

        return null;
    }

    /** The work address is the residence address, and he never said he works where he lives. */
    private function copiesResidence(?Application $application, array $fields, string $workAddress, int $conversationId): bool
    {
        $residence = collect($fields)->firstWhere('key', 'address')['value']
            ?? ($application ? ApplicationData::where('application_id', $application->id)->where('party', 'applicant')
                ->where('field_key', 'address')->value('value') : null);

        if (blank($residence)) {
            return false;
        }

        $compact = fn (string $v) => preg_replace('/[\s\p{P}]+/u', '', \App\Support\ArabicTextNormalizer::normalize($v));
        [$home, $work] = [$compact((string) $residence), $compact($workAddress)];
        similar_text($home, $work, $percent);

        // "القاهرة، عزبة النخل، شارع الشيخ منصور" was cut from the home
        // address and saved as the work address.
        if ($percent < 90 && ($work === '' || ! str_contains($home, $work))) {
            return false;
        }

        return ! $this->statements->anyMessageMatches($conversationId,
            '/نفس (?:العنوان|عنوان|المكان|مكان)|(?:بشتغل|شغال|شغلي)\s+(?:\S+\s+)?(?:من|في|ف)\s+(?:البيت|بيت|بيتي)|تحت (?:البيت|بيت)|ورشه تحت|محل تحت|اونلاين|من البيت/u');
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
