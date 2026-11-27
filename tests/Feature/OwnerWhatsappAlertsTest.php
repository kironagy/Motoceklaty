<?php

namespace Tests\Feature;

use App\Domain\Conversations\DeliveryService;
use App\Jobs\SendOwnerWhatsapp;
use App\Models\Staff;
use App\Models\WhatsappBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Owner 2026-11-27: «عايز الإشعارات تظهرلي وأنا قافل تليفوني» - «عصفورة»'s reports on his WhatsApp too. */
class OwnerWhatsappAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_he_sets_his_number_in_the_chat_and_reports_go_there_on_a_chat_the_bot_never_answers(): void
    {
        Queue::fake([SendOwnerWhatsapp::class]);
        $owner = Staff::create(['name' => 'O', 'email' => uniqid().'@x.test', 'password' => 'secret', 'is_hitler' => true]);
        $this->actingAs($owner, 'filament');

        $this->assertArrayHasKey('error', \App\Domain\Asfoora\AsfooraTools::call('whatsapp_alerts', ['phone' => '123']));
        $r = \App\Domain\Asfoora\AsfooraTools::call('whatsapp_alerts', ['phone' => '+20 101 234 5678']);
        $this->assertSame('01012345678', $r['whatsapp']);
        $this->assertSame('01012345678', $owner->fresh()->notify_whatsapp);
        Queue::assertPushed(SendOwnerWhatsapp::class, 1);

        // the job: a connected (here: any live) number, the chat marked owner_alerts
        Http::fake(['*' => Http::response([], 500)]);
        $bot = WhatsappBot::create(['name' => 'b', 'staff_id' => Staff::create(['name' => 'B', 'email' => uniqid().'@x.test', 'password' => 'secret', 'is_bot' => true])->id, 'whatsapp_phone_number_id' => 'b-'.uniqid(), 'is_active' => true]);
        $sent = null;
        $this->mock(DeliveryService::class, function ($m) use (&$sent) {
            $m->shouldReceive('deliverForConversation')->once()->withArgs(function ($c, $result) use (&$sent) { $sent = [$c, $result]; return true; });
        });
        app()->call([new SendOwnerWhatsapp($owner->id, '🐦 تقرير'), 'handle']);
        $this->assertSame('201012345678', $sent[0]->phone);
        $this->assertSame(SendOwnerWhatsapp::STATUS, $sent[0]->fresh()->status);
        $this->assertSame(['🐦 تقرير'], $sent[1]['messages']);

        $this->assertSame("عنوان\n*كلمة* مهمة", SendOwnerWhatsapp::plain("## عنوان\n**كلمة** مهمة"));

        // empty = stop
        \App\Domain\Asfoora\AsfooraTools::call('whatsapp_alerts', ['phone' => '']);
        $this->assertNull($owner->fresh()->notify_whatsapp);
    }

    public function test_the_app_manifest_is_linked(): void
    {
        $this->assertFileExists(public_path('manifest.webmanifest'));
        $this->assertFileExists(public_path('images/app/icon-512.png'));
        $me = Staff::create(['name' => 'S', 'email' => uniqid().'@x.test', 'password' => 'secret', 'is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($me, 'filament')->get('/admin/notifications')->assertSee('/manifest.webmanifest', false);
    }
}
