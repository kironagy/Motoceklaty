<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Tools\ReadTool;
use App\Agent\Tools\ToolResult;
use App\Agent\Tools\WriteTool;
use App\Console\Commands\AgentInstructionInventory;
use Tests\TestCase;

/** Rebuild ARCH-003: the contract lint (docs/rebuild/tool-contract-v2.md). */
class ToolContractTest extends TestCase
{
    /** READ tools allowed to call a model: it is what they are for. */
    private const AI_CALLING_READ_TOOLS = ['identify_motorcycle_from_image', 'lookup_motorcycle_specs_online'];

    /** Caches only: the vision result on the photo, the web specs cache. Derived state is the runner's (ToolOutcomeRecorder). */
    private const KNOWN_READ_WRITES = ['identify_motorcycle_from_image', 'lookup_motorcycle_specs_online'];

    /** No READ tool calls a model besides the declared vision/web ones (WorkClassifier is gone). */
    private const KNOWN_READ_AI_CALLS = [];

    private const KNOWN_PROSE_LINES = 0;

    private const WRITE_PATTERN = '/QuotedOffer::remember|QuotedMotorcycle::remember|CustomerInterest::remember|->update\(|::create\(|->save\(|fillEmptySelection|Cache::put|->increment\(|->delete\(/';

    private const AI_PATTERN = '/WorkClassifier|->chat\(|generateText/';

    private function tools(): array
    {
        return array_map(fn (string $class) => app($class), config('agent.tools'));
    }

    public function test_every_tool_is_exactly_one_kind_and_its_permission_agrees(): void
    {
        foreach ($this->tools() as $tool) {
            $read = $tool instanceof ReadTool;
            $write = $tool instanceof WriteTool;

            $this->assertTrue($read xor $write, $tool->name().' must implement exactly one of ReadTool / WriteTool');
            $read
                ? $this->assertSame('READ', $tool->permission(), $tool->name().' is a ReadTool')
                : $this->assertContains($tool->permission(), ['WRITE', 'DESTRUCTIVE'], $tool->name().' is a WriteTool');
            $this->assertMatchesRegularExpression('/^[a-z][a-z_]+$/', $tool->name());
        }
    }

    public function test_read_tools_write_nothing_beyond_the_frozen_list(): void
    {
        $writing = [];
        $calling = [];

        foreach ($this->tools() as $tool) {
            if (! $tool instanceof ReadTool) {
                continue;
            }

            $source = file_get_contents((new \ReflectionClass($tool))->getFileName());
            if (preg_match(self::WRITE_PATTERN, $source)) {
                $writing[] = $tool->name();
            }
            if (preg_match(self::AI_PATTERN, $source) && ! in_array($tool->name(), self::AI_CALLING_READ_TOOLS, true)) {
                $calling[] = $tool->name();
            }
        }

        sort($writing);
        sort($calling);
        $knownWrites = self::KNOWN_READ_WRITES;
        sort($knownWrites);
        $knownCalls = self::KNOWN_READ_AI_CALLS;
        sort($knownCalls);

        $this->assertSame($knownWrites, $writing, 'READ tools that write changed: fix the tool or update KNOWN_READ_WRITES and the contract doc');
        $this->assertSame($knownCalls, $calling, 'READ tools that call a model changed');
    }

    public function test_no_new_prose_instructions_in_tool_results(): void
    {
        $this->assertCount(self::KNOWN_PROSE_LINES, AgentInstructionInventory::proseInToolResults(),
            'Prose keys (say/how_to_present/note/...) in tool results changed - wording belongs in instruction packs (INST-012). Lower KNOWN_PROSE_LINES when you remove some.');
    }

    public function test_the_envelope_is_ok_data_or_error_code_detail(): void
    {
        $this->assertSame(['ok' => true, 'data' => ['x' => 1]], ToolResult::ok(['x' => 1])->toArray());
        $this->assertSame(['ok' => false, 'error' => ['code' => 'NOT_FOUND', 'detail' => 'no such motorcycle']], ToolResult::error('NOT_FOUND', 'no such motorcycle')->toArray());
    }
}
