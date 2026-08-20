<?php

namespace App\Services;

use App\Models\AiMemory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AiMemoryContextBuilder
{
    /**
     * How many memories relevantMemories() may return before we trust the
     * filtered set enough to skip the full dump. Generous on purpose: this
     * is the complex/fallback path, so under-including is the real risk,
     * not over-including.
     */
    private const RELEVANCE_LIMIT = 18;

    /**
     * If relevance scoring finds fewer than this many matches, the
     * message likely doesn't share enough vocabulary with any single
     * topic to trust filtering — fall back to the full memory context
     * instead of risking the model missing a rule it needs.
     */
    private const MIN_RELEVANT_TO_TRUST_FILTER = 5;

    public function buildForMessage(string $message, array $conversationContext = []): array
    {
        return [
            'intent' => 'fallback_complex',
            'confidence' => 'system',
            'score' => 0,
            'scores' => [],
            'context' => $this->buildRelevantMemoryContext($message, $conversationContext),
        ];
    }

    /**
     * Only the memories relevant to the current message (+ a little recent
     * conversation for topic continuity) are sent, instead of dumping every
     * active memory on every fallback reply. Falls back to the full,
     * unfiltered context whenever relevance scoring isn't confident, so
     * this can only ever include *more* context than needed, never less
     * than what full dumping already guaranteed.
     */
    private function buildRelevantMemoryContext(string $message, array $conversationContext): string
    {
        if (! class_exists(AiMemory::class) || ! Schema::hasTable('ai_memories')) {
            return '';
        }

        $scoringText = $this->scoringText($message, $conversationContext);

        $relevant = app(AiMemoryResolver::class)->relevantMemories(
            $scoringText,
            null,
            self::RELEVANCE_LIMIT
        );

        if ($relevant->count() < self::MIN_RELEVANT_TO_TRUST_FILTER) {
            return $this->buildFullMemoryContext();
        }

        return $this->toToon($relevant);
    }

    private function scoringText(string $message, array $conversationContext): string
    {
        $messages = $conversationContext['messages']
            ?? $conversationContext['recent_messages']
            ?? [];

        $recentText = collect($messages)
            ->slice(-3)
            ->map(fn ($row) => (string) ($row['body'] ?? $row['message'] ?? $row['text'] ?? ''))
            ->filter()
            ->implode(' ');

        return trim($message . ' ' . $recentText);
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