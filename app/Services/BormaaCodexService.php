<?php

namespace App\Services;

use App\Models\GeminiApiKey;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BormaaCodexService
{
    public function sync(GeminiApiKey $key): array
    {
        if (! $key->isBormaa()) {
            throw new RuntimeException('This key is not a Bormaa Codex key.');
        }

        $models = $this->get($key, 'models')->json('data', []);
        $usage = (array) $this->get($key, 'usage')->json();

        foreach ($models as $priority => $model) {
            $id = (string) data_get($model, 'id');
            if ($id === '') {
                continue;
            }

            $code = 'bormaa:'.$id;
            $key->models()->updateOrCreate(
                ['model_code' => $code],
                [
                    'provider' => 'bormaa',
                    'display_name' => (string) (data_get($model, 'name') ?: $id),
                    'category' => 'Bormaa Codex',
                    'rpm_limit' => max(1, (int) data_get($usage, 'limits.requests_per_minute', 30)),
                    'rpd_limit' => 1000000,
                    'tps_limit' => 10000000,
                    'priority' => $priority,
                    'is_embedding' => false,
                    'is_active' => true,
                ]
            );
        }

        $available = collect($models)->pluck('id')->filter()->map(fn ($id) => 'bormaa:'.$id)->all();
        $key->models()->where('provider', 'bormaa')->when($available !== [], fn ($q) => $q->whereNotIn('model_code', $available))->update(['is_active' => false]);

        $key->update([
            'usage_snapshot' => $usage,
            'usage_refreshed_at' => now(),
            'last_error' => null,
        ]);

        return ['models' => count($models), 'usage' => $usage];
    }

    private function get(GeminiApiKey $key, string $path): Response
    {
        $response = Http::baseUrl(rtrim((string) config('gemini.providers.bormaa.base_url'), '/'))
            ->acceptJson()
            ->withToken($key->api_key)
            ->timeout(20)
            ->get($path);

        if (! $response->successful()) {
            throw new RuntimeException("Bormaa Codex {$path} failed (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 500));
        }

        return $response;
    }
}
