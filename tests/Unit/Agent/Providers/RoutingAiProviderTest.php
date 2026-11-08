<?php

namespace Tests\Unit\Agent\Providers;

use App\Agent\Providers\RoutingAiProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoutingAiProviderTest extends TestCase
{
    #[DataProvider('models')]
    public function test_provider_is_selected_without_ambiguous_model_names(string $model, string $provider): void
    {
        $this->assertSame($provider, RoutingAiProvider::providerFor($model));
    }

    public static function models(): array
    {
        return [
            ['bormaa:gpt-6.1-sol', 'bormaa'],
            ['bormaa:gpt-6-luna', 'bormaa'],
            ['gpt-5-nano', 'openai'],
            ['o3-mini', 'openai'],
            ['gemini-3.1-flash-lite', 'gemini'],
        ];
    }

    public function test_bormaa_alone_answers_customers_only_when_a_bormaa_model_is_configured(): void
    {
        $reply = new \App\Agent\Providers\AiRequest(system: '', contents: [], purpose: 'reply');
        $image = new \App\Agent\Providers\AiRequest(system: '', contents: [], purpose: 'image');

        // server: Bormaa first, Gemini behind it -> Gemini never answers a customer
        $this->assertSame(['bormaa:gpt-6.1-sol'], RoutingAiProvider::allowedModels($reply, ['bormaa:gpt-6.1-sol', 'gemini-3.1-flash-lite']));
        $this->assertSame(['bormaa:x', 'gemini-y'], RoutingAiProvider::allowedModels($image, ['bormaa:x', 'gemini-y']));
        // no Bormaa configured (local Gemini): the configured models answer instead of "No permitted model"
        $this->assertSame(['gemini-3.1-flash-lite', 'gpt-5-nano'], RoutingAiProvider::allowedModels($reply, ['gemini-3.1-flash-lite', 'gpt-5-nano']));
    }
}
