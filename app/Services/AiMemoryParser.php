<?php

namespace App\Services;

use App\Models\AiMemory;
use Illuminate\Support\Collection;

class AiMemoryParser
{
    public function aliasRules(Collection $memories): array
    {
        $rules = [];

        foreach ($memories as $memory) {
            $lines = preg_split('/\r\n|\r|\n/u', (string) $memory->content);

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                /*
                 * يدعم:
                 * هوجن جامبو = هوجن 4
                 * هوجن جامبو => هوجن 4
                 * هوجن جامبو : هوجن 4
                 */
                if (! preg_match('/^(.+?)\s*(?:=>|=|:|：)\s*(.+)$/u', $line, $m)) {
                    continue;
                }

                $alias = trim($m[1]);
                $target = trim($m[2]);

                if ($alias === '' || $target === '') {
                    continue;
                }

                /*
                 * حماية بسيطة عشان ما ناخدش شرح طويل كأنه alias
                 */
                if (mb_strlen($alias) > 80 || mb_strlen($target) > 120) {
                    continue;
                }

                $rules[] = [
                    'alias' => $alias,
                    'target' => $target,
                    'memory_id' => $memory->id,
                    'memory_title' => $memory->title,
                ];
            }
        }

        return $rules;
    }

    public function templateReplies(AiMemory $memory): array
    {
        $items = $memory->template_replies ?? [];

        if (! is_array($items)) {
            return [];
        }

        $replies = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $reply = trim($item);
            } elseif (is_array($item)) {
                $reply = trim((string) ($item['reply'] ?? ''));
            } else {
                $reply = '';
            }

            if ($reply !== '') {
                $replies[] = $reply;
            }
        }

        return $replies;
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower($text);

        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ؤ', 'و', $text);
        $text = str_replace('ئ', 'ي', $text);

        $text = str_replace(
            ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            $text
        );

        $text = preg_replace('/[^\p{Arabic}a-zA-Z0-9\s]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    public function tokens(string $text): array
    {
        $text = $this->normalize($text);

        $stopWords = [
            'يا', 'فندم', 'لو', 'سمحت', 'بعد', 'اذنك',
            'عايز', 'عاوز', 'محتاج', 'ابعت', 'ابعتلي', 'هات', 'هاتلي',
            'من', 'عن', 'في', 'على', 'علي', 'ده', 'دي', 'دا',
        ];

        $tokens = preg_split('/\s+/u', $text);

        $tokens = array_filter($tokens, function ($token) use ($stopWords) {
            $token = trim($token);

            if ($token === '') {
                return false;
            }

            if (in_array($token, $stopWords, true)) {
                return false;
            }

            return mb_strlen($token) >= 2 || is_numeric($token);
        });

        return array_values(array_unique($tokens));
    }
}