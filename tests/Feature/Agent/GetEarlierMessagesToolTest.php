<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\GetEarlierMessagesTool;
use App\Agent\Tools\ToolContext;
use App\Models\AiTrace;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetEarlierMessagesToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_returns_messages_from_this_conversation(): void
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);

        $mine = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $other = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2022', 'status' => 'open']);

        WhatsappMessage::create(['whatsapp_conversation_id' => $mine->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'رقمي القومي 12345678901234']);
        WhatsappMessage::create(['whatsapp_conversation_id' => $other->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => 'رسالة تانية']);

        $trace = AiTrace::create(['conversation_id' => $mine->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new ToolContext(1, $mine->id, null, 1, $trace->id, new TurnResultBuilder());

        $result = (new GetEarlierMessagesTool())->execute([], $ctx);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->data['messages']);
        $this->assertStringContainsString('[REDACTED]', $result->data['messages'][0]['text']);
        $this->assertStringNotContainsString('12345678901234', $result->data['messages'][0]['text']);
    }
}
