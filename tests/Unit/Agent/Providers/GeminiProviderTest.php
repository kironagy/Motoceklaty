<?php

namespace Tests\Unit\Agent\Providers;

use App\Agent\Providers\AiProviderException;
use App\Agent\Providers\AiRequest;
use App\Agent\Providers\GeminiProvider;
use App\Models\GeminiApiKey;
use App\Models\GeminiApiKeyModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('agent.model', 'gemini-test');
    }

    private function makeModel(string $keyName = 'Key A', int $priority = 1, string $modelCode = 'gemini-test'): GeminiApiKeyModel
    {
        $apiKey = GeminiApiKey::query()->create([
            'provider' => 'gemini',
            'name' => $keyName,
            'api_key' => "test-key-{$keyName}",
            'is_active' => true,
        ]);

        return $apiKey->models()->create([
            'provider' => 'gemini',
            'display_name' => 'Gemini Test',
            'model_code' => $modelCode,
            'category' => 'Gemini',
            'rpm_limit' => 15,
            'rpd_limit' => 500,
            'tps_limit' => 1000000,
            'requests_today' => 0,
            'requests_this_minute' => 0,
            'tokens_this_second' => 0,
            'is_active' => true,
            'is_embedding' => false,
            'priority' => $priority,
        ]);
    }

    public function test_request_mapping_for_text_image_and_tools(): void
    {
        $this->makeModel();

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'hi']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
            ], 200),
        ]);

        $request = new AiRequest(
            system: 'be nice',
            contents: [
                ['role' => 'user', 'parts' => [
                    ['type' => 'text', 'text' => 'what is this bike'],
                    ['type' => 'inline_media', 'mime' => 'image/jpeg', 'base64' => 'ZmFrZQ=='],
                ]],
            ],
            tools: [
                ['name' => 'search_motorcycles', 'description' => 'find bikes', 'parameters' => ['type' => 'object']],
            ],
            toolMode: 'any',
            allowedTools: ['search_motorcycles'],
        );

        (new GeminiProvider())->chat($request);

        Http::assertSent(function ($req) {
            $data = $req->data();

            return $req->hasHeader('x-goog-api-key', 'test-key-Key A')
                && $data['systemInstruction']['parts'][0]['text'] === 'be nice'
                && $data['contents'][0]['parts'][0]['text'] === 'what is this bike'
                && $data['contents'][0]['parts'][1]['inlineData']['mimeType'] === 'image/jpeg'
                && $data['contents'][0]['parts'][1]['inlineData']['data'] === 'ZmFrZQ=='
                && $data['tools'][0]['functionDeclarations'][0]['name'] === 'search_motorcycles'
                && $data['toolConfig']['functionCallingConfig']['mode'] === 'ANY'
                && $data['toolConfig']['functionCallingConfig']['allowedFunctionNames'] === ['search_motorcycles']
                && ! str_contains($req->url(), 'key=');
        });
    }

    public function test_parses_multiple_parts_including_parallel_function_calls(): void
    {
        $this->makeModel();

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [
                        ['text' => 'checking two things'],
                        ['functionCall' => ['id' => 'c1', 'name' => 'get_motorcycle_details', 'args' => ['motorcycle_ids' => [1]]], 'thoughtSignature' => 'sig-1'],
                        ['functionCall' => ['id' => 'c2', 'name' => 'get_branch_information', 'args' => []], 'thoughtSignature' => 'sig-2'],
                    ]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 5, 'totalTokenCount' => 10],
            ], 200),
        ]);

        $response = (new GeminiProvider())->chat(new AiRequest(contents: [
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'hi']]],
        ]));

        $this->assertSame(['checking two things'], $response->textParts);
        $this->assertCount(2, $response->toolCalls);
        $this->assertSame('get_motorcycle_details', $response->toolCalls[0]['name']);
        $this->assertSame('get_branch_information', $response->toolCalls[1]['name']);
        $this->assertSame(
            ['c1' => 'sig-1', 'c2' => 'sig-2'],
            $response->rawContinuation['tool_call_signatures']
        );
    }

    public function test_thought_signature_round_trips_into_the_next_request(): void
    {
        $this->makeModel();

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['totalTokenCount' => 1],
            ], 200),
        ]);

        (new GeminiProvider())->chat(new AiRequest(contents: [
            ['role' => 'model', 'parts' => [
                // 'raw' carries AiResponse::$rawContinuation['tool_call_signatures'][$id]
                // verbatim (a plain signature string, per AiRequest's docblock) - not
                // nested under a 'thought_signature' key.
                ['type' => 'tool_call', 'id' => 'c1', 'name' => 'search_motorcycles', 'args' => [], 'raw' => 'sig-1'],
            ]],
            ['role' => 'tool', 'parts' => [
                ['type' => 'tool_result', 'id' => 'c1', 'name' => 'search_motorcycles', 'result' => ['items' => []]],
            ]],
        ]));

        Http::assertSent(function ($req) {
            $data = $req->data();

            return $data['contents'][0]['role'] === 'model'
                && $data['contents'][0]['parts'][0]['thoughtSignature'] === 'sig-1'
                && $data['contents'][1]['role'] === 'user'
                && $data['contents'][1]['parts'][0]['functionResponse']['name'] === 'search_motorcycles';
        });
    }

    public function test_429_on_one_key_fails_over_to_the_next_key(): void
    {
        $this->makeModel('Key A', priority: 1);
        $this->makeModel('Key B', priority: 2);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'quota']], 429);
            }

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['totalTokenCount' => 1],
            ], 200);
        });

        $response = (new GeminiProvider())->chat(new AiRequest(contents: [
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'hi']]],
        ]));

        $this->assertSame(['ok'], $response->textParts);
        $this->assertSame(2, $calls);
    }

    public function test_503_is_retried_up_to_the_bound_then_throws_retryable_exception(): void
    {
        config()->set('gemini.rate_limits.max_transient_failovers', 1);
        $this->makeModel('Key A', priority: 1);
        $this->makeModel('Key B', priority: 2);

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response('server error', 503),
        ]);

        try {
            (new GeminiProvider())->chat(new AiRequest(contents: [
                ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'hi']]],
            ]));
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_invalid_key_is_disabled(): void
    {
        $model = $this->makeModel();

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response(['error' => ['message' => 'API key not valid']], 400),
        ]);

        try {
            (new GeminiProvider())->chat(new AiRequest(contents: [
                ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'hi']]],
            ]));
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException) {
            // expected: no more keys left after this one is disabled
        }

        $this->assertFalse($model->fresh()->is_active);
    }

    public function test_invalid_request_is_not_retried_on_another_key(): void
    {
        $this->makeModel('Key A', priority: 1);
        $this->makeModel('Key B', priority: 2);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['error' => ['message' => 'Invalid JSON payload']], 400);
        });

        try {
            (new GeminiProvider())->chat(new AiRequest(contents: [
                ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'hi']]],
            ]));
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertFalse($e->retryable);
        }

        $this->assertSame(1, $calls);
    }

    public function test_usage_is_parsed(): void
    {
        $this->makeModel();

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 3, 'totalTokenCount' => 10],
            ], 200),
        ]);

        $response = (new GeminiProvider())->chat(new AiRequest(contents: [
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'hi']]],
        ]));

        $this->assertSame(['input_tokens' => 7, 'output_tokens' => 3, 'total_tokens' => 10, 'cached_tokens' => 0, 'thoughts_tokens' => 0], $response->usage);
    }

    public function test_fallback_model_is_used_only_when_the_primary_is_unavailable(): void
    {
        config()->set('agent.fallback_models', 'gemini-fallback');
        $this->makeModel('Primary');
        $this->makeModel('Backup', 1, 'gemini-fallback');

        Http::fake([
            '*models/gemini-test:*' => Http::response(['error' => ['code' => 503, 'message' => 'high demand']], 503),
            '*models/gemini-fallback:*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['totalTokenCount' => 2],
            ], 200),
        ]);

        $response = (new GeminiProvider())->chat(new AiRequest(system: 's', contents: [['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'x']]]], thinkingBudget: 0));

        $this->assertSame(['ok'], $response->textParts);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'gemini-fallback')
            && $req->data()['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'minimal']);
    }
}
