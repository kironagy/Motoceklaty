<?php

namespace App\Console\Commands;

use App\Models\AiMemory;
use Illuminate\Console\Command;

/**
 * One-off, idempotent backfill of category/scope for the memories that
 * existed at the time of the 2026-08-23 retrieval audit. Never touches
 * title/content/template_replies. Safe to re-run — it only sets a field
 * when it's currently null, so manual edits made afterwards in Filament
 * are never overwritten.
 */
class BackfillAiMemoryMetadata extends Command
{
    protected $signature = 'ai:backfill-memory-metadata';

    protected $description = 'Backfill category/scope on ai_memories rows identified in the retrieval audit';

    private const CATEGORY_BY_TITLE = [
        'قواعد العناوين' => 'application',
        'الشركة والفروع' => 'support',
        'طريقة حساب سعر القسط' => 'pricing',
        'المخزون والموديلات' => 'catalog',
        'شرح المواصفات' => 'catalog',
        'نظام 20%' => 'pricing',
        'نظام 30%' => 'pricing',
        'قواعد الدخل الحر' => 'eligibility',
        'أسلوب البيع والكلام' => 'style',
        'التسعير وطريقة عرض التقسيط' => 'pricing',
        'نظام التقسيط' => 'pricing',
        'المستندات الأساسية المطلوبة' => 'application',
        'الموظفين' => 'eligibility',
        'أصحاب المعاشات' => 'eligibility',
        'أصحاب الأنشطة التجارية' => 'eligibility',
        'أصحاب المهن الحرة' => 'eligibility',
        'الدليفري' => 'eligibility',
        'التاكسي' => 'eligibility',
        'الميكروباص' => 'eligibility',
        'كشف الحساب' => 'eligibility',
        'الفئات الممنوعة' => 'eligibility',
        'متابعة العميل' => 'support',
        'مراجعة البيانات مع العميل' => 'application',
        'الاسعار والانواع' => 'pricing',
        'تاكيد البيانات' => 'application',
        'تاكيد الرفع' => 'application',
        'استخراج الاسم من البطاقه' => 'ocr',
        'مراجعه صور النشاط' => 'ocr',
    ];

    private const ALWAYS_INCLUDE_TITLES = [
        'أسلوب البيع والكلام',
        'التسعير وطريقة عرض التقسيط',
        'الفئات الممنوعة',
    ];

    private const FALLBACK_CONTEXT_ONLY_TITLES = [
        'الاسعار والانواع',
    ];

    public function handle(): int
    {
        $updated = 0;

        AiMemory::query()->whereNull('category')->orWhereNull('scope')->each(function (AiMemory $memory) use (&$updated) {
            $title = trim((string) $memory->title);
            $dirty = false;

            if ($memory->category === null && array_key_exists($title, self::CATEGORY_BY_TITLE)) {
                $memory->category = self::CATEGORY_BY_TITLE[$title];
                $dirty = true;
            }

            if ($memory->scope === null) {
                if (in_array($title, self::ALWAYS_INCLUDE_TITLES, true)) {
                    $memory->scope = 'always_include';
                    $dirty = true;
                } elseif (in_array($title, self::FALLBACK_CONTEXT_ONLY_TITLES, true)) {
                    $memory->scope = 'fallback_context_only';
                    $dirty = true;
                }
            }

            if ($dirty) {
                $memory->save();
                $updated++;
            }
        });

        $this->info("Backfilled metadata on {$updated} memory row(s).");

        return self::SUCCESS;
    }
}
