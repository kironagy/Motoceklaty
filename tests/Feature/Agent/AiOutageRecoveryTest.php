<?php

namespace Tests\Feature\Agent;

use App\Console\Commands\ProcessWhatsappMessageJobs;
use App\Domain\Conversations\DeliveryService;
use App\Models\Handoff;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner 2026-10-02: on 1/10 the Gemini quota ran out for three hours.
 * Conversations went to staff after five minutes, nobody answered, and a
 * customer got "حصل عندنا شوية ضغط" six times. The bot must wait for the
 * AI and carry on from where the conversation stopped.
 */
class AiOutageRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): WhatsappConversation
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);

        return WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
    }

    private function turn(WhatsappConversation $conversation, array $overrides = []): object
    {
        $id = DB::table('whatsapp_message_jobs')->insertGetId(array_merge([
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
            'whatsapp_conversation_id' => $conversation->id,
            'from' => '2011@s.whatsapp.net',
            'status' => 'processing',
            'attempts' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return DB::table('whatsapp_message_jobs')->find($id);
    }

    private function invokeCommand(string $method, object $job): mixed
    {
        $command = app(ProcessWhatsappMessageJobs::class);
        $reflection = new \ReflectionMethod($command, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($command, $job);
    }

    private function say(WhatsappConversation $conversation, string $direction, string $sender, string $text, ?int $turnId = null): WhatsappMessage
    {
        return WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
            'direction' => $direction, 'sender_type' => $sender, 'type' => 'text', 'text' => $text, 'turn_id' => $turnId,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.delivery.max_attempts' => 3, 'agent.fallback.message' => 'حصل عندنا شوية ضغط دلوقتي', 'agent.turns.outage_retry_minutes' => 720]);

        $this->app->instance(DeliveryService::class, \Mockery::mock(DeliveryService::class, function ($mock) {
            $mock->shouldReceive('deliverForConversation')->andReturnUsing(function ($conversation, $result, $senderType) {
                foreach ($result['messages'] as $text) {
                    $this->say($conversation, 'outgoing', $senderType, $text);
                }
            });
        }));
    }

    public function test_a_long_outage_keeps_retrying_and_never_hands_off(): void
    {
        $conversation = $this->conversation();
        $job = $this->turn($conversation, ['attempts' => 9, 'created_at' => now()->subHours(3)]);

        [$status, $after] = $this->invokeCommand('scheduleTransientRetry', $job);

        $this->assertSame('pending', $status);
        $this->assertNotNull($after);
        $this->assertSame(0, Handoff::count());
        $this->assertSame('open', $conversation->refresh()->status);
    }

    public function test_the_busy_notice_goes_once_per_outage(): void
    {
        $conversation = $this->conversation();
        $this->say($conversation, 'outgoing', 'bot', 'تمام يا باشا');

        foreach ([3, 4, 5] as $attempts) {
            $this->invokeCommand('scheduleTransientRetry', $this->turn($conversation, ['attempts' => $attempts]));
        }

        $this->assertSame(1, WhatsappMessage::where('sender_type', 'system')->count());

        // the bot answered again; a later outage gets its own notice
        $this->say($conversation, 'outgoing', 'bot', 'رجعنا');
        $this->invokeCommand('scheduleTransientRetry', $this->turn($conversation));
        $this->assertSame(2, WhatsappMessage::where('sender_type', 'system')->count());
    }

    public function test_messages_from_the_outage_move_to_the_turn_that_answers_them(): void
    {
        $conversation = $this->conversation();
        $old = $this->turn($conversation, ['status' => 'pending', 'attempts' => 4]);
        $this->say($conversation, 'incoming', 'customer', 'الدور 6', $old->id);
        $newer = $this->turn($conversation, ['status' => 'pending', 'attempts' => 0]);
        $this->say($conversation, 'incoming', 'customer', 'هينفع ولا', $newer->id);

        $this->assertTrue($this->invokeCommand('supersedeIfStale', $old));

        $this->assertSame(2, WhatsappMessage::where('turn_id', $newer->id)->count());
    }
}
