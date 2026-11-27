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

        $this->assertArrayHasKey('error', \App\Domain\Asfoora\AsfooraTools::call('whatsapp_alerts', ['phones' => ['123']]));
        $r = \App\Domain\Asfoora\AsfooraTools::call('whatsapp_alerts', ['phones' => ['+20 101 234 5678']]);
        $this->assertSame(['01012345678'], $r['whatsapp']);
        $this->assertSame('01012345678', $owner->fresh()->notify_whatsapp);
        Queue::assertPushed(SendOwnerWhatsapp::class, 1);

        // «على رقمين»: the second number gets its first message, the first is not greeted again
        $r = \App\Domain\Asfoora\AsfooraTools::call('whatsapp_alerts', ['phones' => ['01012345678', '01198765432']]);
        $this->assertSame(['01012345678', '01198765432'], $r['whatsapp']);
        Queue::assertPushed(SendOwnerWhatsapp::class, 2);
        Queue::assertPushed(SendOwnerWhatsapp::class, fn ($j) => $j->phone === '201198765432');
        SendOwnerWhatsapp::to($owner->fresh(), 'تقرير', 'r1');
        Queue::assertPushed(SendOwnerWhatsapp::class, 4); // one per number

        // the job: a connected (here: any live) number, the chat marked owner_alerts
        Http::fake(['*' => Http::response([], 500)]);
        $bot = WhatsappBot::create(['name' => 'b', 'staff_id' => Staff::create(['name' => 'B', 'email' => uniqid().'@x.test', 'password' => 'secret', 'is_bot' => true])->id, 'whatsapp_phone_number_id' => 'b-'.uniqid(), 'is_active' => true]);
        $sent = null;
        $this->mock(DeliveryService::class, function ($m) use (&$sent) {
            $m->shouldReceive('deliverForConversation')->once()->withArgs(function ($c, $result) use (&$sent) { $sent = [$c, $result]; return true; });
        });
        app()->call([new SendOwnerWhatsapp($owner->id, '🐦 تقرير', null, '201012345678'), 'handle']);
        $this->assertSame('201012345678', $sent[0]->phone);
        $this->assertSame(SendOwnerWhatsapp::STATUS, $sent[0]->fresh()->status);
        $this->assertSame(['🐦 تقرير'], $sent[1]['messages']);

        $this->assertSame('من 4:30 م لحد 12:05 ص، 9:07 ص، و 3:00 م و 22/11/2026', SendOwnerWhatsapp::twelveHour('من 16:30 لحد 00:05، 09:07:12، و 3:00 م و 22/11/2026'));
        $this->assertSame("عنوان\n*كلمة* مهمة", SendOwnerWhatsapp::plain("## عنوان\n**كلمة** مهمة"));

        // empty = stop
        \App\Domain\Asfoora\AsfooraTools::call('whatsapp_alerts', ['phones' => []]);
        $this->assertNull($owner->fresh()->notify_whatsapp);
    }

    /** Owner 2026-11-27: the table read as one long «|» line on his phone, and he wants the Excel file too */
    public function test_a_report_reads_on_a_phone_and_its_table_comes_as_an_excel_file(): void
    {
        $md = "حصل 2 أكشن.\n- عدّلت طلبين: **Walaa #6551** و**#5874**.\n\n| رقم الطلب | العميل | الحالة |\n|---|---|---|\n| #6551 | ابراهيم | مرفوض |\n| #5874 | عبد القادر | - |\n";
        $text = SendOwnerWhatsapp::plain($md, true);
        $this->assertStringNotContainsString('|', $text);
        $this->assertStringContainsString("*1) رقم الطلب: #6551*\nالعميل: ابراهيم\nالحالة: مرفوض", $text);
        $this->assertStringEndsWith("*2) رقم الطلب: #5874*\nالعميل: عبد القادر", $text);
        $this->assertStringContainsString('*Walaa #6551* و *#5874*', $text);

        \Illuminate\Support\Facades\Storage::fake('public');
        $owner = Staff::create(['name' => 'O', 'email' => uniqid().'@x.test', 'password' => 'secret', 'is_hitler' => true, 'notify_whatsapp' => '01012345678']);
        $chat = \App\Models\AsfooraChat::create(['staff_id' => $owner->id, 'title' => 't']);
        $message = \App\Models\AsfooraMessage::create(['asfoora_chat_id' => $chat->id, 'role' => 'assistant', 'content' => $md, 'meta' => []]);
        Http::fake(['*' => Http::response([], 500)]);
        WhatsappBot::create(['name' => 'b', 'staff_id' => Staff::create(['name' => 'B', 'email' => uniqid().'@x.test', 'password' => 'secret', 'is_bot' => true])->id, 'whatsapp_phone_number_id' => 'b-'.uniqid(), 'is_active' => true]);
        $sent = [];
        $this->mock(DeliveryService::class, function ($m) use (&$sent) {
            $m->shouldReceive('deliverForConversation')->twice()->withArgs(function ($c, $result) use (&$sent) { $sent[] = $result; return true; });
        });
        app()->call([new SendOwnerWhatsapp($owner->id, $text, 'k', '201012345678', $message->id), 'handle']);

        $this->assertSame([$text], $sent[0]['messages']);
        $file = $sent[1]['media'][0];
        $this->assertSame('document', $file['type']);
        $this->assertStringEndsWith('.xlsx', $file['filename']);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($file['path']);
        $xlsx = \Illuminate\Support\Facades\Storage::disk('public')->path($file['path']);
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($xlsx);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $this->assertSame(['رقم الطلب', 'العميل', 'الحالة'], $rows[0]);
        $this->assertSame(['#6551', 'ابراهيم', 'مرفوض'], $rows[1]);
    }

    public function test_the_app_manifest_is_linked(): void
    {
        $this->assertFileExists(public_path('manifest.webmanifest'));
        $this->assertFileExists(public_path('images/app/icon-512.png'));
        $me = Staff::create(['name' => 'S', 'email' => uniqid().'@x.test', 'password' => 'secret', 'is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($me, 'filament')->get('/admin/notifications')->assertSee('/manifest.webmanifest', false);
    }
}
