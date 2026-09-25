<?php

namespace App\Agent\Tools;

use App\Agent\Runtime\TurnResultBuilder;

/**
 * Injected execution context (plan principle 9): tools never receive
 * customer/conversation/application IDs as arguments, only from here, so
 * the AI cannot reach another customer's data.
 */
final class ToolContext
{
    public function __construct(
        public readonly int $customerId,
        public readonly int $conversationId,
        public readonly ?int $activeApplicationId,
        public readonly int $turnId,
        public readonly int $traceId,
        public readonly TurnResultBuilder $outbound,
    ) {
    }

    public function withActiveApplication(int $applicationId): self
    {
        return new self($this->customerId, $this->conversationId, $applicationId, $this->turnId, $this->traceId, $this->outbound);
    }
}
