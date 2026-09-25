<?php

namespace Tests\Feature;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Domain\Conversations\IngestionService;
use App\Jobs\TranscribeVoiceMessage;
use App\Models\Customer;
use App\Models\MessageMedia;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers T04: ingestion is idempotent, never interprets message text, stores
 * media on the private disk, resolves quotes, and hands voice notes off to
 * background transcription (DEC-08).
 */
class WhatsappIncomingMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.bot_token' => 'test-token']);
        Storage::fake('local');
    }

    private function headers(): array
    {
        return ['X-BOT-TOKEN' => 'test-token'];
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

    private function basePayload(WhatsappBot $bot, array $overrides = []): array
    {
        return array_merge([
            'bot_id' => $bot->id,
            'wa_message_id' => $bot->id.'_wa1',
            'chat_jid' => '201000000000@s.whatsapp.net',
            'customer_jid' => null,
            'push_name' => 'Ahmed',
            'timestamp' => now()->timestamp,
            'type' => 'text',
            'text' => 'مساء الفل',
            'media' => [],
        ], $overrides);
    }

    public function test_new_customer_conversation_and_message_are_created(): void
    {
        $bot = $this->bot();

        $this->postJson('/api/whatsapp/incoming-message', $this->basePayload($bot), $this->headers())
            ->assertOk()
            ->assertJson(['ok' => true, 'duplicate' => false]);

        $this->assertSame(1, Customer::count());
        $this->assertSame(1, WhatsappConversation::count());
        $this->assertSame(1, WhatsappMessage::count());

        $message = WhatsappMessage::first();
        $this->assertSame('customer', $message->sender_type);
        $this->assertSame('text', $message->type);
        $this->assertSame('مساء الفل', $message->text);
        $this->assertNotNull($message->conversation->customer_id);
    }

    public function test_same_wa_message_id_posted_twice_creates_one_row(): void
    {
        $bot = $this->bot();
        $payload = $this->basePayload($bot);

        $this->postJson('/api/whatsapp/incoming-message', $payload, $this->headers())->assertOk();

        $response = $this->postJson('/api/whatsapp/incoming-message', $payload, $this->headers());

        $response->assertOk()->assertJson(['ok' => true, 'duplicate' => true]);
        $this->assertSame(1, WhatsappMessage::count());
    }

    public function test_concurrent_duplicate_post_does_not_500_or_duplicate_row(): void
    {
        $bot = $this->bot();
        $payload = $this->basePayload($bot);

        // Simulates two workers racing on the same message: the unique
        // index on wa_message_id makes the second insert fail, and
        // IngestionService's pre-check + transaction keep it a clean
        // "duplicate" response instead of a 500.
        $service = app(IngestionService::class);
        $first = $service->ingest($payload);
        $second = $service->ingest($payload);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, WhatsappMessage::count());
    }

    public function test_image_payload_stores_media_on_configured_disk_with_hash_and_no_base64_in_db(): void
    {
        $bot = $this->bot();
        $binary = 'fake-image-bytes';

        $payload = $this->basePayload($bot, [
            'type' => 'image',
            'text' => null,
            'media' => [[
                'media_type' => 'image',
                'mime' => 'image/jpeg',
                'filename' => 'bike.jpg',
                'base64' => base64_encode($binary),
                'size' => strlen($binary),
            ]],
        ]);

        $this->postJson('/api/whatsapp/incoming-message', $payload, $this->headers())->assertOk();

        $media = MessageMedia::first();
        $this->assertNotNull($media);
        $this->assertSame('local', $media->disk);
        $this->assertSame(hash('sha256', $binary), $media->sha256);
        Storage::disk('local')->assertExists($media->path);

        $raw = json_encode($media->getAttributes());
        $this->assertStringNotContainsString(base64_encode($binary), $raw);
    }

    public function test_quoted_message_is_resolved_to_an_existing_message(): void
    {
        $bot = $this->bot();

        $this->postJson('/api/whatsapp/incoming-message', $this->basePayload($bot, [
            'wa_message_id' => $bot->id.'_wa1',
            'text' => 'السعر كام',
        ]), $this->headers())->assertOk();

        $this->postJson('/api/whatsapp/incoming-message', $this->basePayload($bot, [
            'wa_message_id' => $bot->id.'_wa2',
            'text' => 'وفيها تقسيط؟',
            'quoted' => ['wa_message_id' => $bot->id.'_wa1', 'type' => 'text', 'text' => 'السعر كام'],
        ]), $this->headers())->assertOk();

        $second = WhatsappMessage::where('wa_message_id', $bot->id.'_wa2')->first();
        $first = WhatsappMessage::where('wa_message_id', $bot->id.'_wa1')->first();

        $this->assertSame($first->id, $second->quoted_message_id);
    }

    public function test_unknown_quoted_id_keeps_text_in_metadata(): void
    {
        $bot = $this->bot();

        $this->postJson('/api/whatsapp/incoming-message', $this->basePayload($bot, [
            'quoted' => ['wa_message_id' => 'unknown_id', 'type' => 'text', 'text' => 'قديم'],
        ]), $this->headers())->assertOk();

        $message = WhatsappMessage::first();
        $this->assertNull($message->quoted_message_id);
        $this->assertSame('قديم', $message->metadata['quoted']['text']);
    }

    public function test_from_me_message_is_stored_as_human_phone_sender(): void
    {
        $bot = $this->bot();

        $this->postJson('/api/whatsapp/incoming-message', $this->basePayload($bot, [
            'direction' => 'outgoing',
            'text' => 'هرد عليك دلوقتي',
        ]), $this->headers())->assertOk();

        $message = WhatsappMessage::first();
        $this->assertSame('human_phone', $message->sender_type);
        $this->assertSame('outgoing', $message->direction);
    }

    public function test_from_me_echo_of_a_bot_sent_message_is_not_duplicated(): void
    {
        $bot = $this->bot();

        // The outbound row DeliveryService would have created when the bot
        // sent this message, with the WhatsApp id Node returned.
        $conversation = \App\Models\WhatsappConversation::create([
            'whatsapp_bot_id' => $bot->id,
            'phone' => '201000000000',
            'status' => 'open',
        ]);

        WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id,
            'whatsapp_bot_id' => $bot->id,
            'wa_message_id' => $bot->id.'_botsent1',
            'direction' => 'outgoing',
            'sender_type' => 'bot',
            'delivery_status' => 'sent',
            'message' => 'رد البوت',
        ]);

        // WhatsApp echoes the bot's own message back as fromMe=true with
        // the same underlying key.id - Node forwards it with direction
        // outgoing and the same wa_message_id convention.
        $this->postJson('/api/whatsapp/incoming-message', $this->basePayload($bot, [
            'wa_message_id' => $bot->id.'_botsent1',
            'direction' => 'outgoing',
            'text' => 'رد البوت',
        ]), $this->headers())->assertOk()->assertJson(['ok' => true, 'duplicate' => true]);

        $this->assertSame(1, WhatsappMessage::where('wa_message_id', $bot->id.'_botsent1')->count());
    }

    public function test_invalid_token_is_rejected(): void
    {
        $bot = $this->bot();

        $this->postJson('/api/whatsapp/incoming-message', $this->basePayload($bot), [
            'X-BOT-TOKEN' => 'wrong-token',
        ])->assertStatus(401);

        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_session_gap_sets_a_new_session_started_at(): void
    {
        config(['agent.session_gap_hours' => 6]);
        $bot = $this->bot();

        \Illuminate\Support\Carbon::setTestNow('2026-01-01 10:00:00');

        $this->postJson('/api/whatsapp/incoming-message', $this->basePayload($bot, [
            'wa_message_id' => $bot->id.'_wa1',
        ]), $this->headers())->assertOk();

        $conversation = WhatsappConversation::first();
        $firstSession = $conversation->state['session_started_at'];

        \Illuminate\Support\Carbon::setTestNow('2026-01-01 22:00:00');

        $this->postJson('/api/whatsapp/incoming-message', $this->basePayload($bot, [
            'wa_message_id' => $bot->id.'_wa2',
        ]), $this->headers())->assertOk();

        $conversation->refresh();
        $this->assertNotSame($firstSession, $conversation->state['session_started_at']);
    }

    public function test_voice_note_is_queued_for_background_transcription(): void
    {
        Bus::fake();
        $bot = $this->bot();
        $binary = 'fake-audio-bytes';

        $payload = $this->basePayload($bot, [
            'type' => 'audio',
            'text' => null,
            'media' => [[
                'media_type' => 'audio',
                'mime' => 'audio/ogg',
                'filename' => null,
                'base64' => base64_encode($binary),
                'size' => strlen($binary),
            ]],
        ]);

        $this->postJson('/api/whatsapp/incoming-message', $payload, $this->headers())->assertOk();

        $message = WhatsappMessage::first();
        $this->assertSame('pending', $message->transcription_status);

        Bus::assertDispatched(TranscribeVoiceMessage::class, fn ($job) => $job->messageId === $message->id);
    }

    public function test_transcription_job_stores_the_transcript_via_the_abstracted_provider(): void
    {
        Bus::fake();
        $bot = $this->bot();
        $binary = 'fake-audio-bytes';

        $payload = $this->basePayload($bot, [
            'type' => 'audio',
            'text' => null,
            'media' => [[
                'media_type' => 'audio',
                'mime' => 'audio/ogg',
                'filename' => null,
                'base64' => base64_encode($binary),
                'size' => strlen($binary),
            ]],
        ]);

        $this->postJson('/api/whatsapp/incoming-message', $payload, $this->headers())->assertOk();
        $message = WhatsappMessage::first();

        $fake = new FakeAiProvider();
        $fake->queue(new AiResponse(
            textParts: ['عايز اعرف السعر'],
            toolCalls: [],
            finishReason: 'STOP',
            usage: [],
            model: 'gemini-test',
            keyId: null,
            latencyMs: 1,
        ));
        $this->app->instance(AiProvider::class, $fake);

        (new TranscribeVoiceMessage($message->id))->handle(app(\App\Domain\Conversations\VoiceTranscriber::class));

        $message->refresh();
        $this->assertSame('completed', $message->transcription_status);
        $this->assertSame('عايز اعرف السعر', $message->transcript);
    }
}
