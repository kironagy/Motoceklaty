<?php

namespace Tests\Feature;

use App\Exceptions\BotHasCustomerDataException;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\WhatsappBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting bot 85 cascaded towards every customer and application; it only
 * stopped because one installment request referenced an application.
 */
class WhatsappBotDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function bot(): WhatsappBot
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);

        return WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
    }

    public function test_a_bot_with_customers_cannot_be_deleted(): void
    {
        $bot = $this->bot();
        Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);

        $this->expectException(BotHasCustomerDataException::class);

        try {
            $bot->delete();
        } finally {
            $this->assertDatabaseHas('whatsapp_bots', ['id' => $bot->id]);
            $this->assertSame(1, Customer::count());
        }
    }

    public function test_an_empty_bot_can_still_be_deleted(): void
    {
        $bot = $this->bot();

        $bot->delete();

        $this->assertDatabaseMissing('whatsapp_bots', ['id' => $bot->id]);
    }
}
