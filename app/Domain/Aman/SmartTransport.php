<?php

namespace App\Domain\Aman;

use App\Domain\Documents\OcrException;
use App\Domain\Documents\OcrProvider;
use App\Models\InstallmentRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Smart-transport drivers (Uber, inDrive, DiDi, Talabat ...) on the "دخل حر" type (owner 2026-11-16):
 * - known from the work name ("شغال في نقل الزكي", "اوبر") or, failing that, from the income
 *   proof photos (a driving licence, an app's earnings screen);
 * - their income proof photos go to Aman in the two guarantor slots: the photos are split in two
 *   halves and each half is merged into one picture;
 * - the work address is zeros; the work governorate and area are the customer's own.
 */
class SmartTransport
{
    private const NAME = '/نقل\s*(ال)?(ذكي|زكي)|اوبر|أوبر|اوبار|uber|اندرايف|اندريف|إندرايف|ان\s*درايف|indrive|in\s*drive|ديدي|دي\s*دي|didi|طلبات|talabat|كريم|careem|بولت|bolt|سواق|سائق|swvl|سويفل/iu';

    private const PROOF = '/رخص[ةه]\s*(ال)?قياد[ةه]|رخص[ةه]\s*تسيير|driving\s*licen|الدخل\s*الصافي|صافي\s*الدخل|الأرباح|الارباح|ارباحك|رحلات|رحلة|الأميال|uber|didi|indrive|careem|bolt|talabat/iu';

    public const ZERO_ADDRESS = 'رقم العقار 0 شارع 0 متفرع من 0 محافظة 0 المنطقة 0 علامة مميزة: 0 الدور 0 شقة 0';

    public function __construct(private OcrProvider $ocr)
    {
    }

    public function is(InstallmentRequest $request): bool
    {
        if ($request->work_status !== 'no_income_proof') {
            return false;
        }
        if (preg_match(self::NAME, (string) $request->free_work_name)) {
            return true;
        }
        foreach (array_slice($this->proofs($request), 0, 4) as $path) {
            if (preg_match(self::PROOF, $this->text($path))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> income proof photo paths that exist on the public disk */
    public function proofs(InstallmentRequest $request): array
    {
        $disk = Storage::disk('public');

        return array_values(array_filter((array) $request->free_income_proof_images,
            fn ($p) => is_string($p) && $p !== '' && preg_match('/\.(jpe?g|png|webp)$/i', $p) && $disk->exists($p)));
    }

    /** Path of merged picture $part (1 or 2): the first or second half of the proof photos. */
    public function merged(InstallmentRequest $request, int $part): ?string
    {
        $proofs = $this->proofs($request);
        if (! $proofs) {
            return null;
        }
        $half = (int) ceil(count($proofs) / 2);
        $group = $part === 1 ? array_slice($proofs, 0, $half) : array_slice($proofs, $half);
        if (! $group) {
            return null;
        }

        $disk = Storage::disk('public');
        $key = md5(implode('|', array_map(fn ($p) => $p.':'.$disk->lastModified($p), $group)));
        $out = 'aman-merged/'.$key.'.jpg';
        if (! $disk->exists($out)) {
            $jpeg = $this->merge(array_map(fn ($p) => $disk->get($p), $group));
            if ($jpeg === null) {
                return null;
            }
            $disk->put($out, $jpeg);
        }

        return $out;
    }

    /**
     * Side by side in a grid (2 per row up to 4 photos, then 3), every photo scaled to one height.
     *
     * @param  list<string>  $images  image bytes
     */
    public function merge(array $images): ?string
    {
        $imgs = array_values(array_filter(array_map(fn ($b) => @imagecreatefromstring($b) ?: null, $images)));
        if (! $imgs) {
            return null;
        }
        $n = count($imgs);
        $cols = $n <= 2 ? $n : ($n <= 4 ? 2 : 3);
        $rows = (int) ceil($n / $cols);
        $cellH = 1400;
        $gap = 20;

        $cells = [];
        foreach ($imgs as $img) {
            $w = (int) round(imagesx($img) * $cellH / max(1, imagesy($img)));
            $cells[] = [$img, $w];
        }
        $cellW = max(array_column($cells, 1));
        $canvas = imagecreatetruecolor($cols * $cellW + ($cols + 1) * $gap, $rows * $cellH + ($rows + 1) * $gap);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));

        foreach ($cells as $i => [$img, $w]) {
            $x = $gap + ($i % $cols) * ($cellW + $gap) + (int) (($cellW - $w) / 2);
            $y = $gap + intdiv($i, $cols) * ($cellH + $gap);
            imagecopyresampled($canvas, $img, $x, $y, 0, 0, $w, $cellH, imagesx($img), imagesy($img));
        }

        // Keep the file reasonable for Aman's upload: at most 2400 px wide.
        if (imagesx($canvas) > 2400) {
            $canvas = imagescale($canvas, 2400);
        }

        ob_start();
        imagejpeg($canvas, null, 85);

        return ob_get_clean();
    }

    private function text(string $path): string
    {
        $bytes = Storage::disk('public')->get($path);
        $key = 'aman-proof-text:'.md5($bytes);
        if (Cache::has($key)) {
            return Cache::get($key);
        }
        try {
            $text = $this->ocr->extractText(base64_encode($bytes), Storage::disk('public')->mimeType($path) ?: 'image/jpeg')->text;
        } catch (OcrException $e) {
            Log::warning('Aman proof photo OCR failed', ['path' => $path, 'error' => $e->getMessage()]);

            return '';
        }
        Cache::put($key, $text, now()->addDays(30));

        return $text;
    }
}
