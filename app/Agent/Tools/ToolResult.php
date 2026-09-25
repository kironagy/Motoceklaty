<?php

namespace App\Agent\Tools;

/**
 * The tool result envelope (plan §3.1). Error codes are stable
 * UPPER_SNAKE_CASE strings listed in each tool's contract - never a stack
 * trace or exception message.
 */
final class ToolResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?array $data,
        public readonly ?array $error,
    ) {
    }

    public static function ok(array $data = []): self
    {
        return new self(true, $data, null);
    }

    public static function error(string $code, string $detail = ''): self
    {
        return new self(false, null, ['code' => $code, 'detail' => $detail]);
    }

    public function toArray(): array
    {
        return $this->ok
            ? ['ok' => true, 'data' => $this->data]
            : ['ok' => false, 'error' => $this->error];
    }
}
