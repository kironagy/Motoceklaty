<?php

namespace App\Support;

use App\Models\Branch;

/**
 * Owner 2026-11-09: branch replies reached customers with half addresses and
 * map links cut to "https://maps", all four branches run together in one
 * paragraph. A reply that lists our branches is rebuilt from the branches
 * table: every active branch, its full address and its full map link, each
 * in its own paragraph. A sentence of the model's that is not about a branch
 * (a question at the end) is kept.
 */
final class BranchListReply
{
    private const BROKEN_LINK = '#https?://maps(?!\.app\.goo\.gl/[A-Za-z0-9]+)(?:[^\s،,]*)#i';

    /** $looked: get_branch_information ran this turn - without it a branch named in a stock answer stays as written. */
    public static function repair(string $text, bool $looked = true, ?array $branches = null): string
    {
        if (! $looked) {
            return $text;
        }

        $branches ??= self::activeBranches();

        if ($branches === [] || trim($text) === '') {
            return $text;
        }

        $mentioned = array_filter($branches, fn (array $b) => self::mentions($text, $b));
        $broken = (bool) preg_match(self::BROKEN_LINK, $text);

        // one branch named in passing ("استلامها من فرع عين شمس") is no branch list
        if (count($mentioned) < 2 && ! ($broken && $mentioned !== [])) {
            return $text;
        }

        $before = [];
        $after = [];

        // Live 2026-11-10: splitting on every "." cut "https://maps.app.goo.gl/x" into
        // "https://maps." (dropped) and "app." "goo." "gl/x السبت للخميس..." (kept), and
        // the fragments reached the customer after the clean list. Links go first, then
        // a sentence ends only at a stop followed by a space or a line break.
        $plain = preg_replace('#(?:(?:https?://|www\.)|\b(?:maps\.)?(?:app\.)?goo\.gl/)\S*?(?=[.,،!؟?]*(?:\s|$))#iu', ' ', $text);

        foreach (preg_split('/(?<=[.!؟?])\s+|\n+/u', $plain) as $sentence) {
            $s = trim($sentence);

            // the model's own branch text goes; a question to him ("تحب تيجي أنهي فرع؟") stays
            if ($s === '' || ! preg_match('/\p{L}{2}/u', $s)) {
                continue;
            }

            $question = (bool) preg_match('/[؟?]$/u', $s);

            if (! $question && preg_match('/(?:فروع|عنوان|عناوين|لوكيشن|مكان|مواعيد|السبت|الخميس|الجمع[هة]|صباح|ضهر|ظهر|الساع[هة])/u', $s)) {
                continue;
            }

            if (array_filter($branches, fn (array $b) => self::mentions($s, $b)) !== []) {
                continue;
            }

            $question ? $after[] = $s : $before[] = $s;
        }

        $blocks = array_map([self::class, 'block'], $branches);
        $intro = count($branches) > 1 ? 'عندنا '.count($branches).' فروع:' : 'فرعنا:';

        // "مفيش فرع في المنيا" answers him first; a question to him closes the message
        return trim(($before !== [] ? implode(' ', $before)."\n\n" : '')
            .$intro."\n\n".implode("\n\n", $blocks)
            .($after !== [] ? "\n\n".implode(' ', $after) : ''));
    }

    public static function block(array $branch): string
    {
        $name = trim((string) $branch['name']);
        $name = preg_match('/^فرع/u', $name) ? $name : 'فرع '.$name;
        $lines = ['*'.$name.'*'];

        if (filled($branch['address'] ?? null)) {
            $lines[] = 'العنوان: '.trim((string) $branch['address']);
        }

        if (filled($branch['map_url'] ?? null)) {
            $lines[] = 'اللوكيشن: '.trim((string) $branch['map_url']);
        }

        $hours = array_filter((array) ($branch['working_hours'] ?? []), fn ($v) => filled($v));

        if ($hours !== []) {
            $lines[] = 'المواعيد: '.implode('، ', array_map(
                fn ($days, $time) => is_string($days) && ! is_numeric($days) ? $days.' '.$time : (string) $time,
                array_keys($hours),
                $hours
            ));
        }

        return implode("\n", $lines);
    }

    private static function mentions(string $text, array $branch): bool
    {
        $haystack = ArabicTextNormalizer::normalize($text);

        if (filled($branch['map_url'] ?? null) && str_contains($text, (string) $branch['map_url'])) {
            return true;
        }

        foreach ([preg_replace('/^فرع\s*/u', '', (string) $branch['name']), (string) ($branch['city'] ?? '')] as $place) {
            $place = ArabicTextNormalizer::normalize(trim((string) $place));

            if (mb_strlen($place) >= 3 && str_contains($haystack, $place)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array> */
    private static function activeBranches(): array
    {
        try {
            return Branch::where('is_active', true)->orderBy('sort')->orderBy('id')
                ->get(['name', 'city', 'address', 'map_url', 'working_hours'])->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
