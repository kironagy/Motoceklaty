<?php

namespace App\Console\Commands;

use App\Domain\Simulation\ConversationSimulator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * T18 §2: runs a set of scripted customer conversations (Egyptian Arabic,
 * owner-maintained JSON) against the REAL provider on a dev database, for
 * DEC-06 model evaluation. Never run in CI, never against production data
 * or a production WhatsApp number (plan constraint) - this only ever
 * creates its own throwaway conversation rows and calls AgentRunner
 * directly; nothing here can reach the real WhatsApp worker.
 *
 * The conversation mechanics live in ConversationSimulator (shared with the
 * dashboard simulator page).
 *
 * Unlike the real pipeline, this command never calls DeliveryService (which
 * POSTs to the WhatsApp worker) - that's deliberate, per the constraint
 * above. But ContextBuilder reads conversation history straight from
 * `whatsapp_messages`, so without persisting the bot's own reply, turn 2+ of
 * a scripted conversation would have no memory of what the bot already
 * said (it would look, to the model, like every customer message arrived
 * with no reply in between). runOne() persists each reply as an ordinary
 * outgoing WhatsappMessage row - no HTTP send - so multi-turn scripts get
 * realistic context.
 *
 * Input file shape: [{"customer_type": "employee", "messages": ["...", {"text": "...", "media": ["/abs/path/to.jpg"]}]}]
 * A message may be a plain string, or an object with "text" and/or a
 * "media" array of local file paths - each is read, stored on the local
 * disk exactly like IngestionService::storeMedia does for a real inbound
 * WhatsApp image, and attached to that message so process_document (and
 * therefore the real Google Vision OCR call) can be exercised end to end.
 */
class AgentEvaluate extends Command
{
    protected $signature = 'agent:evaluate {file : Path to a JSON file of scripted conversations}';

    protected $description = 'Run scripted conversations against the real AI provider and write a report (DEC-06 evaluation, manual use only).';

    public function handle(ConversationSimulator $simulator): int
    {
        if (app()->environment('testing', 'production')) {
            $this->error('agent:evaluate must not run in the testing or production environment.');

            return self::FAILURE;
        }

        $path = $this->argument('file');
        $scripts = json_decode(file_get_contents($path) ?: '[]', true);

        if (! is_array($scripts) || $scripts === []) {
            $this->error("No scripted conversations found in {$path}.");

            return self::FAILURE;
        }

        $report = ['model' => config('agent.model'), 'run_at' => now()->toIso8601String(), 'conversations' => []];

        foreach ($scripts as $index => $script) {
            $this->info('Running scripted conversation '.($index + 1).'/'.count($scripts));
            $conversation = $simulator->start($script['customer_type'] ?? null);
            $turns = [];

            foreach ($script['messages'] ?? [] as $entry) {
                $text = is_array($entry) ? ($entry['text'] ?? '') : $entry;
                $turns[] = $simulator->send($conversation, $text, is_array($entry) ? ($entry['media'] ?? []) : []);
            }

            $report['conversations'][] = [
                'customer_type' => $script['customer_type'] ?? null,
                'conversation_id' => $conversation->id,
                'turns' => $turns,
            ];
        }

        $outDir = storage_path('app/agent-eval');

        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $outPath = $outDir.'/'.now()->format('Y-m-d_His').'_'.Str::slug((string) config('agent.model')).'.json';
        file_put_contents($outPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info("Report written to {$outPath}");

        return self::SUCCESS;
    }
}
