<?php

namespace Tests\Feature;

use App\Models\GeminiApiKey;
use App\Models\GeminiApiKeyModel;
use App\Services\GeminiClient;
use App\Services\GeminiKeyManager;
use App\Services\GeminiRateLimitParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_distinguishes_temporary_and_daily_quotas(): void
    {
        $parser = app(GeminiRateLimitParser::class);

        $temporary = $parser->analyze($this->quotaBody('GenerateRequestsPerMinutePerProjectPerModel', '12.4s'));
        $daily = $parser->analyze($this->quotaBody('GenerateRequestsPerDayPerProjectPerModel-FreeTier', '12.4s'));

        $this->assertFalse($temporary['daily_limit']);
        $this->assertSame(13, $temporary['cooldown_seconds']);
        $this->assertTrue($daily['daily_limit']);
    }

    public function test_temporary_rate_limit_does_not_consume_the_whole_daily_allowance(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');
        $model = $this->createModel();

        app(GeminiKeyManager::class)->markRateLimited(
            model: $model,
            error: 'temporary quota',
            dailyLimit: false,
            cooldownSeconds: 45
        );

        $model->refresh();

        $this->assertSame(0, $model->requests_today);
        $this->assertTrue($model->cooldown_until->equalTo(now()->addSeconds(45)));
    }

    public function test_daily_rate_limit_stops_the_model_until_google_daily_reset(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00 UTC');
        config()->set('gemini.rate_limits.daily_reset_timezone', 'America/Los_Angeles');
        $model = $this->createModel();

        app(GeminiKeyManager::class)->markRateLimited(
            model: $model,
            error: 'daily quota',
            dailyLimit: true,
            cooldownSeconds: 10
        );

        $model->refresh();

        $this->assertSame(500, $model->requests_today);
        $this->assertSame(
            '2026-07-21 00:00:00',
            $model->cooldown_until->timezone('America/Los_Angeles')->format('Y-m-d H:i:s')
        );
    }

    public function test_client_moves_to_the_next_key_after_a_temporary_429(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');
        $first = $this->createModel('First');
        $second = $this->createModel('Second');

        Http::fakeSequence()
            ->push($this->quotaPayload('GenerateRequestsPerMinutePerProjectPerModel', '30s'), 429)
            ->push([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'تمام يا فندم']]]],
                ],
                'usageMetadata' => ['totalTokenCount' => 25],
            ], 200);

        $result = app(GeminiClient::class)->generateText('اختبار', 'gemini-test');

        $this->assertTrue($result['ok']);
        $this->assertSame($second->gemini_api_key_id, $result['key_id']);
        $this->assertSame(1, $first->fresh()->requests_today);
        $this->assertSame(1, $second->fresh()->requests_today);
        $this->assertTrue($first->fresh()->cooldown_until->equalTo(now()->addSeconds(30)));
        Http::assertSentCount(2);
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

    private function quotaBody(string $quotaId, string $retryDelay): string
    {
        return json_encode($this->quotaPayload($quotaId, $retryDelay), JSON_THROW_ON_ERROR);
    }

    private function quotaPayload(string $quotaId, string $retryDelay): array
    {
        return [
            'error' => [
                'code' => 429,
                'status' => 'RESOURCE_EXHAUSTED',
                'details' => [
                    [
                        '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                        'violations' => [
                            [
                                'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests',
                                'quotaId' => $quotaId,
                            ],
                        ],
                    ],
                    [
                        '@type' => 'type.googleapis.com/google.rpc.RetryInfo',
                        'retryDelay' => $retryDelay,
                    ],
                ],
            ],
        ];
    }
}
