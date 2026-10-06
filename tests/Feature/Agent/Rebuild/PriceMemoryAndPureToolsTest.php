<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Context\ContextBuilder;
use App\Agent\Runtime\AgentRunner;
use App\Agent\Runtime\ToolOutcomeRecorder;
use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\GetMotorcycleDetailsTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Conversations\QuotedOffer;
use App\Models\Brand;
use App\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rebuild: READ tools write nothing; the runner records what they proved,
 * and a quote he was given is a valid number source on the next turns.
 */
class PriceMemoryAndPureToolsTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeLimits();
        config(['agent.guard.number_min_value' => 1000]);
    }

    private function machine(): Machine
    {
        $brand = Brand::create(['name' => 'Hojan', 'image' => 'b.jpg']);

        return Machine::create(['name' => 'هوجن 4', 'brand_id' => $brand->id, 'cash_price' => 40000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
    }

    private function quote(int $conversationId, Machine $machine): void
    {
        $ctx = new ToolContext(1, $conversationId, null, 1, 1, new TurnResultBuilder());
        app(ToolOutcomeRecorder::class)->record('get_installment_offer', ['motorcycle_id' => $machine->id], ['ok' => true, 'data' => ['offers' => [
            ['months' => 24, 'monthly_payment' => 2533.4, 'cash_due_upfront' => 1750, 'admin_fee_at_pickup' => 1750, 'total_paid' => 60802, 'plan_id' => 7, 'down_payment' => 0],
        ]]], $ctx);
    }

    public function test_a_read_tool_leaves_the_conversation_untouched(): void
    {
        $conversation = $this->conversation();
        $machine = $this->machine();
        $before = $conversation->fresh()->state;

        $result = app(GetMotorcycleDetailsTool::class)->execute(['motorcycle_ids' => [$machine->id]], new ToolContext($conversation->customer_id, $conversation->id, null, 1, 1, new TurnResultBuilder()));

        $this->assertTrue($result->ok);
        $this->assertSame($before, $conversation->fresh()->state);
    }

    public function test_a_given_quote_is_remembered_with_its_numbers_and_shown_next_turn(): void
    {
        $conversation = $this->conversation();
        $machine = $this->machine();
        $this->quote($conversation->id, $machine);

        $ledger = QuotedOffer::ledger($conversation->fresh());
        $this->assertSame(2533, $ledger[0]['monthly_payment']);
        $this->assertEquals(['machine_id' => $machine->id, 'months' => 24, 'plan_id' => 7, 'down_payment' => 0], array_diff_key(QuotedOffer::last($conversation->id), ['at' => 1]));

        $system = app(ContextBuilder::class)->build($this->turnFor($conversation, 'القسط كام تاني؟'))->system;
        $this->assertStringContainsString('"monthly_payment":2533', $system);
    }

    public function test_repeating_a_remembered_installment_needs_no_new_lookup(): void
    {
        $conversation = $this->conversation();
        $this->quote($conversation->id, $this->machine());
        $fake = $this->fakeAi();
        $fake->queue($this->sendReply('القسط على سنتين 2533 جنيه في الشهر'));

        $result = app(AgentRunner::class)->run($this->turnFor($conversation, 'القسط كام تاني؟'));

        $this->assertSame(['القسط على سنتين 2533 جنيه في الشهر'], $result['messages']);
        $this->assertCount(1, $fake->requests());
    }

    public function test_a_price_edit_makes_the_old_quote_stale(): void
    {
        $conversation = $this->conversation();
        $machine = $this->machine();
        $this->quote($conversation->id, $machine);

        $this->travel(1)->minutes();
        $machine->update(['cash_price' => 42000]);

        $this->assertSame([], QuotedOffer::ledger($conversation->fresh()));
    }
}
