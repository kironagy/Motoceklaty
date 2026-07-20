<?php

namespace App\Services;

use App\Models\AiMemory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AiMemoryContextBuilder
{
    public function buildForMessage(string $message): array
    {
        return [
            'intent' => 'fallback_complex',
            'confidence' => 'system',
            'score' => 0,
            'scores' => [],
            'context' => $this->buildFullMemoryContext(),
        ];
    }

    public function buildFullMemoryContext(): string
    {
        return Cache::remember(
            'ai_full_memory_context',
            now()->addMinutes(5),
            fn () => $this->generateFullMemoryContext()
        );
    }

    public function clearCache(): void
    {
        Cache::forget('ai_full_memory_context');
    }

    private function generateFullMemoryContext(): string
    {
        if (! class_exists(AiMemory::class) || ! Schema::hasTable('ai_memories')) {
            return '';
        }

        $memories = AiMemory::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return $this->toToon($memories);
    }

    private function toToon(Collection $memories): string
    {
        $parts = [];

        foreach ($memories as $memory) {
            $title = trim((string) $memory->title);
            $content = trim((string) $memory->content);

            if ($title === '' || $content === '') {
                continue;
            }

            $parts[] = $this->toToonBlock($title, $content);
        }

        return trim(implode("\n\n", $parts));
    }

    private function toToonBlock(string $title, string $content): string
    {
        $lines = preg_split('/\r\n|\r|\n/u', $content);

        $cleanLines = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $line = preg_replace('/\s+/u', ' ', $line);

            $cleanLines[] = '- ' . $line;
        }

        return "## {$title}\n" . implode("\n", $cleanLines);
    }
}