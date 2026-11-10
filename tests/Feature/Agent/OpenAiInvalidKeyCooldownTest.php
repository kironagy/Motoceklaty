<?php

namespace Tests\Feature\Agent;

use App\Agent\Providers\AiProviderException;
use App\Agent\Providers\AiRequest;
use App\Agent\Providers\OpenAiProvider;
use App\Models\GeminiApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Server 2026-11-10 15:07-15:11: Bormaa timed out for four minutes, then answered 401
 * once - and the only key was switched off for good. A 401 parks the key for a while;
 * a provider hiccup must not need a human to re-enable the key.
 */
class OpenAiInvalidKeyCooldownTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_401_parks_the_key_instead_of_disabling_it(): void
    {
        $key = GeminiApiKey::create(['name' => 'b', 'provider' => 'bormaa', 'api_key' => 'fake', 'is_active' => true, 'is_paid' => false]);
        $key->models()->create(['provider' => 'bormaa', 'model_code' => 'bormaa:gpt-5.6-luna', 'display_name' => 'luna', 'category' => 'c', 'is_active' => true, 'is_embedding' => false]);
        Http::fake(['*' => Http::response(['error' => ['code' => 'invalid_api_key', 'type' => 'invalid_request_error']], 401)]);

        try {
            app(OpenAiProvider::class)->chatWith(new AiRequest(system: '', contents: [], purpose: 'reply'), 'bormaa:gpt-5.6-luna', INF, 'bormaa');
            $this->fail('expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertTrue($e->retryable);
        }

        $key->refresh();
        $this->assertTrue((bool) $key->is_active);
        $this->assertNotNull($key->cooldown_until);
        $this->assertTrue($key->cooldown_until->isFuture());
        $this->assertStringContainsString('invalid_api_key', (string) $key->last_error);
    }
}
