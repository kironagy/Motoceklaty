<?php

namespace App\Console\Commands;

use App\Services\AiMemoryContextBuilder;
use Illuminate\Console\Command;

class TestAiMemoryContext extends Command
{
    protected $signature = 'ai:test-memory {message}';

    protected $description = 'Test AI memory context builder';

    public function handle(): int
    {
        $message = (string) $this->argument('message');

        $result = app(AiMemoryContextBuilder::class)->buildForMessage($message);

        $this->info('Intent: ' . $result['intent']);
        $this->info('Confidence: ' . $result['confidence']);
        $this->info('Score: ' . $result['score']);

        $this->line('');
        $this->line('Scores:');
        foreach (($result['scores'] ?? []) as $intent => $score) {
            $this->line("- {$intent}: {$score}");
        }

        $this->line('');
        $this->line('Context:');
        $this->line($result['context']);

        return self::SUCCESS;
    }
}