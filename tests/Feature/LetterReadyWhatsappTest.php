<?php

namespace Tests\Feature;

use App\Domain\Conversations\DeliveryService;
use App\Jobs\SendLetterReadyWhatsapp;
use App\Models\Letter;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/** Owner 2026-11-27: «تم التنفيذ» + «صورة الجواب» = the customer gets the photo and «جوابك جاهز». */
class LetterReadyWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private function letter(array $data = []): Letter
    {
        return Letter::create($data + ['customer_name' => 'يوسف محمد صقر', 'phone' => '01012345678', 'remaining' => 3500, 'status' => 'جاري التنفيذ']);
    }

    public function test_ready_with_a_photo_is_sent_once_and_not_without_one(): void
    {
        Queue::fake([SendLetterReadyWhatsapp::class]);

        $letter = $this->letter();
        $letter->update(['status' => 'تم التنفيذ']);
        Queue::assertNotPushed(SendLetterReadyWhatsapp::class);

        $letter->update(['letter_image' => 'letters/a.jpg']);
        Queue::assertPushed(SendLetterReadyWhatsapp::class, 1);

        $this->assertStringContainsString('جوابك جاهز', SendLetterReadyWhatsapp::message($letter));
        $this->assertStringContainsString('3,500 جنيه', SendLetterReadyWhatsapp::message($letter));
        $this->assertStringNotContainsString('جهّز', SendLetterReadyWhatsapp::message($this->letter(['remaining' => 0])));
    }

    public function test_the_job_sends_the_photo_from_a_live_number(): void
    {
        Queue::fake([SendLetterReadyWhatsapp::class]);
        Http::fake(['*' => Http::response([], 500)]); // worker status unknown: any live number
        $staff = Staff::create(['name' => 'B', 'email' => uniqid().'@x.test', 'password' => 'secret', 'is_bot' => true]);
        $bot = WhatsappBot::create(['name' => 'بوت', 'staff_id' => $staff->id, 'whatsapp_phone_number_id' => 'b-'.uniqid(), 'is_active' => true]);
        $letter = $this->letter(['status' => 'تم التنفيذ', 'letter_image' => 'letters/a.jpg']);

        $sent = null;
        $this->mock(DeliveryService::class, function ($m) use (&$sent) {
            $m->shouldReceive('deliverForConversation')->once()
                ->withArgs(function ($conversation, $result) use (&$sent) { $sent = [$conversation, $result]; return true; });
        });

        app()->call([new SendLetterReadyWhatsapp($letter->id), 'handle']);

        $this->assertSame($bot->id, (int) $sent[0]->whatsapp_bot_id);
        $this->assertSame('201012345678', $sent[0]->phone);
        $this->assertSame('letters/a.jpg', $sent[1]['media'][0]['path']);
        $this->assertStringContainsString('جوابك جاهز', $sent[1]['media'][0]['caption']);
        $this->assertNotNull($letter->fresh()->ready_sent_at);

        // once only
        app()->call([new SendLetterReadyWhatsapp($letter->id), 'handle']);
    }
}
