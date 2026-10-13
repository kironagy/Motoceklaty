<?php

namespace App\Domain\Conversations;

use App\Models\Brand;
use App\Models\Machine;
use App\Models\WhatsappMessage;
use App\Support\ArabicTextNormalizer;

/**
 * Brand words for search_motorcycles: which brand(s) a word in the agent's
 * query names ("هوجن", "سكوتر"), and conflict(): a motorcycle tool call on
 * another model than the one the customer named in this turn.
 */
class MentionedMotorcycle
{
    /** Spellings customers use that are not in the brand/machine names. */
    private const EXTRA_BRAND_WORDS = [
        'هوجان' => ['هجن', 'هوجين', 'هوجن', 'hogan', 'hojan'],
        'باجاج' => ['بجاج', 'bajaj'],
        'دايو' => ['دايوو', 'daewoo', 'daywoo'],
        'كيواي' => ['keeway', 'كيوي'],
        'بينيلي' => ['بنلي', 'بنيلي', 'بينلي', 'benelli'],
        'وينج' => ['وينغ', 'wing'],
        'فيجوري' => ['فيجورى', 'vigory', 'vigorey'],
        'تروسيكلات' => ['تروسيكل', 'تروسكل', 'تروسيكلات'],
        'TVS' => ['tvs'],
    ];

    /** Category words that name no single brand. */
    private const GENERIC = ['scooter', 'scooters', 'electric', 'max', 'sport', 'classic', 'light', 'road', 'music', 'tiger', 'اصلي', 'استيراد', 'فرز', 'تاني', 'تانى'];

    /** Words that mean he wants something else than what he named. */
    private const ASKS_ALTERNATIVE = '/زي|شبه|بديل|غير|تاني[هة]?|مثل|نفس|احسن من|ارخص من|اقل من|مقارن|قارن|ولا/u';

    /**
     * Live 2026-10-13 (conversation 639): "هوجان ٤" was priced, pictured
     * and put on the application as motorcycle 4 - Hogan's L250 (68,000)
     * - because of the 4. The same slip happened 9 times before 10-05 and
     * this check (removed in the rebuild) caught every one. A motorcycle of
     * another brand, model word or number than the one he named in this
     * turn is refused, unless a search this turn returned it.
     */
    public static function conflict(int $conversationId, int $turnId, Machine $machine): ?string
    {
        $texts = self::currentTurnTexts($conversationId);

        foreach ($texts as $raw) {
            $text = ArabicTextNormalizer::normalize($raw);

            // Simulator 2026-10-08: "ايه الفرق بين ال43 وال50" (prices in thousands) was looked up as
            // motorcycles 43 and 50; live 10-13 "هوجان ٤" became motorcycle 4. A number he wrote is
            // never a catalog id - unless it is part of that model's own name.
            // "دايو 4 على 3 سنين", "عندي 25 سنة": a number with a unit is a duration, age or amount
            preg_match_all('/(?<!\d)\d+(?!\d)(?!\s*(?:سن|شهر|شهور|اشهر|يوم|ايام|الف|جنيه|ج\b|٪|%|سي|cc|ك))/u', $text, $written);
            preg_match_all('/\d+/', ArabicTextNormalizer::normalize((string) $machine->name), $own);

            if (in_array((string) $machine->id, $written[0], true) && ! in_array((string) $machine->id, $own[0], true)) {
                return "The customer wrote \"{$raw}\" - his {$machine->id} is not a catalog id, and motorcycle {$machine->id} is \""
                    .trim($machine->name).'", which he did not name. Find what he means with search_motorcycles (his own words, '
                    .'or a price he wrote as min_cash_price/max_cash_price) and use the ids it returns; if unclear, ask him which model.';
            }
        }

        if (self::foundBySearchThisTurn($turnId, (int) $machine->id)) {
            return null;
        }

        $vocabulary = self::vocabulary();

        foreach ($texts as $raw) {
            $text = ArabicTextNormalizer::normalize($raw);

            if (preg_match(self::ASKS_ALTERNATIVE, $text)) {
                continue;
            }

            $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

            // "البوكسر ١٥٠" (no brand word) opened the application on "بلسر ١٥٠":
            // a model word he wrote must be in the motorcycle's own name.
            $modelWords = self::modelWords();
            $named = array_values(array_filter($tokens, fn ($t) => isset($modelWords[$t])));
            $machineWords = array_merge(self::letterTokens((string) $machine->name),
                ...array_map(fn ($alias) => self::letterTokens((string) $alias), (array) ($machine->aliases ?? [])));

            if ($named !== [] && array_intersect($named, $machineWords) === []) {
                return "The customer wrote \"{$raw}\", but motorcycle {$machine->id} \"".trim($machine->name).'" is another model. '
                    .'Use the model he named (search_motorcycles with his own words) and its id.';
            }

            $brands = [];
            $numbers = [];

            foreach ($tokens as $i => $token) {
                if (! isset($vocabulary[$token])) {
                    continue;
                }

                $brands = array_merge($brands, $vocabulary[$token]);

                if (isset($tokens[$i + 1]) && preg_match('/^\d{1,3}$/', $tokens[$i + 1])) {
                    $numbers[] = (int) $tokens[$i + 1];
                }
            }

            if ($brands === []) {
                continue;
            }

            // "Keeway keet 150" is filed under the brand "Scooters": the brand he named is in the model's own name.
            $nameHasBrand = collect(self::letterTokens((string) $machine->name))
                ->contains(fn ($token) => array_intersect($vocabulary[$token] ?? [], $brands) !== []);

            if (! in_array((int) $machine->brand_id, $brands, true) && ! $nameHasBrand) {
                $brandNames = Brand::whereIn('id', array_unique($brands))->pluck('name')->map(fn ($n) => trim($n))->implode(' / ');

                return "The customer wrote \"{$raw}\" ({$brandNames}), but motorcycle {$machine->id} \"".trim($machine->name).'" is another brand. '
                    .'Find the model he named (search_motorcycles with his own words) and use its id. If we do not carry it, say so - '
                    .'never quote, show or open another model in its place unless he asks for one.';
            }

            preg_match_all('/\d+/', ArabicTextNormalizer::normalize((string) $machine->name), $m);
            $nameNumbers = array_map('intval', $m[0]);
            $cc = \App\Domain\Catalog\CatalogService::engineCc($machine);

            foreach ($nameNumbers === [] ? [] : $numbers as $number) {
                // "هوجن 150 استيراد" is the engine size, not a model number (false refusals 10-01..10-03)
                if (! in_array($number, $nameNumbers, true) && ! ($number >= 100 && $cc === $number)) {
                    return "The customer wrote \"{$raw}\", but motorcycle {$machine->id} \"".trim($machine->name).'" is a different model number. '
                        .'Use the model he named (search_motorcycles with his own words). If we do not carry it, say so - never swap in another model.';
                }
            }
        }

        return null;
    }

    /** A search this turn listed the motorcycle - the agent looked it up with his words. */
    private static function foundBySearchThisTurn(int $turnId, int $machineId): bool
    {
        return \App\Models\AiTraceStep::where('turn_id', $turnId)
            ->where('tool_name', 'search_motorcycles')
            ->get(['result_redacted'])
            ->contains(fn ($step) => preg_match('/"id":'.$machineId.'[,}]/', json_encode($step->result_redacted) ?: ''));
    }

    /** @return array<string, true> distinctive words of our active model names (no brands, no category words) */
    private static function modelWords(): array
    {
        $brandWords = self::vocabulary();
        $generic = array_flip(array_map([ArabicTextNormalizer::class, 'normalize'], self::GENERIC));
        $words = [];

        foreach (Machine::where('is_active', true)->get(['name', 'aliases']) as $machine) {
            foreach (array_merge([(string) $machine->name], array_map('strval', (array) ($machine->aliases ?? []))) as $name) {
                foreach (self::letterTokens($name) as $token) {
                    if (! isset($brandWords[$token]) && ! isset($generic[$token])) {
                        $words[$token] = true;
                    }
                }
            }
        }

        return $words;
    }

    /** @return string[] what the customer sent since the last reply */
    private static function currentTurnTexts(int $conversationId): array
    {
        $lastOutgoing = WhatsappMessage::where('whatsapp_conversation_id', $conversationId)->where('direction', 'outgoing')->max('id') ?? 0;

        return WhatsappMessage::where('whatsapp_conversation_id', $conversationId)
            ->where('direction', 'incoming')->where('sender_type', 'customer')->where('id', '>', $lastOutgoing)
            ->get(['text', 'transcript'])
            ->map(fn ($m) => trim(($m->text ?? '').' '.($m->transcript ?? '')))
            ->filter()
            ->values()
            ->all();
    }

    /** Search words for a whole category, not one brand. */
    private const CATEGORY_WORDS = [
        'Scooters' => ['سكوتر', 'اسكوتر', 'سكوتير', 'اسكوتير', 'scooter', 'scooters', 'فيسبا'],
        'Electric scooters' => ['كهربا', 'كهرباء', 'كهربائي', 'كهربائيه', 'كهربايي', 'electric', 'سكوتر كهربا', 'اسكوتر كهربا'],
    ];

    /**
     * Brand ids when the whole query is one brand or category word, else [].
     *
     * @return int[]
     */
    public static function brandIdsFor(string $query): array
    {
        $query = trim(ArabicTextNormalizer::normalize($query));
        $ids = self::vocabulary()[$query] ?? [];

        foreach (Brand::all(['id', 'name']) as $brand) {
            foreach (self::CATEGORY_WORDS[trim($brand->name)] ?? [] as $word) {
                if (ArabicTextNormalizer::normalize($word) === $query) {
                    $ids[] = $brand->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }



    /** @return array<string, int[]> normalized word => brand ids */
    private static function vocabulary(): array
    {
        $words = [];
        $brands = Brand::all(['id', 'name']);

        foreach ($brands as $brand) {
            foreach (self::letterTokens($brand->name) as $token) {
                $words[$token][] = $brand->id;
            }

            foreach (self::EXTRA_BRAND_WORDS[trim($brand->name)] ?? [] as $extra) {
                $words[ArabicTextNormalizer::normalize($extra)][] = $brand->id;
            }
        }

        // Only brand words: a model name such as "VLR" is sold by two brands.
        $generic = array_map([ArabicTextNormalizer::class, 'normalize'], self::GENERIC);

        return array_map(fn ($ids) => array_values(array_unique(array_map('intval', $ids))),
            array_diff_key($words, array_flip($generic)));
    }

    /** @return string[] */
    private static function letterTokens(string $name): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', ArabicTextNormalizer::normalize($name), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($tokens, fn ($t) => mb_strlen($t) >= 3 && ! preg_match('/\d/', $t)));
    }
}
