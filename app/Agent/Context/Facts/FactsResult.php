<?php

namespace App\Agent\Context\Facts;

use App\Models\Application;

/** What ConversationFacts returns: the facts the agent sees, and what was resolved away (for logs and tests only). */
final class FactsResult
{
    /**
     * @param  array<string, mixed>  $facts
     * @param  list<array<string, mixed>>  $conflicts
     */
    public function __construct(
        public readonly array $facts,
        public readonly array $conflicts,
        public readonly ?Application $application,
        public readonly ?array $snapshot,
    ) {
    }
}
