<?php

namespace App\Agent\Tools;

use App\Agent\Tracing\Redactor;
use App\Models\AiTraceStep;
use Illuminate\Support\Facades\Log;

/**
 * The only place that knows how to run a tool by name. No switch/match on
 * tool names anywhere here or in the tools themselves (plan principle 2/4).
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function __construct(private readonly JsonSchemaValidator $validator)
    {
        // Dynamic Links: in form mode the bot takes no application in the chat - it sends the
        // form link instead; in bot mode the link tool is off and the chat intake is back.
        $skip = \App\Domain\Forms\FormLinkService::formsMode()
            ? config('agent.form_links.chat_intake_tools', [])
            : [SendApplicationLinkTool::class];

        if (config('agent.cash.sales_handoff')) $skip[] = CheckCashInventoryTool::class;

        foreach (config('agent.tools', []) as $class) {
            if (in_array($class, $skip, true)) {
                continue;
            }

            $tool = app($class);
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Declarations in the shape AiRequest::$tools expects (T03).
     */
    public function declarations(): array
    {
        return array_values(array_map(
            fn (Tool $tool) => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->inputSchema(),
            ],
            $this->tools
        ));
    }

    /**
     * @return array{ok: bool, data?: array, error?: array}
     */
    public function execute(string $name, array $args, ToolContext $ctx): array
    {
        if (! $this->has($name)) {
            return ToolResult::error('UNKNOWN_TOOL', "No such tool: {$name}")->toArray();
        }

        $tool = $this->tools[$name];
        $errors = $this->validator->validate($tool->inputSchema(), $args);

        if ($errors !== []) {
            return ToolResult::error('INVALID_ARGUMENTS', implode('; ', $errors))->toArray();
        }

        $argsHash = hash('sha256', json_encode($this->canonical($args), JSON_THROW_ON_ERROR));

        $existing = AiTraceStep::query()
            ->where('turn_id', $ctx->turnId)
            ->where('tool_name', $name)
            ->where('args_hash', $argsHash)
            ->first();

        if ($existing && $name !== 'check_cash_inventory') {
            // Media queues are in-memory. A resumed generation must rehydrate them; replaying only the JSON loses the photos.
            if ($name === 'send_motorcycle_images' && ($existing->result_redacted['ok'] ?? false)) {
                if ($ctx->outbound->hasMediaFor((int) ($args['motorcycle_id'] ?? 0))) return $existing->result_redacted;
                return $this->executeWithReconnect($tool, $args + ['resend'=>true], $ctx);
            }
            return $existing->result_redacted;
        }

        $start = microtime(true);
        [$args, $carriedWorkType] = \App\Domain\Applications\CustomerTypeAlias::normalize($args);
        if ($carriedWorkType !== null && $name === 'start_application') {
            $args['_work_type'] = $carriedWorkType;
        }

        try {
            // Every tool that takes a motorcycle takes the one he just named
            // (conversation 639: "هوجان ٤" was quoted and pictured as L250, id 4).
            $conflict = $name === 'search_motorcycles' ? null : $this->mentionedMotorcycleConflict($args, $ctx);
            // a write (his application's model) still waits until it is clearly his; a read or a photo never does
            $result = $conflict !== null && $tool->permission() !== 'READ' && $name !== 'send_motorcycle_images'
                ? ToolResult::error('NOT_THE_MODEL_HE_NAMED', $conflict)->toArray()
                : $this->executeWithReconnect($tool, $args, $ctx);

            // Live 2026-11-07..09: 99 lookups refused as NOT_THE_MODEL_HE_NAMED - a spelling test of his
            // words overruled the agent's reading (photos he asked for, his chosen model's details). What
            // the test saw is passed on as a note; which model he means stays the agent's call.
            if ($conflict !== null && ($result['ok'] ?? false) && is_array($result['data'] ?? null)) {
                $result['data']['check_which_model_he_means'] = $conflict;
            }
        } catch (\Throwable $e) {
            // submit_application crashed on a null plan and nothing anywhere
            // said why - the exception was swallowed whole. Log where it
            // broke (message redacted: it can carry SQL values), never the
            // args, and tell the model plainly that nothing happened.
            Log::error('Agent tool threw', [
                'tool' => $name,
                'turn_id' => $ctx->turnId,
                'exception' => get_class($e),
                'message' => Redactor::redact(mb_substr($e->getMessage(), 0, 500)),
                'at' => $e->getFile().':'.$e->getLine(),
            ]);

            $result = ToolResult::error('TOOL_FAILED', 'Internal error - this action did NOT happen and nothing was saved. '
                .'Do not tell the customer it was done; say you will check and try again, or hand off.')->toArray();
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $redactedResult = Redactor::redact($result);

        AiTraceStep::create([
            'trace_id' => $ctx->traceId,
            'turn_id' => $ctx->turnId,
            'seq' => AiTraceStep::query()->where('trace_id', $ctx->traceId)->count() + 1,
            'kind' => 'tool_call',
            'tool_name' => $name,
            'permission' => $tool->permission(),
            'args_hash' => $argsHash,
            'args_redacted' => Redactor::redact($args),
            'result_redacted' => $redactedResult,
            'result_code' => $result['error']['code'] ?? null,
            'latency_ms' => $latencyMs,
        ]);

        return $redactedResult;
    }

    /**
     * 2026-10-01 (request 4395): submit_application died on "MySQL server
     * has gone away" in the long-lived worker after a slow model call; the
     * customer was told it was being sent and had to confirm a second time.
     */
    private function executeWithReconnect(Tool $tool, array $args, ToolContext $ctx): array
    {
        try {
            return $tool->execute($args, $ctx)->toArray();
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'gone away') && ! str_contains($e->getMessage(), 'Lost connection')) {
                throw $e;
            }

            \Illuminate\Support\Facades\DB::reconnect();

            return $tool->execute($args, $ctx)->toArray();
        }
    }

    /** An error detail when a motorcycle in the args is not the model he named this turn, else null. */
    private function mentionedMotorcycleConflict(array $args, ToolContext $ctx): ?string
    {
        $ids = array_filter(array_merge((array) ($args['motorcycle_ids'] ?? []), isset($args['motorcycle_id']) ? [$args['motorcycle_id']] : []), 'is_numeric');

        if ($ids === []) {
            return null;
        }

        $currentText = app(\App\Agent\Runtime\ReplyGuard::class)->customerTextSinceLastReply(\App\Models\WhatsappConversation::findOrFail($ctx->conversationId));
        $named = app(\App\Agent\Runtime\CatalogMentions::class)->fullNames($currentText);
        // "صور دايو ٢ و٤" is an explicit request for both, not a different-model incident.
        if (count($named) > 1 && (preg_match('/صور|صوره|صورة|وريني/u', $currentText) || ($args['different_motorcycle_confirmed'] ?? false) === true)
            && array_diff(array_map('intval', $ids), $named) === []) return null;
        // A bare "3" answering the duration question is not motorcycle id 3.
        if (preg_match('/^\s*[123١٢٣]\s*$/u', $currentText)
            && app(\App\Agent\Runtime\MotorcycleSelection::class)->lastDuration($ctx->conversationId) !== null
            && count($ids) === 1 && app(\App\Agent\Runtime\MotorcycleSelection::class)->confirms($ctx->conversationId, (int) reset($ids))) return null;

        // A comparison explicitly names several models. Validate the whole
        // set before applying the single-model brand guard to each row.
        if (count($ids) > 1) {
            $text = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
                ->where('turn_id', $ctx->turnId)->where('direction', 'incoming')->pluck('text')->implode(' ');
            $named = app(\App\Agent\Runtime\CatalogMentions::class)->fullNames($text);
            if (preg_match('/بين|قارن|مقارن/u', $text) && count($named) > 1
                && array_diff(array_map('intval', $ids), $named) === []) return null;
        }

        foreach (\App\Models\Machine::whereIn('id', $ids)->get() as $machine) {
            if ($conflict = \App\Domain\Conversations\MentionedMotorcycle::conflict($ctx->conversationId, $ctx->turnId, $machine)) {
                return $conflict;
            }
        }

        return null;
    }

    /**
     * Stable key order so identical args always hash the same regardless of
     * how the caller built the array.
     */
    private function canonical(array $args): array
    {
        ksort($args);

        foreach ($args as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $args;
    }
}
