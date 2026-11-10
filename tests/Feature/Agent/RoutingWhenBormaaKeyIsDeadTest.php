<?php

namespace Tests\Feature\Agent;

use App\Agent\Providers\AiRequest;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\GeminiProvider;
use App\Agent\Providers\OpenAiProvider;
use App\Agent\Providers\RoutingAiProvider;
use App\Domain\Settings\AgentSettings;
use App\Models\GeminiApiKey;
use App\Services\GeminiKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Server 2026-11-10: the only Bormaa key was revoked (401) at 15:11; the dashboard
 * routed every reply to Bormaa alone, so 125 customers got "حصل عطل مؤقت" that day
 * (702 since 10-31) while seven Gemini keys sat idle.
 */
class RoutingWhenBormaaKeyIsDeadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $reset = new \ReflectionProperty(AgentSettings::class, 'defaults');
        $reset->setValue(null, null);
        config(['agent.model' => 'gemini-3.1-flash-lite', 'agent.fallback_models' => 'gemini-3.5-flash-lite']);
        AgentSettings::save(['model' => 'bormaa:gpt-5.6-luna', 'fallback_models' => 'bormaa:gpt-5.6-sol']);
        AgentSettings::apply();

        $gemini = GeminiApiKey::create(['name' => 'g', 'provider' => 'gemini', 'api_key' => 'fake', 'is_active' => true, 'is_paid' => false]);
        $gemini->models()->create(['provider' => 'gemini', 'model_code' => 'gemini-3.1-flash-lite', 'display_name' => 'flash', 'category' => 'c', 'is_active' => true, 'is_embedding' => false]);
    }

    private function bormaaKey(array $attributes): GeminiApiKey
    {
        $key = GeminiApiKey::create(['name' => 'b', 'provider' => 'bormaa', 'api_key' => 'fake', 'is_paid' => false] + $attributes);
        $key->models()->create(['provider' => 'bormaa', 'model_code' => 'bormaa:gpt-5.6-luna', 'display_name' => 'luna', 'category' => 'c', 'is_active' => true, 'is_embedding' => false]);

        return $key;
    }

    public function test_gemini_from_env_answers_the_customer_when_the_bormaa_key_is_disabled(): void
    {
        $this->bormaaKey(['is_active' => false]);

        $this->assertFalse(app(GeminiKeyManager::class)->hasLiveKey('bormaa'));

        $gemini = Mockery::mock(GeminiProvider::class);
        $gemini->shouldReceive('chatWith')->once()
            ->withArgs(fn (AiRequest $r, string $model) => $model === 'gemini-3.1-flash-lite')
            ->andReturn(new AiResponse([['text' => 'ok']], [], 'STOP', [], 'gemini-3.1-flash-lite', 1, 10));
        $openai = Mockery::mock(OpenAiProvider::class);
        $openai->shouldNotReceive('chatWith');

        $response = (new RoutingAiProvider($gemini, $openai))->chat(new AiRequest(system: '', contents: [], purpose: 'reply'));

        $this->assertSame('gemini-3.1-flash-lite', $response->model);
    }

    public function test_a_bormaa_key_in_cooldown_counts_as_dead_until_the_cooldown_passes(): void
    {
        $key = $this->bormaaKey(['is_active' => true, 'cooldown_until' => now()->addMinutes(10)]);
        $this->assertFalse(app(GeminiKeyManager::class)->hasLiveKey('bormaa'));

        $key->update(['cooldown_until' => now()->subMinute()]);
        $this->assertTrue(app(GeminiKeyManager::class)->hasLiveKey('bormaa'));
    }

    public function test_bormaa_alone_answers_while_its_key_is_live(): void
    {
        $this->bormaaKey(['is_active' => true]);

        $openai = Mockery::mock(OpenAiProvider::class);
        $openai->shouldReceive('chatWith')->once()
            ->withArgs(fn (AiRequest $r, string $model, float $deadline, string $provider) => $model === 'bormaa:gpt-5.6-luna' && $provider === 'bormaa')
            ->andReturn(new AiResponse([['text' => 'ok']], [], 'STOP', [], 'bormaa:gpt-5.6-luna', 1, 10));
        $gemini = Mockery::mock(GeminiProvider::class);
        $gemini->shouldNotReceive('chatWith');

        $response = (new RoutingAiProvider($gemini, $openai))->chat(new AiRequest(system: '', contents: [], purpose: 'reply'));

        $this->assertSame('bormaa:gpt-5.6-luna', $response->model);
    }
}
