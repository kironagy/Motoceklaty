<?php

namespace App\Support;

use App\Services\AiMemoryParser;

/**
 * Cheap semantic-repetition signal for the LLM free-text fallback path
 * (AiComplexReplyService). Token-set Jaccard overlap is enough here - no
 * embeddings needed just to notice "this reply looks like the last one".
 * See AI_MEMORY_CONVERSATION_IMPROVEMENT_PLAN.md Section 16: a high score
 * is a signal fed into clarification-attempt escalation, not a reason to
 * silently reroll the LLM (that would just hide non-convergence instead
 * of resolving it).
 */
class RepetitionGuard
{
    public function __construct(private readonly AiMemoryParser $parser)
    {
    }

    /**
     * Highest Jaccard overlap between $candidate and any of $recentOutgoing.
     * Returns 0.0 when there's nothing to compare against.
     */
    public function score(string $candidate, array $recentOutgoing): float
    {
        $candidateTokens = array_unique($this->parser->tokens($candidate));

        if (empty($candidateTokens)) {
            return 0.0;
        }

        $best = 0.0;

        foreach ($recentOutgoing as $previous) {
            $previousTokens = array_unique($this->parser->tokens((string) $previous));

            if (empty($previousTokens)) {
                continue;
            }

            $union = array_unique(array_merge($candidateTokens, $previousTokens));

            if (empty($union)) {
                continue;
            }

            $intersection = array_intersect($candidateTokens, $previousTokens);
            $jaccard = count($intersection) / count($union);

            $best = max($best, $jaccard);
        }

        return $best;
    }
}
