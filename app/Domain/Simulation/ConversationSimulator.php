<?php

namespace App\Domain\Simulation;

use App\Agent\Runtime\AgentRunner;
use App\Domain\Conversations\DeliveryService;
use App\Models\AiTrace;
use App\Models\AiTraceStep;
use App\Models\Customer;
use App\Models\MessageMedia;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Talks to the real agent (real model, real tools, real catalog) without
 * WhatsApp. Replies go through DeliveryService in dry-run mode: stored
 * exactly as a delivered reply would be (so the next turn sees them in its
 * history), but nothing is posted to the WhatsApp worker.
 *
 * Simulated conversations belong to their own inactive bot, so the worker
 * and the Node transport never pick them up.
 */
class ConversationSimulator
{
    public const BOT_KEY = 'dashboard-simulator';

    public function __construct(private readonly DeliveryService $delivery)
    {
    }

    public function bot(): WhatsappBot
    {
        $staff = Staff::firstOrCreate(
            ['email' => 'simulator@local.test'],
            ['name' => 'محاكي المحادثات', 'password' => Str::random(32)]
        );

        return WhatsappBot::firstOrCreate(
            ['whatsapp_phone_number_id' => self::BOT_KEY],
            ['staff_id' => $staff->id, 'name' => 'محاكي المحادثات', 'is_active' => false]
        );
    }

    public function start(?string $label = null, ?WhatsappBot $bot = null): WhatsappConversation
    {
        $bot ??= $this->bot();
        $phone = 'sim-'.Str::lower(Str::random(8));

        $customer = Customer::create([
            'whatsapp_bot_id' => $bot->id,
            'jid' => $phone.'@s.whatsapp.net',
            'phone' => $phone,
            'push_name' => $label,
        ]);

        return WhatsappConversation::create([
            'whatsapp_bot_id' => $bot->id,
            'phone' => $phone,
            'status' => 'open',
            'customer_id' => $customer->id,
        ]);
    }

    /**
     * Sends one customer message and runs the agent on it.
     *
     * @param  string[]  $mediaPaths  absolute local file paths
     * @return array{customer_message: string, reply: string[], media: array, latency_ms: int, tokens: array, guard_events: array, tools: array, error: ?string, trace_id: ?int}
     */
    public function send(WhatsappConversation $conversation, string $text, array $mediaPaths = []): array
    {
        $conversation->refresh();
        $message = WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id,
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'type' => $mediaPaths !== [] ? 'image' : 'text',
            'text' => $text,
        ]);

        foreach ($mediaPaths as $localPath) {
            $this->attachMedia($message, $localPath);
        }

        // Handed off to staff: the real pipeline schedules no turn and the
        // bot stays silent (TurnScheduler), so the simulator does the same.
        if ($conversation->status === 'awaiting_agent') {
            return [
                'customer_message' => $text, 'reply' => [], 'media' => [], 'latency_ms' => 0,
                'tokens' => ['input' => null, 'output' => null], 'model' => null, 'guard_events' => [],
                'tools' => [], 'error' => null, 'trace_id' => null, 'handed_off' => true,
            ];
        }

        $turnId = DB::table('whatsapp_message_jobs')->insertGetId([
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
            'whatsapp_conversation_id' => $conversation->id,
            'status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $message->update(['turn_id' => $turnId]);
        $turn = DB::table('whatsapp_message_jobs')->find($turnId);

        $started = microtime(true);

        try {
            // a fresh runner per turn, like the worker: its services cache
            // per-turn reads (e.g. CustomerStatements' customer messages), so
            // one runner reused across turns never saw newer messages and
            // rejected a national ID the customer had just typed
            $result = app(AgentRunner::class)->run($turn);
            $error = null;
        } catch (\Throwable $e) {
            $result = ['messages' => [], 'media' => []];
            $error = $e->getMessage();
        }

        if (! $error) {
            // same rows, formatting and media memory as a real send, minus
            // the call to the WhatsApp worker
            $this->delivery->dryRun()->deliver($turn, $result, 'bot', false);
        }

        DB::table('whatsapp_message_jobs')->where('id', $turnId)->update([
            'status' => $error ? 'failed' : 'done',
            'updated_at' => now(),
        ]);

        $trace = AiTrace::where('turn_id', $turnId)->first();

        return [
            'customer_message' => $text,
            'reply' => array_values($result['messages'] ?? []),
            'media' => array_values($result['media'] ?? []),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'tokens' => ['input' => $trace?->input_tokens, 'output' => $trace?->output_tokens],
            'model' => $trace?->model,
            'guard_events' => $trace?->guard_events ?? [],
            'tools' => $trace ? $this->toolSteps($trace) : [],
            'error' => $error,
            'trace_id' => $trace?->id,
            'handed_off' => false,
        ];
    }

    /**
     * What staff closing the handoff does, minus answering the waiting
     * messages through the worker (this bot has no WhatsApp session).
     */
    public function returnToBot(WhatsappConversation $conversation): void
    {
        $conversation->update(['status' => 'open']);

        \App\Models\Handoff::where('conversation_id', $conversation->id)
            ->whereNull('closed_at')
            ->update(['closed_at' => now()]);
    }

    /** @return array<int, array{name: string, code: ?string, args: mixed, result: mixed, ms: ?int}> */
    private function toolSteps(AiTrace $trace): array
    {
        return AiTraceStep::where('trace_id', $trace->id)
            ->where('kind', 'tool_call')
            ->orderBy('seq')
            ->get()
            ->map(fn (AiTraceStep $step) => [
                'name' => (string) $step->tool_name,
                'code' => $step->result_code,
                'args' => $step->args_redacted,
                'result' => $step->result_redacted,
                'ms' => $step->latency_ms,
            ])
            ->all();
    }

    private function attachMedia(WhatsappMessage $message, string $localPath): void
    {
        $binary = @file_get_contents($localPath);

        if ($binary === false) {
            throw new \RuntimeException("Could not read media file: {$localPath}");
        }

        $mime = mime_content_type($localPath) ?: 'application/octet-stream';
        $extension = pathinfo($localPath, PATHINFO_EXTENSION) ?: 'bin';
        $path = sprintf('whatsapp-media/conversation-%d/%s_%s.%s', $message->whatsapp_conversation_id, now()->format('Ymd_His'), Str::random(12), $extension);

        Storage::disk('local')->put($path, $binary);

        MessageMedia::create([
            'message_id' => $message->id,
            'media_type' => str_starts_with($mime, 'image/') ? 'image' : 'document',
            'mime' => $mime,
            'disk' => 'local',
            'path' => $path,
            'size' => strlen($binary),
            'sha256' => hash('sha256', $binary),
            'original_filename' => basename($localPath),
        ]);
    }
}
