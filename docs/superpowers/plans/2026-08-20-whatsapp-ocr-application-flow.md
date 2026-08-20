# WhatsApp OCR Application Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After a customer confirms an installment purchase over WhatsApp, collect the right documents one at a time, OCR + validate each with Google Document AI against the business rules already stored in `ai_memories`, only create the `InstallmentRequest` row once everything is valid, and automatically message the customer whenever staff change that request's status on `/admin/deliveries`.

**Architecture:** A swappable `OcrProviderInterface` implementation (`GoogleDocumentAiClient`) feeds raw text into a new AI-driven `DocumentDataExtractor` that extracts structured fields and validates them against memory-sourced rules — no hardcoded PHP rule logic. `ApplicationHandler` is extended to branch cash/installment and drive a per-document collection loop. An `InstallmentRequestObserver` + queued job send WhatsApp notifications on status change.

**Tech Stack:** Laravel (PHP), MySQL, Gemini (via existing `GeminiClient`), Google Document AI, PHPUnit (existing `tests/Feature`), existing WhatsApp bot HTTP bridge (`{WHATSAPP_WORKER_URL}/send-message`).

**Spec:** [docs/superpowers/specs/2026-08-20-whatsapp-ocr-application-flow-design.md](../specs/2026-08-20-whatsapp-ocr-application-flow-design.md)

## Global Constraints

- No hardcoded business rules (age range, minimum salary, document freshness) in PHP — every rule is read from `ai_memories` content at request time via `AiMemoryResolver`.
- If any document fails validation, the customer gets an immediate Arabic explanation and **no `InstallmentRequest` row is ever created** for that application until every required document passes.
- Status-change notifications must be dispatched as a queued job — saving the status in Filament must never block on the WhatsApp HTTP round-trip.
- Follow existing code patterns: Gemini JSON-extraction calls mirror `AiIntentClassifier::extractApplicationData()` (`app/Services/AiIntentClassifier.php:374-459`), OCR provider calls implement `OcrProviderInterface` (`app/Services/Ocr/OcrProviderInterface.php`), file uploads use `Storage::disk('public')` (`app/Services/Handlers/MediaOcrHandler.php`).
- Tests use PHPUnit with `RefreshDatabase` (see `tests/Feature/GeminiRateLimitTest.php` for the house style), sqlite in-memory DB, `QUEUE_CONNECTION=sync` in the test environment.

---

## Status

| Task | Description | Status |
|------|-------------|--------|
| 1.0 | Config-driven OCR provider switch (`config/ocr.php` + service binding) | [ ] Not started |
| 1.1 | Point `MediaOcrHandler` at `OcrProviderInterface` instead of `PaddleOcrClient` | [ ] Not started |
| 2.0 | `google/cloud-document-ai` dependency + `.env.example` keys | [ ] Not started |
| 2.1 | `GoogleDocumentAiClient` implementation | [ ] Not started |
| 2.2 | `GoogleDocumentAiClient` unit tests | [ ] Not started |
| 3.0 | Migration: `installment_requests.whatsapp_conversation_id` | [ ] Not started |
| 3.1 | `InstallmentRequest` model relation + fillable update | [ ] Not started |
| 4.0 | `SendWhatsappStatusNotification` queued job | [ ] Not started |
| 4.1 | Job unit tests (approved/paused/rejected message content) | [ ] Not started |
| 5.0 | `InstallmentRequestObserver` | [ ] Not started |
| 5.1 | Register observer in `AppServiceProvider` | [ ] Not started |
| 5.2 | Observer feature tests | [ ] Not started |
| 6.0 | `DocumentDataExtractor` — prompt + Gemini call | [ ] Not started |
| 6.1 | `DocumentDataExtractor` unit tests (valid/invalid per rule) | [ ] Not started |
| 7.0 | `ApplicationHandler` cash/installment branch | [ ] Not started |
| 7.1 | Income category detection + required-document resolver | [ ] Not started |
| 7.2 | Tests for branch + resolver | [ ] Not started |
| 8.0 | Per-document collection loop wired into `MediaOcrHandler` entry | [ ] Not started |
| 8.1 | Invalid-document feedback path (no persistence) | [ ] Not started |
| 8.2 | Tests for collection loop (advance / reject / retry) | [ ] Not started |
| 9.0 | `InstallmentRequest` creation on full completion | [ ] Not started |
| 9.1 | Feature test: full happy-path conversation creates a correct row | [ ] Not started |
| 9.2 | Feature test: invalid data anywhere never creates a row | [ ] Not started |
| 10.0 | Manual end-to-end verification checklist | [ ] Not started |

---

## Task 1.0: Config-driven OCR provider switch

**Files:**
- Modify: `config/ocr.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/OcrProviderBindingTest.php`

**Interfaces:**
- Consumes: `App\Services\Ocr\OcrProviderInterface` (existing, `app/Services/Ocr/OcrProviderInterface.php`), `App\Services\Ocr\PaddleOcrClient` (existing).
- Produces: `config('ocr.driver')` string; the app container resolves `OcrProviderInterface` to whichever driver is configured. Later tasks (1.1, 2.1) depend on this binding existing.

- [ ] **Step 1: Add the `driver` key to `config/ocr.php`**

Open `config/ocr.php` and add a `driver` key at the top of the returned array:

```php
<?php

return [
    'driver' => env('OCR_DRIVER', 'paddle'), // 'paddle' | 'google_document_ai'

    'enabled' => (bool) env('OCR_ENABLED', true),
    'url' => rtrim((string) env('PADDLE_OCR_URL', 'http://127.0.0.1:8100'), '/'),
    'token' => (string) env('PADDLE_OCR_TOKEN', ''),
    'timeout' => (int) env('PADDLE_OCR_TIMEOUT', 90),
    'connect_timeout' => (int) env('PADDLE_OCR_CONNECT_TIMEOUT', 5),
    'max_file_size_kb' => (int) env('OCR_MAX_FILE_SIZE_KB', 10240),
    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/bmp',
        'image/tiff',
        'application/pdf',
    ],

    'google_document_ai' => [
        'project_id' => env('GOOGLE_DOCUMENT_AI_PROJECT_ID', ''),
        'location' => env('GOOGLE_DOCUMENT_AI_LOCATION', 'us'),
        'processor_id' => env('GOOGLE_DOCUMENT_AI_PROCESSOR_ID', ''),
        'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS', ''),
        'timeout' => (int) env('GOOGLE_DOCUMENT_AI_TIMEOUT', 60),
    ],
];
```

- [ ] **Step 2: Bind `OcrProviderInterface` in `AppServiceProvider::register()`**

Replace the empty `register()` method in `app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Services\Ocr\GoogleDocumentAiClient;
use App\Services\Ocr\OcrProviderInterface;
use App\Services\Ocr\PaddleOcrClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OcrProviderInterface::class, function ($app) {
            return config('ocr.driver') === 'google_document_ai'
                ? $app->make(GoogleDocumentAiClient::class)
                : $app->make(PaddleOcrClient::class);
        });
    }

    public function boot(): void
    {
        //
    }
}
```

This references `GoogleDocumentAiClient`, which doesn't exist yet — that's fine, it's created in Task 2.1 before this code path is ever exercised with `OCR_DRIVER=google_document_ai`. With the default `.env` (`OCR_DRIVER` unset → `paddle`), this binding never touches the Google class.

- [ ] **Step 3: Write the binding test**

Create `tests/Feature/OcrProviderBindingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\Ocr\OcrProviderInterface;
use App\Services\Ocr\PaddleOcrClient;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OcrProviderBindingTest extends TestCase
{
    public function test_defaults_to_paddle_ocr_client(): void
    {
        Config::set('ocr.driver', 'paddle');

        $this->assertInstanceOf(PaddleOcrClient::class, app(OcrProviderInterface::class));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=OcrProviderBindingTest`
Expected: `PASS` (1 test).

- [ ] **Step 5: Commit**

```bash
git add config/ocr.php app/Providers/AppServiceProvider.php tests/Feature/OcrProviderBindingTest.php
git commit -m "Add config-driven OCR provider binding"
```

---

## Task 1.1: Point `MediaOcrHandler` at `OcrProviderInterface`

**Files:**
- Modify: `app/Services/Handlers/MediaOcrHandler.php:6,44`
- Test: `tests/Feature/MediaOcrHandlerProviderTest.php`

**Interfaces:**
- Consumes: `OcrProviderInterface` binding from Task 1.0.
- Produces: `MediaOcrHandler` no longer hard-depends on `PaddleOcrClient`; every later task that swaps OCR drivers (Task 2) automatically flows through here.

- [ ] **Step 1: Swap the import and the `app()` call**

In `app/Services/Handlers/MediaOcrHandler.php`, change:

```php
use App\Services\Ocr\PaddleOcrClient;
```

to:

```php
use App\Services\Ocr\OcrProviderInterface;
```

And change line 44 (`$ocr = app(PaddleOcrClient::class)->recognize($disk->path($path), $mime);`) to:

```php
$ocr = app(OcrProviderInterface::class)->recognize($disk->path($path), $mime);
```

- [ ] **Step 2: Write a test proving the interface is used, not the concrete class**

Create `tests/Feature/MediaOcrHandlerProviderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\WhatsappConversation;
use App\Services\Handlers\MediaOcrHandler;
use App\Services\Ocr\OcrProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaOcrHandlerProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_the_bound_ocr_provider_interface(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/id.jpg', 'fake-image-bytes');

        $fake = new class implements OcrProviderInterface {
            public bool $called = false;

            public function recognize(string $absolutePath, ?string $mime = null): array
            {
                $this->called = true;

                return [
                    'ok' => true,
                    'text' => 'test text',
                    'lines' => [],
                    'pages' => [],
                    'average_confidence' => 0.9,
                    'document' => [],
                    'display_text' => 'test text',
                    'engine' => 'fake',
                    'error' => null,
                ];
            }
        };

        $this->app->instance(OcrProviderInterface::class, $fake);

        $conversation = WhatsappConversation::create([
            'whatsapp_bot_id' => 1,
            'phone' => '201000000000@s.whatsapp.net',
            'status' => 'active',
        ]);

        $conversation->messages()->create([
            'direction' => 'incoming',
            'message' => '[media]',
            'payload' => ['saved_media_items' => [['path' => 'uploads/id.jpg']]],
        ]);

        app(MediaOcrHandler::class)->handle($conversation, [
            ['path' => 'uploads/id.jpg', 'mime' => 'image/jpeg', 'filename' => 'id.jpg'],
        ]);

        $this->assertTrue($fake->called);
    }
}
```

- [ ] **Step 3: Run the test to verify it passes**

Run: `php artisan test --filter=MediaOcrHandlerProviderTest`
Expected: `PASS` (1 test). If `WhatsappConversation` requires different fillable columns than used above, check `Schema::getColumnListing('whatsapp_conversations')` and adjust the `create()` call to match (columns are `id, whatsapp_bot_id, phone, status, last_machine_id, last_machine_ids, last_topic, pending_question, context_payload, current_step, last_intent, customer_job_type`).

- [ ] **Step 4: Commit**

```bash
git add app/Services/Handlers/MediaOcrHandler.php tests/Feature/MediaOcrHandlerProviderTest.php
git commit -m "MediaOcrHandler: depend on OcrProviderInterface instead of PaddleOcrClient"
```

---

## Task 2.0: Add Google Document AI dependency + env keys

**Files:**
- Modify: `composer.json`
- Modify: `.env.example`

**Interfaces:**
- Produces: `google/cloud-document-ai` package available for Task 2.1. `.env` keys documented for the user to fill in from the dashboard/host as they said they would.

- [ ] **Step 1: Require the package**

Run:

```bash
composer require google/cloud-document-ai
```

Expected: composer.json gets a new line under `require` and `composer.lock` updates. If the package name differs on Packagist (verify first with `composer show google/cloud-document-ai --all 2>&1 | head -5`; if it 404s, use `composer search document-ai` to find the exact package name before requiring it).

- [ ] **Step 2: Document the required `.env` keys**

Append to `.env.example`:

```
OCR_DRIVER=paddle
GOOGLE_DOCUMENT_AI_PROJECT_ID=
GOOGLE_DOCUMENT_AI_LOCATION=us
GOOGLE_DOCUMENT_AI_PROCESSOR_ID=
GOOGLE_APPLICATION_CREDENTIALS=
GOOGLE_DOCUMENT_AI_TIMEOUT=60
```

Do **not** add real values here or to `.env` — the user fills those in from the dashboard/host themselves (per their explicit instruction).

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock .env.example
git commit -m "Add google/cloud-document-ai dependency and document required env keys"
```

---

## Task 2.1: `GoogleDocumentAiClient` implementation

**Files:**
- Create: `app/Services/Ocr/GoogleDocumentAiClient.php`

**Interfaces:**
- Consumes: `App\Services\Ocr\OcrProviderInterface` (implements it), `config('ocr.google_document_ai.*')` from Task 1.0/2.0.
- Produces: `GoogleDocumentAiClient::recognize(string $absolutePath, ?string $mime = null): array` — same return shape as `PaddleOcrClient::recognize()` (documented in `OcrProviderInterface`), so `MediaOcrHandler` and `DocumentDataExtractor` (Task 6.0) can treat both providers identically. Task 6.0 consumes `$result['text']` from this method's return value.

- [ ] **Step 1: Implement the client**

Create `app/Services/Ocr/GoogleDocumentAiClient.php`:

```php
<?php

namespace App\Services\Ocr;

use Google\Cloud\DocumentAI\V1\Client\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\ProcessRequest;
use Google\Cloud\DocumentAI\V1\RawDocument;
use Illuminate\Support\Facades\Log;

class GoogleDocumentAiClient implements OcrProviderInterface
{
    public function recognize(string $absolutePath, ?string $mime = null): array
    {
        if (! config('ocr.enabled', true)) {
            return $this->failure('ocr_disabled');
        }

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return $this->failure('file_not_found');
        }

        $projectId = (string) config('ocr.google_document_ai.project_id');
        $location = (string) config('ocr.google_document_ai.location', 'us');
        $processorId = (string) config('ocr.google_document_ai.processor_id');
        $credentialsPath = (string) config('ocr.google_document_ai.credentials_path');

        if ($projectId === '' || $processorId === '' || $credentialsPath === '') {
            return $this->failure('ocr_not_configured');
        }

        $mime = strtolower(trim((string) $mime)) ?: $this->guessMime($absolutePath);
        $allowedMimes = (array) config('ocr.allowed_mimes', []);

        if ($mime !== '' && ! in_array($mime, $allowedMimes, true)) {
            return $this->failure('unsupported_media_type');
        }

        try {
            $client = new DocumentProcessorServiceClient([
                'credentials' => $credentialsPath,
            ]);

            $name = $client->processorName($projectId, $location, $processorId);

            $rawDocument = (new RawDocument())
                ->setContent(file_get_contents($absolutePath))
                ->setMimeType($mime !== '' ? $mime : 'application/octet-stream');

            $request = (new ProcessRequest())
                ->setName($name)
                ->setRawDocument($rawDocument);

            $response = $client->process($request);
            $document = $response->getDocument();

            $text = trim((string) $document->getText());

            if ($text === '') {
                return $this->failure('ocr_no_text_detected');
            }

            return [
                'ok' => true,
                'text' => $text,
                'lines' => [],
                'pages' => [],
                'average_confidence' => null,
                'document' => [],
                'display_text' => $text,
                'engine' => 'google_document_ai',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::error('Google Document AI request failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->failure('ocr_service_unavailable');
        } finally {
            if (isset($client)) {
                $client->close();
            }
        }
    }

    private function guessMime(string $absolutePath): string
    {
        $mime = mime_content_type($absolutePath);

        return is_string($mime) ? strtolower($mime) : '';
    }

    private function failure(string $error): array
    {
        return [
            'ok' => false,
            'text' => '',
            'lines' => [],
            'pages' => [],
            'average_confidence' => null,
            'document' => [],
            'display_text' => '',
            'engine' => 'google_document_ai',
            'error' => $error,
        ];
    }
}
```

Note: `DocumentProcessorServiceClient`, `ProcessRequest`, `RawDocument` are the standard `google/cloud-document-ai` v1 client classes. If Task 2.0's `composer require` pulled a different major version with a different namespace/class shape, check `vendor/google/cloud-document-ai/src/V1/Client/DocumentProcessorServiceClient.php` for the actual constructor/method signatures and adjust this file to match — the return-array shape (the part every other task depends on) stays identical either way.

- [ ] **Step 2: Commit**

```bash
git add app/Services/Ocr/GoogleDocumentAiClient.php
git commit -m "Add GoogleDocumentAiClient OCR provider"
```

---

## Task 2.2: `GoogleDocumentAiClient` unit tests

**Files:**
- Test: `tests/Unit/GoogleDocumentAiClientTest.php`

**Interfaces:**
- Consumes: `GoogleDocumentAiClient` from Task 2.1.

- [ ] **Step 1: Write the failing test for the "not configured" guard**

Create `tests/Unit/GoogleDocumentAiClientTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Ocr\GoogleDocumentAiClient;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class GoogleDocumentAiClientTest extends TestCase
{
    public function test_returns_failure_when_not_configured(): void
    {
        Config::set('ocr.google_document_ai.project_id', '');
        Config::set('ocr.google_document_ai.processor_id', '');
        Config::set('ocr.google_document_ai.credentials_path', '');

        $result = (new GoogleDocumentAiClient())->recognize(__FILE__, 'text/plain');

        $this->assertFalse($result['ok']);
        $this->assertSame('ocr_not_configured', $result['error']);
    }

    public function test_returns_failure_when_file_missing(): void
    {
        Config::set('ocr.google_document_ai.project_id', 'motocyclaty-ocr');
        Config::set('ocr.google_document_ai.processor_id', 'abc123');
        Config::set('ocr.google_document_ai.credentials_path', '/tmp/does-not-exist.json');

        $result = (new GoogleDocumentAiClient())->recognize('/tmp/nonexistent-file.jpg', 'image/jpeg');

        $this->assertFalse($result['ok']);
        $this->assertSame('file_not_found', $result['error']);
    }
}
```

- [ ] **Step 2: Run to verify both pass**

Run: `php artisan test --filter=GoogleDocumentAiClientTest`
Expected: `PASS` (2 tests). These two guard-clause tests don't touch the network, so they run without real Google credentials — that's intentional; a live-credentials smoke test is part of Task 10.0's manual checklist instead.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/GoogleDocumentAiClientTest.php
git commit -m "Add GoogleDocumentAiClient guard-clause unit tests"
```

---

## Task 3.0: Migration — `installment_requests.whatsapp_conversation_id`

**Files:**
- Create: `database/migrations/2026_08_20_000001_add_whatsapp_conversation_id_to_installment_requests_table.php`

**Interfaces:**
- Produces: `installment_requests.whatsapp_conversation_id` nullable unsigned big integer, foreign key to `whatsapp_conversations.id`, `nullOnDelete()`. Task 3.1, 5.0, and 9.0 all read/write this column.

- [ ] **Step 1: Create the migration**

Run: `php artisan make:migration add_whatsapp_conversation_id_to_installment_requests_table --table=installment_requests`

Replace its contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            $table->foreignId('whatsapp_conversation_id')
                ->nullable()
                ->after('id')
                ->constrained('whatsapp_conversations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_conversation_id');
        });
    }
};
```

- [ ] **Step 2: Run the migration locally**

Run: `php artisan migrate`
Expected: `Migrating: ..._add_whatsapp_conversation_id_to_installment_requests_table` then `Migrated:` with no errors.

- [ ] **Step 3: Verify the column exists**

Run: `php artisan tinker --execute="echo in_array('whatsapp_conversation_id', Schema::getColumnListing('installment_requests')) ? 'yes' : 'no';"`
Expected: `yes`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_08_20_000001_add_whatsapp_conversation_id_to_installment_requests_table.php
git commit -m "Add whatsapp_conversation_id to installment_requests"
```

---

## Task 3.1: `InstallmentRequest` model relation + fillable update

**Files:**
- Modify: `app/Models/InstallmentRequest.php`
- Test: `tests/Feature/InstallmentRequestWhatsappConversationTest.php`

**Interfaces:**
- Consumes: migration from Task 3.0.
- Produces: `InstallmentRequest::whatsappConversation(): BelongsTo`, and `whatsapp_conversation_id` added to `$fillable`. Task 4.0/5.0 call `$installmentRequest->whatsappConversation` to get the `phone`/`whatsapp_bot_id` needed to send a WhatsApp message.

- [ ] **Step 1: Add the column to `$fillable` and add the relation**

In `app/Models/InstallmentRequest.php`, add `'whatsapp_conversation_id',` to the `$fillable` array (right after `'machine_id',`), and add this method near `staff()`/`machine()`:

```php
public function whatsappConversation()
{
    return $this->belongsTo(\App\Models\WhatsappConversation::class, 'whatsapp_conversation_id');
}
```

- [ ] **Step 2: Write the test**

Create `tests/Feature/InstallmentRequestWhatsappConversationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\InstallmentRequest;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallmentRequestWhatsappConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_whatsapp_conversation(): void
    {
        $conversation = WhatsappConversation::create([
            'whatsapp_bot_id' => 70,
            'phone' => '201000000000@s.whatsapp.net',
            'status' => 'active',
        ]);

        $request = InstallmentRequest::create([
            'whatsapp_conversation_id' => $conversation->id,
            'applicant_name' => 'Test Customer',
            'status' => 'pending',
        ]);

        $this->assertTrue($request->whatsappConversation->is($conversation));
    }
}
```

- [ ] **Step 3: Run the test**

Run: `php artisan test --filter=InstallmentRequestWhatsappConversationTest`
Expected: `PASS` (1 test).

- [ ] **Step 4: Commit**

```bash
git add app/Models/InstallmentRequest.php tests/Feature/InstallmentRequestWhatsappConversationTest.php
git commit -m "Add whatsappConversation relation to InstallmentRequest"
```

---

## Task 4.0: `SendWhatsappStatusNotification` queued job

**Files:**
- Create: `app/Jobs/SendWhatsappStatusNotification.php`

**Interfaces:**
- Consumes: `InstallmentRequest` model (with `whatsappConversation` relation from Task 3.1), `config('services.whatsapp.bot_token')` (existing, used by `ProcessWhatsappMessageJobs::sendWhatsappText()` at `app/Console/Commands/ProcessWhatsappMessageJobs.php:215`), `env('WHATSAPP_WORKER_URL')`.
- Produces: `new SendWhatsappStatusNotification(int $installmentRequestId)` — dispatchable job. Task 5.0 dispatches this. `SendWhatsappStatusNotification::buildMessage(InstallmentRequest $request): ?string` is a public static method later tasks (4.1's tests) call directly to check message content without dispatching.

- [ ] **Step 1: Implement the job**

Create `app/Jobs/SendWhatsappStatusNotification.php`:

```php
<?php

namespace App\Jobs;

use App\Models\InstallmentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWhatsappStatusNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $installmentRequestId)
    {
    }

    public function handle(): void
    {
        $request = InstallmentRequest::with('whatsappConversation')->find($this->installmentRequestId);

        if (! $request || ! $request->whatsappConversation) {
            Log::warning('SendWhatsappStatusNotification: no conversation to notify', [
                'installment_request_id' => $this->installmentRequestId,
            ]);

            return;
        }

        $message = self::buildMessage($request);

        if ($message === null) {
            return;
        }

        $conversation = $request->whatsappConversation;
        $url = rtrim((string) env('WHATSAPP_WORKER_URL', 'http://127.0.0.1:3080'), '/') . '/send-message';

        $response = Http::connectTimeout(10)
            ->timeout(60)
            ->withHeaders([
                'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                'Accept' => 'application/json',
            ])
            ->post($url, [
                'bot_id' => (string) $conversation->whatsapp_bot_id,
                'jid' => $conversation->phone,
                'message' => $message,
            ]);

        if (! $response->successful() || ! $response->json('ok')) {
            Log::error('SendWhatsappStatusNotification failed', [
                'installment_request_id' => $this->installmentRequestId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $this->fail(new \RuntimeException('WhatsApp status notification send failed: ' . $response->status()));
        }
    }

    public static function buildMessage(InstallmentRequest $request): ?string
    {
        $reason = trim((string) ($request->checks_report ?? ''));

        return match ($request->status) {
            'approved' => "مبروك يا فندم، طلبك اتوافق عليه.\nتعالى المعرض أو الفرع تكمل باقي الإجراءات.",
            'paused' => $reason !== ''
                ? "طلبك متوقف حاليًا للسبب ده:\n{$reason}"
                : 'طلبك متوقف حاليًا، هنتواصل معاك بالتفاصيل قريب.',
            'rejected' => $reason !== ''
                ? "للأسف طلبك اترفض للسبب ده:\n{$reason}"
                : 'للأسف طلبك اترفض.',
            default => null,
        };
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Jobs/SendWhatsappStatusNotification.php
git commit -m "Add SendWhatsappStatusNotification job"
```

---

## Task 4.1: Job unit tests

**Files:**
- Test: `tests/Feature/SendWhatsappStatusNotificationTest.php`

**Interfaces:**
- Consumes: `SendWhatsappStatusNotification` from Task 4.0.

- [ ] **Step 1: Write message-content tests (no HTTP involved)**

Create `tests/Feature/SendWhatsappStatusNotificationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappStatusNotification;
use App\Models\InstallmentRequest;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendWhatsappStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_message_content(): void
    {
        $request = new InstallmentRequest(['status' => 'approved', 'checks_report' => null]);

        $message = SendWhatsappStatusNotification::buildMessage($request);

        $this->assertStringContainsString('اتوافق عليه', $message);
    }

    public function test_paused_message_includes_reason(): void
    {
        $request = new InstallmentRequest(['status' => 'paused', 'checks_report' => 'محتاجين مفردات مرتب أحدث']);

        $message = SendWhatsappStatusNotification::buildMessage($request);

        $this->assertStringContainsString('محتاجين مفردات مرتب أحدث', $message);
    }

    public function test_rejected_message_without_reason_is_generic(): void
    {
        $request = new InstallmentRequest(['status' => 'rejected', 'checks_report' => null]);

        $message = SendWhatsappStatusNotification::buildMessage($request);

        $this->assertSame('للأسف طلبك اترفض.', $message);
    }

    public function test_other_statuses_produce_no_message(): void
    {
        $request = new InstallmentRequest(['status' => 'pending', 'checks_report' => null]);

        $this->assertNull(SendWhatsappStatusNotification::buildMessage($request));
    }

    public function test_job_posts_to_whatsapp_worker_url(): void
    {
        Http::fake([
            '*/send-message' => Http::response(['ok' => true], 200),
        ]);

        $conversation = WhatsappConversation::create([
            'whatsapp_bot_id' => 70,
            'phone' => '201000000000@s.whatsapp.net',
            'status' => 'active',
        ]);

        $request = InstallmentRequest::create([
            'whatsapp_conversation_id' => $conversation->id,
            'applicant_name' => 'Test Customer',
            'status' => 'approved',
        ]);

        (new SendWhatsappStatusNotification($request->id))->handle();

        Http::assertSent(function ($sentRequest) use ($conversation) {
            return $sentRequest['jid'] === $conversation->phone
                && $sentRequest['bot_id'] === (string) $conversation->whatsapp_bot_id
                && str_contains($sentRequest['message'], 'اتوافق عليه');
        });
    }
}
```

- [ ] **Step 2: Run the tests**

Run: `php artisan test --filter=SendWhatsappStatusNotificationTest`
Expected: `PASS` (5 tests).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/SendWhatsappStatusNotificationTest.php
git commit -m "Add SendWhatsappStatusNotification tests"
```

---

## Task 5.0: `InstallmentRequestObserver`

**Files:**
- Create: `app/Observers/InstallmentRequestObserver.php`

**Interfaces:**
- Consumes: `SendWhatsappStatusNotification` from Task 4.0.
- Produces: `InstallmentRequestObserver::updating(InstallmentRequest $request): void` — dispatches the notification job whenever `status` changes to `approved`/`paused`/`rejected`. Task 5.1 registers this class.

- [ ] **Step 1: Implement the observer**

Create `app/Observers/InstallmentRequestObserver.php`:

```php
<?php

namespace App\Observers;

use App\Jobs\SendWhatsappStatusNotification;
use App\Models\InstallmentRequest;

class InstallmentRequestObserver
{
    private const NOTIFIABLE_STATUSES = ['approved', 'paused', 'rejected'];

    public function updating(InstallmentRequest $request): void
    {
        if (! $request->isDirty('status')) {
            return;
        }

        if (! in_array($request->status, self::NOTIFIABLE_STATUSES, true)) {
            return;
        }

        SendWhatsappStatusNotification::dispatch($request->id);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Observers/InstallmentRequestObserver.php
git commit -m "Add InstallmentRequestObserver"
```

---

## Task 5.1: Register the observer

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Consumes: `InstallmentRequestObserver` from Task 5.0.

- [ ] **Step 1: Register it in `boot()`**

In `app/Providers/AppServiceProvider.php`, add the import and register call:

```php
use App\Models\InstallmentRequest;
use App\Observers\InstallmentRequestObserver;
```

```php
public function boot(): void
{
    InstallmentRequest::observe(InstallmentRequestObserver::class);
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "Register InstallmentRequestObserver"
```

---

## Task 5.2: Observer feature tests

**Files:**
- Test: `tests/Feature/InstallmentRequestObserverTest.php`

**Interfaces:**
- Consumes: registered observer from Task 5.1, `SendWhatsappStatusNotification` from Task 4.0.

- [ ] **Step 1: Write the tests using `Queue::fake()`**

Create `tests/Feature/InstallmentRequestObserverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappStatusNotification;
use App\Models\InstallmentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InstallmentRequestObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_change_to_approved_dispatches_notification(): void
    {
        Queue::fake();

        $request = InstallmentRequest::create(['status' => 'pending', 'applicant_name' => 'Test']);
        $request->update(['status' => 'approved']);

        Queue::assertPushed(SendWhatsappStatusNotification::class, function ($job) use ($request) {
            return $job->installmentRequestId === $request->id;
        });
    }

    public function test_status_change_to_pending_does_not_dispatch(): void
    {
        Queue::fake();

        $request = InstallmentRequest::create(['status' => 'new', 'applicant_name' => 'Test']);
        $request->update(['status' => 'pending']);

        Queue::assertNotPushed(SendWhatsappStatusNotification::class);
    }

    public function test_unrelated_field_change_does_not_dispatch(): void
    {
        Queue::fake();

        $request = InstallmentRequest::create(['status' => 'approved', 'applicant_name' => 'Test']);
        $request->update(['applicant_name' => 'Updated Name']);

        Queue::assertNotPushed(SendWhatsappStatusNotification::class);
    }
}
```

- [ ] **Step 2: Run the tests**

Run: `php artisan test --filter=InstallmentRequestObserverTest`
Expected: `PASS` (3 tests).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/InstallmentRequestObserverTest.php
git commit -m "Add InstallmentRequestObserver tests"
```

---

## Task 6.0: `DocumentDataExtractor`

**Files:**
- Create: `app/Services/DocumentDataExtractor.php`

**Interfaces:**
- Consumes: `App\Services\GeminiClient::generateText()` (existing, `app/Services/GeminiClient.php`), `App\Services\AiMemoryResolver` (existing, `app/Services/AiMemoryResolver.php`).
- Produces: `DocumentDataExtractor::extract(string $ocrText, string $documentType, string $incomeCategory): array` returning `['valid' => bool, 'fields' => array, 'violation_message' => ?string]`. Task 9.0 consumes `fields` to populate `InstallmentRequest` columns; Task 8.1 consumes `violation_message` to reply to the customer.

- [ ] **Step 1: Implement the service**

Create `app/Services/DocumentDataExtractor.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DocumentDataExtractor
{
    /** Maps document types to the ai_memories title holding their category rules. */
    private const CATEGORY_MEMORY_TITLES = [
        'government' => 'الموظفين',
        'private_insured' => 'الموظفين',
        'private_uninsured' => 'الموظفين',
        'army' => 'الموظفين',
        'pension' => 'أصحاب المعاشات',
        'business' => 'أصحاب الأنشطة التجارية',
        'freelance' => 'أصحاب المهن الحرة',
    ];

    public function extract(string $ocrText, string $documentType, string $incomeCategory): array
    {
        $rules = $this->rulesFor($incomeCategory);
        $nameRule = $this->rulesFor('id_card_name'); // memory #57, always applies to id_card

        $prompt = $this->prompt($ocrText, $documentType, $rules, $nameRule);

        try {
            $result = app(GeminiClient::class)->generateText($prompt, null, [
                'temperature' => 0.0,
                'maxOutputTokens' => 500,
            ]);

            if (! ($result['ok'] ?? false)) {
                return $this->fallback();
            }

            $json = $this->extractJson(trim((string) ($result['reply'] ?? $result['text'] ?? '')));

            return is_array($json) ? $this->normalize($json) : $this->fallback();
        } catch (\Throwable $e) {
            Log::error('DocumentDataExtractor failed', ['error' => $e->getMessage()]);

            return $this->fallback();
        }
    }

    private function rulesFor(string $incomeCategory): string
    {
        if ($incomeCategory === 'id_card_name') {
            $memory = app(AiMemoryResolver::class)->memoryByExactTitle('استخراج الاسم من البطاقه');

            return $memory ? (string) ($memory->content ?? $memory->body ?? '') : '';
        }

        $title = self::CATEGORY_MEMORY_TITLES[$incomeCategory] ?? null;

        if (! $title) {
            return '';
        }

        $memory = app(AiMemoryResolver::class)->memoryByExactTitle($title);

        return $memory ? (string) ($memory->content ?? $memory->body ?? '') : '';
    }

    private function prompt(string $ocrText, string $documentType, string $rules, string $nameRule): string
    {
        return <<<PROMPT
أنت مستخرج ومدقق بيانات مستندات لمعرض موتوسيكلات.

ممنوع ترد على العميل. ممنوع تشرح. رجع JSON فقط.

نوع المستند: {$documentType}

قواعد الفئة (من الداش بورد، اعتبرها المصدر الوحيد للصحة):
{$rules}

قاعدة استخراج الاسم:
{$nameRule}

النص المستخرج بالـ OCR من المستند:
{$ocrText}

استخرج الحقول المتاحة فقط من النص. اترك أي حقل غير موجود null.
تحقق من مطابقة القواعد أعلاه. لو في أي مخالفة لقاعدة، ضع valid=false واكتب violation_message
برسالة عربية واضحة تشرح للعميل المشكلة بالظبط وايه المطلوب منه يعمله.
لو كل حاجة مطابقة للقواعد، valid=true و violation_message=null.

رجع JSON بهذا الشكل فقط:

{
  "valid": true,
  "violation_message": null,
  "fields": {
    "full_name": null,
    "national_id": null,
    "birthdate": null,
    "age": null,
    "salary_amount": null,
    "document_issue_date": null,
    "employment_start_date": null,
    "pension_amount": null,
    "business_name": null,
    "commercial_reg_number": null,
    "tax_card_number": null
  }
}
PROMPT;
    }

    private function normalize(array $json): array
    {
        return [
            'valid' => (bool) ($json['valid'] ?? false),
            'violation_message' => $json['violation_message'] ?? null,
            'fields' => is_array($json['fields'] ?? null) ? $json['fields'] : [],
        ];
    }

    private function fallback(): array
    {
        return [
            'valid' => false,
            'violation_message' => 'مقدرتش أقرا بيانات المستند ده، ممكن تبعته تاني بصورة أوضح؟',
            'fields' => [],
        ];
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/^```\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        if (preg_match('/\{.*\}/su', $text, $m)) {
            $text = $m[0];
        }

        $data = json_decode($text, true);

        return is_array($data) ? $data : null;
    }
}
```

Note: `$this->rulesFor('id_card_name')` deliberately routes through the same `rulesFor()` method with a sentinel key so the id-card name-merging rule (memory #57, "استخراج الاسم من البطاقه") is always included regardless of income category — it applies to every ID card, not just one category.

- [ ] **Step 2: Commit**

```bash
git add app/Services/DocumentDataExtractor.php
git commit -m "Add DocumentDataExtractor service"
```

---

## Task 6.1: `DocumentDataExtractor` unit tests

**Files:**
- Test: `tests/Feature/DocumentDataExtractorTest.php`

**Interfaces:**
- Consumes: `DocumentDataExtractor` from Task 6.0, `GeminiClient` (faked via HTTP).

- [ ] **Step 1: Write tests faking the Gemini HTTP call**

Create `tests/Feature/DocumentDataExtractorTest.php`. This fakes the outbound Gemini HTTP call the same way `GeminiClient` makes it (`POST https://generativelanguage.googleapis.com/v1beta/models/*:generateContent`), so no real API key or `ai_memories` row is required for the "valid" case (the "invalid" case does need a real memory row, so it uses `RefreshDatabase` and creates one):

```php
<?php

namespace Tests\Feature;

use App\Models\AiMemory;
use App\Models\GeminiApiKey;
use App\Models\GeminiApiKeyModel;
use App\Services\DocumentDataExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentDataExtractorTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGeminiKey(): void
    {
        $key = GeminiApiKey::create(['name' => 'test', 'api_key' => 'test-key', 'is_active' => true]);
        GeminiApiKeyModel::create([
            'gemini_api_key_id' => $key->id,
            'provider' => 'gemini',
            'model_code' => 'gemini-3.1-flash-lite',
            'is_active' => true,
            'rpm_limit' => 100,
            'rpd_limit' => 1000,
            'tps_limit' => 1000000,
        ]);
    }

    public function test_valid_document_returns_no_violation(): void
    {
        $this->fakeGeminiKey();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'valid' => true,
                            'violation_message' => null,
                            'fields' => ['full_name' => 'Ahmed Mohamed', 'salary_amount' => 6000],
                        ]),
                    ]]],
                ]],
            ], 200),
        ]);

        $result = app(DocumentDataExtractor::class)->extract('OCR text here', 'salary_slip', 'private_insured');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['violation_message']);
        $this->assertSame('Ahmed Mohamed', $result['fields']['full_name']);
    }

    public function test_invalid_document_returns_violation_message(): void
    {
        $this->fakeGeminiKey();

        AiMemory::create(['title' => 'أصحاب المعاشات', 'content' => 'السن من 21 لـ 62 سنة. المعاش ما يقلش عن 4000.']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'valid' => false,
                            'violation_message' => 'المعاش المكتوب أقل من الحد الأدنى المطلوب (4000 جنيه).',
                            'fields' => ['pension_amount' => 3000],
                        ]),
                    ]]],
                ]],
            ], 200),
        ]);

        $result = app(DocumentDataExtractor::class)->extract('OCR text here', 'pension_statement', 'pension');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('أقل من الحد الأدنى', $result['violation_message']);
    }

    public function test_gemini_failure_falls_back_to_invalid_with_retry_message(): void
    {
        $this->fakeGeminiKey();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 500),
        ]);

        $result = app(DocumentDataExtractor::class)->extract('OCR text', 'id_card', 'private_insured');

        $this->assertFalse($result['valid']);
        $this->assertNotNull($result['violation_message']);
    }
}
```

If `GeminiApiKey`/`GeminiApiKeyModel` fillable fields differ from what's used above, check `tests/Feature/GeminiRateLimitTest.php`'s `createModel()` helper for the exact working column set and match it.

- [ ] **Step 2: Run the tests**

Run: `php artisan test --filter=DocumentDataExtractorTest`
Expected: `PASS` (3 tests).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/DocumentDataExtractorTest.php
git commit -m "Add DocumentDataExtractor tests"
```

---

## Task 7.0: `ApplicationHandler` cash/installment branch

**Files:**
- Modify: `app/Services/Handlers/ApplicationHandler.php`
- Test: `tests/Feature/ApplicationHandlerCashBranchTest.php`

**Interfaces:**
- Consumes: existing `AiIntentClassifier::classify()` call already in `ApplicationHandler::handle()`.
- Produces: `ApplicationHandler::handle()` replies with a showroom message and stops immediately for cash, otherwise proceeds to the existing text-field collection (unchanged) before Task 7.1's category detection runs. Adds `payment_type` (`'cash'|'installment'`) to `$application` in `context_payload`, which Task 7.1 reads.

- [ ] **Step 1: Add payment-type detection at the top of `handle()`**

In `app/Services/Handlers/ApplicationHandler.php`, right after the existing `$application = $payload['application'] ?? [];` line, add:

```php
$paymentType = $application['payment_type'] ?? $this->detectPaymentType($message);

if ($paymentType === 'cash') {
    $application['payment_type'] = 'cash';
    $this->saveState($conversation, $application, []);

    return $this->reply(
        $conversation,
        'تمام يا فندم، الدفع كاش يتم في المعرض مباشرة. تعالى المعرض وهيتم استكمال باقي الإجراءات هناك.'
    );
}

$application['payment_type'] = 'installment';
```

Add the detection helper near `mergeApplicationData()`:

```php
private function detectPaymentType(string $message): ?string
{
    $m = mb_strtolower(trim($message));

    if (str_contains($m, 'كاش') || str_contains($m, 'كاش فلوس') || str_contains($m, 'نقدي')) {
        return 'cash';
    }

    if (str_contains($m, 'قسط') || str_contains($m, 'تقسيط')) {
        return 'installment';
    }

    return null;
}
```

If `detectPaymentType()` returns `null` (payment type not yet mentioned), the existing flow falls through unchanged and defers the branch to a later turn — that's fine, `$application['payment_type']` stays unset until the customer says one or the other, and this same top-of-`handle()` block re-checks the message every turn.

- [ ] **Step 2: Write the cash-branch test**

Create `tests/Feature/ApplicationHandlerCashBranchTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\WhatsappConversation;
use App\Services\Handlers\ApplicationHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationHandlerCashBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_payment_replies_with_showroom_message_and_no_document_flow(): void
    {
        $machine = Machine::create(['name' => 'Test Machine', 'cash_price' => 50000]);

        $conversation = WhatsappConversation::create([
            'whatsapp_bot_id' => 70,
            'phone' => '201000000000@s.whatsapp.net',
            'status' => 'active',
            'last_machine_id' => $machine->id,
        ]);

        $result = app(ApplicationHandler::class)->handle($conversation, 'عاوز اقدم كاش');

        $this->assertStringContainsString('المعرض', $result['reply']);
        $this->assertSame('cash', $conversation->fresh()->context_payload['application']['payment_type'] ?? null);
    }
}
```

If `Machine::create()` fails on a required column not listed here, check `Schema::getColumnListing('machines')` and add the missing required fields to the `create()` call.

- [ ] **Step 3: Run the test**

Run: `php artisan test --filter=ApplicationHandlerCashBranchTest`
Expected: `PASS` (1 test).

- [ ] **Step 4: Commit**

```bash
git add app/Services/Handlers/ApplicationHandler.php tests/Feature/ApplicationHandlerCashBranchTest.php
git commit -m "ApplicationHandler: branch cash payments to a showroom reply"
```

---

## Task 7.1: Income category detection + required-document resolver

**Files:**
- Create: `app/Services/RequiredDocumentsResolver.php`
- Test: `tests/Feature/RequiredDocumentsResolverTest.php`

**Interfaces:**
- Consumes: `job_type` field already extracted by the existing `AiIntentClassifier` application-extraction call in `ApplicationHandler` (`application_data_extraction` mode, `app/Services/AiIntentClassifier.php:374-459`).
- Produces: `RequiredDocumentsResolver::categoryFor(string $jobTypeText): string` (one of `government|private_insured|private_uninsured|army|pension|business|freelance|unknown`) and `RequiredDocumentsResolver::documentsFor(string $category): array` — an ordered list of document-type strings (e.g. `['id_card_front', 'id_card_back', 'salary_slip']`). Task 7.2 tests both; Task 8.0 consumes `documentsFor()` to drive the collection loop.

- [ ] **Step 1: Implement the resolver**

Create `app/Services/RequiredDocumentsResolver.php`:

```php
<?php

namespace App\Services;

class RequiredDocumentsResolver
{
    private const UNIVERSAL_DOCUMENTS = ['id_card_front', 'id_card_back'];

    private const CATEGORY_DOCUMENTS = [
        'government' => ['salary_slip'],
        'private_insured' => ['salary_slip'],
        'private_uninsured' => ['salary_slip'],
        'army' => ['bank_statement'],
        'pension' => ['pension_statement'],
        'business' => ['activity_photo', 'commercial_reg', 'tax_card'],
        'freelance' => [],
    ];

    public function categoryFor(string $jobTypeText): string
    {
        $text = mb_strtolower(trim($jobTypeText));

        if ($text === '') {
            return 'unknown';
        }

        if (str_contains($text, 'معاش') || str_contains($text, 'متقاعد')) {
            return 'pension';
        }

        if (str_contains($text, 'جيش') || str_contains($text, 'قوات مسلحة')) {
            return 'army';
        }

        if (str_contains($text, 'حكوم') || str_contains($text, 'موظف حكومي')) {
            return 'government';
        }

        if (str_contains($text, 'تجاري') || str_contains($text, 'محل') || str_contains($text, 'نشاط') || str_contains($text, 'صاحب عمل')) {
            return 'business';
        }

        if (str_contains($text, 'نجار') || str_contains($text, 'سباك') || str_contains($text, 'حداد')
            || str_contains($text, 'كهربائي') || str_contains($text, 'نقاش') || str_contains($text, 'حر')) {
            return 'freelance';
        }

        if (str_contains($text, 'قطاع خاص') || str_contains($text, 'خاص')) {
            return str_contains($text, 'مؤمن') ? 'private_insured' : 'private_uninsured';
        }

        return 'unknown';
    }

    public function documentsFor(string $category): array
    {
        return array_merge(
            self::UNIVERSAL_DOCUMENTS,
            self::CATEGORY_DOCUMENTS[$category] ?? []
        );
    }
}
```

Note the category keywords are seeded from `ai_memories` #43-46 content read during Task 6 exploration ("الموظفين", "أصحاب المعاشات", "أصحاب الأنشطة التجارية", "أصحاب المهن الحرة"). The document *lists* are intentionally hardcoded here (they're structural — "which files do we ask for", not a validation *rule*); the validation *rules* applied to those documents' content stay entirely in `DocumentDataExtractor` (Task 6.0), sourced from memory at runtime, matching the global constraint.

- [ ] **Step 2: Write the tests**

Create `tests/Feature/RequiredDocumentsResolverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\RequiredDocumentsResolver;
use Tests\TestCase;

class RequiredDocumentsResolverTest extends TestCase
{
    public function test_categorizes_pension(): void
    {
        $resolver = new RequiredDocumentsResolver();

        $this->assertSame('pension', $resolver->categoryFor('صاحب معاش'));
        $this->assertContains('pension_statement', $resolver->documentsFor('pension'));
    }

    public function test_categorizes_business_owner(): void
    {
        $resolver = new RequiredDocumentsResolver();

        $this->assertSame('business', $resolver->categoryFor('صاحب نشاط تجاري'));
        $this->assertEqualsCanonicalizing(
            ['id_card_front', 'id_card_back', 'activity_photo', 'commercial_reg', 'tax_card'],
            $resolver->documentsFor('business')
        );
    }

    public function test_categorizes_freelancer_without_extra_documents(): void
    {
        $resolver = new RequiredDocumentsResolver();

        $this->assertSame('freelance', $resolver->categoryFor('نجار'));
        $this->assertEqualsCanonicalizing(
            ['id_card_front', 'id_card_back'],
            $resolver->documentsFor('freelance')
        );
    }

    public function test_unknown_job_type_falls_back_to_universal_documents_only(): void
    {
        $resolver = new RequiredDocumentsResolver();

        $this->assertSame('unknown', $resolver->categoryFor('حاجة غريبة جدا'));
        $this->assertEqualsCanonicalizing(
            ['id_card_front', 'id_card_back'],
            $resolver->documentsFor('unknown')
        );
    }
}
```

- [ ] **Step 3: Run the tests**

Run: `php artisan test --filter=RequiredDocumentsResolverTest`
Expected: `PASS` (4 tests).

- [ ] **Step 4: Commit**

```bash
git add app/Services/RequiredDocumentsResolver.php tests/Feature/RequiredDocumentsResolverTest.php
git commit -m "Add RequiredDocumentsResolver for income-category document lists"
```

---

## Task 7.2: Wire the resolver into `ApplicationHandler`

**Files:**
- Modify: `app/Services/Handlers/ApplicationHandler.php`
- Test: `tests/Feature/ApplicationHandlerDocumentQueueTest.php`

**Interfaces:**
- Consumes: `RequiredDocumentsResolver` from Task 7.1, existing `job_type` extraction.
- Produces: once the existing 8 text fields are complete (existing `missingFields()` check returns empty) **and** `payment_type === 'installment'`, `$application['required_documents']` (ordered array) and `$application['collected_documents']` (assoc array, initially empty) are set and saved to `context_payload`. Task 8.0 reads `required_documents` / `collected_documents` to know which document to ask for next.

- [ ] **Step 1: Replace the "basic data complete" reply with document-queue setup**

In `app/Services/Handlers/ApplicationHandler.php`, find the existing block:

```php
if (!empty($missing)) {
    return $this->reply($conversation, $this->questionForMissing($missing, $application));
}

return $this->reply(
    $conversation,
    "تمام يا فندم، كده البيانات الأساسية مكتملة على {$application['machine_name']}.\nهراجع الطلب وحد من المعرض هيتابع معاك."
);
```

Replace it with:

```php
if (!empty($missing)) {
    return $this->reply($conversation, $this->questionForMissing($missing, $application));
}

if (empty($application['required_documents'])) {
    $resolver = app(\App\Services\RequiredDocumentsResolver::class);
    $category = $resolver->categoryFor((string) ($application['job_type'] ?? ''));

    $application['income_category'] = $category;
    $application['required_documents'] = $resolver->documentsFor($category);
    $application['collected_documents'] = [];

    $this->saveState($conversation, $application, []);
}

$nextDocument = $this->nextRequiredDocument($application);

if ($nextDocument !== null) {
    return $this->reply($conversation, $this->questionForDocument($nextDocument));
}

return $this->reply(
    $conversation,
    'طلبك تحت التنفيذ.'
);
```

Add these two helpers near `questionForMissing()`:

```php
private function nextRequiredDocument(array $application): ?string
{
    $required = $application['required_documents'] ?? [];
    $collected = array_keys($application['collected_documents'] ?? []);

    foreach ($required as $documentType) {
        if (! in_array($documentType, $collected, true)) {
            return $documentType;
        }
    }

    return null;
}

private function questionForDocument(string $documentType): string
{
    $labels = [
        'id_card_front' => 'ابعتلي صورة البطاقة وش',
        'id_card_back' => 'ابعتلي صورة البطاقة ضهر',
        'salary_slip' => 'ابعتلي صورة مفردات المرتب',
        'pension_statement' => 'ابعتلي صورة بيان المعاش',
        'bank_statement' => 'ابعتلي كشف الحساب لآخر 6 شهور',
        'activity_photo' => 'ابعتلي صورة أو فيديو للنشاط',
        'commercial_reg' => 'ابعتلي صورة السجل التجاري',
        'tax_card' => 'ابعتلي صورة البطاقة الضريبية',
    ];

    return 'تمام يا فندم، ' . ($labels[$documentType] ?? "ابعتلي مستند {$documentType}") . '.';
}
```

Note: `nextRequiredDocument()` returning `null` when `$application['required_documents']` is empty (the "طلبك تحت التنفيذ" branch) is a placeholder for Task 9.0's actual `InstallmentRequest` creation — that reply and the row-creation logic get built together in Task 9.0, replacing this bare reply.

- [ ] **Step 2: Write the test**

Create `tests/Feature/ApplicationHandlerDocumentQueueTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\WhatsappConversation;
use App\Services\Handlers\ApplicationHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationHandlerDocumentQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_text_fields_for_pensioner_asks_for_id_card_first(): void
    {
        $machine = Machine::create(['name' => 'Test Machine', 'cash_price' => 50000]);

        $conversation = WhatsappConversation::create([
            'whatsapp_bot_id' => 70,
            'phone' => '201000000000@s.whatsapp.net',
            'status' => 'active',
            'last_machine_id' => $machine->id,
            'context_payload' => [
                'application' => [
                    'payment_type' => 'installment',
                    'full_name' => 'Test Customer',
                    'national_id' => '29001011234567',
                    'phone' => '01000000000',
                    'job_type' => 'صاحب معاش',
                    'income_proof' => 'بيان معاش',
                    'work_address' => 'لا يوجد - متقاعد',
                    'home_address' => 'القاهرة، مدينة نصر، شارع الطيران',
                    'installment_months' => 24,
                ],
            ],
        ]);

        $result = app(ApplicationHandler::class)->handle($conversation, 'تمام');

        $this->assertStringContainsString('البطاقة وش', $result['reply']);

        $application = $conversation->fresh()->context_payload['application'];
        $this->assertSame('pension', $application['income_category']);
        $this->assertContains('pension_statement', $application['required_documents']);
    }
}
```

Note this test seeds `context_payload` directly rather than going through the earlier AI-extraction turns, so it doesn't need a live Gemini call — it's testing the document-queue setup logic in isolation, which is what this task adds.

- [ ] **Step 3: Run the test**

Run: `php artisan test --filter=ApplicationHandlerDocumentQueueTest`
Expected: `PASS` (1 test).

- [ ] **Step 4: Commit**

```bash
git add app/Services/Handlers/ApplicationHandler.php tests/Feature/ApplicationHandlerDocumentQueueTest.php
git commit -m "ApplicationHandler: set up required-document queue after text fields complete"
```

---

## Task 8.0: Per-document collection loop in `MediaOcrHandler`

**Files:**
- Modify: `app/Services/Handlers/MediaOcrHandler.php`
- Test: `tests/Feature/MediaOcrHandlerApplicationFlowTest.php`

**Interfaces:**
- Consumes: `DocumentDataExtractor` from Task 6.0, `application.required_documents` / `application.collected_documents` from Task 7.2.
- Produces: when a conversation's `pending_question` (existing column, used elsewhere as `'application_missing_data'`) is `'application_document_upload'`, `MediaOcrHandler::handle()` routes the uploaded image through OCR → `DocumentDataExtractor` instead of the generic OCR reply. Task 8.1/9.0 extend the valid/invalid branches this task creates.

- [ ] **Step 1: Add the application-flow branch**

In `app/Services/Handlers/MediaOcrHandler.php`, add the import:

```php
use App\Services\DocumentDataExtractor;
```

At the top of `handle()`, right after the existing `foreach ($mediaItems as $item)` loop setup (before that loop, so it only branches once per call), insert:

```php
if (($conversation->pending_question ?? null) === 'application_document_upload' && count($mediaItems) === 1) {
    return $this->handleApplicationDocument($conversation, $mediaItems[0]);
}
```

Add the new method (below `handle()`):

```php
private function handleApplicationDocument(WhatsappConversation $conversation, array $item): array
{
    $path = trim((string) ($item['path'] ?? ''));
    $mime = strtolower(trim((string) ($item['mime'] ?? '')));

    $payload = $conversation->context_payload ?? [];
    if (is_string($payload)) {
        $payload = json_decode($payload, true) ?: [];
    }
    $application = $payload['application'] ?? [];

    $documentType = $this->currentDocumentType($application);

    if ($path === '' || ! $this->isOcrSupported($mime) || $documentType === null) {
        return $this->reply($conversation, 'الصورة دي مش واضحة، ممكن تبعتها تاني؟', 'failed', true);
    }

    $disk = Storage::disk('public');

    if (! $disk->exists($path)) {
        return $this->reply($conversation, 'مقدرتش أستقبل الصورة، ممكن تبعتها تاني؟', 'failed', true);
    }

    $ocr = app(\App\Services\Ocr\OcrProviderInterface::class)->recognize($disk->path($path), $mime);

    if (! ($ocr['ok'] ?? false)) {
        return $this->reply($conversation, 'مقدرتش أقرا بيانات الصورة دي، ممكن تبعتها تاني بصورة أوضح؟', 'failed', true);
    }

    $extraction = app(DocumentDataExtractor::class)->extract(
        (string) $ocr['text'],
        $documentType,
        (string) ($application['income_category'] ?? 'unknown')
    );

    if (! $extraction['valid']) {
        return $this->reply(
            $conversation,
            $extraction['violation_message'] ?? 'في مشكلة في المستند ده، ممكن تبعته تاني؟',
            'invalid',
            false
        );
    }

    $application['collected_documents'][$documentType] = [
        'path' => $path,
        'fields' => $extraction['fields'],
    ];

    $payload['application'] = $application;
    $conversation->forceFill(['context_payload' => $payload])->save();

    return app(\App\Services\Handlers\ApplicationHandler::class)->handle($conversation, '');
}

private function currentDocumentType(array $application): ?string
{
    $required = $application['required_documents'] ?? [];
    $collected = array_keys($application['collected_documents'] ?? []);

    foreach ($required as $documentType) {
        if (! in_array($documentType, $collected, true)) {
            return $documentType;
        }
    }

    return null;
}
```

Note the last line of the "valid" branch re-invokes `ApplicationHandler::handle()` with an empty message — this reuses Task 7.2's `nextRequiredDocument()` logic to ask for the *next* document (or, once Task 9.0 lands, to create the `InstallmentRequest`) instead of duplicating that branching here.

- [ ] **Step 2: Set `pending_question` when `ApplicationHandler` asks for a document**

In `app/Services/Handlers/ApplicationHandler.php`, find `saveState()`:

```php
$conversation->forceFill([
    'last_topic' => 'application',
    'pending_question' => empty($missing) ? null : 'application_missing_data',
    'context_payload' => $payload,
])->save();
```

Change the `pending_question` line to also flag document-collection turns:

```php
$conversation->forceFill([
    'last_topic' => 'application',
    'pending_question' => ! empty($missing)
        ? 'application_missing_data'
        : (($application['payment_type'] ?? null) === 'installment' && $this->nextRequiredDocument($application) !== null
            ? 'application_document_upload'
            : null),
    'context_payload' => $payload,
])->save();
```

`saveState()`'s signature stays `saveState(WhatsappConversation $conversation, array $application, array $missing)` — `$application` already carries `required_documents`/`collected_documents` by the time `saveState()` runs at the end of Task 7.2's new block, so `nextRequiredDocument($application)` here sees current data.

- [ ] **Step 3: Write the test**

Create `tests/Feature/MediaOcrHandlerApplicationFlowTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\WhatsappConversation;
use App\Services\DocumentDataExtractor;
use App\Services\Handlers\MediaOcrHandler;
use App\Services\Ocr\OcrProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaOcrHandlerApplicationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_document_advances_to_next_required_document(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/id_front.jpg', 'fake-bytes');

        $this->app->instance(OcrProviderInterface::class, new class implements OcrProviderInterface {
            public function recognize(string $absolutePath, ?string $mime = null): array
            {
                return ['ok' => true, 'text' => 'ID card text', 'lines' => [], 'pages' => [],
                    'average_confidence' => 0.9, 'document' => [], 'display_text' => 'ID card text',
                    'engine' => 'fake', 'error' => null];
            }
        });

        $this->app->instance(DocumentDataExtractor::class, new class extends DocumentDataExtractor {
            public function extract(string $ocrText, string $documentType, string $incomeCategory): array
            {
                return ['valid' => true, 'violation_message' => null, 'fields' => ['full_name' => 'Test Customer']];
            }
        });

        $machine = Machine::create(['name' => 'Test Machine', 'cash_price' => 50000]);

        $conversation = WhatsappConversation::create([
            'whatsapp_bot_id' => 70,
            'phone' => '201000000000@s.whatsapp.net',
            'status' => 'active',
            'last_machine_id' => $machine->id,
            'pending_question' => 'application_document_upload',
            'context_payload' => [
                'application' => [
                    'payment_type' => 'installment',
                    'full_name' => 'Test Customer',
                    'national_id' => '29001011234567',
                    'phone' => '01000000000',
                    'job_type' => 'صاحب معاش',
                    'income_proof' => 'بيان معاش',
                    'work_address' => 'لا يوجد',
                    'home_address' => 'القاهرة',
                    'installment_months' => 24,
                    'income_category' => 'pension',
                    'required_documents' => ['id_card_front', 'id_card_back', 'pension_statement'],
                    'collected_documents' => [],
                ],
            ],
        ]);

        $result = app(MediaOcrHandler::class)->handle($conversation, [
            ['path' => 'uploads/id_front.jpg', 'mime' => 'image/jpeg', 'filename' => 'id_front.jpg'],
        ]);

        $this->assertStringContainsString('البطاقة ضهر', $result['reply']);

        $collected = $conversation->fresh()->context_payload['application']['collected_documents'];
        $this->assertArrayHasKey('id_card_front', $collected);
    }

    public function test_invalid_document_replies_with_violation_and_does_not_advance(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/slip.jpg', 'fake-bytes');

        $this->app->instance(OcrProviderInterface::class, new class implements OcrProviderInterface {
            public function recognize(string $absolutePath, ?string $mime = null): array
            {
                return ['ok' => true, 'text' => 'slip text', 'lines' => [], 'pages' => [],
                    'average_confidence' => 0.9, 'document' => [], 'display_text' => 'slip text',
                    'engine' => 'fake', 'error' => null];
            }
        });

        $this->app->instance(DocumentDataExtractor::class, new class extends DocumentDataExtractor {
            public function extract(string $ocrText, string $documentType, string $incomeCategory): array
            {
                return ['valid' => false, 'violation_message' => 'المرتب أقل من 4000 جنيه.', 'fields' => []];
            }
        });

        $machine = Machine::create(['name' => 'Test Machine', 'cash_price' => 50000]);

        $conversation = WhatsappConversation::create([
            'whatsapp_bot_id' => 70,
            'phone' => '201000000000@s.whatsapp.net',
            'status' => 'active',
            'last_machine_id' => $machine->id,
            'pending_question' => 'application_document_upload',
            'context_payload' => [
                'application' => [
                    'payment_type' => 'installment',
                    'income_category' => 'pension',
                    'required_documents' => ['pension_statement'],
                    'collected_documents' => [],
                ],
            ],
        ]);

        $result = app(MediaOcrHandler::class)->handle($conversation, [
            ['path' => 'uploads/slip.jpg', 'mime' => 'image/jpeg', 'filename' => 'slip.jpg'],
        ]);

        $this->assertStringContainsString('أقل من 4000', $result['reply']);

        $collected = $conversation->fresh()->context_payload['application']['collected_documents'] ?? [];
        $this->assertArrayNotHasKey('pension_statement', $collected);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=MediaOcrHandlerApplicationFlowTest`
Expected: `PASS` (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Handlers/MediaOcrHandler.php app/Services/Handlers/ApplicationHandler.php tests/Feature/MediaOcrHandlerApplicationFlowTest.php
git commit -m "Wire per-document OCR validation loop into MediaOcrHandler"
```

---

## Task 8.1: Confirm no persistence on invalid documents

**Files:**
- Test: `tests/Feature/MediaOcrHandlerApplicationFlowTest.php` (extend from Task 8.0)

**Interfaces:**
- Consumes: everything from Task 8.0.

- [ ] **Step 1: Add a regression test asserting no `InstallmentRequest` row exists after an invalid document**

Append to `tests/Feature/MediaOcrHandlerApplicationFlowTest.php` (inside the same class):

```php
    public function test_invalid_document_never_creates_an_installment_request(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/slip2.jpg', 'fake-bytes');

        $this->app->instance(OcrProviderInterface::class, new class implements OcrProviderInterface {
            public function recognize(string $absolutePath, ?string $mime = null): array
            {
                return ['ok' => true, 'text' => 'slip text', 'lines' => [], 'pages' => [],
                    'average_confidence' => 0.9, 'document' => [], 'display_text' => 'slip text',
                    'engine' => 'fake', 'error' => null];
            }
        });

        $this->app->instance(DocumentDataExtractor::class, new class extends DocumentDataExtractor {
            public function extract(string $ocrText, string $documentType, string $incomeCategory): array
            {
                return ['valid' => false, 'violation_message' => 'السن أقل من 21 سنة.', 'fields' => []];
            }
        });

        $machine = Machine::create(['name' => 'Test Machine 2', 'cash_price' => 50000]);

        $conversation = WhatsappConversation::create([
            'whatsapp_bot_id' => 70,
            'phone' => '201000000001@s.whatsapp.net',
            'status' => 'active',
            'last_machine_id' => $machine->id,
            'pending_question' => 'application_document_upload',
            'context_payload' => [
                'application' => [
                    'payment_type' => 'installment',
                    'income_category' => 'pension',
                    'required_documents' => ['pension_statement'],
                    'collected_documents' => [],
                ],
            ],
        ]);

        app(MediaOcrHandler::class)->handle($conversation, [
            ['path' => 'uploads/slip2.jpg', 'mime' => 'image/jpeg', 'filename' => 'slip2.jpg'],
        ]);

        $this->assertDatabaseCount('installment_requests', 0);
    }
```

- [ ] **Step 2: Run to verify it passes**

Run: `php artisan test --filter=MediaOcrHandlerApplicationFlowTest`
Expected: `PASS` (3 tests — the two from Task 8.0 plus this one). It should already pass since Task 9.0 (row creation) hasn't been built yet — this test locks in the "no row until Task 9.0's valid path" invariant before that code exists, so a future regression in Task 9.0 gets caught.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/MediaOcrHandlerApplicationFlowTest.php
git commit -m "Add regression test: invalid documents never create InstallmentRequest rows"
```

---

## Task 9.0: `InstallmentRequest` creation on full completion

**Files:**
- Modify: `app/Services/Handlers/ApplicationHandler.php`

**Interfaces:**
- Consumes: `$application['collected_documents'][$type]['fields']` (from Task 8.0), `RequiredDocumentsResolver` (Task 7.1), `InstallmentRequest` model (Task 3.1's `whatsapp_conversation_id`).
- Produces: a real `InstallmentRequest` row with `status = 'pending'`, replacing the placeholder `'طلبك تحت التنفيذ.'` reply added in Task 7.2.

- [ ] **Step 1: Replace the placeholder completion reply**

In `app/Services/Handlers/ApplicationHandler.php`, find the block added in Task 7.2:

```php
$nextDocument = $this->nextRequiredDocument($application);

if ($nextDocument !== null) {
    return $this->reply($conversation, $this->questionForDocument($nextDocument));
}

return $this->reply(
    $conversation,
    'طلبك تحت التنفيذ.'
);
```

Replace the final `return` with:

```php
$this->createInstallmentRequest($conversation, $application);

return $this->reply($conversation, 'طلبك تحت التنفيذ.');
```

Add the creation method near `saveState()`:

```php
private function createInstallmentRequest(WhatsappConversation $conversation, array $application): void
{
    $collected = $application['collected_documents'] ?? [];
    $fields = [];

    foreach ($collected as $doc) {
        foreach (($doc['fields'] ?? []) as $key => $value) {
            if ($value !== null && $value !== '') {
                $fields[$key] = $value;
            }
        }
    }

    \App\Models\InstallmentRequest::create([
        'whatsapp_conversation_id' => $conversation->id,
        'machine_id' => $application['machine_id'] ?? null,
        'months' => $application['installment_months'] ?? null,
        'applicant_name' => $fields['full_name'] ?? ($application['full_name'] ?? null),
        'applicant_phone' => $application['phone'] ?? null,
        'applicant_address' => $application['home_address'] ?? null,
        'applicant_national_id' => $fields['national_id'] ?? ($application['national_id'] ?? null),
        'applicant_id_image' => $collected['id_card_front']['path'] ?? null,
        'applicant_id_back_image' => $collected['id_card_back']['path'] ?? null,
        'applicant_birthdate' => $fields['birthdate'] ?? null,
        'work_status' => $application['income_category'] ?? null,
        'work_address' => $application['work_address'] ?? null,
        'salary_amount' => $fields['salary_amount'] ?? null,
        'salary_issue_date' => $fields['document_issue_date'] ?? null,
        'salary_slip_file' => $collected['salary_slip']['path'] ?? null,
        'pension_amount' => $fields['pension_amount'] ?? null,
        'pension_statement_file' => $collected['pension_statement']['path'] ?? null,
        'commercial_reg_file' => $collected['commercial_reg']['path'] ?? null,
        'tax_card_file' => $collected['tax_card']['path'] ?? null,
        'free_income_proof_images' => isset($collected['activity_photo']['path'])
            ? [$collected['activity_photo']['path']]
            : null,
        'status' => 'pending',
    ]);
}
```

- [ ] **Step 2: Write the happy-path integration test**

Create `tests/Feature/ApplicationInstallmentRequestCreationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\InstallmentRequest;
use App\Models\Machine;
use App\Models\WhatsappConversation;
use App\Services\DocumentDataExtractor;
use App\Services\Handlers\MediaOcrHandler;
use App\Services\Ocr\OcrProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationInstallmentRequestCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_valid_document_creates_pending_installment_request(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/pension.jpg', 'fake-bytes');

        $this->app->instance(OcrProviderInterface::class, new class implements OcrProviderInterface {
            public function recognize(string $absolutePath, ?string $mime = null): array
            {
                return ['ok' => true, 'text' => 'pension text', 'lines' => [], 'pages' => [],
                    'average_confidence' => 0.9, 'document' => [], 'display_text' => 'pension text',
                    'engine' => 'fake', 'error' => null];
            }
        });

        $this->app->instance(DocumentDataExtractor::class, new class extends DocumentDataExtractor {
            public function extract(string $ocrText, string $documentType, string $incomeCategory): array
            {
                return ['valid' => true, 'violation_message' => null, 'fields' => ['pension_amount' => 4500]];
            }
        });

        $machine = Machine::create(['name' => 'Test Machine 3', 'cash_price' => 50000]);

        $conversation = WhatsappConversation::create([
            'whatsapp_bot_id' => 70,
            'phone' => '201000000002@s.whatsapp.net',
            'status' => 'active',
            'last_machine_id' => $machine->id,
            'pending_question' => 'application_document_upload',
            'context_payload' => [
                'application' => [
                    'machine_id' => $machine->id,
                    'payment_type' => 'installment',
                    'full_name' => 'Test Customer',
                    'national_id' => '29001011234567',
                    'phone' => '01000000000',
                    'home_address' => 'القاهرة',
                    'installment_months' => 24,
                    'income_category' => 'pension',
                    'required_documents' => ['pension_statement'],
                    'collected_documents' => [],
                ],
            ],
        ]);

        $result = app(MediaOcrHandler::class)->handle($conversation, [
            ['path' => 'uploads/pension.jpg', 'mime' => 'image/jpeg', 'filename' => 'pension.jpg'],
        ]);

        $this->assertStringContainsString('تحت التنفيذ', $result['reply']);

        $this->assertDatabaseHas('installment_requests', [
            'whatsapp_conversation_id' => $conversation->id,
            'applicant_name' => 'Test Customer',
            'status' => 'pending',
            'pension_amount' => 4500,
        ]);
    }
}
```

- [ ] **Step 3: Run the test**

Run: `php artisan test --filter=ApplicationInstallmentRequestCreationTest`
Expected: `PASS` (1 test).

- [ ] **Step 4: Commit**

```bash
git add app/Services/Handlers/ApplicationHandler.php tests/Feature/ApplicationInstallmentRequestCreationTest.php
git commit -m "Create InstallmentRequest row once all required documents pass validation"
```

---

## Task 9.1 & 9.2: Already covered

Task 9.1 ("full happy-path creates a correct row") is the test added in Task 9.0, Step 2. Task 9.2 ("invalid data never creates a row") is the test added in Task 8.1, Step 1. No additional files — this row exists in the status table purely so the spec's two must-pass scenarios are each traceable to one committed test:

- [ ] **Confirm both tests are green together**

Run: `php artisan test --filter="ApplicationInstallmentRequestCreationTest|MediaOcrHandlerApplicationFlowTest"`
Expected: `PASS` (4 tests total).

---

## Task 10.0: Manual end-to-end verification checklist

**Files:** none (manual verification only — this task has no code changes).

This task requires real Google Document AI credentials and a running WhatsApp bot (`whatsapp-bot` + `whatsapp-worker` pm2 processes, per the project's existing setup) — it cannot be automated in CI, so it's a manual checklist run once the user has filled in the real `.env` values from Task 2.0.

- [ ] **Step 1: Set real env values**

Confirm the user has filled in `OCR_DRIVER=google_document_ai`, `GOOGLE_DOCUMENT_AI_PROJECT_ID`, `GOOGLE_DOCUMENT_AI_LOCATION`, `GOOGLE_DOCUMENT_AI_PROCESSOR_ID`, `GOOGLE_APPLICATION_CREDENTIALS` in the real `.env` (not `.env.example`).

- [ ] **Step 2: Restart services**

```bash
php artisan config:clear
pm2 restart whatsapp-bot whatsapp-worker
```

- [ ] **Step 3: Run a real ID card through the OCR client**

Run: `php artisan tinker --execute="print_r(app(App\Services\Ocr\GoogleDocumentAiClient::class)->recognize('/absolute/path/to/a/real/id-card-photo.jpg', 'image/jpeg'));"`
Expected: `ok => true`, non-empty `text`.

- [ ] **Step 4: Walk a full WhatsApp conversation for one income category (pensioner)**

Send via WhatsApp: purchase confirmation → "قسط" → basic text fields → pension statement photo (with a salary under 4000 first, to confirm the rejection message and re-ask) → pension statement photo (valid) → confirm reply is "طلبك تحت التنفيذ".

- [ ] **Step 5: Confirm the dashboard**

Open `http://localhost:8000/admin/deliveries`, find the new request, confirm `applicant_name`, `applicant_national_id`, `pension_amount`, and the uploaded document image are all populated.

- [ ] **Step 6: Confirm status-change notification**

Change the request's status to `paused` with a `checks_report` reason on the dashboard. Confirm the WhatsApp customer receives the reason message within a few seconds (check `pm2 logs whatsapp-worker` / the queue worker for the dispatched job, and confirm `php artisan queue:work` or the configured queue driver is actually running so `SendWhatsappStatusNotification` gets processed — if the app's queue connection is `sync` in production `.env`, this happens inline and no separate queue worker is needed; if it's `database` or `redis`, confirm a `php artisan queue:work` process is running).

- [ ] **Step 7: Confirm rejection with no reason**

Repeat with `status = rejected` and an empty `checks_report`. Confirm the generic "للأسف طلبك اترفض." message arrives.

- [ ] **Step 8: Confirm a memory-only rule edit changes behavior with no deploy**

Edit the "أصحاب المعاشات" memory content on `http://localhost:8000/admin/ai-memories` to change the minimum pension from 4000 to a different number, resend a pension statement photo with an amount between the old and new threshold, and confirm the validation outcome changes accordingly — with no code change or deploy.
