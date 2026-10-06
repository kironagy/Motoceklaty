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
        $lines = BotLesson::where('is_active', true)->orderByDesc('id')->get()
            ->map(function (BotLesson $lesson) {
                $types = (array) ($lesson->scope_customer_types ?? []);

                return $this->lessons->line($lesson, false).($types !== [] ? ' (لـ '.implode('، ', $types).')' : '');
            })->all();

        return $lines === [] ? $base : $base."\n\n".self::HEADING."\n".implode("\n", $lines);
    }

    public static function withoutSection(string $text): string
    {
        $at = mb_strpos($text, self::HEADING);

        return $at === false ? $text : mb_substr($text, 0, $at);
    }
}
