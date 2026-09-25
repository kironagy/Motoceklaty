<?php

namespace App\Domain\Conversations;

/**
 * DEC-08: transcription must report an explicit status, never silently
 * pretend to have succeeded. 'completed' with a null transcript means the
 * audio genuinely had no speech (not an error); 'failed' means the
 * provider could not be reached/errored and the caller may retry.
 */
final class TranscriptionResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $transcript = null,
    ) {
    }

    public static function completed(?string $transcript): self
    {
        return new self('completed', $transcript);
    }

    public static function failed(): self
    {
        return new self('failed');
    }
}
