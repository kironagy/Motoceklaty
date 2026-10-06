<?php

namespace Tests\Feature\Agent;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Agent\Runtime\AgentRunner;
use App\Agent\Runtime\TurnUnderstanding;
use App\Models\Customer;
use App\Models\RequirementField;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner 2026-10-04: the code saves what he wrote, and a second look checks each draft. */
class SalesmanRebuildTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agent.runtime.max_model_calls' => 6,
            'agent.runtime.max_tool_calls' => 10,
            'agent.runtime.wall_clock_seconds' => 30,
        ]);
    }

    private function conversation(): WhatsappConversation
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $conversation->update(['customer_id' => $customer->id]);

        return $conversation;
    }

    private function turnWith(WhatsappConversation $conversation, string $text): object
    {
        $id = DB::table('whatsapp_message_jobs')->insertGetId([
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id, 'whatsapp_conversation_id' => $conversation->id,
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'turn_id' => $id, 'direction' => 'incoming',
            'sender_type' => 'customer', 'type' => 'text', 'text' => $text]);

        return DB::table('whatsapp_message_jobs')->find($id);
    }

    private function fake(): FakeAiProvider
    {
        $fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $fake);

        return $fake;
    }

    private function reply(string $text, string $id): AiResponse
    {
        return new AiResponse([], [['id' => $id, 'name' => 'send_reply', 'args' => ['messages' => [$text]]]], 'STOP', ['input_tokens' => 10, 'output_tokens' => 5], 'gpt-test', null, 5);
    }

    private function jsonAnswer(array $data): AiResponse
    {
        return new AiResponse([json_encode($data, JSON_UNESCAPED_UNICODE)], [], 'STOP', ['input_tokens' => 10, 'output_tokens' => 5], 'gpt-test', null, 5);
    }

    public function test_the_reply_quality_page_shows_refusals_and_fallbacks(): void
    {
        $conversation = $this->conversation();
        \App\Models\AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'done', 'guard_events' => [['code' => 'ENGLISH_WORD', 'args' => []]]]);
        \App\Models\AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 2, 'status' => 'fallback', 'guard_events' => []]);
        \App\Models\AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 3, 'status' => 'done', 'guard_events' => []]);

        $summary = app(\App\Domain\Monitoring\ReplyQuality::class)->summary(now()->subDay());
        $this->assertSame(3, $summary['replies']);
        $this->assertSame(1, $summary['fallbacks']);
        $this->assertSame(['ENGLISH_WORD' => 1], $summary['codes']);

        $this->actingAs(Staff::create(['name' => 'A', 'email' => 'q'.uniqid().'@x.test', 'password' => 'secret', 'is_admin' => true, 'is_super_admin' => true]));
        \Livewire\Livewire::test(\App\Filament\Pages\ReplyQualityPage::class)->assertSee('كلمة إنجليزي')->assertSee('رد احتياطي');
    }
}
