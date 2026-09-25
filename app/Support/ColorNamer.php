<?php

namespace App\Support;

/**
 * The dashboard's color picker stores motorcycle image colors as hex codes
 * (#f50606), but customers ask for "الأحمر" / "سودا" / "red". This turns a
 * stored value into the Arabic name the model can say and match on, and
 * turns whatever the customer (or model) wrote into that same name, so both
 * sides meet on one vocabulary. A stored value that is not hex is already a
 * name and passes through.
 */
class ColorNamer
{
    /** Nearest-RGB palette: Arabic name => reference colors. */
    private const PALETTE = [
        'أسود' => ['000000', '1a1a1a', '2b2b2b'],
        'أبيض' => ['ffffff', 'f0f0f0', 'e6e6e6'],
        'رمادي' => ['808080', '5c5c5c', '9e9e9e', 'b0b0b0'],
        'أحمر' => ['ff0000', 'e00000', 'c81010'],
        'نبيتي' => ['5a1414', '451212', '330909', '4a2020'],
        'أزرق' => ['0000ff', '1010e0', '2020c0', '0a37f0'],
        'كحلي' => ['000080', '06136e', '01025c'],
        'لبني' => ['6a77ad', '87ceeb', '706bfc'],
        'أصفر' => ['ffff00', 'e0e010', 'e8c905', 'c9e012'],
        'أخضر' => ['00c000', '30c030', '18e310', '73cc35'],
        'برتقالي' => ['ff8000', 'ff6600'],
        'بني' => ['8b4513', '6b3a1e'],
    ];

    /** Normalized customer word => palette name. */
    private const SYNONYMS = [
        'اسود' => 'أسود', 'سودا' => 'أسود', 'سوداء' => 'أسود', 'سوده' => 'أسود', 'black' => 'أسود',
        'ابيض' => 'أبيض', 'بيضا' => 'أبيض', 'بيضاء' => 'أبيض', 'بيضه' => 'أبيض', 'white' => 'أبيض',
        'رمادي' => 'رمادي', 'رصاصي' => 'رمادي', 'فضي' => 'رمادي', 'سيلفر' => 'رمادي', 'gray' => 'رمادي', 'grey' => 'رمادي', 'silver' => 'رمادي',
        'احمر' => 'أحمر', 'حمرا' => 'أحمر', 'حمراء' => 'أحمر', 'حمره' => 'أحمر', 'red' => 'أحمر',
        'نبيتي' => 'نبيتي', 'عنابي' => 'نبيتي', 'مارون' => 'نبيتي', 'بورجندي' => 'نبيتي', 'maroon' => 'نبيتي',
        'ازرق' => 'أزرق', 'زرقا' => 'أزرق', 'زرقاء' => 'أزرق', 'زرقه' => 'أزرق', 'blue' => 'أزرق',
        'كحلي' => 'كحلي', 'navy' => 'كحلي',
        'لبني' => 'لبني', 'سماوي' => 'لبني',
        'اصفر' => 'أصفر', 'صفرا' => 'أصفر', 'صفراء' => 'أصفر', 'دهبي' => 'أصفر', 'ذهبي' => 'أصفر', 'yellow' => 'أصفر',
        'اخضر' => 'أخضر', 'خضرا' => 'أخضر', 'خضراء' => 'أخضر', 'green' => 'أخضر',
        'برتقالي' => 'برتقالي', 'اورانج' => 'برتقالي', 'orange' => 'برتقالي',
        'بني' => 'بني', 'brown' => 'بني',
    ];

    /** @return string[] the names the dashboard can pick from */
    public static function names(): array
    {
        return array_keys(self::PALETTE);
    }

    /** Display name for a stored color value. */
    public static function name(string $stored): string
    {
        $hex = self::hex($stored);

        return $hex === null ? trim($stored) : self::nearest($hex);
    }

    /** True when what the customer asked for is the same color as a stored value. */
    public static function matches(string $requested, string $stored): bool
    {
        $requestedHex = self::hex($requested);

        if ($requestedHex !== null && $requestedHex === self::hex($stored)) {
            return true;
        }

        return self::canonical($requested) === self::canonical(self::name($stored));
    }

    private static function canonical(string $word): string
    {
        $hex = self::hex($word);

        if ($hex !== null) {
            return ArabicTextNormalizer::normalize(self::nearest($hex));
        }

        $normalized = ArabicTextNormalizer::normalize($word);

        // Exact word first, so "لبني" is never read as the "بني" inside it.
        foreach ([false, true] as $partial) {
            foreach (self::SYNONYMS as $synonym => $name) {
                $synonym = ArabicTextNormalizer::normalize($synonym);

                if ($normalized === $synonym || ($partial && str_contains($normalized, $synonym))) {
                    return ArabicTextNormalizer::normalize($name);
                }
            }
        }

        return $normalized;
    }

    private static function hex(string $value): ?string
    {
        $value = strtolower(trim($value));

        return preg_match('/^#?([0-9a-f]{6})$/', $value, $m) ? $m[1] : null;
    }

    private static function nearest(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach (self::PALETTE as $name => $references) {
            foreach ($references as $reference) {
                [$rr, $rg, $rb] = self::rgb($reference);
                $distance = ($r - $rr) ** 2 + ($g - $rg) ** 2 + ($b - $rb) ** 2;

                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $name;
                }
            }
        }

        return $best;
    }

    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
