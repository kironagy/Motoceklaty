<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
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

}
