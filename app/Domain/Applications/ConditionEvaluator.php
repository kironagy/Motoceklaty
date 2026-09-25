<?php

namespace App\Domain\Applications;

/**
 * Only structured comparisons on known facts (plan T11 §3) - no free text,
 * no interpreting customer messages. `condition`: {fact, op, value}.
 */
class ConditionEvaluator
{
    /**
     * A missing fact means the condition cannot be confirmed true, so it
     * evaluates to false (a conditional requirement is not shown until its
     * condition is confirmed).
     */
    public function evaluate(?array $condition, array $facts): bool
    {
        if ($condition === null) {
            return true;
        }

        $actual = data_get($facts, $condition['fact']);

        if ($actual === null) {
            return false;
        }

        return match ($condition['op']) {
            'gt' => $actual > $condition['value'],
            'gte' => $actual >= $condition['value'],
            'lt' => $actual < $condition['value'],
            'lte' => $actual <= $condition['value'],
            'eq' => $actual == $condition['value'],
            'in' => in_array($actual, (array) $condition['value'], false),
            'not_in' => ! in_array($actual, (array) $condition['value'], false),
            default => false,
        };
    }
}
