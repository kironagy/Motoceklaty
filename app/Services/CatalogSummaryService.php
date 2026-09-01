<?php

namespace App\Services;

use App\Models\Brand;
use Illuminate\Support\Facades\Cache;

/**
 * قايمة البراندات والموديلات اللي عندنا فعلًا، مكتوبة كنص جاهز للحقن في
 * برومبتات الـ AI.
 *
 * ليه الملف ده موجود:
 * الـ AI كان بيكتب ردود عن التوفر من غير ما يشوف جدول machines/brands
 * أصلًا. لما عميل سأل عن "بينيلي" (براند عندنا فعلًا فيه 4 موديلات)،
 * الـ AI قرا سطر الميموري "المتوفر صيني وهندي بس" واستنتج إن بينيلي
 * مش عندنا - استنتاج سليم من معلومات ناقصة. الحل مش تحذير في البرومبت،
 * الحل إننا نديله الحقيقة.
 */
class CatalogSummaryService
{
    /** الكتالوج بيتغير نادر جدًا، فدقيقتين كفاية ومش هتحمّل الداتابيز. */
    private const CACHE_TTL_SECONDS = 120;

    private const CACHE_KEY = 'ai.catalog_summary.v1';

    public function text(): string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            $brands = Brand::query()
                ->with(['machines' => fn ($q) => $q->orderBy('id')])
                ->orderBy('id')
                ->get();

            $lines = [];

            foreach ($brands as $brand) {
                $brandName = trim((string) $brand->name);

                if ($brandName === '') {
                    continue;
                }

                $models = $brand->machines
                    ->map(fn ($machine) => trim((string) $machine->name))
                    ->filter()
                    ->implode('، ');

                $lines[] = $models === ''
                    ? "- {$brandName}: (مفيش موديلات مسجلة)"
                    : "- {$brandName}: {$models}";
            }

            if (empty($lines)) {
                return 'الكتالوج مش متاح دلوقتي.';
            }

            return implode("\n", $lines);
        });
    }

    /** أسماء البراندات بس - للاستخدام في قواعد البرومبت المختصرة. */
    public function brandNames(): array
    {
        return Brand::query()
            ->orderBy('id')
            ->pluck('name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->values()
            ->all();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
