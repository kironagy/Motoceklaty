<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\ProcessDocumentTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Documents\OcrProvider;
use App\Domain\Documents\OcrResult;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\MessageMedia;
use App\Models\RequirementField;
use App\Models\WhatsappMessage;
use Database\Seeders\DocumentExtractionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/** Rebuild DOC-001 (<= 2 model calls per photo) and DOC-004 (a burst read in one call, one result per photo). */
class DocumentBudgetTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private FakeAiProvider $ai;

    private Application $application;

    private ToolContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        RequirementField::create(['key' => 'full_name', 'label' => 'الاسم', 'data_type' => 'person_name', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        RequirementField::create(['key' => 'national_id', 'label' => 'الرقم القومي', 'data_type' => 'national_id', 'scope' => 'customer', 'is_sensitive' => true, 'is_active' => true]);
        RequirementField::create(['key' => 'monthly_income', 'label' => 'المرتب', 'data_type' => 'money', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);

        foreach (['national_id_front' => ['full_name', 'national_id'], 'national_id_back' => [], 'salary_slip' => ['full_name']] as $key => $fields) {
            DocumentType::updateOrCreate(['key' => $key], ['label' => $key, 'description_for_ai' => $key, 'accepted_mimes' => ['image/jpeg'],
                'extraction_fields' => $fields, 'validation_rules' => [], 'is_active' => true]);
        }

        $this->seed(DocumentExtractionSeeder::class);
        DocumentType::whereNotIn('key', ['national_id_front', 'national_id_back', 'salary_slip'])->update(['is_active' => false]);

        $ocr = Mockery::mock(OcrProvider::class);
        $ocr->shouldReceive('extractText')->andReturn(new OcrResult('ocr text'));
        $this->app->instance(OcrProvider::class, $ocr);

        $this->ai = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->ai);

        $conversation = $this->conversation();
        $type = CustomerType::create(['key' => 'employee', 'label' => 'موظف', 'legacy_work_status' => 'employee']);
        $this->application = Application::create(['customer_id' => $conversation->customer_id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'collecting']);
        ApplicationData::create(['application_id' => $this->application->id, 'party' => 'applicant', 'field_key' => 'full_name',
            'value' => 'محمد احمد علي حسن', 'source' => 'customer_stated', 'status' => 'valid']);

        Storage::fake('local');
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $this->ctx = new ToolContext($conversation->customer_id, $conversation->id, $this->application->id, 1, $trace->id, new TurnResultBuilder());
    }

    private function photo(string $name): MessageMedia
    {
        $message = WhatsappMessage::create(['whatsapp_conversation_id' => $this->ctx->conversationId, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image']);
        Storage::disk('local')->put("{$name}.jpg", "{$name}-bytes");

        return MessageMedia::create(['message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg',
            'disk' => 'local', 'path' => "{$name}.jpg", 'size' => 10, 'sha256' => hash('sha256', "{$name}-bytes")]);
    }

    private function answers(array ...$payloads): void
    {
        foreach ($payloads as $payload) {
            $this->ai->queue(new AiResponse(textParts: [json_encode($payload, JSON_UNESCAPED_UNICODE)], toolCalls: [], finishReason: 'STOP', usage: [], model: 'gemini-test', keyId: null, latencyMs: 1));
        }
    }

    private function slip(array $fields): array
    {
        return ['document_type_key' => 'salary_slip', 'legibility' => 'good', 'confidence' => 0.9, 'fields' => $fields];
    }

    private function process(array $media): array
    {
        return app(ProcessDocumentTool::class)->execute(['media_ids' => array_map(fn ($m) => $m->id, $media)], $this->ctx)->data['results'];
    }

    public function test_a_photo_read_completely_costs_one_call(): void
    {
        $this->answers($this->slip(['full_name' => 'محمد احمد علي حسن', 'monthly_income' => '6000', 'salary_slip_date' => now()->subMonth()->toDateString()]));

        $results = $this->process([$this->photo('slip')]);

        $this->assertTrue($results[0]['accepted'], json_encode($results[0]['issues']));
        $this->assertCount(1, $this->ai->requests());
    }

    public function test_a_missing_field_and_a_name_mismatch_still_cost_two_calls_at_most(): void
    {
        $this->answers(
            $this->slip(['full_name' => 'احمد سيد محمود', 'salary_slip_date' => now()->subMonth()->toDateString()]),
            ['monthly_income' => '6000'],
        );

        $results = $this->process([$this->photo('slip')]);

        $this->assertSame(['NAME_MISMATCH'], array_column($results[0]['issues'], 'code'));
        // classify+read, then ONE focused re-read - no third "high detail" read for the name
        $this->assertCount(2, $this->ai->requests());
    }

    public function test_a_date_before_the_hire_date_is_fixed_in_the_same_single_re_read(): void
    {
        $this->answers(
            $this->slip(['full_name' => 'محمد احمد علي حسن', 'monthly_income' => '6000', 'salary_slip_date' => '2024-09-13', 'hire_date' => '2025-01-01']),
            ['salary_slip_date' => now()->subMonth()->toDateString()],
        );

        $results = $this->process([$this->photo('slip')]);

        $this->assertTrue($results[0]['accepted'], json_encode($results[0]['issues']));
        $this->assertCount(2, $this->ai->requests());
        $this->assertStringContainsString('before the hire date', $this->ai->requests()[1]->system);
    }

    public function test_four_photos_in_one_burst_are_read_in_one_call_with_a_result_each(): void
    {
        $front = $this->photo('front');
        $back = $this->photo('back');
        $slip = $this->photo('slip');
        $bike = $this->photo('bike');
        $this->answers(['documents' => [
            ['image' => 1, 'document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9, 'fields' => ['full_name' => 'محمد احمد علي حسن', 'national_id' => '29001010112345']],
            ['image' => 2, 'document_type_key' => 'national_id_back', 'legibility' => 'good', 'confidence' => 0.9, 'fields' => []],
            ['image' => 3] + $this->slip(['full_name' => 'محمد احمد علي حسن', 'monthly_income' => '6000', 'salary_slip_date' => now()->subMonth()->toDateString()]),
            ['image' => 4, 'document_type_key' => '', 'legibility' => 'good', 'confidence' => 0.2, 'fields' => []],
        ]]);

        $results = $this->process([$front, $back, $slip, $bike]);

        $this->assertCount(1, $this->ai->requests());
        $request = $this->ai->requests()[0];
        $this->assertCount(4, collect($request->contents[0]['parts'])->where('type', 'inline_media'));
        $this->assertSame('array', $request->responseSchema['properties']['documents']['type']);

        $this->assertSame([$front->id, $back->id, $slip->id, $bike->id], array_column($results, 'media_id'));
        $this->assertSame(['national_id_front', 'national_id_back', 'salary_slip', null], array_column($results, 'detected_type'));
        $this->assertTrue($results[0]['accepted'], json_encode($results[0]['issues']));
        $this->assertTrue($results[2]['accepted'], json_encode($results[2]['issues']));
        $this->assertFalse($results[3]['accepted']);
    }

    public function test_a_photo_the_burst_answer_left_out_is_read_on_its_own(): void
    {
        $front = $this->photo('front');
        $slip = $this->photo('slip');
        $this->answers(
            ['documents' => [['image' => 1, 'document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9, 'fields' => ['full_name' => 'محمد احمد علي حسن', 'national_id' => '29001010112345']]]],
            $this->slip(['full_name' => 'محمد احمد علي حسن', 'monthly_income' => '6000', 'salary_slip_date' => now()->subMonth()->toDateString()]),
        );

        $results = $this->process([$front, $slip]);

        $this->assertCount(2, $this->ai->requests());
        $this->assertCount(1, collect($this->ai->requests()[1]->contents[0]['parts'])->where('type', 'inline_media'));
        $this->assertSame(['national_id_front', 'salary_slip'], array_column($results, 'detected_type'));
    }
}
