<?php

namespace Tests\Feature\Agent;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\HandoffToHumanTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Handoff\HandoffService;
use App\Models\AiTrace;
use App\Models\Handoff;
use App\Models\Notification;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HandoffServiceTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): WhatsappConversation
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret', 'is_admin' => true]);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);

        return WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '201000000000', 'status' => 'open']);
    }

    public function test_the_tool_sets_awaiting_agent_and_creates_a_handoff_record(): void
    {
        Staff::create(['name' => 'Admin', 'email' => 'admin'.uniqid().'@x.com', 'password' => 'secret', 'is_admin' => true]);
        $conversation = $this->conversation();
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new ToolContext(1, $conversation->id, null, 1, $trace->id, new TurnResultBuilder());

        $result = app(HandoffToHumanTool::class)->execute([
            'reason' => 'customer_request',
            'note' => 'عايز يكلم حد',
        ], $ctx);

        $this->assertTrue($result->ok);
        $conversation->refresh();
        $this->assertSame('awaiting_agent', $conversation->status);
        $this->assertSame(1, Handoff::where('conversation_id', $conversation->id)->count());
        $this->assertGreaterThan(0, Notification::count());
    }

    public function test_inbound_message_during_handoff_is_stored_with_no_turn(): void
    {
        $conversation = $this->conversation();
        app(HandoffService::class)->handOff($conversation, 'complaint', 'شكوى', 'ai');

        config(['services.whatsapp.bot_token' => 'test-token']);

        $this->postJson('/api/whatsapp/incoming-message', [
            'bot_id' => $conversation->whatsapp_bot_id,
            'wa_message_id' => 'wa1',
            'chat_jid' => '201000000000@s.whatsapp.net',
            'timestamp' => now()->timestamp,
            'type' => 'text',
            'text' => 'لسه مستني',
            'media' => [],
        ], ['X-BOT-TOKEN' => 'test-token'])->assertOk();

        $message = WhatsappMessage::where('wa_message_id', 'wa1')->first();
        $this->assertNull($message->turn_id);
    }

    public function test_staff_reply_is_stored_as_agent_with_wa_message_id(): void
    {
        config(['agent.enabled' => true, 'services.whatsapp.bot_token' => 'test-token', 'services.whatsapp.worker_url' => 'http://worker.test']);
        \Illuminate\Support\Facades\Http::fake([
            'worker.test/send-message' => \Illuminate\Support\Facades\Http::response(['ok' => true, 'wa_message_id' => 'bot1_agent1']),
        ]);

        $conversation = $this->conversation();

        $sent = \App\Filament\Resources\AwaitingAgentConversationResource::sendReply($conversation, 'أهلاً بيك تاني');

        $this->assertTrue($sent);
        $message = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)->where('sender_type', 'agent')->first();
        $this->assertNotNull($message);
        $this->assertSame('bot1_agent1', $message->wa_message_id);
    }

    public function test_closing_creates_a_turn_only_when_unanswered_inbound_messages_exist(): void
    {
        $conversation = $this->conversation();
        app(HandoffService::class)->handOff($conversation, 'complaint', 'شكوى', 'ai');

        $result = app(HandoffService::class)->close($conversation);
        $this->assertFalse($result['turn_created']);

        WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id,
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
            'wa_message_id' => 'wa-pending',
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'type' => 'text',
            'text' => 'في حد؟',
        ]);

        $result = app(HandoffService::class)->close($conversation);
        $this->assertTrue($result['turn_created']);
        $this->assertSame(1, DB::table('whatsapp_message_jobs')->where('id', $result['turn_id'])->count());
        $this->assertSame(
            $result['turn_id'],
            WhatsappMessage::where('wa_message_id', 'wa-pending')->value('turn_id')
        );
    }

    public function test_a_failure_trigger_call_works(): void
    {
        $conversation = $this->conversation();

        $handoff = app(HandoffService::class)->handOffForFailures($conversation, 'PROVIDER_DOWN');

        $conversation->refresh();
        $this->assertSame('awaiting_agent', $conversation->status);
        $this->assertSame('low_confidence', $handoff->reason);
        $this->assertSame('system', $handoff->source);
    }

    public function test_an_unanswered_handoff_returns_to_the_agent_after_the_window(): void
    {
        config(['agent.handoff.return_to_agent_after_minutes' => 20]);
        $conversation = $this->conversation();
        $handoff = app(HandoffService::class)->handOff($conversation, 'customer_request', 'x', 'ai');
        $handoff->update(['opened_at' => now()->subMinutes(25)]);

        $this->assertSame(1, app(HandoffService::class)->returnUnansweredToAgent());
        $this->assertSame('open', $conversation->refresh()->status);
        $this->assertNotNull($handoff->refresh()->closed_at);
    }

    public function test_a_handoff_staff_answered_is_left_alone(): void
    {
        config(['agent.handoff.return_to_agent_after_minutes' => 20]);
        $conversation = $this->conversation();
        $handoff = app(HandoffService::class)->handOff($conversation, 'customer_request', 'x', 'ai');
        $handoff->update(['opened_at' => now()->subMinutes(25)]);
        WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'outgoing', 'sender_type' => 'agent', 'type' => 'text', 'text' => 'معاك',
        ]);

        $this->assertSame(0, app(HandoffService::class)->returnUnansweredToAgent());
        $this->assertSame('awaiting_agent', $conversation->refresh()->status);
    }
}
