<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reported request: a customer who sends 2-3 messages back to back before
 * the bot replies should get each answer back as a WhatsApp quoted reply
 * (so it's clear which answer matches which question), but the exact same
 * message sent twice in a row should only be answered once, with no quote
 * needed since there's nothing to disambiguate.
 *
 * incomingMessage() only queues a whatsapp_message_jobs row (the actual AI
 * reply happens later in a separate worker process) - these tests only
 * exercise that queueing decision, not the reply content.
 */
class WhatsappIncomingMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.bot_token' => 'test-token']);
    }

    private function headers(): array
    {
        return ['X-BOT-TOKEN' => 'test-token'];
    }

    private function bot(): WhatsappBot
    {
        $staff = Staff::create([
            'name' => 'Test Staff',
            'email' => 'staff' . uniqid() . '@example.com',
            'password' => 'secret',
        ]);

        return WhatsappBot::create([
            'staff_id' => $staff->id,
            'name' => 'Test Bot',
            'whatsapp_phone_number_id' => 'wpid-' . uniqid(),
            'is_active' => true,
        ]);
    }

    public function test_first_message_is_queued_without_quote_flag(): void
    {
        $bot = $this->bot();

        $this->postJson('/api/whatsapp/incoming-message', [
            'bot_id' => $bot->id,
            'from' => '201000000000@s.whatsapp.net',
            'message' => 'مساء الفل',
            'wa_message_id' => 'wa1',
        ], $this->headers())->assertOk()->assertJson(['queued' => true]);

        $this->assertSame(1, DB::table('whatsapp_message_jobs')->count());

        $payload = json_decode(DB::table('whatsapp_message_jobs')->first()->payload, true);

        $this->assertFalse($payload['quote_reply']);
    }

    /**
     * Same text sent twice with nothing new in between - second one should
     * not create a job (no second reply), but should still be stored in the
     * conversation history.
     */
    public function test_exact_duplicate_message_is_not_queued_again(): void
    {
        $bot = $this->bot();

        $this->postJson('/api/whatsapp/incoming-message', [
            'bot_id' => $bot->id,
            'from' => '201000000000@s.whatsapp.net',
            'message' => 'مساء الفل',
            'wa_message_id' => 'wa1',
        ], $this->headers())->assertOk();

        $response = $this->postJson('/api/whatsapp/incoming-message', [
            'bot_id' => $bot->id,
            'from' => '201000000000@s.whatsapp.net',
            'message' => 'مساء الفل',
            'wa_message_id' => 'wa2',
        ], $this->headers());

        $response->assertOk()->assertJson(['queued' => false, 'duplicate' => true]);

        $this->assertSame(1, DB::table('whatsapp_message_jobs')->count());

        $conversation = WhatsappConversation::first();
        $this->assertSame(2, $conversation->messages()->where('direction', 'incoming')->count());
    }

    /**
     * Two different messages sent before the first job is processed: both
     * get queued (existing behaviour), but the second one is flagged
     * quote_reply so the worker answers it as a WhatsApp reply, not a bare
     * message - the customer can tell which answer is for which question.
     */
    public function test_distinct_message_while_previous_job_pending_is_flagged_for_quote_reply(): void
    {
        $bot = $this->bot();

        $this->postJson('/api/whatsapp/incoming-message', [
            'bot_id' => $bot->id,
            'from' => '201000000000@s.whatsapp.net',
            'message' => 'سعر الموتوسيكل كام',
            'wa_message_id' => 'wa1',
        ], $this->headers())->assertOk();

        $this->postJson('/api/whatsapp/incoming-message', [
            'bot_id' => $bot->id,
            'from' => '201000000000@s.whatsapp.net',
            'message' => 'وفيها تقسيط سنتين',
            'wa_message_id' => 'wa2',
        ], $this->headers())->assertOk()->assertJson(['queued' => true]);

        $this->assertSame(2, DB::table('whatsapp_message_jobs')->count());

        $jobs = DB::table('whatsapp_message_jobs')->orderBy('id')->get();

        $firstPayload = json_decode($jobs[0]->payload, true);
        $secondPayload = json_decode($jobs[1]->payload, true);

        $this->assertFalse($firstPayload['quote_reply']);
        $this->assertTrue($secondPayload['quote_reply']);
    }

    /**
     * A repeated message is only collapsed when it directly follows itself.
     * If a different message came in between, resending the first text
     * again is a fresh, legitimate message and must be answered normally.
     */
    public function test_repeated_message_after_a_different_one_is_not_treated_as_duplicate(): void
    {
        $bot = $this->bot();

        $this->postJson('/api/whatsapp/incoming-message', [
            'bot_id' => $bot->id,
            'from' => '201000000000@s.whatsapp.net',
            'message' => 'مساء الفل',
            'wa_message_id' => 'wa1',
        ], $this->headers())->assertOk();

        $this->postJson('/api/whatsapp/incoming-message', [
            'bot_id' => $bot->id,
            'from' => '201000000000@s.whatsapp.net',
            'message' => 'عايز اعرف السعر',
            'wa_message_id' => 'wa2',
        ], $this->headers())->assertOk();

        $this->postJson('/api/whatsapp/incoming-message', [
            'bot_id' => $bot->id,
            'from' => '201000000000@s.whatsapp.net',
            'message' => 'مساء الفل',
            'wa_message_id' => 'wa3',
        ], $this->headers())->assertOk()->assertJson(['queued' => true]);

        $this->assertSame(3, DB::table('whatsapp_message_jobs')->count());
    }
}
