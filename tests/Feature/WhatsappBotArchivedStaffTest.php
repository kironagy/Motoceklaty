<?php

namespace Tests\Feature;

use App\Filament\Resources\WhatsappBotResource\Pages\CreateWhatsappBot;
use App\Models\Staff;
use App\Models\WhatsappBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-11-27: Manga's old bot was removed (archived, it had chats) and he could not get a new one. */
class WhatsappBotArchivedStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_employee_whose_bot_was_archived_gets_a_new_bot_but_not_a_second_live_one(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $owner = Staff::create(['name' => 'O', 'email' => uniqid().'@x.test', 'password' => 'secret', 'is_admin' => true, 'is_super_admin' => true, 'is_hitler' => true]);
        $this->actingAs($owner, 'filament');
        $manga = Staff::create(['name' => 'Manga', 'email' => uniqid().'@x.test', 'password' => 'secret', 'is_bot' => true]);
        $old = WhatsappBot::create(['name' => 'بوت منجا', 'staff_id' => $manga->id, 'whatsapp_phone_number_id' => 'old-'.uniqid(), 'is_active' => false]);
        $old->forceFill(['archived_at' => now()])->save();

        $this->assertNull($manga->whatsappBot);

        $form = Livewire::test(CreateWhatsappBot::class);
        $this->assertArrayHasKey($manga->id, $form->instance()->form->getComponent('data.staff_id')?->getOptions() ?? []);

        $new = WhatsappBot::create(['name' => 'بوت منجا 2', 'staff_id' => $manga->id, 'whatsapp_phone_number_id' => 'new-'.uniqid()]);
        $this->assertTrue($manga->fresh()->whatsappBot->is($new));

        // a live bot already: he is not offered again
        $again = Livewire::test(CreateWhatsappBot::class);
        $this->assertArrayNotHasKey($manga->id, $again->instance()->form->getComponent('data.staff_id')?->getOptions() ?? []);
    }
}
