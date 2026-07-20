<?php

namespace App\Console\Commands;

use App\Services\AiComplexReplyService;
use Illuminate\Console\Command;

class TestAiReply extends Command
{
    protected $signature = 'ai:test-reply {message}';

    protected $description = 'Test AI reply with memory context';

    public function handle(): int
    {
        $message = (string) $this->argument('message');

        $result = app(AiComplexReplyService::class)->reply($message);

        if (! ($result['ok'] ?? false)) {
            $this->error('AI failed: ' . ($result['error'] ?? 'unknown_error'));
            return self::FAILURE;
        }

        $this->info('Intent: ' . ($result['intent'] ?? 'unknown'));
        $this->info('Confidence: ' . ($result['confidence'] ?? 'unknown'));
        $this->info('Model: ' . ($result['model'] ?? 'unknown'));
        $this->info('Key ID: ' . ($result['key_id'] ?? 'unknown'));

        $this->line('');
        $this->line('Reply:');
        $this->line($result['reply']);

        return self::SUCCESS;
    }
}