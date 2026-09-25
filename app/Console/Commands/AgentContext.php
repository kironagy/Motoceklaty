<?php

namespace App\Console\Commands;

use App\Agent\Context\ContextBuilder;
use App\Models\AiTrace;
use App\Models\WhatsappConversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * T16 verification: read-only debug preview of the context ContextBuilder
 * would produce for a conversation right now. Runs the real build() inside
 * a rolled-back transaction, so the manifest write, the awaiting clean-up
 * and any summarizer dispatch never actually persist.
 */
class AgentContext extends Command
{
    protected $signature = 'agent:context {conversation : WhatsappConversation ID}';

    protected $description = 'Print the context manifest and token estimates ContextBuilder would produce (read-only).';

    public function handle(ContextBuilder $builder): int
    {
        $conversation = WhatsappConversation::find($this->argument('conversation'));

        if (! $conversation) {
            $this->error('Conversation not found.');

            return self::FAILURE;
        }

        $turn = DB::table('whatsapp_message_jobs')
            ->where('whatsapp_conversation_id', $conversation->id)
            ->latest('id')
            ->first() ?? (object) ['id' => 0, 'whatsapp_conversation_id' => $conversation->id];

        DB::beginTransaction();

        try {
            $builder->build($turn);
            $manifest = AiTrace::where('conversation_id', $conversation->id)->where('turn_id', $turn->id)->value('context_manifest');
        } finally {
            DB::rollBack();
        }

        $this->line(json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
