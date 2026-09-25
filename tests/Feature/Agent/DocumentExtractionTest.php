<?php

namespace Tests\Feature\Agent;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\ProcessDocumentTool;
use App\Agent\Tools\ToolContext;
use App\Domain\Applications\LegacyRequestProjector;
use App\Domain\Documents\DocumentRules\MinDaysSinceEvaluator;
use App\Domain\Documents\OcrException;
use App\Domain\Documents\OcrProvider;
use App\Domain\Documents\OcrResult;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\MessageMedia;
use App\Models\RequirementField;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Database\Seeders\DocumentExtractionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/** Owner 2026-09-26: a salary slip is read for the salary and its dates, not only the name. */
class DocumentExtractionTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $ai;

    protected function setUp(): void
    {
        parent::setUp();

        RequirementField::create(['key' => 'full_name', 'label' => 'الاسم', 'data_type' => 'person_name', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);
        RequirementField::create(['key' => 'national_id', 'label' => 'الرقم القومي', 'data_type' => 'national_id', 'scope' => 'customer', 'is_sensitive' => true, 'is_active' => true]);
        RequirementField::create(['key' => 'monthly_income', 'label' => 'قيمة المعاش الشهري', 'data_type' => 'money', 'scope' => 'application', 'is_sensitive' => false, 'is_active' => true]);

        foreach (['national_id_front' => ['full_name', 'national_id'], 'salary_slip' => ['full_name'], 'pension_statement' => ['full_name']] as $key => $fields) {
            DocumentType::create(['key' => $key, 'label' => $key, 'description_for_ai' => $key, 'accepted_mimes' => ['image/jpeg'],
                'extraction_fields' => $fields, 'validation_rules' => [], 'is_active' => true]);
        }

        $this->seed(DocumentExtractionSeeder::class);

        $ocr = Mockery::mock(OcrProvider::class);
        $ocr->shouldReceive('extractText')->andReturn(new OcrResult('ocr text'));
        $this->app->instance(OcrProvider::class, $ocr);

        $this->ai = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->ai);
    }

    private function application(): array
    {
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret']);
        $bot = WhatsappBot::create(['staff_id' => $staff->id, 'name' => 'B', 'whatsapp_phone_number_id' => uniqid(), 'is_active' => true]);
        $conversation = WhatsappConversation::create(['whatsapp_bot_id' => $bot->id, 'phone' => '2011', 'status' => 'open']);
        $customer = Customer::create(['whatsapp_bot_id' => $bot->id, 'jid' => '2011@s.whatsapp.net', 'phone' => '2011']);
        $type = CustomerType::create(['key' => 'employee', 'label' => 'موظف', 'legacy_work_status' => 'employee']);
        $application = Application::create(['customer_id' => $customer->id, 'origin_conversation_id' => $conversation->id, 'customer_type_id' => $type->id, 'status' => 'collecting']);

        ApplicationData::create(['application_id' => $application->id, 'party' => 'applicant', 'field_key' => 'full_name',
            'value' => 'محمد احمد علي حسن', 'source' => 'customer_stated', 'status' => 'valid']);

        $message = WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'image']);
        Storage::fake('local');
        Storage::disk('local')->put('slip.jpg', 'slip-bytes');
        $media = MessageMedia::create(['message_id' => $message->id, 'media_type' => 'image', 'mime' => 'image/jpeg',
            'disk' => 'local', 'path' => 'slip.jpg', 'size' => 10, 'sha256' => hash('sha256', 'slip-bytes')]);

        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);

        return [$application, $media, new ToolContext($customer->id, $conversation->id, $application->id, 1, $trace->id, new TurnResultBuilder())];
    }

    private function reads(string $type, array $fields, ?array $focused = null): void
    {
        $this->ai->queue(new AiResponse(textParts: [json_encode([
            'document_type_key' => $type, 'legibility' => 'good', 'confidence' => 0.9, 'fields' => $fields,
        ])], toolCalls: [], finishReason: 'STOP', usage: [], model: 'gemini-test', keyId: null, latencyMs: 1));

        if ($focused !== null) {
            $this->ai->queue(new AiResponse(textParts: [json_encode($focused)], toolCalls: [], finishReason: 'STOP', usage: [], model: 'gemini-test', keyId: null, latencyMs: 1));
        }
    }

    public function test_the_focused_read_of_the_type_fills_and_corrects_the_first_one(): void
    {
        [$application, $media, $ctx] = $this->application();
        // live 2026-09-26: the generic call read "٦٬٧٧٥" as 6075 and no hire date
        $this->reads('salary_slip',
            ['full_name' => 'محمد احمد علي حسن', 'monthly_income' => '6075', 'salary_slip_date' => now()->subMonth()->toDateString()],
            ['monthly_income' => '6775', 'hire_date' => '2019-03-15', 'job_title' => 'مشرف إنتاج'],
        );

        $item = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx)->data['results'][0];

        $this->assertTrue($item['accepted']);
        $this->assertEquals(6775, (float) ApplicationData::where('application_id', $application->id)->where('field_key', 'monthly_income')->value('value'));
        $extracted = ApplicationDocument::find($item['document_id'])->extracted;
        $this->assertSame('2019-03-15', $extracted['hire_date']);
        $this->assertSame('مشرف إنتاج', $extracted['job_title']);
        $this->assertSame(['full_name', 'monthly_income', 'salary_slip_date'], $this->ai->requests()[1]->responseSchema['required']);
    }

    public function test_a_salary_slip_gives_the_net_salary_its_month_and_the_hire_date(): void
    {
        [$application, $media, $ctx] = $this->application();
        $this->reads('salary_slip', [
            'full_name' => 'محمد احمد علي',
            'monthly_income' => '٥٬٤٣٢٫٥٠ جنيه',
            'salary_slip_date' => now()->subMonth()->endOfMonth()->toDateString(),
            'hire_date' => '2019-03-01',
            'employer_name' => 'شركة النصر',
            'app_name' => 'طلبات',
        ]);

        $item = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx)->data['results'][0];

        // a three-part name on the slip is the same person as the four-part one
        $this->assertTrue($item['accepted'], json_encode($item['issues']));
        $this->assertContains('monthly_income', $item['applied_fields']);
        $this->assertEquals(5432.5, (float) ApplicationData::where('application_id', $application->id)->where('field_key', 'monthly_income')->value('value'));

        $extracted = ApplicationDocument::find($item['document_id'])->extracted;
        $this->assertSame('2019-03-01', $extracted['hire_date']);
        $this->assertSame('شركة النصر', $extracted['employer_name']);
        $this->assertArrayNotHasKey('app_name', $extracted);

        $prompt = $this->ai->requests()[0]->system;
        $this->assertStringContainsString('also extract when printed: hire_date', $prompt);
    }

    public function test_an_old_slip_is_rejected_and_a_missing_hire_date_is_not_a_reason(): void
    {
        [, $media, $ctx] = $this->application();
        $this->reads('salary_slip', [
            'full_name' => 'محمد احمد علي حسن',
            'monthly_income' => '6000',
            'salary_slip_date' => now()->subMonths(6)->toDateString(),
        ]);

        $item = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx)->data['results'][0];

        $this->assertFalse($item['accepted']);
        $this->assertSame(['EXPIRED_DOCUMENT'], array_column($item['issues'], 'code'));
    }

    public function test_someone_elses_slip_is_a_name_mismatch(): void
    {
        [, $media, $ctx] = $this->application();
        $this->reads('salary_slip', [
            'full_name' => 'احمد سيد محمود',
            'monthly_income' => '6000',
            'salary_slip_date' => now()->subMonth()->toDateString(),
        ]);

        $item = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx)->data['results'][0];

        $this->assertSame(['NAME_MISMATCH'], array_column($item['issues'], 'code'));
    }

    public function test_a_pension_statement_sets_the_income_the_minimum_is_checked_against(): void
    {
        [$application, $media, $ctx] = $this->application();
        ApplicationData::create(['application_id' => $application->id, 'party' => 'applicant', 'field_key' => 'monthly_income',
            'value' => '9000', 'source' => 'customer_stated', 'status' => 'valid']);
        $this->reads('pension_statement', [
            'full_name' => 'محمد احمد علي حسن',
            'monthly_income' => '3,850',
            'pension_statement_date' => now()->subWeeks(2)->toDateString(),
        ]);

        $item = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx)->data['results'][0];

        $this->assertTrue($item['accepted'], json_encode($item['issues']));
        // the statement, not what he said, is the pension on file
        $this->assertEquals(3850, (float) ApplicationData::where('application_id', $application->id)->where('field_key', 'monthly_income')->value('value'));
    }

    public function test_a_vision_outage_no_longer_fails_the_document(): void
    {
        [, $media, $ctx] = $this->application();
        $ocr = Mockery::mock(OcrProvider::class);
        $ocr->shouldReceive('extractText')->andThrow(new OcrException('down'));
        $this->app->instance(OcrProvider::class, $ocr);
        $this->reads('salary_slip', [
            'full_name' => 'محمد احمد علي حسن', 'monthly_income' => '6000', 'salary_slip_date' => now()->subMonth()->toDateString(),
        ]);

        $item = app(ProcessDocumentTool::class)->execute(['media_ids' => [$media->id]], $ctx)->data['results'][0];

        $this->assertTrue($item['accepted']);
    }

    public function test_the_staff_request_gets_the_salary_its_month_and_the_hire_date(): void
    {
        [$application, $media] = $this->application();
        ApplicationData::create(['application_id' => $application->id, 'party' => 'applicant', 'field_key' => 'monthly_income',
            'value' => '5432.5', 'source' => 'document', 'status' => 'valid']);
        ApplicationDocument::create(['application_id' => $application->id, 'document_type_id' => DocumentType::where('key', 'salary_slip')->value('id'),
            'media_id' => $media->id, 'party' => 'applicant', 'status' => 'accepted', 'detected_type_key' => 'salary_slip',
            'extracted' => ['salary_slip_date' => '2026-08-31', 'hire_date' => '2019-03-01', 'employer_name' => 'شركة النصر'], 'issues' => [], 'attempts' => 1]);

        $attributes = app(LegacyRequestProjector::class)->attributes($application, 'employee');

        // was 54325: the decimal point was stripped with the other non-digits
        $this->assertSame(5433, $attributes['salary_amount']);
        $this->assertSame('2026-08-31', $attributes['salary_issue_date']);
        $this->assertStringContainsString('جهة العمل: شركة النصر', $attributes['notes']);
        $this->assertStringContainsString('تاريخ التعيين: 2019-03-01 (مدة الخدمة:', $attributes['notes']);
    }

    public function test_min_days_since_checks_a_length_of_service(): void
    {
        [$application] = $this->application();
        $rule = new MinDaysSinceEvaluator();
        $params = ['date_field' => 'hire_date', 'min_days' => 180];

        $this->assertSame('EMPLOYMENT_TOO_RECENT', $rule->evaluate($params, ['hire_date' => now()->subMonths(2)->toDateString()], $application)['code']);
        $this->assertNull($rule->evaluate($params, ['hire_date' => now()->subYears(2)->toDateString()], $application));
        $this->assertNull($rule->evaluate($params, [], $application));
    }
}
