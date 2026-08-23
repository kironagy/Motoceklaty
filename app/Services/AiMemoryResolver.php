<?php

namespace App\Services;

use App\Models\AiMemory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiMemoryResolver
{
    public function activeMemories(): Collection
    {
        if (! class_exists(AiMemory::class) || ! Schema::hasTable('ai_memories')) {
            return collect();
        }

        return Cache::remember('active_ai_memories_for_laravel', now()->addMinute(), function () {
            return AiMemory::query()
                ->where('is_active', true)
                ->orderBy('sort')
                ->orderBy('id')
                ->get();
        });
    }


    /**
     * Metadata pre-filter, run before any keyword scoring. Keeps the
     * scorer's candidate set small even at hundreds of memories: a memory
     * is a candidate when it's tagged 'always_include' (hard business
     * rules that must never be filtered out by a relevance score, e.g.
     * excluded professions), when it has no applicable_intents tags at
     * all (untagged memories stay eligible for every intent so this
     * degrades to today's behaviour instead of silently excluding
     * anything), or when its applicable_intents contains the given
     * intent.
     */
    public function candidateMemories(?string $intent = null): Collection
    {
        return $this->activeMemories()->filter(function (AiMemory $memory) use ($intent) {
            if (($memory->scope ?? null) === 'always_include') {
                return true;
            }

            $tags = $memory->applicable_intents ?? [];

            /*
             * No tags, or the caller doesn't have a confident classified
             * intent to filter by (e.g. the complex/unknown fallback
             * path) - stay eligible. Only exclude a memory when we KNOW
             * the current intent and it explicitly wasn't tagged for it.
             */
            if (empty($tags) || $intent === null) {
                return true;
            }

            return in_array($intent, $tags, true);
        })->values();
    }

public function memoryByExactTitle(string $title): ?\App\Models\AiMemory
{
    $parser = app(AiMemoryParser::class);

    $wanted = $parser->normalize($title);

    return $this->activeMemories()
        ->first(function ($memory) use ($parser, $wanted) {
            return $parser->normalize((string) $memory->title) === $wanted;
        });
}
    public function relevantMemories(string $message, ?string $intent = null, int $limit = 6): Collection
    {
        $parser = app(AiMemoryParser::class);

        $messageNormalized = $parser->normalize($message);
        $tokens = $parser->tokens($message);

        return $this->candidateMemories($intent)
            ->map(function (AiMemory $memory) use ($parser, $messageNormalized, $tokens, $intent) {
                $title = $parser->normalize((string) $memory->title);
                $content = $parser->normalize((string) $memory->content);

                $score = 0;

                foreach ($tokens as $token) {
                    if (str_contains($title, $token)) {
                        $score += 20;
                    }

                    if (str_contains($content, $token)) {
                        $score += 8;
                    }
                }

                foreach (($memory->keywords ?? []) as $keyword) {
                    $keyword = $parser->normalize((string) $keyword);

                    if ($keyword !== '' && str_contains($messageNormalized, $keyword)) {
                        $score += 25;
                    }
                }

                if ($intent) {
                    foreach ($this->intentKeywords($intent) as $keyword) {
                        $keyword = $parser->normalize($keyword);

                        if (str_contains($title, $keyword)) {
                            $score += 40;
                        }

                        if (str_contains($content, $keyword)) {
                            $score += 15;
                        }
                    }

                    if (in_array($intent, $memory->applicable_intents ?? [], true)) {
                        $score += 30;
                    }
                }

                if (($memory->scope ?? null) === 'always_include') {
                    $score += 1000;
                }

                $score += (int) ($memory->priority ?? 0);

                if ($memory->template_replies && is_array($memory->template_replies)) {
                    $score += 5;
                }

                return [
                    'memory' => $memory,
                    'score' => $score,
                ];
            })
            ->filter(fn ($row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('memory')
            ->values();
    }

    public function resolveAliasTarget(string $message): ?array
    {
        $parser = app(AiMemoryParser::class);

        $messageNormalized = $parser->normalize($message);

        $rules = $parser->aliasRules($this->activeMemories());

        /*
         * الأطول الأول عشان:
         * هوجن جامبو
         * يسبق كلمة جامبو لو موجودة لوحدها
         */
        usort($rules, function ($a, $b) {
            return mb_strlen($b['alias']) <=> mb_strlen($a['alias']);
        });

        foreach ($rules as $rule) {
            $aliasNormalized = $parser->normalize($rule['alias']);

            if ($aliasNormalized === '') {
                continue;
            }

            if (str_contains($messageNormalized, $aliasNormalized)) {
                return $rule;
            }
        }

        return null;
    }

    public function bestTemplateMemories(string $message, ?string $intent = null): Collection
    {
        return $this->relevantMemories($message, $intent, 8)
            ->filter(function (AiMemory $memory) {
                return is_array($memory->template_replies ?? null)
                    && count($memory->template_replies) > 0;
            })
            ->values();
    }

    private function intentKeywords(string $intent): array
    {
        return match ($intent) {
            'ask_images' => ['صور', 'صوره', 'شكل', 'ألوان', 'المخزون', 'موديلات'],
            'ask_price' => ['سعر', 'أسعار', 'تسعير', 'كاش', 'تقسيط'],
            'ask_installment' => ['تقسيط', 'قسط', 'مقدم', 'شهور', 'نظام التقسيط'],
            'ask_branch' => ['فرع', 'فروع', 'عنوان', 'الشركة'],
            'ask_available' => ['متاح', 'موجود', 'المخزون', 'موديلات'],
            'ask_specs' => ['مواصفات', 'شرح المواصفات', 'تفاصيل'],
            'ask_colors' => ['ألوان', 'الوان', 'صور', 'المخزون'],
            'send_documents' => ['مستندات', 'أوراق', 'بطاقة', 'سجل', 'ضريبة'],
            'create_order' => ['تأكيد البيانات', 'تأكيد الرفع', 'متابعة العميل'],
            default => [],
        };
    }
}