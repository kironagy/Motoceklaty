<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Runtime\AgentRunner;
use App\Models\Application;
use App\Models\CustomerType;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rebuild TOOL-013: two stable tool sets, the browsing set a prefix of the application set. */
class ToolSetsTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeLimits();
    }

    /** @return string[] the tool names the model was given on its first call */
    private function toolsOfOneTurn($conversation, string $type = 'text'): array
    {
        $fake = $this->fakeAi();
        $fake->queue($this->sendReply('تحت أمرك'));
        $turn = $this->turnFor($conversation, 'عايز موتوسيكل');

        if ($type !== 'text') {
            WhatsappMessage::where('turn_id', $turn->id)->update(['type' => $type]);
        }

        app(AgentRunner::class)->run($turn);

        return array_column($fake->requests()[0]->tools, 'name');
    }

    public function test_a_photo_does_not_change_the_tool_list(): void
    {
        $conversation = $this->conversation();

        $this->assertSame($this->toolsOfOneTurn($conversation), $this->toolsOfOneTurn($conversation, 'video'));
    }

    public function test_the_browsing_set_is_a_prefix_of_the_application_set(): void
    {
        $conversation = $this->conversation();
        $browsing = $this->toolsOfOneTurn($conversation);

        $type = CustomerType::create(['key' => 'employee', 'label' => 'موظف']);
        Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'collecting']);
        $application = $this->toolsOfOneTurn($conversation);

        $this->assertSame($browsing, array_slice($application, 0, count($browsing)));
        $this->assertSame(AgentRunner::APPLICATION_TOOLS, array_slice($application, count($browsing)));
        $this->assertSame(AgentRunner::PHOTO_TOOLS, array_slice($browsing, -count(AgentRunner::PHOTO_TOOLS)));
        $this->assertSame([], array_intersect(AgentRunner::APPLICATION_TOOLS, $browsing));
    }
}
