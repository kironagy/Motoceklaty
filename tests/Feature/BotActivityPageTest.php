<?php

namespace Tests\Feature;

use App\Domain\Bots\BotActivity;
use App\Filament\Resources\WhatsappBotResource\Pages\{BotActivityPage, ListWhatsappBots};
use App\Models\{Customer, Staff, WhatsappBot, WhatsappConversation, WhatsappMessage};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Bots page cards and the per-bot details page: today's chats, messages per chat, AI usage, chat viewer. */
class BotActivityPageTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappBot $bot;

    private WhatsappConversation $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = Staff::create(['name' => 'Owner', 'email' => uniqid().'@x.test', 'password' => 'secret']);
        $owner->forceFill(['is_hitler' => true])->save();
        $this->actingAs($owner, 'filament');

        $staff = Staff::create(['name' => 'Bot staff', 'email' => uniqid().'@x.test', 'password' => 'secret']);
        $this->bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'بوت التجربة', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true, 'session_status' => 'connected']);
        $customer = Customer::create(['whatsapp_bot_id' => $this->bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011', 'push_name' => 'كريم']);
        $this->chat = WhatsappConversation::create(['whatsapp_bot_id' => $this->bot->id, 'customer_id' => $customer->id, 'phone' => '201000000001', 'status' => 'open']);
        $other = WhatsappConversation::create(['whatsapp_bot_id' => $this->bot->id, 'phone' => '201000000002', 'status' => 'open']);

        $this->message($this->chat, 'incoming', 'customer', 'عايز اقسط الهوجن');
        $this->message($this->chat, 'outgoing', 'bot', 'القسط على سنة 4,060 جنيه');
        $this->message($this->chat, 'outgoing', 'human_phone', 'هبعتلك الرابط');
        $this->message($other, 'incoming', 'customer', 'السلام عليكم');
        // Yesterday's message: not part of today's numbers.
        $this->message($other, 'incoming', 'customer', 'رسالة امبارح', now()->subDay());

        DB::table('ai_calls')->insert([
            ['conversation_id' => $this->chat->id, 'label' => 'main', 'provider' => 'x', 'model' => 'm', 'input_tokens' => 1000, 'output_tokens' => 50, 'latency_ms' => 4000, 'outcome' => 'ok', 'created_at' => now()],
            ['conversation_id' => $this->chat->id, 'label' => 'main', 'provider' => 'x', 'model' => 'm', 'input_tokens' => 500, 'output_tokens' => 50, 'latency_ms' => 2000, 'outcome' => 'ok', 'created_at' => now()],
        ]);
    }

    private function message(WhatsappConversation $chat, string $direction, string $sender, string $text, $at = null): WhatsappMessage
    {
        $message = WhatsappMessage::create([
            'whatsapp_conversation_id' => $chat->id, 'whatsapp_bot_id' => $this->bot->id, 'wa_message_id' => uniqid(),
            'direction' => $direction, 'sender_type' => $sender, 'type' => 'text', 'text' => $text, 'message' => $text,
        ]);

        if ($at) {
            $message->forceFill(['created_at' => $at])->save();
        }

        return $message;
    }

    public function test_today_numbers_per_bot_and_per_chat(): void
    {
        $activity = app(BotActivity::class);

        $this->assertSame(['conversations' => 2, 'messages' => 4], $activity->todayTotals()[$this->bot->id]);

        $summary = $activity->summary($this->bot, today());
        $this->assertSame(2, $summary['conversations']);
        $this->assertSame(1, $summary['answered']);
        $this->assertSame(2, $summary['incoming']);
        $this->assertSame(1, $summary['bot']);
        $this->assertSame(1, $summary['staff']);
        $this->assertSame(2, $summary['ai_calls']);
        $this->assertSame(1600, $summary['tokens']);

        $first = $activity->conversations($this->bot, today())->first();
        $this->assertSame($this->chat->id, $first['id']);
        $this->assertSame(3, $first['messages']);
        $this->assertSame('كريم', $first['name']);
        $this->assertSame(2, $first['ai_calls']);
    }

    public function test_details_page_lists_chats_and_opens_one(): void
    {
        Livewire::test(BotActivityPage::class, ['record' => $this->bot->id])
            ->assertSuccessful()
            ->assertSee('محادثات رد عليها')
            ->assertSee('كريم')
            ->call('openChat', $this->chat->id)
            ->assertSee('القسط على سنة 4,060 جنيه')
            ->assertSee('هبعتلك الرابط')
            ->set('search', 'لا يوجد')
            ->assertSee('مفيش محادثة بالبحث ده')
            ->call('shiftDay', -1)
            ->assertSet('chat', null)
            ->assertSee('امبارح');
    }

    public function test_bots_list_shows_cards_with_today_numbers(): void
    {
        Livewire::test(ListWhatsappBots::class)
            ->assertSuccessful()
            ->assertSee('بوت التجربة')
            ->assertSee('محادثة النهارده')
            ->assertSee('التفاصيل والمحادثات');
    }

    public function test_details_page_is_closed_to_staff_without_access(): void
    {
        $this->actingAs(Staff::create(['name' => 'Nobody', 'email' => uniqid().'@x.test', 'password' => 'secret']), 'filament');

        Livewire::test(BotActivityPage::class, ['record' => $this->bot->id])->assertForbidden();
    }
}
