<?php

namespace App\Domain\Teaching;

use App\Domain\Settings\AgentInstructions;
use App\Domain\Settings\AgentSettings;
use App\Models\AiTrace;
use App\Models\AiTraceStep;
use App\Models\CustomerType;
use App\Models\Machine;
use App\Models\TeachingSession;
use App\Models\WhatsappMessage;
use Illuminate\Support\Str;

/** Everything the coach reads, as one compact text block. */
class CoachContext
{
    private const MACHINE_BRIEF = ['id', 'name', 'brand_id', 'cash_price', 'installment_price', 'old_price', 'new_price', 'type', 'aliases', 'is_active', 'availability', 'cc', 'model_year', 'category'];

    public function __construct(
        private readonly AgentInstructions $instructions,
        private readonly LessonBook $lessons,
    ) {
    }

    public function knowledge(string $focusText): string
    {
        $parts = [];
        $parts[] = "# تعليمات البوت الحالية (L0)\n".$this->instructions->current()['text'];

        $lessons = $this->lessons->active()->map(fn ($l) => "[lesson_id={$l->id}] ".$this->lessons->line($l, true))->implode("\n");
        $parts[] = "# الدروس الحالية\n".($lessons ?: 'لسه مفيش');

        $parts[] = "# مراحل المحادثة (scope_stage)\n".collect(LessonBook::STAGES)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n");
        $parts[] = "# أنواع العملاء\n".$this->json(CustomerType::get(['id', 'key', 'label', 'is_active']));

        foreach (EditableEntities::MAP as $entity => [$model, $label]) {
            $fields = array_keys(EditableEntities::fields($entity));
            $rows = $entity === 'machine'
                ? $this->machines($focusText)
                : $model::query()->get(array_merge(['id'], $fields))->toArray();

            $parts[] = "# {$label} (entity={$entity}) - الحقول المسموحة: ".implode(', ', $fields)."\n".$this->json($rows);
        }

        $settings = collect(AgentSettings::definitions())
            ->map(fn ($d, $key) => "{$key} ({$d[1]}, {$d[2]}) = ".json_encode(config("agent.{$key}"), JSON_UNESCAPED_UNICODE))
            ->implode("\n");
        $parts[] = "# إعدادات البوت (entity=agent_setting)\n{$settings}";
        $parts[] = '# أنواع البيانات المسموحة في requirement_field.data_type: '.implode(', ', array_keys((array) config('agent.field_validators')));

        return implode("\n\n", $parts);
    }

    /** The conversation up to (and including) the corrected turn, with what the bot did in it. */
    public function transcript(int $conversationId, ?int $targetMessageId): string
    {
        $messages = WhatsappMessage::where('whatsapp_conversation_id', $conversationId)
            ->when($targetMessageId, fn ($q) => $q->where('id', '<=', $targetMessageId))
            ->with('media')
            ->orderByDesc('id')->take(40)->get()->reverse();

        $target = $targetMessageId ? $messages->firstWhere('id', $targetMessageId) : null;
        $lines = [];

        foreach ($messages as $m) {
            $who = $m->direction === 'incoming' ? 'العميل' : 'البوت';
            $media = $m->media->isNotEmpty() ? ' [بعت '.($m->media->first()->media_type === 'image' ? 'صورة' : 'ملف').']' : '';
            $mark = $target && $m->direction === 'outgoing' && $m->turn_id === $target->turn_id ? '  <<< الرد اللي بيتصحح' : '';
            $lines[] = "{$who}{$media}: {$m->text}{$mark}";
        }

        if ($target?->turn_id && ($trace = AiTrace::where('turn_id', $target->turn_id)->first())) {
            $steps = AiTraceStep::where('trace_id', $trace->id)->where('kind', 'tool_call')->orderBy('seq')->get()
                ->reject(fn ($s) => $s->tool_name === 'send_reply')
                ->map(fn ($s) => "- {$s->tool_name}(".Str::limit(json_encode($s->args_redacted, JSON_UNESCAPED_UNICODE), 300).') => '
                    .Str::limit(json_encode($s->result_redacted, JSON_UNESCAPED_UNICODE), 900));

            if ($steps->isNotEmpty()) {
                $lines[] = "\n# اللي البوت عمله في الرد ده (أدوات ونتايجها)\n".$steps->implode("\n");
            }
        }

        $earlier = TeachingSession::where('conversation_id', $conversationId)->latest('id')->take(3)->get()->reverse();

        if ($earlier->isNotEmpty()) {
            $lines[] = "\n# آخر تعليم في المحادثة دي\n".$earlier->map(fn ($s) => "صاحب الشغل: {$s->owner_text}\nإنت فهمت: {$s->understanding}".($s->question ? "\nوسألت: {$s->question}" : ''))->implode("\n---\n");
        }

        return implode("\n", $lines);
    }

    private function machines(string $focusText): array
    {
        return Machine::query()->get()->map(function (Machine $m) use ($focusText) {
            $names = array_filter(array_merge([$m->name], (array) $m->aliases));
            $focused = collect($names)->contains(fn ($n) => mb_strlen($n) > 2 && mb_stripos($focusText, $n) !== false);

            return $focused
                ? $m->only(array_merge(['id'], array_keys(EditableEntities::fields('machine'))))
                : $m->only(self::MACHINE_BRIEF);
        })->all();
    }

    private function json(mixed $rows): string
    {
        return json_encode($rows, JSON_UNESCAPED_UNICODE);
    }
}
