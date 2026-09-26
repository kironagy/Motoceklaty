<?php

namespace App\Domain\Conversations;

use App\Models\Brand;
use App\Models\Machine;
use App\Models\WhatsappMessage;
use App\Support\ArabicTextNormalizer;

/**
 * The model the customer named in this turn ("دايو 4 استيراد"). Live: that
 * message was priced - and application 4278 opened - on "هوجن ٤ استيراد",
 * because both names carry a 4. A tool call on a motorcycle of another
 * brand, or another number, than the one he just named is refused.
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
    private const GENERIC = ['scooter', 'scooters', 'electric', 'max', 'sport', 'classic', 'light', 'road', 'music', 'tiger', 'اصلي', 'استيراد'];

    /** Words that mean he wants something else than what he named. */
    private const ASKS_ALTERNATIVE = '/زي|شبه|بديل|غير|تاني[هة]?|مثل|نفس|احسن من|ارخص من|اقل من|مقارن|قارن|ولا/u';

    /** An error detail when $machine contradicts the model he named this turn, else null. */
    public static function conflict(int $conversationId, Machine $machine): ?string
    {
        $vocabulary = self::vocabulary();

        foreach (self::currentTurnTexts($conversationId) as $raw) {
            $text = ArabicTextNormalizer::normalize($raw);

            if (! preg_match('/\d/', $text) || preg_match(self::ASKS_ALTERNATIVE, $text)) {
                continue;
            }

            $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
            $brands = [];
            $numbers = [];

            foreach ($tokens as $i => $token) {
                if (! isset($vocabulary[$token])) {
                    continue;
                }

                $brands = array_merge($brands, $vocabulary[$token]);

                if (isset($tokens[$i + 1]) && preg_match('/^\d{1,3}$/', $tokens[$i + 1])) {
                    $numbers[] = $tokens[$i + 1];
                }
            }

            if ($brands === []) {
                continue;
            }

            $brandNames = Brand::whereIn('id', array_unique($brands))->pluck('name')->map(fn ($n) => trim($n))->implode(' / ');

            if (! in_array((int) $machine->brand_id, $brands, true)) {
                return "The customer wrote \"{$raw}\" ({$brandNames}), but motorcycle {$machine->id} \"".trim($machine->name).'" is another brand. '
                    .'Find the model he named (search_motorcycles with his own words) and use its id. If we do not carry it, tell him '
                    .'"للأسف مش متوفرة عندنا حاليا" - never quote, show or open another model in its place unless he asks for one.';
            }

            preg_match_all('/\d+/', ArabicTextNormalizer::normalize($machine->name), $m);

            if ($numbers !== [] && $m[0] !== [] && array_intersect($numbers, $m[0]) === []) {
                return "The customer wrote \"{$raw}\", but motorcycle {$machine->id} \"".trim($machine->name).'" is a different model number. '
                    .'Use the model he named (search_motorcycles with his own words). If we do not carry it, say so - never swap in another model.';
            }
        }

        return null;
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

        foreach (Machine::where('is_active', true)->whereNotNull('brand_id')->get(['name', 'brand_id']) as $machine) {
            $first = self::letterTokens($machine->name)[0] ?? null;

            if ($first !== null) {
                $words[$first][] = (int) $machine->brand_id;
            }
        }

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
