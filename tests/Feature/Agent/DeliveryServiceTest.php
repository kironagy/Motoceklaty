<?php

namespace Tests\Feature\Agent;

use App\Domain\Conversations\DeliveryService;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T05 §5: every delivered item is persisted as its own outbound message
 * with the WhatsApp id Node returns, and a retry after a partial failure
 * only resends the items that never reached 'sent'.
 */
class DeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agent.enabled' => true,
            'services.whatsapp.bot_token' => 'test-token',
            'services.whatsapp.worker_url' => 'http://worker.test',
        ]);
    }

    private function turn(): object
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '201000000000', 'status' => 'open']);

        $turnId = \Illuminate\Support\Facades\DB::table('whatsapp_message_jobs')->insertGetId([
            'whatsapp_bot_id' => $bot->id,
            'whatsapp_conversation_id' => $conversation->id,
            'from' => '201000000000@s.whatsapp.net',
            'reply_jid' => '201000000000@s.whatsapp.net',
            'status' => 'generated',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \Illuminate\Support\Facades\DB::table('whatsapp_message_jobs')->find($turnId);
    }

    public function test_refuses_to_deliver_while_agent_disabled(): void
    {
        config(['agent.enabled' => false]);

        $this->expectException(\RuntimeException::class);

        app(DeliveryService::class)->deliver($this->turn(), ['messages' => ['hi']]);
    }

    public function test_each_item_gets_its_own_outbound_row_with_wa_message_id(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['ok' => true, 'wa_message_id' => "bot1_msg{$calls}"]);
        });

        $turn = $this->turn();
        app(DeliveryService::class)->deliver($turn, ['messages' => ['أول رسالة', 'تاني رسالة']]);

        $this->assertSame(2, WhatsappMessage::where('turn_id', $turn->id)->count());
        $this->assertSame(2, WhatsappMessage::where('turn_id', $turn->id)->where('delivery_status', 'sent')->count());
        $this->assertEqualsCanonicalizing(
            ['bot1_msg1', 'bot1_msg2'],
            WhatsappMessage::where('turn_id', $turn->id)->pluck('wa_message_id')->all()
        );
    }

    public function test_retry_after_partial_failure_only_resends_the_unsent_item(): void
    {
        Http::fake([
            'worker.test/*' => Http::sequence()
                ->push(['ok' => true, 'wa_message_id' => 'bot1_first'])
                ->push(['ok' => false, 'error' => 'boom'], 500)
                ->push(['ok' => true, 'wa_message_id' => 'bot1_second']),
        ]);

        $turn = $this->turn();

        try {
            app(DeliveryService::class)->deliver($turn, ['messages' => ['أول رسالة', 'تاني رسالة']]);
            $this->fail('Expected delivery to throw on the second item');
        } catch (\RuntimeException) {
            // expected: item 2 failed
        }

        $this->assertSame(1, WhatsappMessage::where('turn_id', $turn->id)->where('delivery_status', 'sent')->count());
        $this->assertSame(1, WhatsappMessage::where('turn_id', $turn->id)->where('delivery_status', 'failed')->count());

        // Retry: item 1 must not be re-sent (it's already 'sent').
        app(DeliveryService::class)->deliver($turn, ['messages' => ['أول رسالة', 'تاني رسالة']]);

        Http::assertSentCount(3); // 1 success + 1 failure from before, + 1 retry
        $this->assertSame(2, WhatsappMessage::where('turn_id', $turn->id)->where('delivery_status', 'sent')->count());
    }

    public function test_a_superseded_turn_delivers_nothing_more(): void
    {
        Http::fake(fn () => Http::response(['ok' => true, 'wa_message_id' => 'x']));
        $turn = $this->turn();
        \Illuminate\Support\Facades\DB::table('whatsapp_message_jobs')->where('id', $turn->id)->update(['status' => 'superseded', 'superseded_by' => 999]);

        app(DeliveryService::class)->deliver($turn, ['messages' => ['رد قديم']]);

        Http::assertNothingSent();
        $this->assertSame(0, WhatsappMessage::where('turn_id', $turn->id)->count());
    }

    public function test_a_retry_reuses_the_failed_row_instead_of_adding_copies(): void
    {
        $turn = $this->turn();
        Http::fakeSequence()->push(['ok' => false, 'error' => 'session not found'], 404)->push(['ok' => true, 'wa_message_id' => 'w1']);

        try {
            app(DeliveryService::class)->deliver($turn, ['messages' => ['أهلاً']]);
        } catch (\RuntimeException) {
        }
        app(DeliveryService::class)->deliver($turn, ['messages' => ['أهلاً']]);

        $this->assertSame(1, WhatsappMessage::where('turn_id', $turn->id)->count());
        $this->assertSame('sent', WhatsappMessage::where('turn_id', $turn->id)->value('delivery_status'));
    }

    public function test_photos_arrive_before_the_text_that_talks_about_them(): void
    {
        $endpoints = [];
        Http::fake(function ($request) use (&$endpoints) {
            $endpoints[] = parse_url($request->url(), PHP_URL_PATH);

            return Http::response(['ok' => true, 'wa_message_id' => 'w'.count($endpoints)]);
        });

        app(DeliveryService::class)->deliver($this->turn(), [
            'messages' => ['دي صور اللون الأحمر 👌', 'إيه رأيك؟'],
            'media' => [['type' => 'image', 'url' => 'http://x/red.jpg']],
        ]);

        $this->assertSame(['/send-media-items', '/send-message', '/send-message'], $endpoints);
    }

    public function test_media_is_remembered_as_sent_only_after_delivery(): void
    {
        Http::fake(fn () => Http::response(['ok' => true, 'wa_message_ids' => ['m1']]));
        $turn = $this->turn();

        app(DeliveryService::class)->deliver($turn, ['messages' => [], 'media' => [['type' => 'image', 'url' => 'http://x/a.jpg', 'motorcycle_id' => 7, 'color' => null]]]);

        $state = WhatsappConversation::find($turn->whatsapp_conversation_id)->state;
        $this->assertSame(7, $state['last_media_sent'][0]['motorcycle_id']);
    }
}
