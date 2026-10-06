<?php

namespace App\Domain\Conversations;

use App\Models\Brand;
use App\Support\ArabicTextNormalizer;

/**
 * Brand words for search_motorcycles: which brand(s) a word in the agent's
 * query names ("هوجن", "سكوتر"). Rebuild 2026-10-05: the gate that read the
 * customer's messages and refused a tool on "another model than he named"
 * is gone - which model he means is the agent's call; every tool result
 * names the motorcycle it used.
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
