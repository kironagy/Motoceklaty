<?php

namespace App\Services;

use App\Models\AiMemory;
use Illuminate\Support\Facades\Schema;

/**
 * Plan task 3.3: reads the structured half of ai_memories (the `rules`
 * column) so business rules can be edited in Filament instead of in PHP.
 *
 * Everything here is additive on purpose. A memory can add an excluded
 * profession or change which documents a job category needs, but it can
 * never empty the built-in defaults - a half-filled or mistyped rules block
 * must not be able to open the installment flow to a profession the shop
 * refuses, so the code keeps its own floor and the memory raises it.
 *
 * Expected shape (all keys optional):
 *   {
 *     "banned_professions": ["ضابط", "محام"],
 *     "job_category": "delivery",
 *     "job_keywords": ["دليفري", "اوبر"],
 *     "required_documents": ["driver_license", "trips_screenshot"]
 *   }
 */
class AiMemoryRules
{
    private ?array $cache = null;

    /** Extra profession keywords that block the installment flow. */
    public function bannedProfessions(): array
    {
        return $this->collect('banned_professions');
    }

    /**
     * Extra job-category keywords, as [category => [keyword, ...]] - used to
     * recognise a category the code does not have a literal for yet.
     */
    public function jobCategoryKeywords(): array
    {
        $map = [];

        foreach ($this->rules() as $rule) {
            $category = trim((string) ($rule['job_category'] ?? ''));
            $keywords = $this->strings($rule['job_keywords'] ?? []);

            if ($category === '' || empty($keywords)) {
                continue;
            }

            $map[$category] = array_values(array_unique(array_merge($map[$category] ?? [], $keywords)));
        }

        return $map;
    }

    /**
     * Documents a category requires, when a memory defines them. Returns
     * null when nothing is configured so the caller keeps its own list.
     */
    public function requiredDocumentsFor(string $category): ?array
    {
        foreach ($this->rules() as $rule) {
            if (trim((string) ($rule['job_category'] ?? '')) !== $category) {
                continue;
            }

            $documents = $this->strings($rule['required_documents'] ?? []);

            if (! empty($documents)) {
                return $documents;
            }
        }

        return null;
    }

    private function collect(string $key): array
    {
        $values = [];

        foreach ($this->rules() as $rule) {
            $values = array_merge($values, $this->strings($rule[$key] ?? []));
        }

        return array_values(array_unique($values));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rules(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (! class_exists(AiMemory::class) || ! Schema::hasTable('ai_memories') || ! Schema::hasColumn('ai_memories', 'rules')) {
            return $this->cache = [];
        }

        return $this->cache = AiMemory::query()
            ->where('is_active', true)
            ->whereNotNull('rules')
            ->pluck('rules')
            ->filter(fn ($rules) => is_array($rules))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R|,/u', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) ? trim($item) : '',
            $value
        )));
    }
}
