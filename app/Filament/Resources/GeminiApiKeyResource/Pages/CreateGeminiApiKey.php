<?php

namespace App\Filament\Resources\GeminiApiKeyResource\Pages;

use App\Filament\Resources\GeminiApiKeyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGeminiApiKey extends CreateRecord
{
    protected static string $resource = GeminiApiKeyResource::class;

    protected function afterCreate(): void
    {
        $apiKey = $this->record;

        $provider = $apiKey->provider ?? 'gemini';

        foreach (config("gemini.providers.$provider.default_models", []) as $model) {
            $apiKey->models()->firstOrCreate(
                [
                    'model_code' => $model['model_code'],
                ],
                [
                    'provider' => $provider,
                    'display_name' => $model['display_name'],
                    'category' => $model['category'],
                    'rpm_limit' => $model['rpm_limit'],
                    'rpd_limit' => $model['rpd_limit'],
                    'tps_limit' => $model['tps_limit'],
                    'priority' => $model['priority'],
                    'is_embedding' => $model['is_embedding'],
                    'is_active' => true,
                    'requests_today' => 0,
                    'requests_this_minute' => 0,
                    'tokens_this_second' => 0,
                    'minute_window_started_at' => now(),
                    'second_window_started_at' => now(),
                ]
            );
        }
    }
}
