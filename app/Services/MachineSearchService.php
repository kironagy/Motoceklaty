<?php

namespace App\Services;

use App\Models\Machine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use App\Models\Brand;
class MachineSearchService
{
    public function search(string $message, int $limit = 20): Collection
    {
        $query = $this->extractQuery($message);

        if ($query === '') {
            return collect();
        }

        $queryNorm = $this->normalizeSearchText($query);
        $queryCode = $this->normalizeModelCode($query);
        $queryTokens = $this->importantTokens($queryNorm);

        if ($queryNorm === '' && $queryCode === '') {
            return collect();
        }

        $family = $this->familyMatches($queryNorm, $queryCode, $queryTokens);

if ($family->isNotEmpty()) {
    return $family->take($limit)->values();
}

        $ranked = Machine::query()
            ->orderBy('id')
            ->get()
            ->map(function (Machine $machine) use ($queryNorm, $queryCode, $queryTokens) {
                return [
                    'machine' => $machine,
                    'score' => $this->scoreMachine($machine, $queryNorm, $queryCode, $queryTokens),
                ];
            })
            ->filter(fn ($row) => $row['score'] >= 900)
            ->sortByDesc('score')
            ->values();

        if ($ranked->isEmpty()) {
            return collect();
        }

        return collect([$ranked->first()['machine']]);
    }

    public function findBest(string $message): ?Machine
    {
        return $this->search($message, 1)->first();
    }

    public function debug(string $message): array
    {
        $query = $this->extractQuery($message);
        $queryNorm = $this->normalizeSearchText($query);
        $queryCode = $this->normalizeModelCode($query);
        $queryTokens = $this->importantTokens($queryNorm);
        $family = $this->familyMatches($queryNorm, $queryCode, $queryTokens);

        return [
            'message' => $message,
            'query' => $query,
            'query_norm' => $queryNorm,
            'query_code' => $queryCode,
            'query_tokens' => $queryTokens,
            'family_names' => $family->pluck('name')->values()->all(),
            'search_result' => $this->search($message)->pluck('name')->values()->all(),
            'scores' => Machine::query()
                ->orderBy('id')
                ->get()
                ->map(fn (Machine $machine) => [
                    'id' => $machine->id,
                    'name' => $machine->name,
                    'score' => $this->scoreMachine($machine, $queryNorm, $queryCode, $queryTokens),
                    'tokens' => $this->importantTokens($this->normalizeSearchText($machine->name)),
                    'code' => $this->normalizeModelCode($machine->name),
                ])
                ->sortByDesc('score')
                ->take(15)
                ->values()
                ->all(),
        ];
    }

private function familyMatches(string $queryNorm, string $queryCode, array $queryTokens): Collection
{
    $nonNumericTokens = array_values(array_filter(
        $queryTokens,
        fn ($token) => ! $this->isNumericToken($token)
    ));

    $queryBrandToken = $this->detectBrandOnlyToken($queryNorm, $queryCode, $nonNumericTokens, $queryTokens);

    if ($queryBrandToken) {
        return Machine::query()
            ->with('brand')
            ->orderBy('id')
            ->get()
            ->filter(function (Machine $machine) use ($queryBrandToken) {
                $brandName = $machine->brand?->name ?? '';
                $brandNorm = $this->normalizeSearchText($brandName);
                $brandCode = $this->normalizeModelCode($brandName);

                return $brandNorm === $queryBrandToken || $brandCode === $queryBrandToken;
            })
            ->values();
    }

    $brandTokens = $this->allBrandTokens();

    $queryBrandTokens = array_values(
        array_intersect($nonNumericTokens, $brandTokens)
    );

    $modelTokens = array_values(
        array_diff($nonNumericTokens, $brandTokens)
    );

    return Machine::query()
        ->with('brand')
        ->orderBy('id')
        ->get()
       ->filter(function (Machine $machine) use (
    $queryBrandTokens,
    $modelTokens,
    $queryTokens,
    $queryCode
) {
            $brandName = $machine->brand?->name ?? '';
            $brandNorm = $this->normalizeSearchText($brandName);
            $brandCode = $this->normalizeModelCode($brandName);

            if (! empty($queryBrandTokens)) {
                $brandMatched = false;

                foreach ($queryBrandTokens as $brandToken) {
                    if ($brandNorm === $brandToken || $brandCode === $brandToken) {
                        $brandMatched = true;
                        break;
                    }
                }

                if (! $brandMatched) {
                    return false;
                }
            }

            $hasNumericQueryToken = ! empty(array_filter(
                $queryTokens,
                fn ($token) => $this->isNumericToken($token)
            ));

            if (! empty($queryBrandTokens) && empty($modelTokens) && ! $hasNumericQueryToken) {
                return true;
            }

            foreach ($this->machineNames($machine) as $name) {
                $nameNorm = $this->normalizeSearchText($name);
                $nameTokens = $this->importantTokens($nameNorm);
$nameCode = $this->normalizeModelCode($name);

if (
    $queryCode !== ''
    && $nameCode !== ''
    && $nameCode === $queryCode
) {
    return true;
}
                foreach ($modelTokens as $token) {
                    if (! in_array($token, $nameTokens, true)) {
                        continue 2;
                    }
                }

                foreach ($queryTokens as $token) {
                    if ($this->isNumericToken($token) && ! in_array($token, $nameTokens, true)) {
                        continue 2;
                    }
                }

                return true;
            }

            return false;
        })
        ->values();
}
    private function scoreMachine(Machine $machine, string $queryNorm, string $queryCode, array $queryTokens): int
    {
        $best = 0;

        foreach ($this->machineNames($machine) as $rawName) {
            $nameNorm = $this->normalizeSearchText($rawName);
            $nameCode = $this->normalizeModelCode($rawName);
            $nameTokens = $this->importantTokens($nameNorm);

            $score = 0;

            /*
             * ممنوع رقم لوحده يعمل ماتش.
             */
            $hasStrongWordMatch = $this->hasStrongWordMatch($queryTokens, $nameTokens);
            $hasStrongCodeMatch = $this->hasStrongCodeMatch($queryCode, $nameCode);

            if (! $hasStrongWordMatch && ! $hasStrongCodeMatch) {
                continue;
            }

            if ($nameNorm !== '' && $queryNorm !== '') {
                if ($nameNorm === $queryNorm) {
                    $score += 3000;
                } elseif (str_contains($nameNorm, $queryNorm)) {
                    $score += 1800;
                } elseif (str_contains($queryNorm, $nameNorm)) {
                    $score += 1300;
                }
            }

            if ($nameCode !== '' && $queryCode !== '' && ! $this->isNumericToken($queryCode)) {
                if ($nameCode === $queryCode) {
                    $score += 3500;
                } elseif (str_starts_with($nameCode, $queryCode)) {
                    $score += 2600;
                } elseif ($this->codeAppearsAsPart($nameCode, $queryCode)) {
                    $score += 1500;
                }
            }

            foreach ($queryTokens as $token) {
                if ($this->isNumericToken($token)) {
                    continue;
                }

                if (in_array($token, $nameTokens, true)) {
                    $score += 700;
                } else {
                    foreach ($nameTokens as $nameToken) {
                        if ($this->tokensAreSimilar($token, $nameToken)) {
                            $score += 400;

                            break;
                        }
                    }
                }
            }

            foreach ($queryTokens as $token) {
                if (! $this->isNumericToken($token)) {
                    continue;
                }

                if (in_array($token, $nameTokens, true)) {
                    $score += 250;
                }
            }

            /*
             * Tie breaker:
             * Tx 250 يكسب Dayun Tx 250 لما العميل يقول تي اكس.
             */
            if (
                $queryCode !== ''
                && ! $this->isNumericToken($queryCode)
                && str_starts_with($nameCode, $queryCode)
            ) {
                $score += 500;
            }

            $best = max($best, $score);
        }

        return $best;
    }
public function isBrandOnlyRequest(string $message): bool
{
    $query = $this->extractQuery($message);

    if ($query === '') {
        return false;
    }

    $queryNorm = $this->normalizeSearchText($query);
    $queryCode = $this->normalizeModelCode($query);
    $queryTokens = $this->importantTokens($queryNorm);

    $nonNumericTokens = array_values(array_filter(
        $queryTokens,
        fn ($token) => ! $this->isNumericToken($token)
    ));

    return (bool) $this->detectBrandOnlyToken($queryNorm, $queryCode, $nonNumericTokens, $queryTokens);
}

private function detectBrandOnlyToken(string $queryNorm, string $queryCode, array $nonNumericTokens, array $queryTokens = []): ?string
{
    if (count($nonNumericTokens) !== 1) {
        return null;
    }

    /*
     * وجود رقم مع اسم البراند معناه العميل بيقصد موديل معين
     * ("هوجن ٤")، مش كل موديلات البراند.
     */
    if (count($queryTokens) !== count($nonNumericTokens)) {
        return null;
    }

    $token = $nonNumericTokens[0];

    foreach ($this->allBrandTokens() as $brandToken) {
        if ($token === $brandToken || $queryCode === $brandToken || $queryNorm === $brandToken) {
            return $brandToken;
        }
    }

    return null;
}

private function allBrandTokens(): array
{
    $tokens = [];

    \App\Models\Brand::query()
        ->get(['name'])
        ->each(function ($brand) use (&$tokens) {
            $name = trim((string) $brand->name);

            if ($name === '') {
                return;
            }

            $norm = $this->normalizeSearchText($name);
            $code = $this->normalizeModelCode($name);

            if ($norm !== '') {
                $tokens[] = $norm;
            }

            if ($code !== '') {
                $tokens[] = $code;
            }
        });

    /*
     * نيك نيمز/تعريب للبراندات.
     */
    $tokens = array_merge($tokens, [
        'tvs',
        'تي في اس',
        'فيجوري',
        'vigori',
        'بينيلي',
        'benelli',
        'هوجن',
        'هاوجن',
        'دايون',
        'دايو',
        'dayun',
    ]);

    return array_values(array_unique(array_filter($tokens)));
}
    private function hasStrongWordMatch(array $queryTokens, array $nameTokens): bool
    {
        foreach ($queryTokens as $token) {
            if ($this->isNumericToken($token)) {
                continue;
            }

            if (mb_strlen($token) < 2) {
                continue;
            }

            if (in_array($token, $nameTokens, true)) {
                return true;
            }

            foreach ($nameTokens as $nameToken) {
                if ($this->tokensAreSimilar($token, $nameToken)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * سماحية غلطة إملائية بسيطة (حرف واحد فرق) في الكلمات الطويلة نسبيًا،
     * عشان "هوجن" تتطابق مع "هوجان" أو "هوغن" لو العميل غلط في الكتابة.
     */
    private function tokensAreSimilar(string $a, string $b): bool
    {
        $lenA = mb_strlen($a);
        $lenB = mb_strlen($b);

        if ($lenA < 4 || $lenB < 4) {
            return false;
        }

        if (abs($lenA - $lenB) > 1) {
            return false;
        }

        $maxDistance = max($lenA, $lenB) >= 7 ? 2 : 1;

        return $this->mbLevenshtein($a, $b) <= $maxDistance;
    }

    /**
     * levenshtein() الأصلية بتشتغل على البايتات مش الحروف، وده غلط مع
     * العربي (كل حرف 2 بايت أو أكتر في UTF-8). النسخة دي بتشتغل على
     * مصفوفة حروف حقيقية.
     */
    private function mbLevenshtein(string $a, string $b): int
    {
        $chars1 = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chars2 = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $len1 = count($chars1);
        $len2 = count($chars2);

        $prev = range(0, $len2);

        for ($i = 1; $i <= $len1; $i++) {
            $curr = [$i];

            for ($j = 1; $j <= $len2; $j++) {
                $cost = $chars1[$i - 1] === $chars2[$j - 1] ? 0 : 1;

                $curr[$j] = min(
                    $prev[$j] + 1,
                    $curr[$j - 1] + 1,
                    $prev[$j - 1] + $cost
                );
            }

            $prev = $curr;
        }

        return $prev[$len2];
    }

    private function hasStrongCodeMatch(string $queryCode, string $nameCode): bool
    {
        if ($queryCode === '' || $nameCode === '' || $this->isNumericToken($queryCode)) {
            return false;
        }

        if ($nameCode === $queryCode) {
            return true;
        }

        if (str_starts_with($nameCode, $queryCode)) {
            return true;
        }

        return $this->codeAppearsAsPart($nameCode, $queryCode);
    }

    private function codeAppearsAsPart(string $nameCode, string $queryCode): bool
    {
        if ($queryCode === '' || $this->isNumericToken($queryCode)) {
            return false;
        }

        if (mb_strlen($queryCode) === 1) {
            return preg_match('/^' . preg_quote($queryCode, '/') . '\d+/i', $nameCode) === 1;
        }

        /*
         * tx لا يمسك ktx.
         * tx يمسك tx250.
         */
        return str_starts_with($nameCode, $queryCode);
    }

    private function extractQuery(string $message): string
    {
        $text = $this->normalizeSearchText($message);

        $remove = [
            'عايز', 'عاوزه', 'عاوز', 'محتاج', 'هات', 'هاتلي', 'ابعت', 'ابعتلي',
            'وريني', 'وريلي', 'اشوف', 'شوفني',
            'صور', 'صوره', 'صورة', 'صورها', 'صورتها', 'شكلها', 'الوان', 'الوانها',
            'سعر', 'السعر', 'بكام', 'كام', 'كاش', 'ثمن',
            'المكنه', 'المكنة', 'مكنه', 'مكنة', 'موتوسيكل', 'موتسكل', 'سكوتر',
            'يا', 'فندم', 'لو', 'سمحت', 'بعد', 'اذنك', 'من', 'عن', 'بتاع', 'بتاعة', 'بتاعت',
            'دي', 'ده', 'دا', 'هو', 'هي', 'عندكم', 'موجود', 'موجوده', 'موجودة',
        ];

        foreach ($remove as $word) {
            $word = $this->normalizeSearchText($word);
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
        foreach ($this->memoryAliasesForMachine($machine) as $alias) {
            $names[] = $alias;
        }

        return array_values(array_unique(array_filter($names)));
    }
 
 
 
 
 
 private function memoryAliasesForMachine(Machine $machine): array
{
    static $map = null;

    if ($map === null) {
        $map = $this->loadMemoryAliasesMap();
    }

    $aliases = [];

    foreach ([$machine->name] as $name) {
        $key = $this->normalizeSearchText((string) $name);

        if (isset($map[$key])) {
            $aliases = array_merge($aliases, $map[$key]);
        }
    }

    return array_values(array_unique(array_filter($aliases)));
}

private function loadMemoryAliasesMap(): array
{
    if (! class_exists(\App\Services\AiMemoryResolver::class)) {
        return [];
    }

    $memory = app(\App\Services\AiMemoryResolver::class)
        ->memoryByExactTitle('المخزون والموديلات');

    if (! $memory) {
        return [];
    }

    $content = (string) ($memory->content ?? $memory->body ?? $memory->text ?? '');

    $map = [];

    foreach (preg_split('/\R/u', $content) as $line) {
        $line = trim($line);

        if ($line === '' || ! str_contains($line, '=')) {
            continue;
        }

        [$alias, $machineName] = array_map('trim', explode('=', $line, 2));

        if ($alias === '' || $machineName === '') {
            continue;
        }

        $key = $this->normalizeSearchText($machineName);
        $map[$key][] = $alias;
    }

    return $map;
}
 
 
 
 
 
 
 
 
    public function normalizeSearchText(string $text): string
    {
        $text = $this->arabicDigitsToEnglish($text);
        $text = mb_strtolower($text);

        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace('ؤ', 'و', $text);
        $text = str_replace('ئ', 'ي', $text);

        $text = preg_replace('/\bال(?=[\p{Arabic}]{2,})/u', '', $text);

        $replace = [
            'hogan' => 'هوجن',
            'hojon' => 'هوجن',
            'hogon' => 'هوجن',
            'haojin' => 'هوجن',
            'haojing' => 'هوجن',
            'haojiang' => 'هوجن',
            'haojang' => 'هوجن',
            'هوجان' => 'هوجن',
            'هوجين' => 'هوجن',
            'هوجون' => 'هوجن',
            'الهوجان' => 'هوجن',
            'الهوجن' => 'هوجن',

            'dayun' => 'دايون',
            'daion' => 'دايون',
            'دايو' => 'دايون',
            'ديوان' => 'دايون',
            'الدايو' => 'دايون',
            'الدايون' => 'دايون',

            'تي في اس' => 'tvs',
            'تي فى اس' => 'tvs',
            'تى فى اس' => 'tvs',

            'ار كيه' => 'rk',
            'ار كى' => 'rk',
            'اركيه' => 'rk',
            'اركي' => 'rk',
            'r k' => 'rk',

            'استراد' => 'استيراد',
            'وارد' => 'استيراد',
            'فرز ثاني' => 'فرز تاني',
            'فرز 2' => 'فرز تاني',
            'فرز تانى' => 'فرز تاني',
            'تانى' => 'تاني',
            'اصلى' => 'اصلي',
            'original' => 'اصلي',
            'اوريجينال' => 'اصلي',

            'واحده' => '1',
            'واحد' => '1',
            'اتنين' => '2',
            'اثنين' => '2',
            'إتنين' => '2',
            'تلاته' => '3',
            'تلاتة' => '3',
            'ثلاثة' => '3',
            'ثلاثه' => '3',
            'اربعه' => '4',
            'اربعة' => '4',
            'أربعة' => '4',
            'خمسه' => '5',
            'خمسة' => '5',
            'سته' => '6',
            'ستة' => '6',
            'سبعه' => '7',
            'سبعة' => '7',
            'تمانيه' => '8',
            'تمانية' => '8',
            'ثمانية' => '8',
            'تسعه' => '9',
            'تسعة' => '9',
            'عشره' => '10',
            'عشرة' => '10',
        ];

        uksort($replace, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $text = str_replace(array_keys($replace), array_values($replace), $text);
$text = preg_replace('/\b(\d+)\s*cc\b/i', '$1', $text);
$text = preg_replace('/\b(\d+)\s*سي\s*سي\b/u', '$1', $text);
        $text = preg_replace('/[^\p{Arabic}a-zA-Z0-9\s]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    public function normalizeModelCode(string $text): string
    {
        $text = $this->normalizeSearchText($text);

        $map = [
            'اي' => 'a',
            'اى' => 'a',
            'بي' => 'b',
            'بى' => 'b',
            'سي' => 'c',
            'سى' => 'c',
            'دي' => 'd',
            'دى' => 'd',
            'اف' => 'f',
            'جي' => 'g',
            'جى' => 'g',
            'اتش' => 'h',
            'ايتش' => 'h',
            'كي' => 'k',
            'كى' => 'k',
            'ال' => 'l',
            'ايل' => 'l',
            'ام' => 'm',
            'ان' => 'n',
            'او' => 'o',
            'كيو' => 'q',
            'ار' => 'r',
            'اس' => 's',
            'تي' => 't',
            'تى' => 't',
            'يو' => 'u',
            'في' => 'v',
            'فى' => 'v',
            'اكس' => 'x',
            'واي' => 'y',
            'واى' => 'y',
            'زد' => 'z',
        ];

        $tokens = preg_split('/\s+/u', $text);
        $out = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $clean = $token;

            if (str_starts_with($clean, 'ال') && mb_strlen($clean) > 2) {
                $without = mb_substr($clean, 2);

                if (isset($map[$without])) {
                    $clean = $without;
                }
            }

            if (isset($map[$clean])) {
                $out[] = $map[$clean];
                continue;
            }

            if (preg_match('/^[a-z0-9]+$/i', $clean)) {
                $out[] = strtolower($clean);
                continue;
            }

            if (preg_match('/^\d+$/', $clean)) {
                $out[] = $clean;
            }
        }

        $joined = implode('', $out);
        $joined = preg_replace('/[^a-z0-9]+/i', '', strtolower($joined));

        return trim($joined);
    }

    private function importantTokens(string $text): array
    {
        $text = $this->normalizeSearchText($text);

        $stopWords = [
            'عايز', 'عاوزه', 'عاوز', 'محتاج', 'هات', 'ابعت', 'وريني',
            'صوره', 'صورة', 'صور', 'صورها', 'صورتها', 'شكلها',
            'المكنه', 'مكنه', 'موتوسيكل', 'موتسكل', 'سكوتر',
            'دي', 'ده', 'دا', 'من', 'في', 'على', 'علي', 'عن', 'لو', 'يا', 'فندم',
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

if (mb_strlen($token) < 2 && ! $this->isNumericToken($token)) {
    return in_array($token, ['f', 'r', 'x', 'i', 's'], true);
}
            return true;
        });

        return array_values(array_unique($tokens));
    }

    private function arabicDigitsToEnglish(string $text): string
    {
        return str_replace(
            ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            $text
        );
    }

    private function isNumericToken(string $token): bool
    {
        return preg_match('/^\d+$/', $token) === 1;
    }
}