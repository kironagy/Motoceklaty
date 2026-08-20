# WhatsApp OCR Application Flow — Design

## Goal

After a customer confirms they want to buy a machine via WhatsApp:
- Cash → tell them to come to the showroom, done.
- Installment → determine their income category, ask for the right documents
  one at a time, OCR each one with Google Document AI, extract structured
  data and validate it against the business rules already written in the
  `ai_memories` dashboard (age, salary minimums, document freshness, etc.),
  and give the customer immediate feedback if something's wrong. Only once
  every document passes does a real `InstallmentRequest` row get created —
  with the extracted fields and uploaded images already populated — so it
  shows up ready-to-review on `/admin/deliveries`.

When staff change an application's `status` on the dashboard, the customer
must automatically get a WhatsApp message: approved (with next steps),
paused (with `checks_report` reason + what to do), or rejected (with reason
if present).

## Non-goals

- No changes to the existing machine-search / pricing / installment-calc
  conversation flows.
- No new Filament UI — `DeliveryResource`'s existing form fields already
  match the `installment_requests` columns this flow will populate.
- No hardcoded validation rules in PHP — every business rule (age range,
  minimum salary, document freshness, required docs per income type) is
  sourced from `ai_memories` content at runtime so editing the dashboard
  changes behavior with no deploy.

## Current state (verified in code)

- `app/Services/Ocr/OcrProviderInterface.php` — clean provider contract,
  already the only thing `MediaOcrHandler` depends on.
- `app/Services/Ocr/PaddleOcrClient.php` — current implementation, HTTP to
  a local PaddleOCR service. No provider binding/config switch exists yet
  (`MediaOcrHandler` instantiates it directly).
- `app/Services/Handlers/ApplicationHandler.php` — collects 8 text fields
  via AI extraction (name, national ID, phone, job type, income proof
  yes/no, addresses, months) but **never creates an `InstallmentRequest`
  row**, never asks for document photos, never branches cash vs.
  installment.
- `app/Models/InstallmentRequest.php` / `DeliveryResource.php` (model
  `InstallmentRequest`, served at `/admin/deliveries`) — full schema
  already exists: applicant + guarantor ID images, salary/pension/business
  docs, `status` (`new`, `pending`, `approved`, `rejected`, `paused`, ...),
  `checks_report` (the reason field), full Spatie activity log on status
  changes. No `whatsapp_conversation_id` / `whatsapp_bot_id` columns.
- `ai_memories` table already has freeform-text business rules under
  titles: "المستندات الأساسية المطلوبة", "الموظفين", "أصحاب المعاشات",
  "أصحاب الأنشطة التجارية", "أصحاب المهن الحرة", "استخراج الاسم من
  البطاقه", "مراجعه صور النشاط", "تاكيد الرفع". `AiMemoryResolver` /
  `AiMemoryContextBuilder` already know how to fetch memory by title or by
  relevance.
- No `Observer` classes exist anywhere in the app yet.
- No generic "send this WhatsApp text to this customer" mechanism exists
  outside `ProcessWhatsappMessageJobs::sendWhatsappText()`, which only
  handles replies to inbound jobs (POSTs to
  `{WHATSAPP_WORKER_URL}/send-message` with `bot_id` + `jid` + `message`).

## Architecture

### 1. OCR provider — Google Document AI

New `App\Services\Ocr\GoogleDocumentAiClient implements OcrProviderInterface`.
Config-driven provider selection in `config/ocr.php` (`OCR_DRIVER=google_document_ai|paddle`),
bound in a service provider so `MediaOcrHandler` resolves
`OcrProviderInterface` instead of `PaddleOcrClient` directly. Credentials:
project id, location, processor id, and a service-account JSON key path —
all via `.env`, filled in by the user from the dashboard/host, not
committed.

### 2. Document extraction + validation — AI-driven, not hardcoded

New `App\Services\DocumentDataExtractor`. Input: raw OCR text + document
type (`id_card`, `salary_slip`, `pension_statement`, `commercial_reg`,
`tax_card`, `activity_photo`) + the customer's income category + the
relevant `ai_memories` rule text for that category (fetched via
`AiMemoryResolver`). Calls Gemini (same pattern as
`AiIntentClassifier::extractApplicationData`) with a JSON-only contract:
extracted fields (name — first+last merged per memory #57, national ID,
birthdate, salary, document issue date, employment start date, etc.),
`valid: bool`, and an Arabic `violation_message` when invalid.

### 3. Application flow rewrite

`ApplicationHandler` gets a cash/installment branch (reusing existing
"القسط بيطلب بيانات، الكاش يقول تعالى المعرض" framing). For installment:
1. Determine income category from conversation (government/private-insured/
   private-uninsured/army/pension/business/freelance) — reusing the
   existing 8-field text collection already in place.
2. Resolve the required-document list for that category from memory
   (§`الموظفين`, `أصحاب المعاشات`, `أصحاب الأنشطة التجارية`,
   `أصحاب المهن الحرة`, plus the universal list in `المستندات الأساسية
   المطلوبة`).
3. Ask for documents one at a time (extends `MediaOcrHandler`'s existing
   per-image OCR call). Each upload → OCR → `DocumentDataExtractor`. Invalid
   → immediate WhatsApp reply explaining what's wrong and what's needed;
   **nothing is persisted to `installment_requests` until every document is
   valid.** Valid → store the extracted fields + file in conversation state
   and move to the next required document.
4. Once every document passes: create the `InstallmentRequest` row —
   extracted fields mapped directly onto the matching columns
   (`applicant_name`, `applicant_national_id`, `salary_amount`,
   `salary_issue_date`, `salary_slip_file`, etc.), uploaded images attached,
   `status = 'pending'`, `whatsapp_conversation_id` set. Reply: "طلبك تحت
   التنفيذ."

### 4. Schema addition

Migration adding `whatsapp_conversation_id` (nullable FK →
`whatsapp_conversations.id`) and `whatsapp_bot_id` to `installment_requests`,
so the notification step below knows where to send.

### 5. Status-change notifications

New `App\Observers\InstallmentRequestObserver`, registered in
`AppServiceProvider::boot()`. On `updating`, when `isDirty('status')` and
the new value is `approved` / `rejected` / `paused`, dispatch a new queued
job `SendWhatsappStatusNotification` (mirrors
`ProcessWhatsappMessageJobs::sendWhatsappText()`'s HTTP call, but as a
standalone Laravel queue job so saving the status in Filament never blocks
on the WhatsApp round-trip). Message content:
- `approved`: confirmation + "تعالى المعرض/الفرح تكمل باقي الإجراءات."
- `paused`: `checks_report` reason + what the customer needs to do.
- `rejected`: `checks_report` reason if present, else a generic rejection
  message.

## Data flow

```
WhatsApp customer message
  → WhatsappIntentRouter (existing) → intent=application
  → ApplicationHandler
      cash → reply, done
      installment →
        collect text fields (existing AI extraction, unchanged)
        → determine income category
        → for each required doc:
            customer sends image
            → MediaOcrHandler → GoogleDocumentAiClient::recognize()
            → DocumentDataExtractor (OCR text + memory rules → fields + valid?)
            → invalid: reply with reason, stay on same document
            → valid: store field data + file, advance to next document
        → all docs valid → create InstallmentRequest (status=pending)
        → reply "طلبك تحت التنفيذ"

Staff changes status on /admin/deliveries
  → InstallmentRequestObserver::updating()
  → dispatch SendWhatsappStatusNotification job
  → POST {WHATSAPP_WORKER_URL}/send-message
```

## Error handling

- OCR call fails/times out → treat like an invalid document: ask the
  customer to resend the photo (clearer / better lit), don't crash the
  conversation.
- Gemini extraction call fails → same fallback pattern already used
  elsewhere in the codebase (`AiIntentClassifier::fallback()`): ask the
  customer to resend, log the failure.
- `SendWhatsappStatusNotification` job failure (WhatsApp API down) → let
  Laravel's queue retry/backoff handle it; log on final failure so staff
  can manually follow up. Never blocks or reverts the status save itself.

## Testing

- Unit: `GoogleDocumentAiClient` against fixture responses (success,
  malformed, timeout).
- Unit: `DocumentDataExtractor` against fixture OCR text for each document
  type, valid and invalid cases (below-minimum salary, out-of-range age,
  stale document date), confirming the violation message is populated only
  on invalid.
- Integration: full `ApplicationHandler` conversation for at least one
  income category (employee) — happy path creates a correctly-populated
  `InstallmentRequest`; injected invalid data never creates a row.
- Integration: `InstallmentRequestObserver` — status transitions to
  approved/paused/rejected each dispatch exactly one notification job with
  the right message content.
- Manual: editing an `ai_memories` rule (e.g. minimum salary) changes
  validation behavior with no code change.
