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
        $this->assertSame(['bormaa:gpt-6.1-sol'], RoutingAiProvider::allowedModels($reply, ['bormaa:gpt-6.1-sol', 'gemini-3.1-flash-lite'], bormaaLive: true));
        $this->assertSame(['bormaa:x', 'gemini-y'], RoutingAiProvider::allowedModels($image, ['bormaa:x', 'gemini-y'], bormaaLive: true));
        // no Bormaa configured (local Gemini): the configured models answer instead of "No permitted model"
        $this->assertSame(['gemini-3.1-flash-lite', 'gpt-5-nano'], RoutingAiProvider::allowedModels($reply, ['gemini-3.1-flash-lite', 'gpt-5-nano']));
    }

    /** Server 2026-11-10: Bormaa's only key revoked -> its models step aside, Gemini answers. */
    public function test_bormaa_without_a_live_key_steps_aside_for_the_other_models(): void
    {
        $reply = new \App\Agent\Providers\AiRequest(system: '', contents: [], purpose: 'reply');
        $image = new \App\Agent\Providers\AiRequest(system: '', contents: [], purpose: 'image');

        $this->assertSame(['gemini-3.1-flash-lite'], RoutingAiProvider::allowedModels($reply, ['bormaa:gpt-5.6-luna', 'bormaa:gpt-5.6-sol', 'gemini-3.1-flash-lite'], bormaaLive: false));
        $this->assertSame(['gemini-y'], RoutingAiProvider::allowedModels($image, ['bormaa:x', 'gemini-y'], bormaaLive: false));
    }
}
