<?php

namespace Tests\Feature;

use App\Models\GeminiApiKey;
use App\Models\GeminiApiKeyModel;
use App\Services\GeminiKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeminiKeyManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reserves_request_allowance_before_dispatch(): void
    {
        $model = $this->createModel();

        $reserved = app(GeminiKeyManager::class)->reserveAvailableModel(
            preferredModelCode: 'gemini-test',
            estimatedTokens: 120,
            embedding: false
        );

        $this->assertSame($model->id, $reserved?->id);
        $this->assertDatabaseHas('gemini_api_key_models', [
            'id' => $model->id,
            'requests_today' => 1,
            'requests_this_minute' => 1,
            'tokens_this_second' => 120,
        ]);
    }

    public function test_it_distributes_equal_priority_requests_to_the_less_used_key(): void
    {
        $first = $this->createModel('First');
        $second = $this->createModel('Second');
        $manager = app(GeminiKeyManager::class);

        $firstReservation = $manager->reserveAvailableModel('gemini-test', 10, false);
        $secondReservation = $manager->reserveAvailableModel('gemini-test', 10, false);

        $this->assertSame($first->id, $firstReservation?->id);
        $this->assertSame($second->id, $secondReservation?->id);
    }

    public function test_it_resets_expired_short_windows_while_reserving(): void
    {
        $model = $this->createModel();
        $model->update([
            'requests_this_minute' => 15,
            'tokens_this_second' => 1000,
            'minute_window_started_at' => now()->subMinutes(2),
            'second_window_started_at' => now()->subSeconds(2),
        ]);

        $reserved = app(GeminiKeyManager::class)->reserveAvailableModel('gemini-test', 25, false);

        $this->assertSame($model->id, $reserved?->id);
        $this->assertDatabaseHas('gemini_api_key_models', [
            'id' => $model->id,
            'requests_this_minute' => 1,
            'tokens_this_second' => 25,
        ]);
    }

    public function test_it_skips_keys_in_parent_cooldown(): void
    {
        $model = $this->createModel();
        $model->apiKey->update(['cooldown_until' => now()->addMinute()]);

        $reserved = app(GeminiKeyManager::class)->reserveAvailableModel('gemini-test', 10, false);

        $this->assertNull($reserved);
        $this->assertDatabaseHas('gemini_api_key_models', [
            'id' => $model->id,
            'requests_today' => 0,
        ]);
    }

    private function createModel(string $keyName = 'Key'): GeminiApiKeyModel
    {
        $apiKey = GeminiApiKey::query()->create([
            'provider' => 'gemini',
            'name' => $keyName,
            'api_key' => 'test-key-'.$keyName,
            'is_active' => true,
        ]);

        return $apiKey->models()->create([
            'provider' => 'gemini',
            'display_name' => 'Gemini Test',
            'model_code' => 'gemini-test',
            'category' => 'Gemini',
            'rpm_limit' => 15,
            'rpd_limit' => 500,
            'tps_limit' => 1000,
            'requests_today' => 0,
            'requests_this_minute' => 0,
            'tokens_this_second' => 0,
            'minute_window_started_at' => now(),
            'second_window_started_at' => now(),
            'is_active' => true,
            'is_embedding' => false,
            'priority' => 1,
        ]);
    }
}
