<?php

namespace Tests\Feature\Agent;

use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers T05's turn-grouping rules (DEC-07): a burst of messages within the
 * debounce window shares one turn; a message after max_wait starts a new
 * one; an already-claimed (processing) turn is superseded, not silently
 * replaced; a conversation under human handoff never gets a turn.
 */
class TurnSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.bot_token' => 'test-token',
            'agent.turns.debounce_seconds' => 3,
            'agent.turns.media_debounce_seconds' => 6,
            'agent.turns.max_wait_seconds' => 15,
        ]);
    }

    private function bot(): WhatsappBot
    {
        $staff = Staff::create([
            'name' => 'Test Staff',
            'email' => 'staff'.uniqid().'@example.com',
            'password' => 'secret',
        ]);

        return WhatsappBot::create([
            'staff_id' => $staff->id,
            'name' => 'Test Bot',
            'whatsapp_phone_number_id' => 'wpid-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function send(WhatsappBot $bot, string $waMessageId, string $text): void
    {
        $this->postJson('/api/whatsapp/incoming-message', [
            'bot_id' => $bot->id,
            'wa_message_id' => $waMessageId,
            'chat_jid' => '201000000000@s.whatsapp.net',
            'timestamp' => now()->timestamp,
            'type' => 'text',
            'text' => $text,
            'media' => [],
        ], ['X-BOT-TOKEN' => 'test-token'])->assertOk();
    }

    public function test_three_messages_within_debounce_window_share_one_turn(): void
    {
        $bot = $this->bot();
        Carbon::setTestNow('2026-01-01 10:00:00');

        $this->send($bot, $bot->id.'_wa1', 'واحد');
        Carbon::setTestNow('2026-01-01 10:00:01');
        $this->send($bot, $bot->id.'_wa2', 'اتنين');
        Carbon::setTestNow('2026-01-01 10:00:02');
        $this->send($bot, $bot->id.'_wa3', 'تلاتة');

        $this->assertSame(1, DB::table('whatsapp_message_jobs')->count());

        $turnId = DB::table('whatsapp_message_jobs')->value('id');
        $this->assertSame(3, DB::table('whatsapp_messages')->where('turn_id', $turnId)->count());
    }

    public function test_message_after_max_wait_starts_a_new_turn(): void
    {
        $bot = $this->bot();
        Carbon::setTestNow('2026-01-01 10:00:00');

        $this->send($bot, $bot->id.'_wa1', 'واحد');
        Carbon::setTestNow('2026-01-01 10:00:20'); // past the 15s max wait

        $this->send($bot, $bot->id.'_wa2', 'اتنين');

        $this->assertSame(2, DB::table('whatsapp_message_jobs')->count());
    }

    public function test_awaiting_agent_conversation_gets_no_turn(): void
    {
        $bot = $this->bot();
        $this->send($bot, $bot->id.'_wa1', 'واحد');

        WhatsappConversation::first()->update(['status' => 'awaiting_agent']);

        $this->send($bot, $bot->id.'_wa2', 'اتنين');

        $secondMessage = DB::table('whatsapp_messages')->where('wa_message_id', $bot->id.'_wa2')->first();
        $this->assertNull($secondMessage->turn_id);
    }

    public function test_new_message_supersedes_a_turn_already_processing(): void
    {
        $bot = $this->bot();
        $this->send($bot, $bot->id.'_wa1', 'واحد');

        $turnId = DB::table('whatsapp_message_jobs')->value('id');
        DB::table('whatsapp_message_jobs')->where('id', $turnId)->update(['status' => 'processing']);

        $this->send($bot, $bot->id.'_wa2', 'اتنين');

        $original = DB::table('whatsapp_message_jobs')->where('id', $turnId)->first();
        $this->assertSame('superseded', $original->status);
        $this->assertNotNull($original->superseded_by);

        $newTurn = DB::table('whatsapp_message_jobs')->where('id', $original->superseded_by)->first();
        $this->assertSame('pending', $newTurn->status);

        $secondMessage = DB::table('whatsapp_messages')->where('wa_message_id', $bot->id.'_wa2')->first();
        $this->assertSame($newTurn->id, $secondMessage->turn_id);
    }

    public function test_a_generated_but_undelivered_reply_is_superseded_by_a_new_message(): void
    {
        // WhatsApp down: the generated reply waits for delivery; once the
        // customer says something new, that reply is stale.
        $bot = $this->bot();
        $this->send($bot, $bot->id.'_wa1', 'واحد');
        $turnId = DB::table('whatsapp_message_jobs')->value('id');
        DB::table('whatsapp_message_jobs')->where('id', $turnId)->update(['status' => 'generated']);

        $this->send($bot, $bot->id.'_wa2', 'اتنين');

        $this->assertSame('superseded', DB::table('whatsapp_message_jobs')->find($turnId)->status);
    }

    public function test_a_turn_is_superseded_at_most_once(): void
    {
        $bot = $this->bot();
        $this->send($bot, $bot->id.'_wa1', 'واحد');

        $turnId = DB::table('whatsapp_message_jobs')->value('id');
        DB::table('whatsapp_message_jobs')->where('id', $turnId)->update(['status' => 'processing']);

        $this->send($bot, $bot->id.'_wa2', 'اتنين');
        $firstSupersedeTurnId = DB::table('whatsapp_message_jobs')->where('id', $turnId)->value('superseded_by');

        // A second message arrives while the *new* turn is still pending
        // (not processing) - it should just join that pending turn, not
        // re-supersede the already-superseded original.
        $this->send($bot, $bot->id.'_wa3', 'تلاتة');

        $original = DB::table('whatsapp_message_jobs')->where('id', $turnId)->first();
        $this->assertSame($firstSupersedeTurnId, $original->superseded_by);
        $this->assertSame(2, DB::table('whatsapp_message_jobs')->count());
    }

    public function test_message_after_a_done_turn_starts_a_fresh_turn(): void
    {
        $bot = $this->bot();
        $this->send($bot, $bot->id.'_wa1', 'واحد');

        DB::table('whatsapp_message_jobs')->update(['status' => 'done']);

        $this->send($bot, $bot->id.'_wa2', 'اتنين');

        $this->assertSame(2, DB::table('whatsapp_message_jobs')->count());
        $this->assertSame('done', DB::table('whatsapp_message_jobs')->orderBy('id')->first()->status);
        $this->assertSame('pending', DB::table('whatsapp_message_jobs')->orderBy('id')->get()->last()->status);
    }
}
