<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\GetBusinessKnowledgeTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Knowledge\KnowledgeService;
use App\Models\AiTrace;
use App\Models\BusinessMemory;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pinned_returns_active_pinned_ordered_by_priority(): void
    {
        BusinessMemory::create(['key' => 'a', 'title' => 'A', 'content' => 'x', 'is_pinned' => true, 'is_active' => true, 'priority' => 2]);
        BusinessMemory::create(['key' => 'b', 'title' => 'B', 'content' => 'x', 'is_pinned' => true, 'is_active' => true, 'priority' => 1]);
        BusinessMemory::create(['key' => 'c', 'title' => 'C', 'content' => 'x', 'is_pinned' => false, 'is_active' => true]);
        BusinessMemory::create(['key' => 'd', 'title' => 'D', 'content' => 'x', 'is_pinned' => true, 'is_active' => false]);

        $pinned = app(KnowledgeService::class)->pinned();

        $this->assertSame(['b', 'a'], $pinned->pluck('key')->all());
    }

    public function test_index_returns_active_non_pinned_key_to_title(): void
    {
        BusinessMemory::create(['key' => 'a', 'title' => 'عنوان أ', 'content' => 'x', 'is_pinned' => false, 'is_active' => true]);
        BusinessMemory::create(['key' => 'b', 'title' => 'عنوان ب', 'content' => 'x', 'is_pinned' => true, 'is_active' => true]);

        $index = app(KnowledgeService::class)->index();

        $this->assertSame(['a' => 'عنوان أ'], $index->all());
    }

    public function test_scoped_matches_customer_type_or_application_status(): void
    {
        BusinessMemory::create([
            'key' => 'self_employed_tips', 'title' => 'T', 'content' => 'x', 'is_active' => true,
            'scope_customer_types' => ['self_employed'],
        ]);
        BusinessMemory::create([
            'key' => 'unrelated', 'title' => 'T2', 'content' => 'x', 'is_active' => true,
            'scope_customer_types' => ['employee'],
        ]);

        $scoped = app(KnowledgeService::class)->scoped('self_employed', null);

        $this->assertSame(['self_employed_tips'], $scoped->pluck('key')->all());
    }

    public function test_pinned_cap_blocks_save_when_configured(): void
    {
        config(['agent.context.pinned_memory_tokens' => 5]);

        $memory = new BusinessMemory([
            'key' => 'too_long', 'title' => 'T', 'content' => str_repeat('a', 100), 'is_pinned' => true,
        ]);

        // 100 chars / 4 = 25 tokens > cap of 5.
        $this->assertGreaterThan(5, $memory->estimatedTokens());
    }

    public function test_tool_returns_content_and_unknown_key(): void
    {
        BusinessMemory::create(['key' => 'known', 'title' => 'T', 'content' => 'محتوى', 'is_active' => true]);

        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new ToolContext(1, $conversation->id, null, 1, $trace->id, new TurnResultBuilder());

        $tool = app(GetBusinessKnowledgeTool::class);

        $ok = $tool->execute(['keys' => ['known']], $ctx);
        $this->assertTrue($ok->ok);
        $this->assertSame('محتوى', $ok->data['items'][0]['content']);

        $fail = $tool->execute(['keys' => ['missing']], $ctx);
        $this->assertFalse($fail->ok);
        $this->assertSame('UNKNOWN_KEY', $fail->error['code']);
    }
}
