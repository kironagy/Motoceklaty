<?php

namespace App\Console\Commands;

use App\Services\GeminiKeyManager;
use Illuminate\Console\Command;

class ResetGeminiUsage extends Command
{
    protected $signature = 'gemini:reset-usage';

    protected $description = 'Reset Gemini API keys models daily usage';

    public function handle(): int
    {
        app(GeminiKeyManager::class)->resetDailyUsage();

        $this->info('Gemini usage reset successfully.');

        return self::SUCCESS;
    }
}
