<?php

namespace Tests\Feature\Agent;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\GetMotorcycleDetailsTool;
use App\Agent\Tools\IdentifyMotorcycleFromImageTool;
use App\Agent\Tools\SearchMotorcyclesTool;
use App\Agent\Tools\SendMotorcycleImagesTool;
use App\Agent\Tools\ToolContext;
use App\Models\AiTrace;
use App\Models\Brand;
use App\Models\Machine;
use App\Models\MessageMedia;
use App\Models\MotorcycleImage;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MotorcycleToolsTest extends TestCase
{
    use RefreshDatabase;

    private function machine(array $overrides = []): Machine
    {
        $brand = Brand::create(['name' => 'Bajaj', 'image' => 'b.jpg']);

        return Machine::create(array_merge([
            'name' => 'بوكسر 150',
            'brand_id' => $brand->id,
            'cash_price' => 50000,
            'installment_price' => 55000,
            'is_active' => true,
            'availability' => 'in_stock',
            'type' => 'normal',
        ], $overrides));
    }

    private function ctx(): ToolContext
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);

        return new ToolContext(1, $conversation->id, null, 1, $trace->id, new TurnResultBuilder());
    }

    public function test_search_motorcycles_tool(): void
    {
        $this->machine();
        $result = app(SearchMotorcyclesTool::class)->execute([], $this->ctx());

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->data['items']);
    }

    public function test_get_motorcycle_details_tool_unknown_id(): void
    {
        $result = app(GetMotorcycleDetailsTool::class)->execute(['motorcycle_ids' => [999]], $this->ctx());

        $this->assertFalse($result->ok);
        $this->assertSame('UNKNOWN_MOTORCYCLE', $result->error['code']);
    }

    public function test_send_motorcycle_images_tool_queues_without_marking_them_sent(): void
    {
        Storage::fake('public');
        $machine = $this->machine();
        MotorcycleImage::create(['machine_id' => $machine->id, 'color' => 'black', 'path' => 'x.jpg', 'is_display' => false]);

        $ctx = $this->ctx();
        $result = app(SendMotorcycleImagesTool::class)->execute(['motorcycle_id' => $machine->id, 'color' => 'black'], $ctx);

        $this->assertTrue($result->ok);
        $this->assertSame(1, $result->data['queued_count']);
        $this->assertCount(1, $ctx->outbound->toArray()['media']);

        // Queued is not sent: only DeliveryService records last_media_sent.
        $conversation = WhatsappConversation::find($ctx->conversationId);
        $this->assertEmpty($conversation->state['last_media_sent'] ?? []);
        $this->assertSame($machine->id, $ctx->outbound->toArray()['media'][0]['motorcycle_id']);

        $again = app(SendMotorcycleImagesTool::class)->execute(['motorcycle_id' => $machine->id], $ctx);
        $this->assertSame('ALREADY_QUEUED_THIS_TURN', $again->error['code']);
    }

    public function test_an_explicit_resend_bypasses_the_recently_sent_window(): void
    {
        config(['agent.images.resend_window_minutes' => 30]);
        Storage::fake('public');
        $machine = $this->machine();
        MotorcycleImage::create(['machine_id' => $machine->id, 'color' => 'black', 'path' => 'x.jpg', 'is_display' => false]);
        $ctx = $this->ctx();
        WhatsappConversation::find($ctx->conversationId)->update(['state' => ['last_media_sent' => [
            ['motorcycle_id' => $machine->id, 'color' => null, 'sent_at' => now()->subMinutes(2)->toIso8601String()],
        ]]]);

        $blocked = app(SendMotorcycleImagesTool::class)->execute(['motorcycle_id' => $machine->id], $ctx);
        $this->assertSame('ALREADY_SENT_RECENTLY', $blocked->error['code']);

        $resent = app(SendMotorcycleImagesTool::class)->execute(['motorcycle_id' => $machine->id, 'resend' => true], $this->ctx());
        $this->assertTrue($resent->ok);
    }

    public function test_send_motorcycle_images_unknown_color(): void
    {
        $machine = $this->machine();
        MotorcycleImage::create(['machine_id' => $machine->id, 'color' => 'black', 'path' => 'x.jpg']);

        $result = app(SendMotorcycleImagesTool::class)->execute(['motorcycle_id' => $machine->id, 'color' => 'red'], $this->ctx());

        $this->assertFalse($result->ok);
        $this->assertSame('UNKNOWN_COLOR', $result->error['code']);
    }

    public function test_a_confident_guess_from_shape_alone_is_only_similar(): void
    {
        // The official H250 photo came back as "VLM 200 0.95 match".
        config(['agent.recognition.match_threshold' => 0.8, 'agent.recognition.similar_threshold' => 0.5]);
        Storage::fake('local');
        $machine = $this->machine();
        $ctx = $this->ctx();
        $message = WhatsappMessage::create(['whatsapp_conversation_id' => $ctx->conversationId, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image']);
        Storage::disk('local')->put('photo.jpg', 'fake-bytes');
        $media = MessageMedia::create(['message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg', 'disk' => 'local', 'path' => 'photo.jpg', 'size' => 10]);

        $fake = new FakeAiProvider();
        $fake->queue(new AiResponse(
            textParts: [json_encode(['candidates' => [['motorcycle_id' => $machine->id, 'confidence' => 0.95]], 'identified_by' => 'shape_only'])],
            toolCalls: [], finishReason: 'STOP', usage: [], model: 'gemini-test', keyId: null, latencyMs: 1,
        ));
        $this->app->instance(AiProvider::class, $fake);

        $result = app(IdentifyMotorcycleFromImageTool::class)->execute(['media_id' => $media->id], $ctx);

        $this->assertSame('similar', $result->data['band']);
    }

    public function test_identify_motorcycle_drops_invented_ids_and_bands_by_threshold(): void
    {
        config(['agent.recognition.match_threshold' => 0.8, 'agent.recognition.similar_threshold' => 0.5]);
        Storage::fake('local');

        $machine = $this->machine();
        $ctx = $this->ctx();

        $conversation = WhatsappConversation::find($ctx->conversationId);
        $message = WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id,
            'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image',
        ]);

        Storage::disk('local')->put('photo.jpg', 'fake-bytes');
        $media = MessageMedia::create([
            'message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg',
            'disk' => 'local', 'path' => 'photo.jpg', 'size' => 10,
        ]);

        $fake = new FakeAiProvider();
        $fake->queue(new AiResponse(
            textParts: [json_encode(['candidates' => [
                ['motorcycle_id' => $machine->id, 'confidence' => 0.9],
                ['motorcycle_id' => 999999, 'confidence' => 0.95], // invented, must be dropped
            ], 'identified_by' => 'visible_name_or_logo', 'observed' => ['brand' => 'Bajaj']])],
            toolCalls: [], finishReason: 'STOP', usage: [], model: 'gemini-test', keyId: null, latencyMs: 1,
        ));
        $this->app->instance(AiProvider::class, $fake);

        $result = app(IdentifyMotorcycleFromImageTool::class)->execute(['media_id' => $media->id], $ctx);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->data['candidates']);
        $this->assertSame($machine->id, $result->data['candidates'][0]['motorcycle_id']);
        $this->assertSame('match', $result->data['band']);

        $media->refresh();
        $this->assertSame('match', $media->analysis['band']);
    }

    public function test_identify_motorcycle_media_not_found_for_other_conversation(): void
    {
        $ctx = $this->ctx();
        $other = WhatsappConversation::create(['whatsapp_bot_id' => 1, 'phone' => '999', 'status' => 'open']);
        $otherMessage = WhatsappMessage::create(['whatsapp_conversation_id' => $other->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image']);
        $media = MessageMedia::create(['message_id' => $otherMessage->id, 'media_type' => 'image', 'mime' => 'image/jpeg', 'disk' => 'local', 'path' => 'x.jpg', 'size' => 1]);

        $result = app(IdentifyMotorcycleFromImageTool::class)->execute(['media_id' => $media->id], $ctx);

        $this->assertFalse($result->ok);
        $this->assertSame('MEDIA_NOT_FOUND', $result->error['code']);
    }
    public function test_a_logo_on_a_neighbouring_bike_is_not_a_match(): void
    {
        // Live: a row of scooters came back "vigorey, logo, match" - the logo
        // was on the scooter beside the Demora the customer meant.
        config(['agent.recognition.match_threshold' => 0.8, 'agent.recognition.similar_threshold' => 0.5]);
        Storage::fake('local');
        $machine = $this->machine();
        $ctx = $this->ctx();
        $message = WhatsappMessage::create(['whatsapp_conversation_id' => $ctx->conversationId, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image']);
        Storage::disk('local')->put('photo.jpg', 'fake-bytes');
        $media = MessageMedia::create(['message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg', 'disk' => 'local', 'path' => 'photo.jpg', 'size' => 10]);

        $fake = new FakeAiProvider();
        $fake->queue(new AiResponse(
            textParts: [json_encode(['candidates' => [['motorcycle_id' => $machine->id, 'confidence' => 0.9]], 'identified_by' => 'visible_name_or_logo', 'vehicle_count' => 3])],
            toolCalls: [], finishReason: 'STOP', usage: [], model: 'gemini-test', keyId: null, latencyMs: 1,
        ));
        $this->app->instance(AiProvider::class, $fake);

        $result = app(IdentifyMotorcycleFromImageTool::class)->execute(['media_id' => $media->id], $ctx);

        $this->assertSame('similar', $result->data['band']);
        $this->assertSame(3, $result->data['vehicle_count']);
    }

    public function test_each_queued_photo_carries_its_own_color_and_a_dashboard_name_wins_over_the_hex(): void
    {
        // Live: a white Demora stored as light grey #aba5a5 - the bot said "رمادي بس"
        // while the customer was looking at the white one.
        Storage::fake('public');
        $machine = $this->machine();
        // edited from the dashboard: the observer projects colors onto motorcycle_images
        $machine->update(['colors' => [
            ['color' => '#8f8e8e', 'color_display' => 'grey.jpg', 'images' => []],
            ['color' => '#aba5a5', 'color_name' => 'أبيض', 'color_display' => 'white.jpg', 'images' => []],
        ]]);

        $ctx = $this->ctx();
        $result = app(SendMotorcycleImagesTool::class)->execute(['motorcycle_id' => $machine->id], $ctx);

        $this->assertTrue($result->ok);
        $this->assertSame(['رمادي', 'أبيض'], $result->data['sent_photo_colors']);
        $this->assertSame(['رمادي', 'أبيض'], $result->data['colors_available']);
        $this->assertSame(['رمادي', 'أبيض'], array_column($ctx->outbound->toArray()['media'], 'image_color'));

        $white = app(SendMotorcycleImagesTool::class)->execute(['motorcycle_id' => $machine->id, 'color' => 'الابيض'], $this->ctx());
        $this->assertSame(1, $white->data['queued_count']);
    }
}
