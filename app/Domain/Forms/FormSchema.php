<?php

namespace App\Domain\Forms;

use App\Support\EgyptianNationalId;
use Illuminate\Support\Carbon;

/**
 * One form's definition and the rules the owner set on it (spec §4.1). The same class judges
 * the customer's page (live), every autosave and the final submit, so the browser and the
 * server never disagree.
 *
 * Stored shape (the dashboard builder writes it):
 *  {settings: {customer_type_id?, intro?},
 *   sections: [{key, title, description?, conditions?, match?, items: [{type, data: {...}}]}]}
 *
 * An item's data: key, label, help?, placeholder?, required?, binding?, options?
 * [{value, label, customer_type_id?}], rules {min, max, min_length, max_length, digits_only,
 * min_age, max_age, gender}, error_message?, conditions? [{field, prop?, operator, value?}],
 * match? (all|any), document_type?, instructions?, max_files?, accept?, message? (note/stop).
 */
class FormSchema
{
    /** Item types answered with uploaded files (counted, not typed). */
    public const FILE_TYPES = ['document', 'images'];

    /** Item types that hold no answer. */
    public const DISPLAY_TYPES = ['note', 'stop'];

    public const TYPES = [
        'text' => 'نص قصير',
        'textarea' => 'نص طويل',
        'number' => 'رقم',
        'money' => 'فلوس',
        'phone' => 'موبايل',
        'national_id' => 'رقم قومي',
        'date' => 'تاريخ',
        'select' => 'اختيار من قائمة',
        'radio' => 'اختيار بالأزرار',
        'checkbox' => 'اختيار متعدد',
        'yes_no' => 'نعم / لا',
        'address' => 'عنوان مفصّل',
        'document' => 'مستند (صورة / PDF / فيديو)',
        'images' => 'صور متعددة (سكرينات إثبات دخل...)',
        'machine' => 'المكنة',
        'plan' => 'نظام القسط',
        'group' => 'مجموعة متكررة (زي الضامنين)',
        'note' => 'نص توضيحي',
        'stop' => 'رسالة توقف التقديم',
    ];

    public const OPERATORS = [
        'equals' => 'يساوي',
        'not_equals' => 'لا يساوي',
        'in' => 'واحد من (افصل بفاصلة)',
        'gt' => 'أكبر من',
        'gte' => 'أكبر من أو يساوي',
        'lt' => 'أصغر من',
        'lte' => 'أصغر من أو يساوي',
        'filled' => 'متكتب / متختار',
        'empty' => 'فاضي',
    ];

    /** Values read off a national id that a condition can test. */
    public const NATIONAL_ID_PROPS = ['age' => 'السن', 'gender' => 'النوع (male / female)', 'governorate' => 'المحافظة'];

    private array $sections;

    public function __construct(private readonly array $schema)
    {
        $this->sections = $this->normalize($schema['sections'] ?? []);
    }

    public function raw(): array
    {
        return $this->schema;
    }

    public function settings(): array
    {
        return (array) ($this->schema['settings'] ?? []);
    }

    /** @return list<array{key: string, title: string, description: ?string, conditions: array, match: string, items: list<array>}> */
    public function sections(): array
    {
        return $this->sections;
    }

    /** Fields that hold an answer, by key. @return array<string, array> */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->sections as $section) {
            foreach ($section['items'] as $item) {
                if (! in_array($item['type'], self::DISPLAY_TYPES, true)) {
                    $fields[$item['key']] = $item;
                }
            }
        }

        return $fields;
    }

    public function field(string $key): ?array
    {
        return $this->fields()[$key] ?? null;
    }

    /** The field tied to a binding (one field per binding). */
    public function fieldBoundTo(string $binding): ?array
    {
        foreach ($this->fields() as $field) {
            if (($field['binding'] ?? null) === $binding) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Keys of everything shown with these answers. A condition on a hidden field sees it empty,
     * so hiding "نوع الشغل" also hides what depends on it.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, true>  item key => true (sections as "section:key")
     */
    public function visible(array $answers): array
    {
        $visible = [];
        $seen = [];

        foreach ($this->sections as $section) {
            $sectionShown = $this->conditionsMet($section['conditions'], $section['match'], $seen);

            if ($sectionShown) {
                $visible['section:'.$section['key']] = true;
            }

            foreach ($section['items'] as $item) {
                $shown = $sectionShown && $this->conditionsMet($item['conditions'], $item['match'], $seen);

                if ($shown) {
                    $visible[$item['key']] = true;

                    if (array_key_exists($item['key'], $answers)) {
                        $seen[$item['key']] = $answers[$item['key']];
                    }
                }
            }
        }

        return $visible;
    }

    /** Messages of the visible "stop" items: while any shows, the form cannot be sent. @return string[] */
    public function blockingMessages(array $answers): array
    {
        $visible = $this->visible($answers);
        $messages = [];

        foreach ($this->sections as $section) {
            foreach ($section['items'] as $item) {
                if ($item['type'] === 'stop' && isset($visible[$item['key']])) {
                    $messages[] = (string) ($item['message'] ?: $item['label'] ?: 'للأسف مش هينفع نكمّل الطلب.');
                }
            }
        }

        return $messages;
    }

    /**
     * What is wrong with one answer on its own (format and the owner's rules), or null.
     * Emptiness is not judged here - only on submit, and only for visible required fields.
     */
    public function validateValue(array $field, mixed $value): ?string
    {
        if ($this->isEmpty($field, $value)) {
            return null;
        }

        $rules = (array) ($field['rules'] ?? []);
        $custom = filled($field['error_message'] ?? null) ? (string) $field['error_message'] : null;
        $fail = fn (string $default) => $custom ?? $default;

        switch ($field['type']) {
            case 'phone':
                $digits = $this->digits((string) $value);

                if (! preg_match('/^01[0125]\d{8}$/', $digits)) {
                    return $fail('اكتب رقم موبايل مصري صحيح (11 رقم يبدأ بـ 01)');
                }
                break;

            case 'national_id':
                $parsed = app(EgyptianNationalId::class)->parse((string) $value);

                if (! $parsed['valid']) {
                    return $fail('الرقم القومي لازم يكون 14 رقم صحيح زي ما هو مكتوب في البطاقة');
                }

                if (filled($rules['min_age'] ?? null) && $parsed['age'] < (int) $rules['min_age']) {
                    return $fail('السن لازم يكون '.$rules['min_age'].' سنة أو أكتر');
                }

                if (filled($rules['max_age'] ?? null) && $parsed['age'] > (int) $rules['max_age']) {
                    return $fail('السن لازم ميزيدش عن '.$rules['max_age'].' سنة');
                }

                if (filled($rules['gender'] ?? null) && $parsed['gender'] !== $rules['gender']) {
                    return $fail($rules['gender'] === 'male' ? 'التقديم ده للرجالة بس' : 'التقديم ده للستات بس');
                }
                break;

            case 'number':
            case 'money':
                $number = $this->number($value);

                if ($number === null) {
                    return $fail('اكتب رقم بس');
                }

                if (filled($rules['min'] ?? null) && $number < (float) $rules['min']) {
                    return $fail('أقل قيمة مسموحة '.$rules['min']);
                }

                if (filled($rules['max'] ?? null) && $number > (float) $rules['max']) {
                    return $fail('أكبر قيمة مسموحة '.$rules['max']);
                }
                break;

            case 'date':
                try {
                    $date = Carbon::parse((string) $value);
                } catch (\Throwable) {
                    return $fail('اختار تاريخ صحيح');
                }

                if (filled($rules['min'] ?? null) && $date->lt(Carbon::parse($rules['min']))) {
                    return $fail('التاريخ لازم يكون بعد '.$rules['min']);
                }

                if (filled($rules['max'] ?? null) && $date->gt(Carbon::parse($rules['max']))) {
                    return $fail('التاريخ لازم يكون قبل '.$rules['max']);
                }
                break;

            case 'select':
            case 'radio':
                if (! in_array((string) $value, array_map(fn ($o) => (string) $o['value'], $field['options']), true)) {
                    return $fail('اختار من الاختيارات الموجودة');
                }
                break;

            case 'checkbox':
                $allowed = array_map(fn ($o) => (string) $o['value'], $field['options']);

                if (! is_array($value) || array_diff(array_map('strval', $value), $allowed) !== []) {
                    return $fail('اختار من الاختيارات الموجودة');
                }
                break;

            case 'yes_no':
                if (! in_array($value, ['yes', 'no'], true)) {
                    return $fail('اختار نعم أو لا');
                }
                break;

            case 'address':
                if (! is_array($value)) {
                    return $fail('اكتب العنوان');
                }
                break;

            case 'group':
                if (! is_array($value)) {
                    return $fail('البيانات مش مظبوطة');
                }

                foreach (array_values($value) as $i => $row) {
                    foreach ($field['fields'] as $sub) {
                        $message = $this->validateValue($sub, is_array($row) ? ($row[$sub['key']] ?? null) : null);

                        if ($message !== null) {
                            return $sub['label'].' ('.($i + 1).'): '.$message;
                        }
                    }
                }
                break;
        }

        if (in_array($field['type'], ['text', 'textarea', 'phone', 'national_id', 'number', 'money'], true)) {
            $text = trim((string) $value);
            $length = mb_strlen($text);

            if (filled($rules['min_length'] ?? null) && $length < (int) $rules['min_length']) {
                return $fail('لازم يكون '.$rules['min_length'].' حرف على الأقل');
            }

            if (filled($rules['max_length'] ?? null) && $length > (int) $rules['max_length']) {
                return $fail('لازم ميزيدش عن '.$rules['max_length'].' حرف');
            }

            if (! empty($rules['digits_only']) && ! preg_match('/^\d+$/', $this->latin($text))) {
                return $fail('اكتب أرقام بس');
            }
        }

        return null;
    }

    /**
     * Everything that stops a submit: visible required fields left empty, invalid answers and
     * visible stop messages.
     *
     * @param  array<string, int>  $fileCounts  document field key => files uploaded
     * @return array{fields: array<string, string>, blocking: string[]}
     */
    public function submitErrors(array $answers, array $fileCounts): array
    {
        $visible = $this->visible($answers);
        $errors = [];

        foreach ($this->fields() as $key => $field) {
            if (! isset($visible[$key])) {
                continue;
            }

            $value = in_array($field['type'], self::FILE_TYPES, true) ? ($fileCounts[$key] ?? 0) : ($answers[$key] ?? null);

            if ($this->isEmpty($field, $value)) {
                if ($field['required']) {
                    $errors[$key] = in_array($field['type'], self::FILE_TYPES, true) ? 'ارفع '.$field['label'] : 'مطلوب';
                }

                continue;
            }

            // "multiple images": at least min_files screenshots
            if ($field['type'] === 'images' && (int) $value < ($min = max(1, (int) ($field['min_files'] ?? 1)))) {
                $errors[$key] = "ارفع {$min} صور على الأقل (رفعت ".(int) $value.')';

                continue;
            }

            if ($field['type'] === 'group' && ($problem = $this->groupProblem($field, (array) $value)) !== null) {
                $errors[$key] = $problem;

                continue;
            }

            if ($field['type'] === 'address' && $field['required'] && ($missing = $this->missingAddressParts($field, (array) $value)) !== []) {
                $errors[$key] = 'كمّل: '.implode('، ', $missing);

                continue;
            }

            if (! in_array($field['type'], self::FILE_TYPES, true) && ($message = $this->validateValue($field, $value)) !== null) {
                $errors[$key] = $message;
            }
        }

        return ['fields' => $errors, 'blocking' => $this->blockingMessages($answers)];
    }

    /** @return array{done: int, total: int, missing: string[]} visible required fields */
    public function progress(array $answers, array $fileCounts): array
    {
        $visible = $this->visible($answers);
        $done = 0;
        $missing = [];

        foreach ($this->fields() as $key => $field) {
            if (! $field['required'] || ! isset($visible[$key])) {
                continue;
            }

            $value = in_array($field['type'], self::FILE_TYPES, true) ? ($fileCounts[$key] ?? 0) : ($answers[$key] ?? null);

            if ($this->isEmpty($field, $value)) {
                $missing[] = $field['label'];
            } else {
                $done++;
            }
        }

        return ['done' => $done, 'total' => $done + count($missing), 'missing' => $missing];
    }

    /** The customer type the answers choose: the work.status option's, else the form's fixed one. */
    public function customerTypeId(array $answers): ?int
    {
        $field = $this->fieldBoundTo('work.status');

        if ($field && isset($answers[$field['key']])) {
            foreach ($field['options'] as $option) {
                if ((string) $option['value'] === (string) $answers[$field['key']] && filled($option['customer_type_id'] ?? null)) {
                    return (int) $option['customer_type_id'];
                }
            }
        }

        return filled($this->settings()['customer_type_id'] ?? null) ? (int) $this->settings()['customer_type_id'] : null;
    }

    /**
     * Problems that stop a form from being published.
     *
     * @return string[]
     */
    public function publishProblems(): array
    {
        $problems = [];
        $keys = [];
        $bindings = [];

        foreach ($this->sections as $section) {
            foreach ($section['items'] as $item) {
                if (isset($keys[$item['key']])) {
                    $problems[] = "المفتاح \"{$item['key']}\" متكرر - كل حقل لازم يكون ليه مفتاح مختلف.";
                }

                $keys[$item['key']] = true;

                if (filled($item['binding'] ?? null)) {
                    if (isset($bindings[$item['binding']])) {
                        $problems[] = 'الربط "'.(FormBindings::get($item['binding'])['label'] ?? $item['binding']).'" مستخدم في حقلين.';
                    }

                    $bindings[$item['binding']] = true;
                }

                if (in_array($item['type'], ['select', 'radio', 'checkbox'], true) && $item['options'] === []) {
                    $problems[] = "الحقل \"{$item['label']}\" مفيهوش اختيارات.";
                }
            }
        }

        if ($keys === []) {
            $problems[] = 'الفورم فاضية.';
        }

        $workStatus = $this->fieldBoundTo('work.status');

        if (! $workStatus && blank($this->settings()['customer_type_id'] ?? null)) {
            $problems[] = 'لازم حقل مربوط بـ "نوع الشغل" أو تحدد نوع عميل ثابت للفورم من الإعدادات.';
        }

        if ($workStatus) {
            foreach ($workStatus['options'] as $option) {
                if (blank($option['customer_type_id'] ?? null)) {
                    $problems[] = "الاختيار \"{$option['label']}\" في نوع الشغل مش مربوط بنوع عميل.";
                }
            }
        }

        foreach (['request.machine' => 'المكنة', 'request.plan' => 'نظام القسط'] as $binding => $label) {
            if (! $this->fieldBoundTo($binding)) {
                $problems[] = "لازم حقل {$label} في الفورم.";
            }
        }

        return $problems;
    }

    public function isEmpty(array $field, mixed $value): bool
    {
        return match ($field['type']) {
            'document', 'images' => (int) $value <= 0,
            'checkbox' => ! is_array($value) || $value === [],
            'address' => ! is_array($value) || array_filter($value, fn ($v) => filled($v)) === [],
            'group' => $this->rows($value) === [],
            default => $value === null || (is_string($value) && trim($value) === '') || $value === [],
        };
    }

    /** A group's rows as one line per row, for staff. */
    public static function groupText(array $field, mixed $value): string
    {
        $lines = [];

        foreach ((new self([]))->rows($value) as $i => $row) {
            $parts = [];

            foreach ($field['fields'] ?? [] as $sub) {
                if (filled($row[$sub['key']] ?? null)) {
                    $parts[] = $sub['label'].': '.$row[$sub['key']];
                }
            }

            $lines[] = ($i + 1).') '.implode(' - ', $parts);
        }

        return implode("\n", $lines);
    }

    /** The group's rows that hold anything. @return list<array<string, mixed>> */
    public function rows(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], fn ($row) => is_array($row)
            && array_filter($row, fn ($v) => filled($v)) !== []));
    }

    /** Too few rows, or a row missing a required part. */
    private function groupProblem(array $field, array $value): ?string
    {
        $rows = $this->rows($value);
        $min = max($field['required'] ? 1 : 0, (int) ($field['min_items'] ?? 0));

        if (count($rows) < $min) {
            return 'لازم '.$min.' على الأقل';
        }

        foreach ($rows as $i => $row) {
            foreach ($field['fields'] as $sub) {
                if ($sub['required'] && blank($row[$sub['key']] ?? null)) {
                    return 'كمّل '.$sub['label'].' ('.($i + 1).')';
                }
            }
        }

        return null;
    }

    /** Required address parts the owner ticked, still empty. @return string[] */
    public function missingAddressParts(array $field, array $value): array
    {
        $parts = (array) ($field['address_parts'] ?? array_keys(FormBindings::ADDRESS_PARTS));
        $required = (array) ($field['address_required'] ?? ['governorate', 'area', 'street']);

        return array_values(array_map(
            fn ($part) => FormBindings::ADDRESS_PARTS[$part] ?? $part,
            array_filter($required, fn ($part) => in_array($part, $parts, true) && blank($value[$part] ?? null))
        ));
    }

    /** @param array<string, mixed> $seen answers of fields shown so far */
    private function conditionsMet(array $conditions, string $match, array $seen): bool
    {
        if ($conditions === []) {
            return true;
        }

        $results = array_map(fn (array $c) => $this->conditionMet($c, $seen), $conditions);

        return $match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    private function conditionMet(array $condition, array $seen): bool
    {
        $key = (string) ($condition['field'] ?? '');
        $value = $seen[$key] ?? null;
        $prop = $condition['prop'] ?? null;

        if ($prop && $value !== null) {
            $parsed = app(EgyptianNationalId::class)->parse((string) $value);
            $value = $parsed['valid'] ? ($parsed[$prop] ?? null) : null;
        }

        $expected = $condition['value'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $isEmpty = $value === null || $value === '' || $value === [];

        if (is_array($value) && ! in_array($operator, ['filled', 'empty'], true)) {
            // multi-choice: true when any chosen value matches
            return collect($value)->contains(fn ($v) => $this->compare((string) $v, $operator, $expected));
        }

        return match ($operator) {
            'filled' => ! $isEmpty,
            'empty' => $isEmpty,
            default => ! $isEmpty && $this->compare((string) $value, $operator, $expected),
        };
    }

    private function compare(string $value, string $operator, mixed $expected): bool
    {
        $expected = (string) $expected;
        $numeric = is_numeric($value) && is_numeric($expected);

        return match ($operator) {
            'equals' => $numeric ? (float) $value == (float) $expected : trim($value) === trim($expected),
            'not_equals' => $numeric ? (float) $value != (float) $expected : trim($value) !== trim($expected),
            'in' => in_array(trim($value), array_map('trim', explode(',', $expected)), true),
            'gt' => $numeric && (float) $value > (float) $expected,
            'gte' => $numeric && (float) $value >= (float) $expected,
            'lt' => $numeric && (float) $value < (float) $expected,
            'lte' => $numeric && (float) $value <= (float) $expected,
            default => false,
        };
    }

    private function normalize(array $sections): array
    {
        $normalized = [];

        foreach (array_values($sections) as $i => $section) {
            $items = [];

            foreach (array_values((array) ($section['items'] ?? [])) as $j => $item) {
                // the builder stores {type, data}; tests and seeds may write the flat item
                $data = isset($item['data']) && is_array($item['data']) ? $item['data'] + ['type' => $item['type'] ?? null] : $item;
                $type = (string) ($data['type'] ?? 'text');

                $items[] = array_merge($data, [
                    'type' => $type,
                    'key' => (string) (filled($data['key'] ?? null) ? $data['key'] : "s{$i}_f{$j}"),
                    'label' => (string) ($data['label'] ?? ''),
                    'required' => (bool) ($data['required'] ?? false),
                    'binding' => filled($data['binding'] ?? null) ? (string) $data['binding'] : null,
                    'options' => array_values(array_filter((array) ($data['options'] ?? []), fn ($o) => is_array($o) && filled($o['value'] ?? null))),
                    'rules' => array_filter((array) ($data['rules'] ?? []), fn ($v) => $v !== null && $v !== ''),
                    'conditions' => array_values(array_filter((array) ($data['conditions'] ?? []), fn ($c) => is_array($c) && filled($c['field'] ?? null))),
                    'match' => ($data['match'] ?? 'all') === 'any' ? 'any' : 'all',
                    'fields' => array_values(array_map(fn ($sub) => [
                        'key' => (string) ($sub['key'] ?? ''),
                        'label' => (string) ($sub['label'] ?? ''),
                        'type' => (string) ($sub['type'] ?? 'text'),
                        'required' => (bool) ($sub['required'] ?? false),
                        'maps_to' => $sub['maps_to'] ?? null,
                        'options' => [],
                        'rules' => array_filter((array) ($sub['rules'] ?? []), fn ($v) => $v !== null && $v !== ''),
                    ], array_filter((array) ($data['fields'] ?? []), fn ($sub) => is_array($sub) && filled($sub['key'] ?? null)))),
                ]);
            }

            $normalized[] = [
                'key' => (string) (filled($section['key'] ?? null) ? $section['key'] : "s{$i}"),
                'title' => (string) ($section['title'] ?? ''),
                'description' => $section['description'] ?? null,
                'conditions' => array_values(array_filter((array) ($section['conditions'] ?? []), fn ($c) => is_array($c) && filled($c['field'] ?? null))),
                'match' => ($section['match'] ?? 'all') === 'any' ? 'any' : 'all',
                'items' => $items,
            ];
        }

        return $normalized;
    }

    private function digits(string $value): string
    {
        return app(EgyptianNationalId::class)->normalizeDigits($value);
    }

    /** Arabic-Indic digits as 0-9, nothing else changed. */
    public function latin(string $value): string
    {
        return strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    public function number(mixed $value): ?float
    {
        $text = $this->latin(strtr(trim((string) $value), ['٫' => '.', ',' => '', '،' => '']));

        return is_numeric($text) ? (float) $text : null;
    }
}
