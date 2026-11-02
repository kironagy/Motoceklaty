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
}
