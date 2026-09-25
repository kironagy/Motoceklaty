<?php

namespace Tests\Feature\Agent;

use App\Console\Commands\ProcessWhatsappMessageJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T05 §3: process_after gating and the existing busy-conversation skip
 * rule, exercised directly on claimNextJob() (private - the command's
 * handle() loops forever, so it's not itself testable end to end).
 */
class ProcessWhatsappMessageJobsClaimTest extends TestCase
{
    use RefreshDatabase;

    private function claim(): ?object
    {
        $command = app(ProcessWhatsappMessageJobs::class);
        $method = new \ReflectionMethod($command, 'claimNextJob');
        $method->setAccessible(true);

        return $method->invoke($command);
    }

    private function insertTurn(array $overrides = []): int
    {
        return DB::table('whatsapp_message_jobs')->insertGetId(array_merge([
            'whatsapp_conversation_id' => 1,
            'from' => '201000000000@s.whatsapp.net',
            'status' => 'pending',
            'attempts' => 0,
            'process_after' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_pending_turn_with_future_process_after_is_not_claimed(): void
    {
        $this->insertTurn(['process_after' => now()->addSeconds(10)]);

        $this->assertNull($this->claim());
    }

    public function test_pending_turn_past_process_after_is_claimed(): void
    {
        $id = $this->insertTurn(['process_after' => now()->subSecond()]);

        $claimed = $this->claim();

        $this->assertNotNull($claimed);
        $this->assertSame($id, $claimed->id);
        $this->assertSame('processing', DB::table('whatsapp_message_jobs')->find($id)->status);
    }

    public function test_a_generated_turn_waits_for_its_delivery_backoff(): void
    {
        // WhatsApp down: three delivery attempts used to burn inside ten
        // seconds. The retry now waits for process_after like any turn.
        $this->insertTurn(['status' => 'generated', 'process_after' => now()->addMinutes(5)]);
        $this->assertNull($this->claim());

        $id = $this->insertTurn(['status' => 'generated', 'process_after' => now()->subSecond()]);
        $this->assertSame($id, $this->claim()->id);
    }

    public function test_a_turn_abandoned_mid_processing_is_reclaimed_then_failed_past_its_budget(): void
    {
        config(['agent.delivery.max_attempts' => 3]);
        $retry = $this->insertTurn(['status' => 'processing', 'locked_at' => now()->subMinutes(30), 'attempts' => 1]);
        $this->assertSame($retry, $this->claim()->id);

        $dead = $this->insertTurn(['status' => 'processing', 'locked_at' => now()->subMinutes(30), 'attempts' => 3]);
        $this->claim();
        $this->assertSame('failed', DB::table('whatsapp_message_jobs')->find($dead)->status);
    }

    public function test_a_conversation_already_processing_is_skipped(): void
    {
        $this->insertTurn(['whatsapp_conversation_id' => 1, 'status' => 'processing', 'locked_at' => now()]);
        $other = $this->insertTurn([
            'whatsapp_conversation_id' => 2,
            'from' => '201000000001@s.whatsapp.net',
            'process_after' => now()->subSecond(),
        ]);

        $claimed = $this->claim();

        $this->assertNotNull($claimed);
        $this->assertSame($other, $claimed->id);
    }

    public function test_superseded_and_skipped_turns_are_never_claimed(): void
    {
        $this->insertTurn(['status' => 'superseded', 'process_after' => now()->subSecond()]);
        $this->insertTurn(['status' => 'skipped', 'process_after' => now()->subSecond()]);

        $this->assertNull($this->claim());
    }
}
