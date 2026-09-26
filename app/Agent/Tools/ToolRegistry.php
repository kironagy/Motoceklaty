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
        foreach (config('agent.tools', []) as $class) {
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

        if ($existing) {
            return $existing->result_redacted;
        }

        $start = microtime(true);

        try {
            $result = ($conflict = $this->mentionedMotorcycleConflict($args, $ctx))
                ? ToolResult::error('NOT_THE_MODEL_HE_NAMED', $conflict)->toArray()
                : $tool->execute($args, $ctx)->toArray();
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

    /** Every tool that takes a motorcycle must take the one the customer just named. */
    private function mentionedMotorcycleConflict(array $args, ToolContext $ctx): ?string
    {
        $ids = array_filter(array_merge((array) ($args['motorcycle_ids'] ?? []), isset($args['motorcycle_id']) ? [$args['motorcycle_id']] : []), 'is_numeric');

        foreach (\App\Models\Machine::whereIn('id', $ids)->get() as $machine) {
            if ($conflict = \App\Domain\Conversations\MentionedMotorcycle::conflict($ctx->conversationId, $machine)) {
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
