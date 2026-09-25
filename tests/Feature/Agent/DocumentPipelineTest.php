<?php

namespace Tests\Feature\Agent;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\ProcessDocumentTool;
use App\Agent\Tools\ToolContext;
use App\Agent\Tools\ToolRegistry;
use App\Domain\Documents\OcrException;
use App\Domain\Documents\OcrProvider;
use App\Domain\Documents\OcrResult;
use App\Models\AiTrace;
use App\Models\AiTraceStep;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\ApplicationRequirement;
use App\Models\Customer;
use App\Models\CustomerAttribute;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\EligibilityRule;
use App\Models\MessageMedia;
use App\Models\RequirementField;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class DocumentPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function fields(): void
    {
        RequirementField::create(['key' => 'national_id', 'label' => 'National ID', 'data_type' => 'national_id', 'scope' => 'customer', 'is_sensitive' => true, 'is_active' => true]);
        RequirementField::create(['key' => 'full_name', 'label' => 'Full name', 'data_type' => 'person_name', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
    }

    private function nationalIdDocumentType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'key' => 'national_id_front',
            'label' => 'National ID (front)',
            'description_for_ai' => 'Egyptian national ID card, front side',
            'accepted_mimes' => ['image/jpeg'],
            'extraction_fields' => ['national_id', 'full_name'],
            'validation_rules' => [],
            'is_active' => true,
        ], $overrides));
    }

    private function bootstrapApplication(): array
    {
        $staff = Staff::create(['name' => 'S', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $type = CustomerType::create(['key' => 'employee', 'label' => 'Employee']);

        $application = Application::create([
            'customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $type->id, 'status' => 'collecting',
        ]);

        $message = WhatsappMessage::create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image',
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('id.jpg', 'fake-bytes');
        $media = MessageMedia::create([
            'message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg',
            'disk' => 'local', 'path' => 'id.jpg', 'size' => 10, 'sha256' => hash('sha256', 'fake-bytes'),
        ]);

        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);
        $ctx = new ToolContext($customer->id, $conversation->id, $application->id, 1, $trace->id, new TurnResultBuilder());

        return [$application, $media, $ctx, $type];
    }

    private function fakeOcr(): void
    {
        $ocr = Mockery::mock(OcrProvider::class);
        $ocr->shouldReceive('extractText')->andReturn(new OcrResult('some ocr text'));
        $this->app->instance(OcrProvider::class, $ocr);
    }

    private function queueClassification(array $data): void
    {
        $fake = new FakeAiProvider();
        $fake->queue(new AiResponse(textParts: [json_encode($data)], toolCalls: [], finishReason: 'STOP', usage: [], model: 'gemini-test', keyId: null, latencyMs: 1));
        $this->app->instance(AiProvider::class, $fake);
    }

    public function test_classification_schema_declares_extraction_fields_explicitly(): void
    {
        // Regression: `fields` sent to Gemini as a schema-less {type: object}
        // (no declared sub-properties) drove the real model into a genuine
        // degenerate output loop - thousands of space characters instead of
        // the extracted value, hitting MAX_TOKENS on every real document.
        // Declaring every known extraction field as a named string property
        // fixed it; this locks in that the schema is actually built that way.
        $this->fields();
        $this->nationalIdDocumentType();
        [$application, $media, $ctx] = $this->bootstrapApplication();
        $this->fakeOcr();

        $fake = new FakeAiProvider();
        $fake->queue(new AiResponse(textParts: [json_encode([
            'document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9,
            'fields' => ['national_id' => '29001010112345', 'full_name' => 'Mohamed Ali'],
        ])], toolCalls: [], finishReason: 'STOP', usage: [], model: 'gemini-test', keyId: null, latencyMs: 1));
        $this->app->instance(AiProvider::class, $fake);

        app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx);

        $schema = $fake->lastRequest()->responseSchema;

        $this->assertSame(
            ['type' => 'object', 'properties' => ['national_id' => ['type' => 'string'], 'full_name' => ['type' => 'string']]],
            $schema['properties']['fields']
        );
    }

    public function test_valid_national_id_is_accepted_and_fields_applied(): void
    {
        $this->fields();
        $this->nationalIdDocumentType();
        [$application, $media, $ctx] = $this->bootstrapApplication();
        $this->fakeOcr();
        $this->queueClassification([
            'document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9,
            'fields' => ['national_id' => '29001010112345', 'full_name' => 'Mohamed Ali'],
        ]);

        $result = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx);

        $this->assertTrue($result->ok);
        $item = $result->data['results'][0];
        $this->assertTrue($item['accepted']);
        $this->assertSame('accepted', $item['status']);
        $this->assertSame(['national_id', 'full_name'], $item['applied_fields']);

        $this->assertSame('29001010112345', CustomerAttribute::where('customer_id', $application->customer_id)->where('field_key', 'national_id')->value('value'));
        $this->assertSame('document', CustomerAttribute::where('customer_id', $application->customer_id)->where('field_key', 'national_id')->value('source'));
        $this->assertSame('Mohamed Ali', ApplicationData::where('application_id', $application->id)->where('field_key', 'full_name')->value('value'));
    }

    public function test_a_supporting_document_never_overwrites_a_name_read_from_another_document(): void
    {
        $this->fields();
        $this->nationalIdDocumentType();
        $proof = DocumentType::create([
            'key' => 'self_employed_income_proof', 'label' => 'Income proof', 'description_for_ai' => 'income',
            'accepted_mimes' => ['image/jpeg'], 'extraction_fields' => ['full_name'], 'validation_rules' => [], 'is_active' => true,
        ]);
        [$application, $media, $ctx] = $this->bootstrapApplication();
        $this->fakeOcr();

        $idDocument = ApplicationDocument::create([
            'application_id' => $application->id, 'media_id' => $media->id, 'document_type_id' => DocumentType::where('key', 'national_id_front')->value('id'),
            'status' => 'accepted', 'detected_type_key' => 'national_id_front',
        ]);
        ApplicationData::create([
            'application_id' => $application->id, 'party' => 'applicant', 'field_key' => 'full_name',
            'value' => 'احمد سيد حسين علي', 'source' => 'document', 'status' => 'valid', 'document_id' => $idDocument->id,
        ]);

        Storage::disk('local')->put('app.jpg', 'app-screen');
        $screen = MessageMedia::create([
            'message_id' => $media->message_id, 'media_type' => 'image', 'mime' => 'image/jpeg',
            'disk' => 'local', 'path' => 'app.jpg', 'size' => 10, 'sha256' => hash('sha256', 'app-screen'),
        ]);
        $this->queueClassification([
            'document_type_key' => 'self_employed_income_proof', 'legibility' => 'good', 'confidence' => 0.9,
            'fields' => ['full_name' => 'Abdulrahman Osama Abbas Hassan'],
        ]);

        $item = app(ProcessDocumentTool::class)->execute(['media_ids' => [$screen->id]], $ctx)->data['results'][0];

        $this->assertTrue($item['accepted']);
        $this->assertSame([], $item['applied_fields']);
        $this->assertSame('full_name', $item['differs_from_file'][0]['field']);
        $this->assertSame('احمد سيد حسين علي', ApplicationData::where('application_id', $application->id)->where('field_key', 'full_name')->value('value'));
    }

    public function test_wrong_document_when_a_different_required_type_is_expected(): void
    {
        $this->fields();
        $this->nationalIdDocumentType();
        DocumentType::create([
            'key' => 'salary_slip', 'label' => 'Salary slip', 'description_for_ai' => 'Salary slip',
            'accepted_mimes' => ['image/jpeg'], 'extraction_fields' => [], 'validation_rules' => [], 'is_active' => true,
        ]);
        [, $media, $ctx] = $this->bootstrapApplication();
        $this->fakeOcr();
        // Customer sent a national ID while a salary slip was expected, and
        // national_id_front is not itself a required (and missing) document.
        $this->queueClassification(['document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9, 'fields' => []]);

        $result = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id], 'expected_document_type' => 'salary_slip'], $ctx);

        $item = $result->data['results'][0];
        $this->assertFalse($item['accepted']);
        $this->assertSame('WRONG_DOCUMENT', $item['issues'][0]['code']);
    }

    public function test_a_required_but_missing_type_is_accepted_even_when_a_different_type_was_expected(): void
    {
        $this->fields();
        $this->nationalIdDocumentType(['extraction_fields' => []]);
        $salary = DocumentType::create([
            'key' => 'salary_slip', 'label' => 'Salary slip', 'description_for_ai' => 'Salary slip',
            'accepted_mimes' => ['image/jpeg'], 'extraction_fields' => [], 'validation_rules' => [], 'is_active' => true,
        ]);
        [$application, $media, $ctx, $type] = $this->bootstrapApplication();

        ApplicationRequirement::create([
            'customer_type_id' => $type->id, 'requirement_type' => 'document',
            'document_type_id' => DocumentType::where('key', 'national_id_front')->value('id'), 'is_required' => true,
        ]);

        $this->fakeOcr();
        $this->queueClassification(['document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9, 'fields' => []]);

        $result = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id], 'expected_document_type' => 'salary_slip'], $ctx);

        $item = $result->data['results'][0];
        $this->assertTrue($item['accepted']);
        $this->assertSame('national_id_front', $item['detected_type']);
    }

    public function test_blurry_document_is_rejected(): void
    {
        $this->fields();
        $this->nationalIdDocumentType();
        [, $media, $ctx] = $this->bootstrapApplication();
        $this->fakeOcr();
        $this->queueClassification(['document_type_key' => 'national_id_front', 'legibility' => 'poor', 'confidence' => 0.5, 'fields' => []]);

        $result = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx);

        $item = $result->data['results'][0];
        $this->assertFalse($item['accepted']);
        $this->assertSame('BLURRY_DOCUMENT', $item['issues'][0]['code']);
    }

    public function test_an_id_over_the_age_limit_is_a_valid_document_and_eligibility_blocks_the_application(): void
    {
        $this->fields();
        $this->nationalIdDocumentType();
        EligibilityRule::create(['customer_type_id' => null, 'rule_type' => 'age_range', 'params' => ['min' => 21, 'max' => 60], 'is_active' => true]);
        [$application, $media, $ctx] = $this->bootstrapApplication();
        $this->fakeOcr();
        // A 2024-born national ID (century 3, year 24) -> well under 21.
        $this->queueClassification([
            'document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9,
            'fields' => ['national_id' => '32401010112345', 'full_name' => 'Mohamed Ali'],
        ]);

        $result = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx);

        // The card is real and readable; the age is an eligibility outcome
        // of the application, decided by Laravel from the accepted data.
        $this->assertTrue($result->data['results'][0]['accepted']);
        $snapshot = $result->data['snapshot'];
        $this->assertSame('not_eligible', $snapshot['eligibility']['status']);
        $this->assertContains('AGE_OUT_OF_RANGE', array_column($snapshot['eligibility']['reasons'], 'code'));
        $this->assertFalse($snapshot['can_submit']);
    }

    public function test_a_null_word_is_not_an_extracted_value(): void
    {
        $this->fields();
        $this->nationalIdDocumentType();
        [, $media, $ctx] = $this->bootstrapApplication();
        $this->fakeOcr();
        $this->queueResponses([
            ['document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9, 'fields' => ['national_id' => '29001010112345', 'full_name' => 'null']],
            ['full_name' => 'N/A'],
        ]);

        $item = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx)->data['results'][0];

        $this->assertFalse($item['accepted']);
        $this->assertContains(['code' => 'MISSING_DATA', 'field' => 'full_name'], $item['issues']);
    }

    public function test_an_unknown_expected_type_is_an_error(): void
    {
        [, $media, $ctx] = $this->bootstrapApplication();
        $this->nationalIdDocumentType();

        $result = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id], 'expected_document_type' => 'national_id'], $ctx);

        $this->assertSame('UNKNOWN_DOCUMENT_TYPE', $result->error['code']);
    }

    public function test_a_wrong_persons_id_can_be_replaced_by_the_customers_own(): void
    {
        // Persona B: his brother's card was accepted first; his own card was
        // then rejected for not matching the brother's - locked in.
        $this->fields();
        $this->nationalIdDocumentType([
            'validation_rules' => [
                ['rule_type' => 'matches_application_field', 'params' => ['extracted_field' => 'full_name', 'stored_field' => 'full_name', 'issue_code' => 'NAME_MISMATCH']],
                ['rule_type' => 'matches_application_field', 'params' => ['extracted_field' => 'national_id', 'stored_field' => 'national_id', 'issue_code' => 'ID_MISMATCH']],
            ],
        ]);
        [$application, $brotherCard, $ctx] = $this->bootstrapApplication();
        $ownCard = $this->secondMedia($brotherCard);
        $this->fakeOcr();
        $this->queueResponses([
            ['document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9, 'fields' => ['national_id' => '30411262102496', 'full_name' => 'ناصر درويش']],
            ['document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9, 'fields' => ['national_id' => '30407230106719', 'full_name' => 'يوسف ابراهيم']],
            ['document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9, 'fields' => ['national_id' => '30407230106719', 'full_name' => 'يوسف ابراهيم']],
        ]);

        app(ProcessDocumentTool::class)->execute(['media_ids' => [$brotherCard->id]], $ctx);

        $plain = app(ProcessDocumentTool::class)->execute(['media_ids' => [$ownCard->id]], $ctx)->data['results'][0];
        $this->assertFalse($plain['accepted'], 'a conflicting card is not silently swapped in');

        \App\Models\ApplicationDocument::where('media_id', $ownCard->id)->delete();
        $replaced = app(ProcessDocumentTool::class)->execute(['media_ids' => [$ownCard->id], 'replaces_previous' => true], $ctx)->data['results'][0];

        $this->assertTrue($replaced['accepted']);
        $this->assertSame('يوسف ابراهيم', \App\Models\ApplicationData::where('application_id', $application->id)->where('field_key', 'full_name')->value('value'));
        $this->assertSame('30407230106719', \App\Models\CustomerAttribute::where('field_key', 'national_id')->value('value'));
        $this->assertSame('superseded', \App\Models\ApplicationDocument::where('media_id', $brotherCard->id)->value('status'));
        $this->assertDatabaseHas('application_events', ['application_id' => $application->id, 'type' => 'document_superseded']);
    }

    private function secondMedia(MessageMedia $first): MessageMedia
    {
        $message = WhatsappMessage::create([
            'whatsapp_conversation_id' => $first->message->whatsapp_conversation_id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image',
        ]);
        Storage::disk('local')->put('id2.jpg', 'other-bytes');

        return MessageMedia::create([
            'message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg',
            'disk' => 'local', 'path' => 'id2.jpg', 'size' => 11, 'sha256' => hash('sha256', 'other-bytes'),
        ]);
    }

    private function queueResponses(array $payloads): void
    {
        $fake = new FakeAiProvider();

        foreach ($payloads as $data) {
            $fake->queue(new AiResponse(textParts: [json_encode($data)], toolCalls: [], finishReason: 'STOP', usage: [], model: 'gemini-test', keyId: null, latencyMs: 1));
        }

        $this->app->instance(AiProvider::class, $fake);
    }

    public function test_name_mismatch_against_a_previously_stated_full_name(): void
    {
        $this->fields();
        $this->nationalIdDocumentType([
            'validation_rules' => [['rule_type' => 'matches_application_field', 'params' => ['extracted_field' => 'full_name', 'stored_field' => 'full_name', 'issue_code' => 'NAME_MISMATCH']]],
        ]);
        [$application, $media, $ctx] = $this->bootstrapApplication();

        ApplicationData::create([
            'application_id' => $application->id, 'party' => 'applicant', 'field_key' => 'full_name',
            'value' => 'Ahmed Hassan', 'source' => 'customer_stated', 'status' => 'valid',
        ]);

        $this->fakeOcr();
        $this->queueClassification([
            'document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9,
            'fields' => ['national_id' => '29001010112345', 'full_name' => 'Someone Else'],
        ]);

        $result = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx);

        $item = $result->data['results'][0];
        $this->assertFalse($item['accepted']);
        $this->assertContains('NAME_MISMATCH', array_column($item['issues'], 'code'));
    }

    public function test_ocr_failure_is_retryable_and_leaves_the_application_unchanged(): void
    {
        $this->fields();
        $this->nationalIdDocumentType();
        [$application, $media, $ctx] = $this->bootstrapApplication();

        $ocr = Mockery::mock(OcrProvider::class);
        $ocr->shouldReceive('extractText')->andThrow(new OcrException('down'));
        $this->app->instance(OcrProvider::class, $ocr);

        $result = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx);

        $item = $result->data['results'][0];
        $this->assertFalse($item['accepted']);
        $this->assertSame('failed', $item['status']);
        $this->assertSame('OCR_UNAVAILABLE', $item['issues'][0]['code']);
        $this->assertSame(0, CustomerAttribute::where('customer_id', $application->customer_id)->count());
    }

    public function test_the_same_image_is_deduplicated(): void
    {
        $this->fields();
        $this->nationalIdDocumentType();
        [, $media, $ctx] = $this->bootstrapApplication();
        $this->fakeOcr();
        $this->queueClassification([
            'document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9,
            'fields' => ['national_id' => '29001010112345', 'full_name' => 'Mohamed Ali'],
        ]);

        $first = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx);
        $this->assertTrue($first->data['results'][0]['accepted']);

        // Re-uploading the same bytes must not call the AI/OCR again.
        $secondMedia = MessageMedia::create([
            'message_id' => $media->message_id, 'media_type' => 'image', 'mime' => 'image/jpeg',
            'disk' => 'local', 'path' => 'id.jpg', 'size' => 10, 'sha256' => $media->sha256,
        ]);

        $second = app(ProcessDocumentTool::class)->execute(['media_ids' => [$secondMedia->id]], $ctx);

        $item = $second->data['results'][0];
        $this->assertTrue($item['accepted']);
        $this->assertSame($first->data['results'][0]['document_id'], $item['document_id']);
        $this->assertSame(1, ApplicationDocument::count());
    }

    public function test_traces_contain_no_national_id(): void
    {
        $this->fields();
        $this->nationalIdDocumentType();
        [, $media, $ctx] = $this->bootstrapApplication();
        $this->fakeOcr();
        $this->queueClassification([
            'document_type_key' => 'national_id_front', 'legibility' => 'good', 'confidence' => 0.9,
            'fields' => ['national_id' => '29001010112345', 'full_name' => 'Mohamed Ali'],
        ]);

        app(ToolRegistry::class)->execute('process_document', ['media_ids' => [$media->id]], $ctx);

        $step = AiTraceStep::where('tool_name', 'process_document')->first();
        $dump = json_encode($step->result_redacted);
        $this->assertStringNotContainsString('29001010112345', $dump);
    }

    private function earningsScreen(Application $application, string $bytes): MessageMedia
    {
        $message = WhatsappMessage::create([
            'whatsapp_conversation_id' => $application->origin_conversation_id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image',
        ]);
        Storage::disk('local')->put("{$bytes}.jpg", $bytes);

        return MessageMedia::create([
            'message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg',
            'disk' => 'local', 'path' => "{$bytes}.jpg", 'size' => 10, 'sha256' => hash('sha256', $bytes),
        ]);
    }

    public function test_earnings_screenshots_sent_one_by_one_add_up_to_three_months(): void
    {
        $this->travelTo('2026-09-23');
        $this->fields();
        DocumentType::create([
            'key' => 'delivery_app_earnings', 'label' => 'Earnings', 'description_for_ai' => 'earnings',
            'accepted_mimes' => ['image/jpeg'], 'extraction_fields' => ['app_name', 'period_start', 'period_end'],
            'validation_rules' => [['rule_type' => 'period_coverage', 'params' => ['start_field' => 'period_start', 'end_field' => 'period_end', 'min_days' => 80, 'max_age_days' => 45]]],
            'is_active' => true,
        ]);
        [$application, , $ctx] = $this->bootstrapApplication();
        $this->fakeOcr();

        $fake = new FakeAiProvider();
        foreach ([['2026-09-01', '2026-09-23'], ['2026-07-01', '2026-08-31']] as [$from, $to]) {
            $fake->queue(new AiResponse(textParts: [json_encode([
                'document_type_key' => 'delivery_app_earnings', 'legibility' => 'good', 'confidence' => 0.9,
                'fields' => ['app_name' => 'Uber', 'period_start' => $from, 'period_end' => $to],
            ])], toolCalls: [], finishReason: 'STOP', usage: [], model: 'gemini-test', keyId: null, latencyMs: 1));
        }
        $this->app->instance(AiProvider::class, $fake);

        $first = app(ProcessDocumentTool::class)->execute(['media_ids' => [$this->earningsScreen($application, 'sep')->id]], $ctx);
        $this->assertTrue($first->data['results'][0]['accepted']);
        $this->assertNotContains('delivery_app_earnings', $first->data['snapshot']['documents']['accepted']);
        $this->assertSame(23, $first->data['snapshot']['documents']['partial']['delivery_app_earnings']['covered_days']);

        $second = app(ProcessDocumentTool::class)->execute(['media_ids' => [$this->earningsScreen($application, 'julaug')->id]], $ctx);
        $this->assertContains('delivery_app_earnings', $second->data['snapshot']['documents']['accepted']);
        $this->assertArrayNotHasKey('partial', $second->data['snapshot']['documents']);
    }

    public function test_old_earnings_screenshots_do_not_count_even_when_long_enough(): void
    {
        $this->travelTo('2026-09-23');
        $this->fields();
        $type = DocumentType::create([
            'key' => 'delivery_app_earnings', 'label' => 'Earnings', 'description_for_ai' => 'earnings',
            'accepted_mimes' => ['image/jpeg'], 'extraction_fields' => ['app_name', 'period_start', 'period_end'],
            'validation_rules' => [['rule_type' => 'period_coverage', 'params' => ['start_field' => 'period_start', 'end_field' => 'period_end', 'min_days' => 80, 'max_age_days' => 45]]],
            'is_active' => true,
        ]);
        [$application, $media] = $this->bootstrapApplication();

        ApplicationDocument::create([
            'application_id' => $application->id, 'media_id' => $media->id, 'document_type_id' => $type->id, 'status' => 'accepted',
            'detected_type_key' => 'delivery_app_earnings', 'extracted' => ['app_name' => 'Uber', 'period_start' => '2026-01-01', 'period_end' => '2026-04-30'],
        ]);

        $summary = app(\App\Domain\Documents\PeriodCoverage::class)->summarize($application, $type);

        $this->assertFalse($summary['satisfied']);
        $this->assertTrue($summary['too_old']);
    }
}
