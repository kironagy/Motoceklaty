<?php

namespace App\Domain\Teaching;

use App\Domain\Settings\AgentInstructions;
use App\Models\BotLesson;

/**
 * Rebuild (one instruction source): the owner's lessons live INSIDE the
 * instruction text, in its last section, not as a separate prompt layer.
 * bot_lessons stays the record teach mode and the admin page edit; every
 * change to it rewrites that section and publishes a new instruction
 * version (so it is versioned and can be rolled back like any edit).
 * Newest first: when two lessons disagree the newer one is read first and
 * says so.
 */
class LessonSync
{
    public const HEADING = '## دروس صاحب الشغل (الأحدث فوق، ولو درسين اتعارضوا الأحدث هو الصح)';

    /** Dashboard business knowledge, folded in the same way (rescue 2026-11-08: edits there reached no prompt). */
    public const KNOWLEDGE_HEADING = '## المعرفة التجارية من الداشبورد (لو اتعارضت مع اللي فوق، الأحدث هو الصح)';

    public function __construct(
        private readonly AgentInstructions $instructions,
        private readonly LessonBook $lessons,
    ) {
    }

    public function sync(?int $staffId = null): bool
    {
        $current = $this->instructions->current()['text'];
        $updated = $this->withLessons($current);

        if (trim($updated) === trim($current)) {
            return false;
        }

        $this->instructions->publish($updated, 'دروس صاحب الشغل اتحدثت', $staffId);

        return true;
    }

    public function withLessons(string $text): string
    {
        $base = rtrim(self::withoutSection($text));
        // general knowledge only: a row scoped to a stage/customer type would change the text per
        // customer and break the provider cache, so scoped rows stay out (as since 2026-10-05)
        $knowledge = \App\Models\BusinessMemory::where('is_active', true)->orderBy('priority')->orderByDesc('updated_at')->get()
            ->filter(fn ($row) => (array) ($row->scope_customer_types ?? []) === [] && (array) ($row->scope_application_statuses ?? []) === [])
            ->map(function (\App\Models\BusinessMemory $row) {
                $types = (array) ($row->scope_customer_types ?? []);

                return '- '.trim($row->title).': '.trim(preg_replace('/\s*\n\s*/u', ' ', (string) $row->content)).($types !== [] ? ' (لـ '.implode('، ', $types).')' : '');
            })->all();
        if ($knowledge !== []) {
            $base .= "\n\n".self::KNOWLEDGE_HEADING."\n".implode("\n", $knowledge);
        }
        $lines = BotLesson::where('is_active', true)->orderByDesc('id')->get()
            ->map(function (BotLesson $lesson) {
                $types = (array) ($lesson->scope_customer_types ?? []);

                return $this->lessons->line($lesson, false).($types !== [] ? ' (لـ '.implode('، ', $types).')' : '');
            })->all();

        return $lines === [] ? $base : $base."\n\n".self::HEADING."\n".implode("\n", $lines);
    }

    public static function withoutSection(string $text): string
    {
        $cuts = array_filter([mb_strpos($text, self::KNOWLEDGE_HEADING), mb_strpos($text, self::HEADING)], fn ($at) => $at !== false);

        return $cuts === [] ? $text : mb_substr($text, 0, min($cuts));
    }
}
