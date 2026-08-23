<?php

namespace App\Services;

use App\Models\WhatsappConversation;

/**
 * Tracks repeated failed-understanding attempts on a conversation so the
 * bot can escalate to a human instead of asking the same generic
 * clarification question indefinitely. There was previously no memory of
 * a clarification having already been asked once (see
 * AI_MEMORY_CONVERSATION_IMPROVEMENT_PLAN.md Section 14).
 */
class ClarificationService
{
    public function threshold(): int
    {
        return (int) config('ai.max_clarification_attempts', 3);
    }

    /**
     * Call when the AI could not confidently understand the message and
     * is about to ask a clarification question. Returns true when the
     * attempt count has now reached the configured threshold, meaning the
     * caller should escalate instead of sending another question.
     */
    public function recordAttempt(WhatsappConversation $conversation, string $question): bool
    {
        $attempts = (int) ($conversation->clarification_attempts ?? 0) + 1;

        $conversation->forceFill([
            'clarification_attempts' => $attempts,
            'last_clarification_question' => mb_substr($question, 0, 500),
        ])->save();

        return $attempts >= $this->threshold();
    }

    /**
     * Call whenever a message was handled confidently (any deterministic
     * intent handler, or a high-confidence classified intent) so an
     * earlier bout of confusion on an unrelated topic doesn't keep
     * counting against the customer.
     */
    public function reset(WhatsappConversation $conversation): void
    {
        if ((int) ($conversation->clarification_attempts ?? 0) === 0) {
            return;
        }

        $conversation->forceFill([
            'clarification_attempts' => 0,
            'last_clarification_question' => null,
        ])->save();
    }
}
