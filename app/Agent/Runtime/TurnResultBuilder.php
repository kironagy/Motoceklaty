<?php

namespace App\Agent\Runtime;

/**
 * Accumulates one turn's outbound reply (text + media) across tool calls.
 * This is the "outbound-queue object for media" ToolContext carries, and
 * `send_reply` is the only tool that calls finish() (plan §6.19: send_reply
 * is the only way a turn ends normally).
 */
class TurnResultBuilder
{
    private array $messages = [];

    private ?string $quoteWaMessageId = null;

    private array $media = [];

    /** @var string[] Laravel-authored messages sent after the AI's reply (e.g. the submission summary). */
    private array $trailing = [];

    private bool $finished = false;

    /** @var int[] */
    private array $focusMotorcycleIds = [];

    public function addMessages(array $messages): void
    {
        $this->messages = array_merge($this->messages, array_values($messages));
    }

    public function setQuote(?string $waMessageId): void
    {
        $this->quoteWaMessageId = $waMessageId;
    }

    /**
     * T16 (L8): stamped onto the outbound message's own metadata by
     * DeliveryService, so a later quote of *this specific* bot message
     * recovers what it was about, independent of conversation.state's
     * current (possibly since-moved-on) focus.
     *
     * @param  int[]  $motorcycleIds
     */
    public function setFocus(array $motorcycleIds): void
    {
        $this->focusMotorcycleIds = $motorcycleIds;
    }

    public function addMedia(array $item): void
    {
        $this->media[] = $item;
    }

    public function addTrailingMessage(string $text): void
    {
        if (! in_array($text, $this->trailing, true)) {
            $this->trailing[] = $text;
        }
    }

    public function hasMediaFor(int $motorcycleId): bool
    {
        foreach ($this->media as $item) {
            if (($item['motorcycle_id'] ?? null) === $motorcycleId) {
                return true;
            }
        }

        return false;
    }

    public function finish(): void
    {
        $this->finished = true;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function toArray(): array
    {
        return [
            'messages' => array_merge($this->messages, $this->trailing),
            'quote_wa_message_id' => $this->quoteWaMessageId,
            'media' => $this->media,
            'focus_motorcycle_ids' => $this->focusMotorcycleIds,
        ];
    }
}
