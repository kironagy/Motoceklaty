<?php

namespace App\Domain\Teaching;

use App\Domain\Settings\AgentInstructions;
use App\Domain\Settings\AgentSettings;
use App\Models\AgentInstructionVersion;
use App\Models\AgentSetting;
use App\Models\BotLesson;
use App\Models\TeachingChange;
use Illuminate\Support\Facades\DB;

class ChangeApplier
{
    public const LESSON_FIELDS = ['title', 'rule', 'fixed_facts', 'example_context', 'example_reply', 'scope_customer_types', 'scope_stage', 'priority'];

    public function __construct(private readonly AgentInstructions $instructions)
    {
    }

    /** Throws before touching anything when the change is invalid. */
    public function validate(TeachingChange $change): void
    {
        $after = (array) $change->after;

        match ($change->kind) {
            'lesson' => trim((string) ($after['fields']['rule'] ?? '')) !== '' || throw new \InvalidArgumentException('الدرس من غير قاعدة'),
            'instruction' => $this->locate($change),
            'setting' => isset(AgentSettings::definitions()[$change->target_id]) || throw new \InvalidArgumentException("الإعداد {$change->target_id} مش موجود"),
            'data_update' => $this->castAll($change->target_type, $after) && $this->record($change),
            'data_create' => EditableEntities::canCreate($change->target_type) && $this->castAll($change->target_type, $after)
                || throw new \InvalidArgumentException('مينفعش أضيف '.EditableEntities::label($change->target_type).' جديد'),
            default => throw new \InvalidArgumentException("نوع تغيير مش معروف: {$change->kind}"),
        };
    }

    public function apply(TeachingChange $change, ?int $staffId = null): void
    {
        $this->validate($change);

        DB::transaction(function () use ($change, $staffId) {
            $after = (array) $change->after;

            switch ($change->kind) {
                case 'lesson':
                    $lesson = isset($after['lesson_id']) ? BotLesson::find($after['lesson_id']) : null;
                    $fields = array_intersect_key($after['fields'], array_flip(self::LESSON_FIELDS));

                    if ($lesson) {
                        $change->before = $lesson->only(self::LESSON_FIELDS) + ['is_active' => $lesson->is_active];
                        $lesson->update($fields + ['revision' => $lesson->revision + 1, 'is_active' => true]);
                    } else {
                        $lesson = BotLesson::create($fields + [
                            'teaching_session_id' => $change->teaching_session_id,
                            'source_message_id' => $change->session?->target_message_id,
                            'created_by' => $staffId,
                        ]);
                    }

                    $change->target_id = (string) $lesson->id;
                    break;

                case 'instruction':
                    $current = $this->instructions->current();
                    // An empty `find` adds a new rule of its own at the end.
                    $text = (string) ($after['find'] ?? '') === ''
                        ? rtrim($current['text'])."\n\n".trim((string) $after['replace'])."\n"
                        : str_replace($after['find'], $after['replace'], $current['text']);
                    $change->before = ['version_id' => $current['id']];
                    $version = $this->instructions->publish($text,'من وضع التعليم: '.$change->summary, $staffId);
                    $change->target_id = (string) $version->id;
                    break;

                case 'setting':
                    $change->before = ['value' => AgentSetting::where('key', $change->target_id)->value('value')];
                    AgentSettings::save([$change->target_id => $after['value'] ?? null], $staffId);
                    break;

                case 'data_update':
                    $record = $this->record($change);
                    $values = $this->castAll($change->target_type, $after);
                    $change->before = collect(array_keys($values))->mapWithKeys(fn ($f) => [$f => $record->getAttribute($f)])->all();
                    $record->update($values);
                    break;

                case 'data_create':
                    $model = EditableEntities::model($change->target_type);
                    $change->target_id = (string) $model::create($this->castAll($change->target_type, $after))->id;
                    break;
            }

            $change->status = 'applied';
            $change->error = null;
            $change->applied_at = now();
            $change->applied_by = $staffId;
            $change->save();
        });
    }

    public function revert(TeachingChange $change, bool $force = false): void
    {
        if ($change->status !== 'applied') {
            throw new \RuntimeException('التغيير ده مش متطبق');
        }

        DB::transaction(function () use ($change, $force) {
            $before = (array) $change->before;

            switch ($change->kind) {
                case 'lesson':
                    $lesson = BotLesson::findOrFail($change->target_id);
                    $before === [] ? $lesson->update(['is_active' => false]) : $lesson->update($before + ['revision' => $lesson->revision + 1]);
                    break;

                case 'instruction':
                    $previous = isset($before['version_id']) ? AgentInstructionVersion::find($before['version_id']) : null;
                    $previous ? $this->instructions->activate($previous) : $this->instructions->useFile();
                    break;

                case 'setting':
                    AgentSettings::save([$change->target_id => $before['value'] ?? null]);
                    break;

                case 'data_update':
                    $record = $this->record($change);
                    $expected = $this->castAll($change->target_type, (array) $change->after);

                    foreach ($expected as $field => $value) {
                        if (! $force && $record->getAttribute($field) != $value) {
                            throw new \RuntimeException("حد عدّل {$field} بعد التغيير ده، الرجوع هيمسح تعديله");
                        }
                    }

                    $record->update($before);
                    break;

                case 'data_create':
                    $record = $this->record($change);
                    array_key_exists('is_active', EditableEntities::fields($change->target_type))
                        ? $record->update(['is_active' => false])
                        : throw new \RuntimeException('السجل ده مينفعش يتقفل، اقفله من لوحة التحكم');
                    break;
            }

            $change->update(['status' => 'reverted']);
        });
    }

    /**
     * The coach quotes the paragraph from memory: "الأوفر له" for the text's
     * "الأوفر ليه" was reported as "the paragraph is not in the
     * instructions". An exact match is used as is; otherwise the one line
     * (or run of lines) that is clearly the same paragraph is taken, and
     * `find` is rewritten to its exact text so the diff shown is real.
     */
    private function locate(TeachingChange $change): bool
    {
        $after = (array) $change->after;
        $find = (string) ($after['find'] ?? '');
        $text = $this->instructions->current()['text'];

        // A new rule with nothing to replace: appended as it is.
        if ($find === '') {
            return trim((string) ($after['replace'] ?? '')) !== '' || throw new \InvalidArgumentException('التعليمة الجديدة فاضية');
        }

        $count = $find === '' ? 0 : substr_count($text, $find);

        if ($count > 1) {
            throw new \InvalidArgumentException('الفقرة موجودة أكتر من مرة في التعليمات، محتاج جزء أوضح');
        }

        if ($count === 1) {
            return true;
        }

        $exact = $find === '' ? null : $this->closestPassage($text, $find);

        if ($exact === null) {
            throw new \InvalidArgumentException('الفقرة اللي عايز أعدلها مش موجودة في التعليمات الحالية');
        }

        $change->after = ['find' => $exact] + $after;

        return true;
    }

    private function closestPassage(string $text, string $find): ?string
    {
        $lines = explode("\n", $text);
        $span = max(1, count(array_filter(explode("\n", trim($find)), fn ($l) => trim($l) !== '')));
        $target = $this->comparable($find);
        $scores = [];

        for ($i = 0; $i + $span <= count($lines); $i++) {
            $passage = implode("\n", array_slice($lines, $i, $span));

            if (trim($passage) === '' || substr_count($text, $passage) !== 1) {
                continue;
            }

            $candidate = $this->comparable($passage);
            similar_text($target, $candidate, $percent);
            $scores[] = [$percent, $passage];
        }

        usort($scores, fn ($a, $b) => $b[0] <=> $a[0]);
        [$best, $second] = [$scores[0] ?? null, $scores[1] ?? null];

        // Clearly the same paragraph, and not a near-tie with another one.
        return $best && $best[0] >= 85 && (! $second || $best[0] - $second[0] >= 5) ? $best[1] : null;
    }

    private function comparable(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', '', \App\Support\ArabicTextNormalizer::normalize($value))));
    }

    private function castAll(string $entity, array $values): array
    {
        if ($values === []) {
            throw new \InvalidArgumentException('مفيش قيم');
        }

        return collect($values)->mapWithKeys(fn ($v, $f) => [$f => EditableEntities::cast($entity, $f, $v)])->all();
    }

    private function record(TeachingChange $change): \Illuminate\Database\Eloquent\Model
    {
        if (! isset(EditableEntities::MAP[$change->target_type])) {
            throw new \InvalidArgumentException("مينفعش أعدّل {$change->target_type}");
        }

        $model = EditableEntities::model($change->target_type);

        return $model::find($change->target_id) ?? throw new \InvalidArgumentException(EditableEntities::label($change->target_type)." رقم {$change->target_id} مش موجود");
    }
}
