<?php

namespace App\Agent\Context\Facts;

use App\Support\ArabicTextNormalizer;

/**
 * Phase 1: ONE rule for which value of a fact the agent sees. Candidates
 * are ranked by tier (lower number = higher authority); the first filled
 * candidate wins, and a lower candidate that disagrees is a conflict - kept
 * for the log, never shown to the agent. Pure: no store is read here.
 */
final class FactResolver
{
    /** Live business state: catalog price/version, handoff, request status, the application row. */
    public const BUSINESS = 1;

    /** Conversation tool evidence still valid: quote ledger, cash prices shown. */
    public const TOOL_EVIDENCE = 2;

    /** The open application's snapshot (its own source: document/staff before customer). */
    public const SNAPSHOT = 3;

    /** The conversation's work profile and state. */
    public const CONVERSATION = 4;

    /** Durable customer memory and profile attributes. */
    public const MEMORY = 5;

    /**
     * @param  array<int, array{tier: int, value: mixed, source: string, free_text?: bool}>  $candidates in the caller's order; equal tiers keep that order
     * @return array{value: mixed, tier: ?int, source: ?string, conflicts: list<array<string, mixed>>}
     */
    public function resolve(string $fact, array $candidates): array
    {
        $filled = array_values(array_filter($candidates, fn ($c) => $this->isFilled($c['value'] ?? null)));
        usort($filled, fn ($a, $b) => $a['tier'] <=> $b['tier']); // stable on PHP 8

        if ($filled === []) {
            return ['value' => null, 'tier' => null, 'source' => null, 'conflicts' => []];
        }

        $winner = $filled[0];
        $conflicts = [];

        foreach (array_slice($filled, 1) as $other) {
            // free text ("متأمن" vs yes) can read differently without contradicting
            if (empty($other['free_text']) && empty($winner['free_text']) && $this->key($other['value']) !== $this->key($winner['value'])) {
                $conflicts[] = [
                    'fact' => $fact,
                    'winner' => ['tier' => $winner['tier'], 'source' => $winner['source'], 'value' => $winner['value']],
                    'loser' => ['tier' => $other['tier'], 'source' => $other['source'], 'value' => $other['value']],
                ];
            }
        }

        return ['value' => $winner['value'], 'tier' => $winner['tier'], 'source' => $winner['source'], 'conflicts' => $conflicts];
    }

    private function isFilled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private function key(mixed $value): string
    {
        return is_scalar($value)
            ? ArabicTextNormalizer::normalize((string) $value)
            : json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
