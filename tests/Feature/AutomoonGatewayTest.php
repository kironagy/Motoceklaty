<?php

namespace Tests\Feature;

use App\Domain\Conversations\BotSessions;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Services\AutomoonGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Owner 2026-11-14: WhatsApp goes through Automoon (WHATSAPP_GATEWAY=automoon) instead of our Node
 * worker, which stays in the code as the fallback.
 */
class AutomoonGatewayTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappBot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.gateway' => 'automoon',
            'services.automoon.base_url' => 'https://automoon.test/api',
            'services.automoon.api_key' => 'key-1',
            'services.automoon.webhook_secret' => 'sec-1',
        ]);

        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret', 'is_bot' => true]);
        $this->bot = WhatsappBot::create(['name' => 'wahwah', 'staff_id' => $staff->id, 'whatsapp_phone_number_id' => 'b1', 'automoon_device_id' => 'dev_1', 'is_active' => true, 'session_status' => 'connected']);
    }

    public function test_a_text_goes_to_automoon_with_the_key_device_and_international_number(): void
    {
        Http::fake(['automoon.test/*' => Http::response(['success' => true, 'data' => ['messageId' => 'AM123']])]);

        $response = app(AutomoonGateway::class)->send('/send-message', [
            'bot_id' => (string) $this->bot->id, 'jid' => '201012345678@s.whatsapp.net', 'message' => 'أهلاً',
        ]);

        $this->assertTrue($response->json('ok'));
        $this->assertSame('AM123', $response->json('wa_message_id'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://automoon.test/api/whatsapp/send'
            && $r->header('X-API-KEY') === ['key-1']
            && $r['to'] === '201012345678' && $r['body'] === 'أهلاً' && $r['deviceId'] === 'dev_1');
    }

    public function test_an_lid_chat_is_sent_to_the_customers_real_phone(): void
    {
        Http::fake(['automoon.test/*' => Http::response(['success' => true])]);
        $customer = Customer::create(['whatsapp_bot_id' => $this->bot->id, 'jid' => '99887766@lid', 'phone' => '201099990000']);
        WhatsappConversation::create(['whatsapp_bot_id' => $this->bot->id, 'customer_id' => $customer->id, 'phone' => '99887766']);

        $response = app(AutomoonGateway::class)->send('/send-message', ['bot_id' => $this->bot->id, 'jid' => '99887766@lid', 'message' => 'x']);

        $this->assertTrue($response->json('ok'));
        $this->assertStringStartsWith('am-', $response->json('wa_message_id'));
        Http::assertSent(fn (Request $r) => $r['to'] === '201099990000');
    }

    public function test_media_goes_by_its_public_url(): void
    {
        Http::fake(['automoon.test/*' => Http::response(['success' => true, 'data' => ['id' => 'M1']])]);

        $response = app(AutomoonGateway::class)->send('/send-media-items', [
            'bot_id' => $this->bot->id, 'jid' => '201012345678@s.whatsapp.net',
            'media_items' => [['type' => 'image', 'url' => 'https://site.test/storage/a.jpg', 'caption' => 'هوجان']],
        ]);

        $this->assertTrue($response->json('ok'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/whatsapp/send-media')
            && $r['mediaUrl'] === 'https://site.test/storage/a.jpg' && $r['caption'] === 'هوجان');
    }

    public function test_a_refused_send_is_a_failure_the_delivery_retries(): void
    {
        Http::fake(['automoon.test/*' => Http::response(['success' => false, 'error' => 'device offline'], 400)]);

        $response = app(AutomoonGateway::class)->send('/send-message', ['bot_id' => $this->bot->id, 'jid' => '201012345678@s.whatsapp.net', 'message' => 'x']);

        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('device offline', $response->json('error'));
    }

    public function test_a_bot_without_a_device_is_not_sent_from(): void
    {
        Http::fake();
        $this->bot->update(['automoon_device_id' => null]);

        $response = app(AutomoonGateway::class)->send('/send-message', ['bot_id' => $this->bot->id, 'jid' => '201012345678@s.whatsapp.net', 'message' => 'x']);

        $this->assertFalse($response->json('ok'));
        Http::assertNothingSent();
    }

    public function test_connected_numbers_come_from_automoon_devices(): void
    {
        Http::fake(['automoon.test/*' => Http::response(['success' => true, 'data' => [
            ['id' => 'dev_1', 'status' => 'connected'],
            ['id' => 'dev_2', 'status' => 'disconnected'],
        ]])]);

        $this->assertSame([$this->bot->id], app(BotSessions::class)->connected());
    }

    public function test_a_customer_message_from_the_webhook_is_stored_on_the_devices_bot(): void
    {
        $payload = ['event' => 'message.received', 'data' => ['from' => '201012345678', 'text' => 'عايز هوجان', 'timestamp' => 1711456789, 'deviceId' => 'dev_1']];

        $this->postJson('/api/automoon/webhook', $payload, ['NBG-WEBHOOK-SECRET' => 'sec-1'])->assertOk()->assertJson(['ok' => true]);
        // Automoon retrying the same webhook is not a second message
        $this->postJson('/api/automoon/webhook', $payload, ['NBG-WEBHOOK-SECRET' => 'sec-1'])->assertOk()->assertJson(['duplicate' => true]);

        $message = WhatsappMessage::where('direction', 'incoming')->sole();
        $this->assertSame('عايز هوجان', $message->text);
        $this->assertSame($this->bot->id, (int) $message->whatsapp_bot_id);
    }

    public function test_the_webhook_refuses_a_wrong_secret(): void
    {
        $this->postJson('/api/automoon/webhook', ['event' => 'message.received', 'data' => ['from' => '201012345678', 'text' => 'x', 'deviceId' => 'dev_1']], ['NBG-WEBHOOK-SECRET' => 'nope'])
            ->assertUnauthorized();

        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_the_old_worker_path_is_untouched_when_the_gateway_is_worker(): void
    {
        config(['services.whatsapp.gateway' => 'worker', 'services.whatsapp.worker_url' => 'http://worker.test']);
        Http::fake(['worker.test/*' => Http::response(['sessions' => [['bot_id' => $this->bot->id, 'connected' => true]]])]);

        $this->assertSame([$this->bot->id], app(BotSessions::class)->connected());
        Http::assertSent(fn (Request $r) => $r->url() === 'http://worker.test/status');
    }
}
