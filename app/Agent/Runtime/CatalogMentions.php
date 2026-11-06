<?php

namespace App\Agent\Runtime;

use App\Models\Machine;
use App\Support\ArabicTextNormalizer;
use Illuminate\Support\Facades\Cache;

/**
 * Which of our motorcycles a reply names, read from the catalog itself
 * (names, brand names and each machine's editable aliases) - never a list
 * in code. Two readings:
 *  - words(): every catalog word ("كيواي", "الليفان") with the machines it
 *    can mean, for "which model is this sentence about";
 *  - fullNames(): whole model names ("دايو 2", "بوكسر 150"), for "did the
 *    reply name a model nothing looked up".
 */
class CatalogMentions
{
    /** Words in names that say nothing about which motorcycle it is. */
    private const GENERIC = ['استيراد', 'فرز', 'تاني', 'اصلي', 'الاصلي', 'ماكس', 'max', 'scooter', 'scooters', 'electric', 'تروسيكلات', 'تروسيكل', 'اسكوتر'];

    /**
     * One maker, spelled in Arabic or Latin ("طلبك على Keeway" for a كيواي
     * machine). Spelling only - which machine is whose stays in the catalog.
     */
    private const SAME_BRAND = [['كيواي', 'keeway'], ['ليفان', 'لايفان', 'lifan'], ['هوجان', 'هوجن', 'hojan'], ['بينيلي', 'بنيلي', 'benelli'],
        ['باجاج', 'bajaj'], ['دايو', 'دايون', 'dayun'], ['فيجوري', 'vigory', 'vigorey'], ['وينج', 'wing'], ['اباتشي', 'apache']];

    /** Brand rows that are categories, not makers. */
    private const CATEGORY_BRANDS = ['scooters', 'electric scooters', 'تروسيكلات'];

    /** @return array<string, int[]> catalog word => the machines it can mean, in the text */
    public function words(string $text): array
    {
        $vocabulary = $this->vocabulary()['words'];
        $found = [];

        foreach ($this->tokens($text) as $token) {
            foreach ($this->withoutPrefixes($token) as $word) {
                if (isset($vocabulary[$word])) {
                    $found[$word] = $vocabulary[$word];

                    break;
                }
            }
        }

        return $found;
    }

    /** @return int[] machines whose whole name or alias is in the text */
    public function fullNames(string $text): array
    {
        $text = $this->normalize($text);
        $ids = [];
        $matches = [];

        foreach ($this->vocabulary()['names'] as $name => $machineIds) {
            if (preg_match_all('/(?<![\p{L}\p{N}])(?:و)?'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $text,$found,PREG_OFFSET_CAPTURE)) {
                foreach ($found[0] as [$value,$offset]) {
                    $eligible = $machineIds;
                    // A shorthand followed by an engine/model number cannot identify a model with a different number.
                    if (!preg_match('/\d/',$name) && preg_match('/^\s*(\d{2,4})(?!\d)/u',substr($text,$offset+strlen($value)),$number)) {
                        $eligible = array_values(array_filter($eligible,function($id)use($number){
                            return collect($this->namesOf($id))->contains(fn($own)=>preg_match('/(?<!\d)'.preg_quote($number[1],'/').'(?!\d)/u',$own));
                        }));
                    }
                    if ($eligible !== []) $matches[] = ['start'=>$offset,'end'=>$offset+strlen($value),'ids'=>$eligible];
                }
            }
        }
        usort($matches,fn($a,$b)=>($b['end']-$b['start'])<=>($a['end']-$a['start']));
        $accepted=[];
        foreach ($matches as $match) {
            foreach ($accepted as $span) if ($match['start']<$span['end'] && $match['end']>$span['start']) continue 2;
            $accepted[]=$match;
            $ids=array_merge($ids,$match['ids']);
        }

        return array_values(array_unique($ids));
    }

    /** @return string[] every normalized name and alias of a machine */
    public function namesOf(int $machineId): array
    {
        return $this->vocabulary()['by_machine'][$machineId] ?? [];
    }

    public function normalize(string $text): string
    {
        // "(cc200) هوجن ٤", "N-MAX", "G- Max": brackets and dashes are not part of the name
        $text = ArabicTextNormalizer::normalize(preg_replace('/[()\-–_]+/u', ' ', $text) ?? $text);
        $text = preg_replace('/(?<!\p{L})(?:وال|بال|ال|لل)(?=[a-z])/u', '', $text);
        $text = preg_replace('/(?<!\p{L})(?:ال)?(?:زد|زيد)(?!\p{L})/u', 'z', $text);
        $text = preg_replace('/(?<!\p{L})(?:ال)?(?:اف|إف)(?!\p{L})/u', 'f', $text);
        $text = preg_replace('/(?<!\p{L})(?:ال)?اكس\s*رود(?!\p{L})/u', 'x road', $text);
        $text = preg_replace('/(?<!\p{L})(و)?(?:كيوي|كيواي)(?!\p{L})/u', '$1keeway', $text);
        $text = preg_replace('/(?<!\p{L})(و)?كيت(?!\p{L})/u', '$1keet', $text);
        $text = preg_replace('/(?<=\p{L})(?=\d)|(?<=\d)(?=\p{L})/u', ' ', $text);
        $text = preg_replace('/(?<!\p{L})دايون(?!\p{L})/u', 'دايو', $text);
        $text = preg_replace('/(?<!\p{L})(دايو|هوجن|هوجان)\s*(\d+)\s*و\s*(\d+)(?!\d)/u', '$1 $2 و $1 $3', $text);
        $text = preg_replace('/(?<!\p{L})(?:اتنين|اثنين)(?!\p{L})/u', '2', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** @return string[] */
    private function tokens(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', $this->normalize($text), -1, PREG_SPLIT_NO_EMPTY);
    }

    /** "وليفان" / "بالليفان" / "الكيواي": the word itself first, then without و/ب/ال. */
    private function withoutPrefixes(string $token): array
    {
        $forms = [$token];

        foreach (['وال', 'بال', 'فال', 'لل', 'ال', 'و', 'ب', 'ل'] as $prefix) {
            if (str_starts_with($token, $prefix) && mb_strlen($token) - mb_strlen($prefix) >= 3) {
                $forms[] = mb_substr($token, mb_strlen($prefix));
            }
        }

        return $forms;
    }

    /** @return array{words: array<string, int[]>, names: array<string, int[]>, by_machine: array<int, string[]>} */
    private function vocabulary(): array
    {
        // Cached for ten minutes like reply_guard.latin_names: the workers live for days, the catalog changes.
        return Cache::remember('reply_guard.catalog_mentions.v6', 600, function () {
            $words = [];
            $names = [];
            $byMachine = [];

            foreach (Machine::with('brand')->where('is_active', true)->get() as $machine) {
                $brand = (string) $machine->brand?->name;
                $own = array_filter(array_merge([(string) $machine->name], array_map('strval', (array) ($machine->aliases ?? []))));
                // Customer shorthand is accepted only when the catalog makes it unique.
                $short = trim(preg_replace('/\b(?:scooter|scooters)\b|\bi\b/iu', ' ', (string)$machine->name));
                $short = trim(preg_replace('/\s+/u', ' ', $short));
                if ($short !== '') $own[] = $short;
                if (preg_match('/^([a-z]{1,})\s*\d+$/i', trim((string)$machine->name), $match)) $own[] = $match[1];
                $normalizedName = $this->normalize((string) $machine->name);
                // rk200 R -> rk200; keet 2000w -> keet. Shared shorthands remain ambiguous, never first-row wins.
                if (preg_match('/^(.+\d)\s+[a-z]$/i', $normalizedName, $match)) $own[] = $match[1];
                if (preg_match('/^([a-z]{2,}(?:\s+[a-z]+)*)\s+\d+(?:\s+[a-z])?$/i', $normalizedName, $match)) $own[] = $match[1];

                foreach ($own as $name) {
                    $normalized = $this->normalize($name);

                    // a whole name needs a letter and three characters ("بوكسر", "F16", not "ال")
                    if ((mb_strlen($normalized) >= 3 || preg_match('/^[a-z]$/', $normalized)) && preg_match('/\p{L}/u', $normalized)) {
                        $names[$normalized][] = $machine->id;
                        $byMachine[$machine->id][] = $normalized;
                    }
                }

                $sources = $own;

                if ($brand !== '' && ! in_array(mb_strtolower($brand), self::CATEGORY_BRANDS, true)) {
                    $sources[] = $brand;
                }

                foreach ($sources as $name) {
                    foreach ($this->tokens($name) as $token) {
                        if (mb_strlen($token) >= 3 && ! preg_match('/^\d+$/', $token) && ! in_array($token, self::GENERIC, true)) {
                            $words[$token][] = $machine->id;
                        }
                    }
                }
            }

            foreach (self::SAME_BRAND as $spellings) {
                $ids = array_merge(...array_map(fn ($w) => $words[$w] ?? [], $spellings));

                if ($ids !== []) {
                    foreach ($spellings as $spelling) {
                        $words[$spelling] = $ids;
                    }
                }
            }

            return [
                'words' => array_map(fn ($ids) => array_values(array_unique($ids)), $words),
                'names' => array_map(fn ($ids) => array_values(array_unique($ids)), $names),
                'by_machine' => $byMachine,
            ];
        });
    }
}
