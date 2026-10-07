<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Providers\AiResponse;
use App\Agent\Runtime\AgentRunner;
use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\RecordWorkProfileTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Documents\OcrProvider;
use App\Domain\Documents\OcrResult;
use App\Models\ApplicationDocument;
use App\Models\MessageMedia;
use App\Models\WhatsappMessage;
use Database\Seeders\AgentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/** Rebuild DOC-006: an ID photo sent before the application existed is read the moment it opens. */
class EarlyDocumentsTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    public function test_a_photo_sent_before_applying_is_read_when_the_application_opens(): void
    {
        $this->runtimeLimits();
        $this->seed(AgentCatalogSeeder::class);
        Storage::fake('local');
        $ocr = Mockery::mock(OcrProvider::class);
        $ocr->shouldReceive('extractText')->andReturn(new OcrResult('ocr text'));
        $this->app->instance(OcrProvider::class, $ocr);

        $conversation = $this->conversation();
        // yesterday: the ID photo, no application yet
        $earlier = WhatsappMessage::create(['whatsapp_bot_id' => $conversation->whatsapp_bot_id, 'whatsapp_conversation_id' => $conversation->id,
            'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image', 'wa_message_id' => uniqid('wa')]);
        Storage::disk('local')->put('id.jpg', 'id-bytes');
        $media = MessageMedia::create(['message_id' => $earlier->id, 'media_type' => 'image', 'mime' => 'image/jpeg', 'disk' => 'local', 'path' => 'id.jpg',
            'size' => 8, 'sha256' => hash('sha256', 'id-bytes')]);

        $turn = $this->turnFor($conversation, 'انا موظف متأمن عليا وعايز اقدم');
        app(RecordWorkProfileTool::class)->execute(['evidence' => 'انا موظف متأمن عليا', 'occupation' => 'موظف', 'job_title' => 'محاسب', 'work_stated' => true,
            'customer_type' => 'employee', 'working_now' => 'yes', 'relation_to_workplace' => 'works_for_someone', 'insured' => 'yes'],
            new ToolContext($conversation->customer_id, $conversation->id, null, 0, 1, new TurnResultBuilder()));

        $fake = $this->fakeAi();
        $fake->queue($this->reply([['id' => 'c1', 'name' => 'start_application', 'args' => ['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف متأمن عليا']]]));
        // the document reader's one call for that photo
        $fake->queue(new AiResponse([json_encode(['document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9,
            'fields' => ['full_name' => 'محمد احمد علي', 'national_id' => '29001010112345']], JSON_UNESCAPED_UNICODE)], [], 'STOP', [], 'gemini-test', null, 1));
        $fake->queue($this->sendReply('تمام، البطاقة وصلت'));

        app(AgentRunner::class)->run($turn);

        $this->assertSame(1, ApplicationDocument::where('media_id', $media->id)->count());
        $requests = $fake->requests();
        $lastCall = json_encode(end($requests)->contents, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('قبل ما الطلب يتفتح اتقرت دلوقتي', $lastCall);
    }
}
