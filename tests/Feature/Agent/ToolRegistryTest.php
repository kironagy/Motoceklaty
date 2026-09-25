<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\Tool;
use App\Agent\Tools\ToolContext;
use App\Agent\Tools\ToolRegistry;
use App\Agent\Tools\ToolResult;
use App\Models\AiTrace;
use App\Models\AiTraceStep;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A test-only tool that proves it received conversationId from context,
 * not from its own args, and that lets tests trigger a TOOL_FAILED path.
 */
class SpyTool implements Tool
{
    public static int $calls = 0;

    public static ?int $lastSeenConversationId = null;

    public static bool $shouldThrow = false;

    public function name(): string
    {
        return 'spy_tool';
    }

    public function description(): string
    {
        return 'test only';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string', 'maxLength' => 5], 'age' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10]],
            'required' => ['name'],
        ];
    }

    public function permission(): string
    {
        return 'READ';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        self::$calls++;
        self::$lastSeenConversationId = $ctx->conversationId;

        if (self::$shouldThrow) {
            throw new \RuntimeException('boom');
        }

        return ToolResult::ok(['echo' => $args, 'national_id' => '12345678901234']);
    }
}

class ToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SpyTool::$calls = 0;
        SpyTool::$lastSeenConversationId = null;
        SpyTool::$shouldThrow = false;

        config(['agent.tools' => [SpyTool::class]]);
    }

    private function context(): ToolContext
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '201000000000', 'status' => 'open']);
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);

        return new ToolContext(
            customerId: 1,
            conversationId: $conversation->id,
            activeApplicationId: null,
            turnId: 1,
            traceId: $trace->id,
            outbound: new TurnResultBuilder(),
        );
    }

    public function test_unknown_tool_is_rejected(): void
    {
        $registry = app(ToolRegistry::class);

        $result = $registry->execute('does_not_exist', [], $this->context());

        $this->assertFalse($result['ok']);
        $this->assertSame('UNKNOWN_TOOL', $result['error']['code']);
    }

    public function test_invalid_arguments_are_rejected_for_each_schema_keyword(): void
    {
        $registry = app(ToolRegistry::class);
        $ctx = $this->context();

        // required
        $result = $registry->execute('spy_tool', [], $ctx);
        $this->assertSame('INVALID_ARGUMENTS', $result['error']['code']);

        // maxLength
        $result = $registry->execute('spy_tool', ['name' => 'toolong'], $ctx);
        $this->assertSame('INVALID_ARGUMENTS', $result['error']['code']);

        // maximum
        $result = $registry->execute('spy_tool', ['name' => 'ok', 'age' => 99], $ctx);
        $this->assertSame('INVALID_ARGUMENTS', $result['error']['code']);
    }

    public function test_identical_call_in_same_turn_is_cached_and_runs_once(): void
    {
        $registry = app(ToolRegistry::class);
        $ctx = $this->context();

        $first = $registry->execute('spy_tool', ['name' => 'ok'], $ctx);
        $second = $registry->execute('spy_tool', ['name' => 'ok'], $ctx);

        $this->assertSame(1, SpyTool::$calls);
        $this->assertSame($first, $second);
        $this->assertSame(1, AiTraceStep::count());
    }

    public function test_exception_becomes_tool_failed_and_is_traced(): void
    {
        SpyTool::$shouldThrow = true;
        $registry = app(ToolRegistry::class);

        $result = $registry->execute('spy_tool', ['name' => 'ok'], $this->context());

        $this->assertFalse($result['ok']);
        $this->assertSame('TOOL_FAILED', $result['error']['code']);
        $this->assertSame('TOOL_FAILED', AiTraceStep::first()->result_code);
    }

    public function test_context_injection_not_from_args(): void
    {
        $registry = app(ToolRegistry::class);
        $ctx = $this->context();

        $registry->execute('spy_tool', ['name' => 'ok'], $ctx);

        $this->assertSame($ctx->conversationId, SpyTool::$lastSeenConversationId);
    }

    public function test_redactor_masks_national_id_in_nested_result(): void
    {
        $registry = app(ToolRegistry::class);
        $result = $registry->execute('spy_tool', ['name' => 'ok'], $this->context());

        $this->assertSame('[REDACTED]', $result['data']['national_id']);
    }
}
