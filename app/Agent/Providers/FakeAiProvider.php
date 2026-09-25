<?php

namespace App\Agent\Providers;

/**
 * Test double: returns scripted responses in order and records every
 * request it received, so callers can assert on both sides.
 */
class FakeAiProvider implements AiProvider
{
    /** @var AiResponse[] */
    private array $responses = [];

    /** @var AiRequest[] */
    private array $requests = [];

    public function queue(AiResponse $response): static
    {
        $this->responses[] = $response;

        return $this;
    }

    public function chat(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;

        if ($this->responses === []) {
            throw new AiProviderException('FakeAiProvider has no queued responses left.');
        }

        return array_shift($this->responses);
    }

    /** @return AiRequest[] */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): ?AiRequest
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }
}
