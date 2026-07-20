<?php

namespace App\Services;

use App\Models\Machine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MachineNameResolver
{

public function resolveOne(string $message): ?Machine
{
    $query = $this->extractMachineQuery($message);

    if ($query === '') {
        return null;
    }

    $variants = $this->queryVariants($query);

    if (empty($variants)) {
        return null;
    }

    $rows = Machine::query()
        ->orderBy('id')
        ->get()
        ->map(function (Machine $machine) use ($variants) {
            $rank = $this->rankMachine($machine, $variants);

            return [
                'machine' => $machine,
                'score' => $rank['score'],
                'priority' => $rank['priority'],
                'name_length' => $rank['name_length'],
            ];
        })
        ->filter(fn ($row) => $row['score'] >= 500)
        ->sort(function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }

            if ($a['name_length'] !== $b['name_length']) {
                return $a['name_length'] <=> $b['name_length'];
            }

            return $a['machine']->id <=> $b['machine']->id;
        })
        ->values();

    if ($rows->isEmpty()) {
        return null;
    }

    return $rows->first()['machine'];
}

public function resolve(string $message, int $limit = 1): Collection
{
    $machine = $this->resolveOne($message);

    return $machine ? collect([$machine]) : collect();
}


    public function debug(string $message, int $limit = 10): array
    {
        $query = $this->extractMachineQuery($message);
        $variants = $this->queryVariants($query);

        $results = Machine::query()
            ->get()
            ->map(function (Machine $machine) use ($variants) {
                return [
                    'id' => $machine->id,
                    'name' => $machine->name,
                    'score' => $this->rankMachine($machine, $variants),
                    'tokens' => $this->nameTokens($machine->name),
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();

        return [
            'message' => $message,
            'extracted_query' => $query,
            'variants' => $variants,
            'results' => $results,
        ];
    }

private function rankMachine(Machine $machine, array $queryVariants): array
{
    $best = [
        'score' => 0,
        'priority' => 0,
        'name_length' => 999999,
    ];

    foreach ($this->machineNames($machine) as $name) {
        $nameKey = $this->latinKey($name);
        $nameTokens = $this->nameTokens($name);
        $firstToken = $nameTokens[0] ?? '';
        $nameLength = mb_strlen($nameKey);

        foreach ($queryVariants as $variant) {
            $queryKey = $variant['key'];
            $queryTokens = $variant['tokens'];
            $isCode = $variant['is_code'];

            if ($queryKey === '') {
                continue;
            }

            $score = 0;
            $priority = 0;

            if ($isCode) {
                $codeScore = $this->scoreCodeAgainstName($queryKey, $nameTokens);

                /*
                 * مهم جدًا:
                 * لو query عبارة عن كود زي tx / f / h
                 * ومفيش match كود واضح، نرمي المكنة دي فورًا.
                 * كده KTX مش هتمسك مع tx.
                 */
                if ($codeScore <= 0) {
                    continue;
                }

                $score += $codeScore;

                if ($firstToken === $queryKey) {
                    $priority += 500;
                }

                if (str_starts_with($firstToken, $queryKey)) {
                    $priority += 400;
                }

                if (in_array($queryKey, $nameTokens, true)) {
                    $priority += 250;
                }
            } else {
                if ($queryKey === $nameKey) {
                    $score += 1300;
                    $priority += 500;
                }

                if (mb_strlen($queryKey) >= 3 && str_contains($nameKey, $queryKey)) {
                    $score += 500;
                }

                foreach ($queryTokens as $token) {
                    if ($token !== '' && in_array($token, $nameTokens, true)) {
                        $score += is_numeric($token) ? 100 : 220;
                    }
                }

                $percent = 0;
                similar_text($queryKey, $nameKey, $percent);

                if ($percent >= 85) {
                    $score += (int) round($percent * 2);
                }
            }

            if ($score > $best['score']
                || ($score === $best['score'] && $priority > $best['priority'])
                || ($score === $best['score'] && $priority === $best['priority'] && $nameLength < $best['name_length'])
            ) {
                $best = [
                    'score' => $score,
                    'priority' => $priority,
                    'name_length' => $nameLength,
                ];
            }
        }
    }

    return $best;
}
   private function scoreCodeAgainstName(string $code, array $nameTokens): int
{
    $code = strtolower(trim($code));
    $firstToken = strtolower($nameTokens[0] ?? '');

    if ($code === '' || $firstToken === '') {
        return 0;
    }

    /*
     * حرف واحد زي:
     * f / h / l / z
     *
     * لازم يمسك أول token فقط ويكون موديل واضح فيه رقم:
     * f250
     * h250
     * z250
     *
     * كده "اف" مش هتمسك HLX 150 F.
     */
    if (mb_strlen($code) === 1) {
        if (preg_match('/^' . preg_quote($code, '/') . '\d+/i', $firstToken)) {
            return 1200;
        }

        return 0;
    }

    /*
     * كود من حرفين أو أكتر:
     * tx يمسك:
     * Tx 250 لأن أول token = tx
     *
     * لكن مايمسكش:
     * KTX 250 لأن أول token = ktx مش tx
     */
    if ($firstToken === $code) {
        return 1400;
    }

    if (str_starts_with($firstToken, $code) && preg_match('/\d/', $firstToken)) {
        return 1300;
    }

    /*
     * لو الكود موجود كـ token بعد البراند:
     * Dayun Tx 250
     * ده valid بس أقل أولوية من Tx 250.
     */
    foreach ($nameTokens as $token) {
        $token = strtolower($token);

        if ($token === $code) {
            return 950;
        }

        if (str_starts_with($token, $code) && preg_match('/\d/', $token)) {
            return 900;
        }
    }

    return 0;
}
    private function queryVariants(string $query): array
    {
        $query = $this->normalizeArabic($query);

        $items = [];

        $englishTyped = $this->latinReadable($query);
        $items[] = [
            'source' => 'english_typed',
            'text' => $englishTyped,
            'is_code' => $this->looksLikeCode($englishTyped),
        ];

        $code = $this->arabicLetterWordsToEnglishCode($query);
        $items[] = [
            'source' => 'arabic_code_letters',
            'text' => $code,
            'is_code' => $this->looksLikeCode($code),
        ];

        $phonetic = $this->arabicToRoughLatin($query);
        $items[] = [
            'source' => 'arabic_phonetic',
            'text' => $phonetic,
            'is_code' => false,
        ];

        $final = [];

        foreach ($items as $item) {
            $text = trim((string) $item['text']);

            if ($text === '') {
                continue;
            }

            foreach ($this->expandLatinVariants($text) as $variantText) {
                $key = $this->latinKey($variantText);

                if ($key === '') {
                    continue;
                }

                $final[$key] = [
                    'source' => $item['source'],
                    'text' => $variantText,
                    'key' => $key,
                    'tokens' => $this->tokensFromLatin($variantText),
                    'skeleton' => $this->skeleton($key),
                    'is_code' => (bool) $item['is_code'],
                ];
            }
        }

        return array_values($final);
    }

    private function expandLatinVariants(string $text): array
    {
        $variants = [];

        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s]+/i', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $variants[] = $text;

        $tokens = preg_split('/\s+/u', $text);
        $compact = $this->compactCodeTokens($tokens);

        if ($compact !== $text) {
            $variants[] = $compact;
        }

        $variants[] = str_replace(' ', '', $text);

        return array_values(array_unique(array_filter($variants)));
    }

    private function compactCodeTokens(array $tokens): string
    {
        $out = [];
        $buffer = [];

        $flush = function () use (&$out, &$buffer) {
            if (! empty($buffer)) {
                $out[] = implode('', $buffer);
                $buffer = [];
            }
        };

        foreach ($tokens as $token) {
            $token = trim(strtolower($token));

            if ($token === '') {
                continue;
            }

            if (preg_match('/^[a-z]$/', $token) || preg_match('/^\d+$/', $token)) {
                $buffer[] = $token;
                continue;
            }

            $flush();
            $out[] = $token;
        }

        $flush();

        return trim(implode(' ', $out));
    }

    private function arabicLetterWordsToEnglishCode(string $text): string
    {
        $text = $this->normalizeArabic($text);

        $tokens = preg_split('/\s+/u', $text);
        $out = [];

        $map = $this->englishLetterWordMap();

        for ($i = 0; $i < count($tokens); $i++) {
            $token = trim($tokens[$i]);

            if ($token === '') {
                continue;
            }

            /*
             * دبل يو / دبل يـو => w
             */
            if (
                in_array($token, ['دبل', 'دابل'], true)
                && isset($tokens[$i + 1])
                && in_array($tokens[$i + 1], ['يو', 'يـو'], true)
            ) {
                $out[] = 'w';
                $i++;
                continue;
            }

            /*
             * التي / الاتش / الاف / الاكس
             * نشيل "ال" لو اللي بعدها كلمة حرف إنجليزي.
             */
            $cleanToken = $this->stripArabicAlFromLetterWord($token);

            if (isset($map[$cleanToken])) {
                $out[] = $map[$cleanToken];
                continue;
            }

            if (preg_match('/^\d+$/', $cleanToken)) {
                $out[] = $cleanToken;
                continue;
            }

            /*
             * لو الكلمة إنجليزية أصلًا نسيبها.
             */
            if (preg_match('/^[a-z0-9]+$/i', $cleanToken)) {
                $out[] = strtolower($cleanToken);
                continue;
            }

            /*
             * أي كلمة عربية مش حرف إنجليزي نحولها transliteration بسيط.
             */
            $out[] = $this->arabicToRoughLatin($cleanToken);
        }

        $text = trim(implode(' ', array_filter($out)));
        $text = preg_replace('/\s+/u', ' ', $text);

        return $this->compactCodeTokens(preg_split('/\s+/u', $text));
    }

    private function englishLetterWordMap(): array
    {
        return [
            // A
            'اي' => 'a',
            'اى' => 'a',
            'ايه' => 'a',
            'ايى' => 'a',

            // B
            'بي' => 'b',
            'بى' => 'b',
            'باء' => 'b',

            // C
            'سي' => 'c',
            'سى' => 'c',
            'سيه' => 'c',

            // D
            'دي' => 'd',
            'دى' => 'd',
            'دال' => 'd',

            // E
            'اييي' => 'e',
            'اي حرف' => 'e',

            // F
            'اف' => 'f',
            'افف' => 'f',

            // G
            'جي' => 'g',
            'جى' => 'g',
            'جيم' => 'g',

            // H
            'اتش' => 'h',
            'ايتش' => 'h',
            'هاتش' => 'h',
            'هتش' => 'h',

            // I
            'اي واي' => 'i',

            // J
            'جيه' => 'j',
            'جاي' => 'j',
            'جاى' => 'j',

            // K
            'كي' => 'k',
            'كى' => 'k',
            'كيه' => 'k',
            'كاي' => 'k',
            'كاى' => 'k',

            // L
            'ال' => 'l',
            'ايل' => 'l',
            'ايلل' => 'l',

            // M
            'ام' => 'm',
            'امم' => 'm',

            // N
            'ان' => 'n',
            'انن' => 'n',

            // O
            'او' => 'o',
            'اوه' => 'o',

            // P
            'بيي' => 'p',
            'پي' => 'p',
            'بىى' => 'p',

            // Q
            'كيو' => 'q',
            'كيوو' => 'q',

            // R
            'ار' => 'r',
            'ارر' => 'r',

            // S
            'اس' => 's',
            'اسس' => 's',

            // T
            'تي' => 't',
            'تى' => 't',
            'تيه' => 't',

            // U
            'يو' => 'u',
            'يوو' => 'u',

            // V
            'في' => 'v',
            'فى' => 'v',
            'ڤي' => 'v',
            'ڤى' => 'v',
            'فيي' => 'v',

            // W
            'دبليو' => 'w',
            'دابليو' => 'w',

            // X
            'اكس' => 'x',
            'اكسس' => 'x',
            'اكص' => 'x',

            // Y
            'واي' => 'y',
            'واى' => 'y',

            // Z
            'زد' => 'z',
            'زي' => 'z',
            'زى' => 'z',
            'زيت' => 'z',
        ];
    }

    private function stripArabicAlFromLetterWord(string $token): string
    {
        $map = $this->englishLetterWordMap();

        if (isset($map[$token])) {
            return $token;
        }

        if (str_starts_with($token, 'ال')) {
            $withoutAl = mb_substr($token, 2);

            if (isset($map[$withoutAl])) {
                return $withoutAl;
            }
        }

        return $token;
    }

    private function extractMachineQuery(string $message): string
    {
        $text = $this->normalizeArabic($message);

        /*
         * نشيل عبارات التقسيط والمدة عشان رقم 12 شهر مايدخلش كأنه موديل.
         */
        $text = preg_replace('/\b(?:6|٦|12|١٢|18|١٨|24|٢٤|36|٣٦)\s*(?:شهر|شهور)\b/u', ' ', $text);
        $text = preg_replace('/\b(?:سنه|سنة|سنتين|سنه ونص|سنة ونص|تلات سنين|ثلاث سنين)\b/u', ' ', $text);

        $remove = [
            'ابعتلي', 'ابعت', 'ابعتي', 'هاتلي', 'هات', 'وريني', 'وريلي', 'اشوف', 'شوفني',
            'صور', 'صوره', 'صورة', 'صورها', 'صورتها', 'شكلها', 'الوان', 'الوانها',
            'سعر', 'السعر', 'بكام', 'كام', 'كاش', 'ثمن',
            'قسط', 'تقسيط', 'القسط', 'اقسط', 'اقسطها', 'قسطها', 'تقسيطها', 'احسب', 'احسبها',
            'مقدم', 'هدفع', 'ادفع', 'دافع',
            'شهر', 'شهور', 'سنه', 'سنة', 'سنتين',
            'النظام', 'المتاح', 'نظام', 'على', 'علي',
            'متاح', 'موجود', 'موجوده', 'موجودة', 'عندكم', 'فيه', 'منها',
            'مواصفات', 'تفاصيل', 'امكانيات',
            'المكنه', 'المكنة', 'مكنه', 'مكنة', 'موتوسيكل', 'موتسكل', 'سكوتر',
            'يا', 'فندم', 'لو', 'سمحت', 'بعد', 'اذنك', 'من', 'عن', 'بتاع', 'بتاعة', 'بتاعت',
            'عايز', 'عاوز', 'محتاج', 'دي', 'ده', 'دا', 'هو', 'هي',
        ];

        foreach ($remove as $word) {
            $word = $this->normalizeArabic($word);
            $text = preg_replace('/(?:^|\s)' . preg_quote($word, '/') . '(?:\s|$)/u', ' ', $text);
        }

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function machineNames(Machine $machine): array
    {
        $names = [];

        if (! empty($machine->name)) {
            $names[] = $machine->name;
        }

        if (Schema::hasColumn('machines', 'aliases') && ! empty($machine->aliases)) {
            $aliases = $machine->aliases;

            if (is_string($aliases)) {
                $decoded = json_decode($aliases, true);
                $aliases = is_array($decoded) ? $decoded : explode(',', $aliases);
            }

            if (is_array($aliases)) {
                foreach ($aliases as $alias) {
                    if (is_string($alias) && trim($alias) !== '') {
                        $names[] = $alias;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    private function nameTokens(string $name): array
    {
        return $this->tokensFromLatin($this->latinReadable($name));
    }

    private function tokensFromLatin(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]+/i', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $rawTokens = preg_split('/\s+/u', $text);
        $tokens = [];

        foreach ($rawTokens as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $tokens[] = $token;

            preg_match_all('/[a-z]+|\d+/i', $token, $parts);

            foreach ($parts[0] ?? [] as $part) {
                $tokens[] = strtolower($part);
            }
        }

        $compact = $this->compactCodeTokens($rawTokens);

        if ($compact !== $text) {
            foreach (preg_split('/\s+/u', $compact) as $token) {
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    private function looksLikeCode(string $text): bool
    {
        $text = trim(strtolower($text));

        if ($text === '') {
            return false;
        }

        if (! preg_match('/^[a-z0-9\s]+$/i', $text)) {
            return false;
        }

        return (bool) preg_match('/[a-z]/i', $text);
    }

    private function latinReadable(string $text): string
    {
        $text = strtolower($text);
        $text = str_replace(['-', '_', '/', '\\', '.', ','], ' ', $text);
        $text = preg_replace('/[^a-z0-9\p{Arabic}\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    private function latinKey(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/i', '', $text);

        return trim($text);
    }

    private function skeleton(string $text): string
    {
        $text = strtolower($text);
        $text = str_replace(['ph', 'v'], ['f', 'f'], $text);
        $text = preg_replace('/[aeiou]+/i', '', $text);
        $text = preg_replace('/(.)\1+/u', '$1', $text);
        $text = preg_replace('/[^a-z0-9]+/i', '', $text);

        return trim($text);
    }

    private function arabicToRoughLatin(string $text): string
    {
        $text = $this->normalizeArabic($text);

        $map = [
            'ا' => 'a',
            'ب' => 'b',
            'ت' => 't',
            'ث' => 'th',
            'ج' => 'j',
            'ح' => 'h',
            'خ' => 'kh',
            'د' => 'd',
            'ذ' => 'z',
            'ر' => 'r',
            'ز' => 'z',
            'س' => 's',
            'ش' => 'sh',
            'ص' => 's',
            'ض' => 'd',
            'ط' => 't',
            'ظ' => 'z',
            'ع' => 'a',
            'غ' => 'gh',
            'ف' => 'f',
            'ق' => 'q',
            'ك' => 'k',
            'ل' => 'l',
            'م' => 'm',
            'ن' => 'n',
            'ه' => 'h',
            'و' => 'u',
            'ي' => 'y',
            'ء' => '',
            ' ' => ' ',
        ];

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        $out = '';

        foreach ($chars as $char) {
            if (isset($map[$char])) {
                $out .= $map[$char];
                continue;
            }

            if (preg_match('/[a-z0-9]/i', $char)) {
                $out .= strtolower($char);
            } else {
                $out .= ' ';
            }
        }

        $out = preg_replace('/\s+/u', ' ', $out);

        return trim($out);
    }

    private function normalizeArabic(string $text): string
    {
        $text = mb_strtolower($text);

        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
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
}