<?php

namespace App\Domain\Aman;

use App\Domain\Documents\OcrException;
use App\Domain\Documents\OcrProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Makes an ID card photo readable for Aman's own card reader: turns it upright
 * (Vision tells which way the words run) and cuts away the background around
 * the card, keeping the card's 85.6 x 54 mm shape. Aman refuses a sideways
 * photo of a card on a carpet ("من فضلك تأكد من ادخال 14 رقما").
 *
 * The result is kept on the public disk under aman-clean/. If the text cannot
 * be found, the original photo is used.
 */
class IdCardImage
{
    private const CARD_RATIO = 85.6 / 54;

    public function __construct(private OcrProvider $ocr)
    {
    }

    /** Path on the public disk of the cleaned photo (or the original when cleaning is not possible). */
    public function cleaned(string $path): string
    {
        $disk = Storage::disk('public');
        $out = 'aman-clean/'.md5($path.'|'.$disk->lastModified($path)).'.jpg';
        if ($disk->exists($out)) {
            return $out;
        }

        $bytes = $disk->get($path);
        $words = $this->words($path, $bytes, $disk->mimeType($path) ?: 'image/jpeg');
        $jpeg = $words ? $this->straighten($bytes, $words) : null;
        if ($jpeg === null) {
            return $path;
        }

        $disk->put($out, $jpeg);

        return $out;
    }

    private function words(string $path, string $bytes, string $mime): array
    {
        return Cache::remember('aman-words:'.md5($bytes), now()->addDays(30), function () use ($bytes, $mime, $path) {
            try {
                return $this->ocr->extractText(base64_encode($bytes), $mime)->words;
            } catch (OcrException $e) {
                Log::warning('Aman card image OCR failed', ['path' => $path, 'error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Returns JPEG bytes of the upright, cropped card, or null when there are too few words.
     *
     * @param  array  $words  [['text' => string, 'v' => [[x, y] x4]]]
     */
    public function straighten(string $bytes, array $words): ?string
    {
        $words = array_values(array_filter($words, fn ($w) => mb_strlen(trim($w['text'])) >= 2));
        if (count($words) < 4) {
            return null;
        }

        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);

        $turn = $this->turn($words);
        if ($turn !== 0) {
            $img = imagerotate($img, $turn, 0);
        }
        [$nw, $nh] = $turn % 180 === 0 ? [$w, $h] : [$h, $w];

        // Box around the Arabic words, in the upright picture (the Latin serial "KK1234567" sits
        // far left on the front and would stretch the box).
        $arabic = array_values(array_filter($words, fn ($w) => preg_match('/\p{Arabic}/u', $w['text'])));
        $xs = $ys = [];
        foreach (count($arabic) >= 4 ? $arabic : $words as $word) {
            foreach ($word['v'] as [$x, $y]) {
                [$ux, $uy] = $this->rotatePoint($x, $y, $w, $h, $turn);
                $xs[] = $ux;
                $ys[] = $uy;
            }
        }
        // The front starts with "جمهورية مصر العربية / بطاقة تحقيق الشخصية"; the back has no header.
        $front = (bool) array_filter($words, fn ($w) => preg_match('/جمهوري|الشخصي|تحقيق/u', $w['text']));
        $box = $this->cardBox(min($xs), min($ys), max($xs), max($ys), $nw, $nh, $front);

        $card = imagecrop($img, $box);
        if (! $card) {
            return null;
        }

        ob_start();
        imagejpeg($card, null, 92);

        return ob_get_clean();
    }

    /** Degrees to turn the picture counter-clockwise (GD's imagerotate) so the words read left to right. */
    public function turn(array $words): int
    {
        $votes = [0 => 0, 90 => 0, 180 => 0, 270 => 0];
        foreach ($words as $word) {
            [[$x0, $y0], [$x1, $y1]] = $word['v'];
            $dx = $x1 - $x0;
            $dy = $y1 - $y0;
            if (abs($dx) >= abs($dy)) {
                $votes[$dx >= 0 ? 0 : 180]++;
            } else {
                // The reading direction points down: the text was turned clockwise, turn it back.
                $votes[$dy > 0 ? 90 : 270]++;
            }
        }
        arsort($votes);

        return (int) array_key_first($votes);
    }

    /** Where (x, y) of a w x h picture lands after imagerotate($turn). */
    public function rotatePoint(int $x, int $y, int $w, int $h, int $turn): array
    {
        return match ($turn) {
            90 => [$y, $w - $x],
            180 => [$w - $x, $h - $y],
            270 => [$h - $y, $x],
            default => [$x, $y],
        };
    }

    /**
     * The card around its text: Arabic text sits
     * at the card's right side (the photo / barcode is on the left), so the card grows leftwards.
     */
    public function cardBox(int $x1, int $y1, int $x2, int $y2, int $w, int $h, bool $front = false): array
    {
        // Front: the text runs from the header to the number, about 4/5 of the card's height.
        // Back: the text is the top half (the barcode is below) and about 3/5 of the width.
        $textH = max(1, $y2 - $y1);
        $cardW = (int) round($front ? $textH * 1.25 * self::CARD_RATIO : max($textH * 1.25 * self::CARD_RATIO, ($x2 - $x1) / 0.58));
        $cardH = (int) round($cardW / self::CARD_RATIO);

        $right = $x2 + (int) round($cardW * 0.04);
        $top = $y1 - (int) round(($cardH - $textH) / 2);

        $left = max(0, $right - $cardW);
        $right = min($w, max($right, $left + $cardW));
        $top = max(0, $top);
        $bottom = min($h, $top + $cardH);

        return ['x' => $left, 'y' => $top, 'width' => max(1, $right - $left), 'height' => max(1, $bottom - $top)];
    }
}
