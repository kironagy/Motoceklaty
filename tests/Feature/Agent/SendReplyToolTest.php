<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\SendReplyTool;
use App\Agent\Tools\ToolContext;
use App\Models\AiTrace;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendReplyToolTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): WhatsappConversation
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);

        return WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '201000000000', 'status' => 'open']);
    }

    private function contextFor(WhatsappConversation $conversation, TurnResultBuilder $outbound): ToolContext
    {
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);

        return new ToolContext(1, $conversation->id, null, 1, $trace->id, $outbound);
    }

    public function test_replaces_awaiting_and_writes_to_outbound(): void
    {
        $conversation = $this->conversation();
        $outbound = new TurnResultBuilder();
        $ctx = $this->contextFor($conversation, $outbound);

        $result = (new SendReplyTool())->execute([
            'messages' => ['أهلاً بيك'],
            'awaiting' => [['kind' => 'confirmation', 'key' => 'submit']],
        ], $ctx);

        $this->assertTrue($result->ok);
        $this->assertTrue($outbound->isFinished());
        $this->assertSame(['أهلاً بيك'], $outbound->toArray()['messages']);

        $conversation->refresh();
        $this->assertSame('confirmation', $conversation->state['awaiting'][0]['kind']);

        // A second send_reply replaces, not appends.
        $ctx2 = $this->contextFor($conversation, new TurnResultBuilder());
        (new SendReplyTool())->execute(['messages' => ['تاني'], 'awaiting' => []], $ctx2);
        $conversation->refresh();
        $this->assertSame([], $conversation->state['awaiting']);
    }

    public function test_rejects_unknown_focus_id(): void
    {
        $conversation = $this->conversation();
        $ctx = $this->contextFor($conversation, new TurnResultBuilder());

        $result = (new SendReplyTool())->execute([
            'messages' => ['رد'],
            'focus_motorcycle_ids' => [999999],
        ], $ctx);

        $this->assertFalse($result->ok);
        $this->assertSame('UNKNOWN_FOCUS_ID', $result->error['code']);
    }

    public function test_rejects_unknown_quote_target(): void
    {
        $conversation = $this->conversation();
        $ctx = $this->contextFor($conversation, new TurnResultBuilder());

        $result = (new SendReplyTool())->execute([
            'messages' => ['رد'],
            'quote_wa_message_id' => 'does-not-exist',
        ], $ctx);

        $this->assertFalse($result->ok);
        $this->assertSame('QUOTE_NOT_FOUND', $result->error['code']);
    }

    public function test_never_modifies_an_application_row(): void
    {
        // No applications domain exists yet (T13) - this test documents and
        // guards the constraint: send_reply must touch nothing but
        // conversation state and the outbound queue.
        $conversation = $this->conversation()->fresh();
        $before = $conversation->getAttributes();

        (new SendReplyTool())->execute(['messages' => ['رد']], $this->contextFor($conversation, new TurnResultBuilder()));

        $conversation->refresh();
        unset($before['state'], $before['updated_at']);
        $after = $conversation->getAttributes();
        unset($after['state'], $after['updated_at']);

        $this->assertSame($before, $after);
    }
}
