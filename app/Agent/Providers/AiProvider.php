<?php

namespace App\Agent\Providers;

interface AiProvider
{
    /**
     * @throws AiProviderException
     */
    public function chat(AiRequest $request): AiResponse;
}
