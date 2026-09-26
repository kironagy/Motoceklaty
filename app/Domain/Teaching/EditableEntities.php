<?php

namespace App\Domain\Teaching;

use App\Models;

/**
 * What the coach may change. Anything outside this map is rejected; nothing
 * is ever deleted (is_active = false is the most it can do).
 */
class EditableEntities
{
    /** entity => [model, label, can_create, fields => type] (types: string|text|int|number|bool|json) */
    public const MAP = [
        'machine' => [Models\Machine::class, 'مكنة', true, [
            'name' => 'string', 'brand_id' => 'int', 'cash_price' => 'number', 'installment_price' => 'number',
            'old_price' => 'number', 'new_price' => 'number', 'type' => 'string', 'aliases' => 'json',
            'is_active' => 'bool', 'availability' => 'string', 'cc' => 'int', 'model_year' => 'int',
            'category' => 'string', 'description' => 'text', 'specifications' => 'json', 'colors' => 'json', 'features' => 'json',
        ]],
        'installment_system' => [Models\InstallmentSystem::class, 'نظام تقسيط', true, [
            'name' => 'string', 'pricing_mode' => 'string', 'administrative_fees' => 'number', 'minimum_down_payment' => 'number',
            'is_active' => 'bool', 'no_upfront_only' => 'bool', 'priority' => 'int', 'customer_type_ids' => 'json',
            'governorates' => 'json', 'max_financed_amount' => 'number', 'plans' => 'json',
        ]],
        'installment_plan' => [Models\InstallmentPlan::class, 'مدة تقسيط', true, [
            'installment_system_id' => 'int', 'months' => 'int', 'interest_percent' => 'number', 'is_active' => 'bool',
        ]],
        'eligibility_rule' => [Models\EligibilityRule::class, 'شرط أهلية', true, [
            'customer_type_id' => 'int', 'rule_type' => 'string', 'params' => 'json', 'is_active' => 'bool',
        ]],
        'application_requirement' => [Models\ApplicationRequirement::class, 'متطلب تقديم', true, [
            'customer_type_id' => 'int', 'requirement_type' => 'string', 'requirement_field_id' => 'int',
            'document_type_id' => 'int', 'is_required' => 'bool', 'condition' => 'json', 'sort' => 'int',
        ]],
        'requirement_field' => [Models\RequirementField::class, 'بيان مطلوب', true, [
            'key' => 'string', 'label' => 'string', 'data_type' => 'string', 'enum_options' => 'json', 'scope' => 'string',
            'is_sensitive' => 'bool', 'description_for_ai' => 'text', 'is_active' => 'bool',
        ]],
        // Creatable: the owner teaches new kinds of proof ("صورة عقد الإيجار").
        'document_type' => [Models\DocumentType::class, 'نوع مستند', true, [
            'key' => 'string', 'label' => 'string', 'description_for_ai' => 'text', 'extraction_fields' => 'json', 'optional_fields' => 'json',
            'validation_rules' => 'json', 'accepted_mimes' => 'json', 'is_active' => 'bool',
        ]],
        'branch' => [Models\Branch::class, 'فرع', true, [
            'name' => 'string', 'governorate' => 'string', 'city' => 'string', 'address' => 'text', 'map_url' => 'string',
            'phones' => 'json', 'working_hours' => 'json', 'services' => 'json', 'is_active' => 'bool',
        ]],
        'customer_type' => [Models\CustomerType::class, 'نوع عميل', true, [
            'key' => 'string', 'label' => 'string', 'legacy_work_status' => 'string', 'is_active' => 'bool', 'sort' => 'int',
        ]],
        'business_memory' => [Models\BusinessMemory::class, 'معلومة', true, [
            'key' => 'string', 'category' => 'string', 'title' => 'string', 'content' => 'text', 'priority' => 'int',
            'is_pinned' => 'bool', 'is_active' => 'bool',
        ]],
    ];

    public static function has(string $entity): bool
    {
        return isset(self::MAP[$entity]) || $entity === 'agent_setting';
    }

    public static function label(string $entity): string
    {
        return $entity === 'agent_setting' ? 'إعداد البوت' : (self::MAP[$entity][1] ?? $entity);
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    public static function model(string $entity): string
    {
        return self::MAP[$entity][0];
    }

    public static function canCreate(string $entity): bool
    {
        return self::MAP[$entity][2] ?? false;
    }

    /** @return array<string, string> */
    public static function fields(string $entity): array
    {
        return self::MAP[$entity][3] ?? [];
    }

    /**
     * @return mixed the value cast to the field's type
     *
     * @throws \InvalidArgumentException
     */
    public static function cast(string $entity, string $field, mixed $value): mixed
    {
        $type = self::fields($entity)[$field] ?? throw new \InvalidArgumentException("الحقل {$field} مش مسموح يتعدل في ".self::label($entity));

        if (is_string($value) && $type !== 'string' && $type !== 'text') {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'string', 'text' => is_scalar($value) ? (string) $value : throw new \InvalidArgumentException("{$field} لازم يكون نص"),
            'int' => is_numeric($value) && (int) $value == $value ? (int) $value : throw new \InvalidArgumentException("{$field} لازم يكون رقم صحيح"),
            'number' => is_numeric($value) && $value >= 0 ? $value + 0 : throw new \InvalidArgumentException("{$field} لازم يكون رقم موجب"),
            'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
                : throw new \InvalidArgumentException("{$field} لازم يكون صح أو غلط"),
            'json' => is_array($value) ? $value : throw new \InvalidArgumentException("{$field} لازم يكون قائمة أو JSON"),
        };
    }
}
