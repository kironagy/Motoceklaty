<?php

namespace App\Services;

use App\Models\AiMemory;
use App\Models\AiMemoryRetrievalLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AiMemoryContextBuilder
{
    /**
     * How many memories relevantMemories() may return. The metadata
     * pre-filter (AiMemoryResolver::candidateMemories) already bounds the
     * candidate set before scoring, so this no longer needs a "trust the
     * filter or dump everything" escape hatch - see buildRelevantMemoryContext().
     */
    private const RELEVANCE_LIMIT = 18;

    /**
     * While the whole active memory set fits in this many characters we send
     * all of it and skip relevance scoring entirely - it stays comfortably
     * under AiPromptBuilder::MAX_MEMORY_CHARS (20000).
     */
    private const FULL_SET_CHAR_BUDGET = 18000;

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

    private function buildRelevantMemoryContext(string $message, array $conversationContext): string
    {
        if (! class_exists(AiMemory::class) || ! Schema::hasTable('ai_memories')) {
            return '';
        }

        $resolver = app(AiMemoryResolver::class);
        $intent = $conversationContext['intent'] ?? null;
        $all = $resolver->activeMemories();

        if ($all->isEmpty()) {
            return '';
        }

        /*
         * The whole active memory set is currently ~13k characters while
         * AiPromptBuilder::MAX_MEMORY_CHARS is 20k - everything fits in one
         * prompt with room to spare. Scoring and then truncating to
         * RELEVANCE_LIMIT was dropping ~21 memories every turn (confirmed in
         * ai_memory_retrieval_logs: candidates=39, selected=18 on every row)
         * to save room we don't need. Send the full set while it fits, and
         * only fall back to scoring once the memory base actually outgrows
         * the prompt budget.
         */
        $totalChars = $all->sum(fn (AiMemory $memory) => mb_strlen((string) $memory->content));

        if ($totalChars <= self::FULL_SET_CHAR_BUDGET) {
            $this->logRetrieval($conversationContext, $message, $intent, $all, $all, 'full_set', false);

            return $this->toToon($all);
        }

        $candidates = $resolver->candidateMemories($intent);

        if ($candidates->isEmpty()) {
            $fallback = $all->filter(
                fn (AiMemory $memory) => ($memory->scope ?? null) === 'always_include'
            );

            $this->logRetrieval($conversationContext, $message, $intent, collect(), $fallback, 'always_include_only', false);

            return $this->toToon($fallback);
        }

        $scoringText = $this->scoringText($message, $conversationContext);
        $relevant = $resolver->relevantMemories($scoringText, $intent, self::RELEVANCE_LIMIT);

        if ($relevant->isEmpty()) {
            $relevant = $candidates->take(self::RELEVANCE_LIMIT);

            $this->logRetrieval($conversationContext, $message, $intent, $candidates, $relevant, 'candidate_set_unscored', false);

            return $this->toToon($relevant);
        }

        $this->logRetrieval($conversationContext, $message, $intent, $candidates, $relevant, 'metadata_filtered', false);

        return $this->toToon($relevant);
    }

    private function logRetrieval(
        array $conversationContext,
        string $message,
        ?string $intent,
        Collection $candidates,
        Collection $selected,
        string $method,
        bool $fellBackToFullDump
    ): void {
        try {
            AiMemoryRetrievalLog::create([
                'whatsapp_conversation_id' => $conversationContext['conversation_id'] ?? null,
                'message_excerpt' => mb_substr($message, 0, 500),
                'intent' => $intent,
                'candidate_memory_ids' => $candidates->pluck('id')->values()->all(),
                'selected_memory_ids' => $selected->pluck('id')->values()->all(),
                'scores' => [],
                'retrieval_method' => $method,
                'fell_back_to_full_dump' => $fellBackToFullDump,
            ]);
        } catch (\Throwable $e) {
            // Observability must never break the reply path.
        }
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