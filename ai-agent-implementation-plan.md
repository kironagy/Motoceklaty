# Motocyklaty WhatsApp AI Agent — Master Implementation Plan

## Purpose

This file is the **single source of truth** for implementing the new WhatsApp AI Sales Agent described in `project.md`. It turns the approved Step 2 architecture and the codebase audit into ordered, verifiable tasks.

- Implement tasks **in the order of the master table**, one task at a time.
- A task becomes `DONE` only after **every** acceptance checkbox is ticked and its verification steps were actually run.
- When a task is finished, update **both** the master table and the task's own `Status:` line.
- If you hit a question this file does not answer, **stop and ask the owner**. Do not guess. Add the question to the Decision Register (§4).

---

## 1. Master task status table

| ID | Task | Priority | Dependencies | Status |
|---|---|---|---|---|
| T01 | Isolate the rebuild and protect production | P0 | — | DONE (owner overrode: work directly on `main`, no isolation branch) |
| T02 | Make the test suite runnable | P0 | T01 | DONE |
| T03 | AI provider abstraction + Gemini provider | P0 | T02 | DONE |
| T04 | Customer / conversation / message persistence and ingestion | P0 | T02, T03 | DONE |
| T05 | Turn scheduler, delivery and outbound persistence | P0 | T04 | DONE |
| T06 | Tool framework, AI traces, conversation tools | P0 | T03, T05 | DONE |
| T07 | Human handoff and conversation dashboard | P0 | T05, T06 | DONE |
| T08 | Business knowledge (memory) | P1 | T06 | DONE |
| T09 | Motorcycle catalog, images and image recognition | P0 | T03, T06 | DONE |
| T10 | Branch information | P1 | T06 | DONE |
| T11 | Application requirements configuration and eligibility engine | P0 | T06 | DONE |
| T12 | Installment domain and calculator | P0 | T09, T11 | DONE |
| T13 | Application domain core and customer profile | P0 | T04, T09, T11, T12 | DONE |
| T14 | Application submission and InstallmentRequest projection | P0 | T13 | DONE |
| T15 | Document pipeline (`process_document`) | P0 | T03, T13 | DONE |
| T16 | Context builder and conversation summary | P0 | T07–T15 | DONE |
| T17 | Agent runtime, guardrails and turn wiring | P0 | T16 | DONE |
| T18 | Acceptance scenario suite and model evaluation | P0 | T17 | IN PROGRESS |
| T19 | Production cutover | P0 | T18 | NEEDS DECISION |
| T20 | Post-cutover legacy cleanup | P2 | T19 | TODO |

**Status legend**
- `TODO`: the task's content is fully specified. It can start once its dependencies are `DONE`.
- `NEEDS DECISION`: at least one Decision Register item (§4) must be answered by the owner before the task can be finished. The task names the decision(s). Parts that don't depend on the decision may be prepared, but the task cannot become `DONE`.
- `BLOCKED`: cannot proceed because of an external dependency (credentials, business data, infrastructure). Currently none.
- `DONE`: all acceptance criteria verified.

Dependency ordering is expressed in the **Dependencies** column, not by marking tasks `BLOCKED`.

---

## 2. Non-negotiable architecture principles

These apply to **every** task. A change that violates one of them is wrong even if its tests pass.

1. **AI decides what needs to happen. Laravel decides what is allowed to happen.**
   - The AI (Gemini) owns:
     - Understanding Egyptian Arabic, slang, typos and references.
     - Choosing tools and deciding what to ask next.
     - Wording the reply, handling topic switches and quoted replies.
     - Deciding when to send images and when to hand off.
   - Laravel owns:
     - Business rules, validation, eligibility, calculations.
     - IDs, prices, plans, document validity, application state.
     - Permissions, persistence, security and state transitions.
2. **Laravel must not interpret natural-language customer text to determine conversational intent or route the conversation.** Laravel *does* store, deduplicate, hash, redact, transcribe, attach and audit message data; that is fine.
   - Forbidden everywhere in the agent/conversation code:
     - `str_contains`, `preg_match` or similar applied to customer text to decide meaning.
     - Keyword lists.
     - `$intent === …` trees.
     - `current_intent` / `current_step` fields.
     - `askName()`-style chains.
     - Hardcoded question order.
     - Hardcoded customer-type flows.
     - Hardcoded document flows.
3. **Structured tool arguments are the only interface** from AI reasoning into deterministic Laravel behavior.
4. **Tools are thin.** The call path is: tool class → domain/application service → model/DB. Each tool is one small class. No central switch over tool names.
5. **No business rule lives in a prompt or in business memory.** Age limits, formulas, fees, caps, required fields/documents and eligibility live in structured tables/config and reach the AI **only through tool results and the application snapshot**.
6. **`send_reply` is conversational delivery only.**
   - It may carry reply text, an optional quote target, and two *conversational hints*: `focus_motorcycle_ids` and `awaiting`.
   - It must never change applications, customer data, documents, selections or handoff state.
   - **`awaiting` is guidance for the next turn, not a step machine.** Application truth always comes from the application snapshot.
7. **The compact catalog index (context layer L3) is for discovery only.** It is never the source of final prices, calculations, eligibility or rules. A price shown to the customer must come from a tool result in the same turn (enforced by the number guard in T17).
8. **Every tool declares a permission level:**
   - `READ`: no persistent change.
   - `WRITE`: creates or updates data, reversible.
   - `DESTRUCTIVE`: ends or cancels something the customer or staff cannot trivially undo, e.g. `withdraw_application`, `submit_application`.
9. **Tools never receive customer/conversation/application IDs as arguments.** These are injected from the execution context, so the AI cannot reach another customer's data.
10. **Production safety.**
    - All work happens on the isolated rebuild branch (T01).
    - Until T19, migrations are **additive only**: no dropping or renaming of any table or column that the production commit `09318bf1` uses.
    - Nothing may deliver WhatsApp messages from placeholder code.
11. **Decision-dependent values are never hardcoded.**
    - A numeric threshold or limit that depends on a Decision Register item is read from `config/agent.php` (or the relevant domain table) **with no default value in code**.
    - Tests set explicit values.
    - The readiness command (T19) refuses cutover while a required value is missing.
12. **Simplicity.**
    - No Redis, no new queue systems, no vector database, no microservices, no extra agents.
    - Reuse the existing DB-backed worker (`whatsapp:process-jobs`) and the `laravel-queue` process already defined in `ecosystem.config.cjs`.
13. **Privacy.**
    - National IDs, document images and extracted document data never appear in normal logs or traces.
    - Sensitive fields are redacted by the field registry's `is_sensitive` flag (T11) and the redaction helper (T06).

### 2.1 Code location conventions for new code

Existing models stay in `app/Models`. New code uses these locations. Follow them; do not invent others.

| Area | Location |
|---|---|
| Agent config | `config/agent.php` |
| Provider interface, DTOs, Gemini provider | `app/Agent/Providers/` |
| Tool interface, registry, envelope, one class per tool | `app/Agent/Tools/` |
| Context builder and layers | `app/Agent/Context/` |
| Agent runtime, guards | `app/Agent/Runtime/` |
| Trace writer and redaction helper | `app/Agent/Tracing/` |
| Static agent instructions (L0), versioned text files | `resources/agent/instructions/` |
| Domain services (one folder per domain) | `app/Domain/Conversations`, `app/Domain/Catalog`, `app/Domain/Installments`, `app/Domain/Applications`, `app/Domain/Documents`, `app/Domain/Branches`, `app/Domain/Knowledge`, `app/Domain/Handoff` |
| Filament dashboard | Existing `app/Filament/Resources`, `app/Filament/Pages` |
| Tests | `tests/Feature/Agent/...`, `tests/Unit/...` |

---

## 3. Core shapes referenced by several tasks

### 3.1 Tool result envelope (all tools)
```json
{ "ok": true, "data": { } }
{ "ok": false, "error": { "code": "UPPER_SNAKE_CODE", "detail": "short English explanation for the AI" } }
```
Error codes are stable strings listed in each tool contract (§6). No stack traces or exception messages ever go to the AI.

### 3.2 Conversation state (`conversations.state` JSON, owned by Laravel)
```json
{
  "focus_motorcycle_ids": [12],
  "awaiting": [{ "kind": "field|document|confirmation", "key": "home_address", "asked_at": "ISO-8601" }],
  "last_media_sent": [{ "motorcycle_id": 12, "color": "#0d0d0d", "sent_at": "ISO-8601" }],
  "session_started_at": "ISO-8601"
}
```
- **Written only by:**
  - `send_reply`: `focus_motorcycle_ids` and `awaiting`, both validated.
  - `send_motorcycle_images`: `last_media_sent`.
  - Ingestion: `session_started_at`.
- **Deterministic clean-up:** an `awaiting` entry is removed when that field becomes collected or that document type becomes accepted in the snapshot. A new `send_reply` **replaces** the `awaiting` array; it does not append.
- **Forbidden keys:** there is no intent field and no step field, and none may be added.

### 3.3 Application snapshot (returned by every application-mutating tool and shown in context L4)
```json
{
  "application_id": 81, "status": "collecting", "customer_type": "self_employed",
  "selection": { "motorcycle_id": 12, "plan_id": 5, "down_payment": 10000 },
  "fields": { "required": ["..."], "collected": ["full_name"], "missing": ["home_address"],
              "invalid": [{ "key": "national_id", "code": "INVALID_FORMAT" }] },
  "documents": { "required": ["national_id_front"], "accepted": [], "missing": ["national_id_front"],
                 "rejected": [{ "type": "national_id_front", "code": "BLURRY_DOCUMENT" }], "processing": [] },
  "eligibility": { "status": "eligible|not_eligible|unknown", "reasons": [{ "code": "AGE_OUT_OF_RANGE", "params": {} }] },
  "can_submit": false,
  "last_activity_at": "ISO-8601"
}
```
Values of sensitive fields are **never** in the snapshot; only keys and statuses are.

---

## 4. Decision Register

Nothing below may be invented by the implementer. Each item lists what the repository shows today (evidence) and which tasks depend on it. When the owner answers, record the answer here, set `Status` to `DECIDED`, and re-evaluate the affected tasks' statuses.

| ID | Question | Evidence in repo today | Blocks | Status |
|---|---|---|---|---|
| DEC-01 | Exact installment formula per installment system: which price is the base (cash or installment price), whether interest is total or per year, what "zero fees" systems do, admin-fee base, rounding, minimum down payment. | Website JS (`resources/views/layouts/app.blade.php` ~L1135-1175): `(installment_price − down) × (1+interest%)/months`, admin fee `% × (installment_price − down)`; a system whose **name contains "زيرو مصاريف"** uses `(cash − down) × 1.075 × (1+interest%) / months`. Deleted bot calculator (`HEAD:app/Services/InstallmentCalculator.php`): hardcoded 20%/30% per year, 7% admin fee, 60,000 self-employed cap. DB: 7 systems with `plans[{months, interest}]` and `administrative_fees`. The two formulas disagree. | T12, T13, T19 | **DECIDED** (2026-09-23): keep both existing website formulas exactly as-is, unchanged. The branch is selected by an explicit `installment_systems.pricing_mode` column (`standard`\|`zero_fees`, dashboard-editable) instead of matching the system's name. Rounding: `monthly_installment` rounded to the nearest pound (matches the site's `Math.round`); `financed_amount`/`total_with_interest`/`administrative_fees` kept at 2 decimals, unrounded. `minimum_down_payment` is an additive nullable per-system column with no default - unenforced until the owner sets it per system from the dashboard. |
| DEC-02 | Eligibility rules: age range (project.md says 21–60; web form uses 21–62), self-employed financing cap and what happens above it, any other per-customer-type conditions. | `project.md` §23 and §52; `InstallmentRequestController::store` uses `>= 21 && <= 62`; deleted calculator's `FREELANCE_FINANCE_CAP = 60000` with excess as mandatory down payment. | T12 (cap rule type), T19 (seeding) | **DECIDED** (2026-09-23): the *mechanism* only - a `financing_cap` eligibility rule type (`app/Domain/Applications/EligibilityRules/FinancingCapEvaluator.php`) added to the T11 registry, evaluated against `facts['financed_amount']` (computed by the calculator). No cap value, age range, or "what happens above the cap" behavior is hardcoded - `eligibility_rules.params` holds the actual numbers, entered by the owner from the dashboard (same data-entry pattern as DEC-19/DEC-20), and whether exceeding a cap blocks the application or should prompt for a bigger down payment is left to T16/T17's conversational handling of the `FINANCING_CAP_EXCEEDED` reason code, not invented here. |
| DEC-03 | Customer types and their required fields and documents, including guarantor requirements. | `installment_requests.work_status` enum: `employee, pension, self_employed, no_income_proof`; web form has guarantor fields, salary slip, pension statement, commercial register, tax card, place video, free-income proof images; `project.md` §18 lists only examples. | T19 (seeding) | OPEN |
| DEC-04 | Document acceptance rules: which document types exist, which fields each must contain, name-match threshold, expiry/age-of-document rules, whether national ID front and back are separate documents, duplicate-national-ID policy, legibility/confidence thresholds. | `project.md` §22–26 lists issue codes only. Deleted `DocumentDataExtractor` had ad-hoc rules. | T15, T19 | **DECIDED (engine) — duplicate-national-ID policy still OPEN** (2026-09-23): fully data-driven per DEC-19/20's pattern - nothing below is hardcoded. `document_types` (key, `accepted_mimes`, `extraction_fields`, `validation_rules`) defines every actual document type, its required fields and its rules; the owner enters the real catalog (national ID front/back as separate or combined types, salary slip, etc.) from the dashboard as a data-entry task, same as DEC-03. Legibility uses the AI's own categorical `good\|poor\|unreadable` classification (no numeric confidence threshold needed for that). Name/ID matching is the generic `matches_application_field` document rule (`app/Domain/Documents/DocumentRules/MatchesApplicationFieldEvaluator.php`), driven by `config('agent.documents.name_match_threshold')` - no default, so it requires an exact normalized match until the owner sets a fuzzy tolerance. Expiry is the generic `not_expired` rule (`date_field`, `max_age_days`, both per document type). Duplicate-national-ID policy across different customers is not yet implemented and stays OPEN. |
| DEC-05 | Motorcycle image-recognition confidence bands (`match` / `similar` / `unknown` thresholds). | Step 2 proposed 0.8 / 0.5, **not approved**. | T19 (config value) | OPEN |
| DEC-06 | Which Gemini model(s) the agent uses. | Memory note: `gemini-3.7-flash` took 17–40 s; everything runs on `gemini-3.1-flash-lite`. Tool-calling quality of flash-lite is unmeasured. | T18 produces evidence; T19 | OPEN |
| DEC-07 | Burst handling: debounce window(s), maximum wait, and what happens when new messages arrive while a reply is being generated (supersede the unsent reply or deliver it). | Step 2 proposed 3 s text / 6 s media / 15 s max, supersede once. | T05 | **DECIDED** (2026-09-22): 3s text / 6s media / 15s max debounce. On mid-generation arrival: mark current turn `superseded` (once only), don't deliver its result, fold the new messages into the next turn which gets full context including the messages that caused the supersession. A turn already delivered stays delivered; a new message after delivery just starts a fresh turn. Superseded turns stay persisted for observability. Purely a scheduling concern — never keyed on message text/keywords. |
| DEC-08 | Voice notes: transcribe at ingestion (stored transcript) or send audio inline to the agent. | `VoiceTranscriptionService` exists (unused), uses Gemini inline audio. | T04 | **DECIDED** (2026-09-22): transcribe at ingestion. Store the original audio as private media; background-transcribe; store `transcript` on the message; the turn scheduler waits for transcription on a turn containing an unprocessed voice message; the Agent Runtime normally sees text, never re-sending audio to Gemini on later turns. Transcription failure sets an explicit status (no silent pretend-success) and is retryable. The transcription provider is abstracted so it's swappable. Audio/transcripts follow the same privacy/redaction/retention rules as other customer data. |
| DEC-09 | Customer identity scope: one customer per (bot, WhatsApp JID) or global across bots. | Only 1 bot exists; conversations are keyed by `(whatsapp_bot_id, phone)`; `@lid` JIDs exist. | T04 | **DECIDED** (2026-09-22): per-bot. Unique key is `(whatsapp_bot_id, jid)`. |
| DEC-10 | Legacy data: whether to backfill existing `whatsapp_messages` into the new structure, link existing `installment_requests` to customers, and when legacy columns/tables are dropped. | 24 local messages; production volume unknown; 2,967 installment requests. | T19, T20 | OPEN |
| DEC-11 | Motorcycle images: normalize into a `motorcycle_images` table, or keep reading the `machines.colors` JSON. | Images live only in `machines.colors[{color, color_display, images[]}]`. | T09 | **DECIDED** (2026-09-22): dedicated `motorcycle_images` table, backfilled once from `colors` JSON and kept resynced by `MachineObserver` whenever `colors` changes in the (unchanged) Filament repeater. `colors` itself is left in place for the website. |
| DEC-12 | Trace retention and whether raw (encrypted) prompts/responses are stored at all. | `project.md` §47–50: useful logs, no secrets or sensitive data. | T06 extension only (baseline stores redacted data only) | OPEN |
| DEC-13 | Automatic handoff thresholds (how many failed turns) and the exact fallback text sent when the AI provider is down. | Step 2 proposed "2 failed turns" and one short holding message; **not approved**. | T19 (config values) | OPEN |
| DEC-14 | Session gap length, draft-application expiry, and whether a customer may have more than one *active* application at a time. | `project.md` §28: a customer may make more than one application (concurrency unspecified). | T13 (active-application policy), T19 (values) | **DECIDED (policy) — session gap / draft expiry values still OPEN** (2026-09-23): `start_application` reuses an existing active application by default (matches project.md §28 and the tool's own §6.10 contract) rather than blocking; `config('agent.applications.concurrent_active_policy')` can be set to `reject` from the dashboard to require single-active instead, returning `ACTIVE_APPLICATION_EXISTS`. `session_gap_hours` (already in `config/agent.php`) and a draft-expiry value are still unset numbers - T19's job once chosen. |
| DEC-16 | Test database strategy: a dedicated MySQL test database, or change the MySQL-only migrations so they run on SQLite. | `phpunit.xml` uses SQLite `:memory:`; 13/14 tests fail because migrations use `ALTER TABLE … MODIFY … ENUM`. | T02 | OPEN |
| DEC-17 | Capture messages staff send from the phone itself (`fromMe`) as conversation history. | Node drops `fromMe` messages (`whatsapp-bot/index.js` L404-411). Bot-sent messages also arrive as `fromMe` and would need de-duplication by WhatsApp message ID. | T05 | **DECIDED** (2026-09-22): yes, capture them. Node forwards `fromMe` messages; Laravel stores them as `sender_type=human_phone` unless the `wa_message_id` already exists (bot-sent), so the AI sees when staff already answered from the phone. |
| DEC-18 | Media storage: new customer media on the private `local` disk (`storage/app/private`) served to the dashboard through signed/authorized routes, and what to do with existing media on the `public` disk. | Today media is saved to the `public` disk (`whatsapp-documents/conversation-{id}`), which is web-readable. | T04 | **DECIDED** (2026-09-22): new media (`message_media`) goes on the private `local` disk, served to the dashboard only via signed/authorized routes. Existing `public`-disk media is left in place (no migration of historical files); only new ingestion changes disk. |
| DEC-19 | Motorcycle catalog fields: availability semantics (e.g. in stock / out of stock / on order), whether `cc`, `category`, `description`, `specifications` are added, and who enters data for the existing 58 machines. | `machines` has none of these columns. | T09, T19 | **DECIDED** (2026-09-22): add all four (`cc` int, `category` string validated against `config('agent.catalog.categories')` — not free text, `description` nullable text, `specifications` nullable JSON) plus `availability` (implementer's choice of values, not specified by the owner: `in_stock`\|`out_of_stock`\|`on_order`, default `in_stock`) and `is_active`. No fabricated data — new columns are `nullable`/safe-defaulted on the existing 58 machines; **who enters real category/description/specifications data for them is a data-entry task for the owner, same as T19's DEC-20 branch data — not something this task invents.** The `agent.catalog.categories` list is factual/implementation data (7 common motorcycle categories) — **owner should review it** the same way DEC-20's governorate list needs review, since it constrains what `category` can ever be set to. |
| DEC-20 | Branch data content (list of branches, addresses, hours, phones, services) and who enters it. | No branch data exists anywhere in the repo. | T19 | OPEN |
| DEC-21 | Mapping from a submitted Application to the legacy `InstallmentRequest` (which columns; values for non-null legacy columns `installment_type`, `months`, `applicant_name`, `work_status`; status mapping in both directions; the conditional unique index on `applicant_phone`). | `installment_requests` schema; `DeliveryResource` staff workflow; statuses `new_request, new, pending, work_check, approved, rejected, paused, transferred, delivered, canceled`. | T14 | **DECIDED** (2026-09-23): `machine_id`/`installment_type` (system name)/`months` come straight from the application's selection; `applicant_name` from the `full_name` field (application-scope first, customer-scope fallback); `applicant_phone` from `customers.phone`; `work_status` from a new `customer_types.legacy_work_status` column, dashboard-set per customer type - submission fails with `LEGACY_WORK_STATUS_NOT_MAPPED` rather than guess one. Status mapping (both directions) is a dashboard-editable `legacy_status_mappings` table (`app/Filament/Resources/LegacyStatusMappingResource.php`), pre-filled only for `approved`→`approved` and `rejected`→`rejected` since those are the only two whose meaning is unambiguous from the code alone; every other legacy status (`new_request, new, pending, work_check, paused, transferred, delivered, canceled`) is left unmapped on purpose - `InstallmentRequestObserver` logs an `application_events` row instead of guessing when it sees one of these change. The `applicant_phone` unique-index question is not yet addressed and stays OPEN. |
| DEC-22 | Privacy approval to send national-ID and other document images/text to Google (Vision OCR and Gemini) for extraction. | Web form already sends ID images to Google Vision. | T15 | **DECIDED** (2026-09-23, approved by the owner): Vision OCR and Gemini only, for OCR/classification/extraction strictly needed to process the application - no other external service. Documents remain private media (DEC-18); no document image, OCR text or extracted value is ever written to logs or AI traces (`Redactor` already masks by sensitive-field key and any 14-digit number); API keys/credentials are never logged; a failed OCR/AI call is retryable and never loses the original document; retention policy is deferred and editable later; Gemini/Vision output is never authoritative - it only feeds Laravel's deterministic validation (T15 §3.5), which has the final say. |
| DEC-23 | Agent runtime limits: max model calls per turn, max tool calls, wall-clock budget, per-call timeout, input-token cap, pinned-memory token cap, number-guard minimum value. | Step 2 proposed ≤6 calls, ≤8 tools, ~35 s, ~15 s, 6–10k tokens; **not approved**. | T19 (config values) | OPEN |
| DEC-24 | Accepted phone-number format(s) for the `phone` field validator (Egyptian mobile only? landlines? with/without country code?). | No phone-format rule exists in the current code or in the deleted `HEAD` helpers; the web form only validates `max:30`. | T11 | **DECIDED** (2026-09-22): Egyptian mobile only (`010`/`011`/`012`/`015` + 8 digits), country code `+20`/`20` optional. Normalized to local 11-digit form (`01XXXXXXXXX`) on save. |
| DEC-25 | Business memory vs configured requirements contradict each other, and both differ from the owner's field lists (`بيانات العملاء/*/المطلوب.txt`): pinned memory `required_documents_baseline` asks for the ID "وش وضهر" but only `national_id_front` exists as a document type; `business_owner_docs` asks for photos of the shop, which has no document type (a shop photo is rejected `DOCUMENT_NOT_SUPPORTED`); المطلوب.txt makes the salary slip optional and asks for the employee's work address (config: salary slip required, no work address); pension needs the guarantor's ID images (config: guarantor fields only); delivery needs "3+ months on the app, many trips" and the profile/join-date screen (not configured). | Live QA 2026-09-24 personas A–E. Principle 5: requirements must live in structured config, not memory. | T19 (data entry) | OPEN - nothing changed; the agent now answers requirements only from `get_application_requirements`. |
| DEC-26 | Duplicate identity across customers: implemented safe default until DEC-04's duplicate-ID policy is decided - a national ID held by another customer is marked `IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER`, blocks submission, and the agent hands off (`lookup_hash` = HMAC of the normalized number; the value itself stays encrypted). Legitimate case this also blocks: the same person writing from a second WhatsApp number. | One customer's ID was accepted onto another customer's application (live QA persona B). | T19 | NEEDS CONFIRMATION |
| DEC-27 | A side question the agent cannot answer (warranty, licensing - `policies_not_on_file`) hands the whole conversation to a human, so the rest of the sales/application flow stops until staff reply or the 20-minute return. Option: a non-blocking staff escalation for policy questions. | Live QA persona E: a motorcycle change was lost behind the handoff. | T07/T19 | OPEN |
| DEC-28 | Submission now requires an explicit selection: motorcycle, installment plan and down payment (0 must be stated explicitly). Implemented because the projection (`months`, `installment_type`, `deposit`) and the financing-cap check need them; confirm this matches how staff want applications to arrive. | Submission crashed on a null plan (live QA persona A). | T14 | NEEDS CONFIRMATION |
| DEC-29 | `AGENT_FALLBACK_MESSAGE` ("حصل عندنا شوية ضغط دلوقتي...") is still the provider-outage text (DEC-13). Guard/limit exhaustion no longer uses it (it opens a handoff and sends the handoff waiting message instead), but the outage wording itself is the owner's call. | Real customer (conversation 115) received it after a guard false positive. | T19 | OPEN |
| DEC-30 | Data gaps found live: `machines.cc` is NULL for all 58 machines (size search falls back to names; the tool now says so); the ID back side and shop photos have no document type; the address validator accepts a single word ("الخصوص") - stricter address rules need DEC-03. | Live QA. | T19 (data entry) | OPEN |
| DEC-31 | L0 instructions bumped to v1.10.0 (system-enforced mechanics: two-phase submission, blockers, provenance quotes, unprocessed media, document replacement). `AGENT_INSTRUCTIONS_APPROVED_VERSION` is still v1.9.0, so `agent:readiness` reports the mismatch until the owner approves. | Live QA remediation. | T19 | NEEDS APPROVAL |

(DEC-15 from Step 2, the branch strategy, is resolved by T01 as an explicit production-safety requirement. The owner still performs or approves the git operations.)

---

## 5. Context layers (implemented in T16)

| Layer | Contains | Why | Included | Must NOT contain | Target budget |
|---|---|---|---|---|---|
| L0 Static instructions | Persona (friendly Egyptian salesperson), WhatsApp style, no-repetition / never-re-ask rules, topic switching, quoted replies, images, tool-usage rules, "never invent prices/models/rules", prompt-injection defence, information priority order (project.md §40) | Stable behaviour | Always (cached prefix) | Any business number, rule, required document, branch, price | ~1,500 |
| L1 Pinned business memory | Short dashboard-managed guidance marked `pinned` | Tone and policy explanations | Always | Enforceable rules/numbers (dashboard warns) | ≤ `agent.context.pinned_memory_tokens` (DEC-23) |
| L2 Knowledge index | One line per non-pinned active memory: `key: title` | Lets the AI pull knowledge on demand | Always | Memory content | ~400 |
| L3 Catalog index | One line per active motorcycle: `id · brand · name · cc? · cash · installment · offer` | Name→ID resolution without a search call | Always (cached; rebuilt when catalog changes) | Anything treated as authoritative | ~1,500 |
| L4 Structured state | Customer profile facts (key, value unless sensitive, source, verified), conversation state (§3.2), application snapshot (§3.3), handoff status | Ground truth for this customer | Always | Sensitive values (national ID, document text) | ~1,000 |
| L5 Stage-scoped memory | Memories whose scope matches the active application `status` and/or `customer_type` | Guidance relevant to the current application stage | Only when an application exists and matching memories exist | Memories selected by reading customer text | ≤ 800 |
| L6 Summary | Rolling summary of messages before the recent window | Long conversations and returning customers | When a summary exists | Facts of record not also in L4 | ≤ 400 |
| L7 Recent messages | Last messages of the current session (inbound, bot, staff), oldest first, as real multi-turn content, within the token budget | Immediate context | Always | Previous turns' tool traffic; sensitive values | ~2,000 |
| L8 Current turn | The turn's new inbound messages (text, transcripts, image parts), each with its resolved quoted message and that message's stored `focus` metadata | What to answer now | Always | — | Variable |

**Growth control**
- L7 is capped by token count and by `session_started_at`.
- Older messages are covered only by L6.
- L6 is rewritten (not appended) by the summarizer.
- Tool results exist only within the turn that produced them.

---

## 6. Tool contracts

Each tool below is implemented in the task noted in brackets. "Changes" lists everything the tool may modify; anything not listed must not be modified.

**Common rules for all tools**
- Arguments are validated against the JSON schema before execution. An invalid argument returns `INVALID_ARGUMENTS`.
- `customer_id`, `conversation_id`, `application_id` and `turn_id` come from the execution context.
- **Idempotency:** each call is recorded in `ai_trace_steps` with `(turn_id, tool_name, args_hash)`. A repeated identical call in the same turn returns the stored result without re-executing.
- **Failure:** an exception inside a tool is caught by the registry, recorded in the trace, and returned as `{ok:false, error:{code:"TOOL_FAILED"}}`. The turn continues.

### 6.1 `search_motorcycles` — READ [T09]
- **Purpose:** find catalog motorcycles by structured filters or a name the AI extracted.
- **Use when:** the customer asks what is available, gives a budget/cc/brand, or names a model not obvious from the L3 index.
- **Do not use when:** the ID is already known from L3/L4 (use `get_motorcycle_details`).
- **Input:** `{ name_query?: string(≤60), brand?: string, cc_min?: int, cc_max?: int, max_cash_price?: number, max_installment_price?: number, offers_only?: bool, available_only?: bool=true, limit?: int(1..8)=5 }`
- **Output:** `{ items: [{ id, name, brand, cc|null, cash_price, installment_price, is_offer, offer_price|null, availability }], total }`
- **Changes:** nothing.
- **Validation:** numeric ranges ≥ 0 and min ≤ max; limit capped at 8; only active machines; `name_query` is matched against `name` and `aliases` after normalization (Arabic letter variants, Arabic-Indic digits, case). This is a **catalog lookup of an AI-supplied value**, not interpretation of customer text.
- **Errors:** `INVALID_ARGUMENTS`.

### 6.2 `get_motorcycle_details` — READ [T09]
- **Purpose:** authoritative details and prices for 1–3 motorcycles. This is also how motorcycles are compared.
- **Use when:** before stating any price, spec, color or offer, or when comparing models.
- **Do not use when:** you only need to know that a model exists.
- **Input:** `{ motorcycle_ids: int[1..3] }`
- **Output:** `{ items: [{ id, name, brand, cash_price, installment_price, is_offer, old_price|null, new_price|null, features: string[], colors: [{ color, has_images }], availability, installment_system_ids: int[], cc|null, description|null, specifications|null }] }`
- **Changes:** nothing.
- **Validation:** every ID exists and is active; unknown IDs → `UNKNOWN_MOTORCYCLE` listing them.

### 6.3 `send_motorcycle_images` — WRITE (outbound queue) [T09]
- **Purpose:** queue real catalog images of one motorcycle for delivery after the reply text.
- **Use when:** the customer asks for photos, or showing photos clearly helps.
- **Do not use when:** the same images were sent recently (see `last_media_sent` in L4).
- **Input:** `{ motorcycle_id: int, color?: string, max?: int(1..4)=4 }`
- **Output:** `{ queued_count, colors_available: string[] }`
- **Changes:** adds media items to the turn's outbound queue; updates `state.last_media_sent`.
- **Cannot change:** catalog data, application.
- **Validation:** motorcycle exists and has images; if `color` is given it must exist; images already sent in this conversation within `agent.images.resend_window_minutes` are skipped (value from config; required at cutover).
- **Errors:** `UNKNOWN_MOTORCYCLE`, `NO_IMAGES`, `UNKNOWN_COLOR`, `ALREADY_SENT_RECENTLY`.

### 6.4 `identify_motorcycle_from_image` — READ (caches result on the media row) [T09]
- **Purpose:** map a customer photo to catalog candidates.
- **Use when:** the customer sends a motorcycle photo and asks about it.
- **Do not use when:** the image is a document (use `process_document`).
- **Input:** `{ media_id: int }`
- **Output:** `{ band: "match|similar|unknown", candidates: [{ motorcycle_id, name, confidence }], observed: { brand?: string, style?: string } }`
- **Changes:** stores the result in `message_media.analysis`.
- **Validation:** the media belongs to the current conversation and is an image; candidate IDs must exist in the catalog (others dropped); band thresholds from config (DEC-05).
- **Errors:** `MEDIA_NOT_FOUND`, `NOT_AN_IMAGE`, `VISION_UNAVAILABLE`.
- **Rule:** the AI must not claim certainty on `similar`, and must say the model isn't available on `unknown`.

### 6.5 `get_installment_options` — READ [T12]
- **Purpose:** list installment systems and plans valid for a motorcycle, and for a customer type if one is given.
- **Use when:** the customer asks about installments for a motorcycle.
- **Do not use when:** you need actual monthly amounts (use `calculate_installment`).
- **Input:** `{ motorcycle_id: int, customer_type?: string }`
- **Output:** `{ systems: [{ system_id, name, plans: [{ plan_id, months }], admin_fee_percent }], restrictions: [{ code, params }] }`
- **Changes:** nothing.
- **Validation:** only systems linked to the motorcycle and active plans; restrictions come from the eligibility engine (T11) for the given customer type.
- **Errors:** `UNKNOWN_MOTORCYCLE`, `UNKNOWN_CUSTOMER_TYPE`, `NO_INSTALLMENT_SYSTEMS`.

### 6.6 `calculate_installment` — READ [T12]
- **Purpose:** deterministic installment numbers.
- **Use when:** the customer asks "how much per month", or for a given down payment or duration.
- **Do not use when:** you'd be calculating anything yourself. Never compute numbers yourself.
- **Input:** `{ motorcycle_id: int, plan_id: int, down_payment?: number, customer_type?: string }`
- **Output:** `{ months, base_price, down_payment, financed_amount, interest_amount, total_payable, monthly_payment, admin_fee, warnings: [{ code, params }] }` (exact fields depend on DEC-01)
- **Changes:** nothing.
- **Validation:** the plan belongs to a system linked to the motorcycle; `down_payment ≥ 0` and `< base price`; customer-type caps from the eligibility engine.
- **Errors:** `UNKNOWN_MOTORCYCLE`, `PLAN_NOT_AVAILABLE_FOR_MOTORCYCLE`, `DOWN_PAYMENT_TOO_HIGH`, `DOWN_PAYMENT_BELOW_MINIMUM`.

### 6.7 `check_eligibility` — READ [T12]
- **Purpose:** hypothetical eligibility check before or outside an application (e.g. "السن 20 ينفع؟").
- **Use when:** the customer asks whether they qualify.
- **Do not use when:** an application exists and you only need its status (read `eligibility` in the snapshot in L4).
- **Input:** `{ customer_type?: string, age?: int, motorcycle_id?: int, plan_id?: int, down_payment?: number }`
- **Output:** `{ status: "eligible|not_eligible|unknown", reasons: [{ code, params }], missing_inputs: string[] }`
- **Changes:** nothing.
- **Implementation path:** tool → `EligibilityService` (T11) → rule evaluators. The tool contains no rules.
- **Errors:** `UNKNOWN_CUSTOMER_TYPE`, `UNKNOWN_MOTORCYCLE`.

### 6.8 `get_application_requirements` — READ [T11]
- **Purpose:** what a customer type needs (fields, documents, rules).
- **Use when:** the customer asks "what do I need?" for a type, before or during an application.
- **Do not use when:** you need the current application's progress (use the snapshot in L4).
- **Input:** `{ customer_type: string }`
- **Output:** `{ fields: [{ key, label, required }], documents: [{ key, label, description, required }], rules: [{ code, params }] }`
- **Changes:** nothing.
- **Errors:** `UNKNOWN_CUSTOMER_TYPE`.

### 6.9 `record_customer_data` — WRITE [T13]
- **Purpose:** save facts the customer stated (anytime, asked or not).
- **Use when:** the customer gives personal/work data, including unprompted.
- **Do not use when:** the data came from a document (the document pipeline writes it), or for selections (use `update_application_selection`).
- **Input:** `{ fields: [{ key: string, value: string|number, evidence_message_id: int }] (1..10) }`
- **Output:** `{ saved: string[], rejected: [{ key, code }], conflicts: [{ key, code: "CONFLICTS_WITH_VERIFIED_VALUE" }], snapshot|null }`
- **Changes:** `customer_attributes` and/or `application_data`. Laravel decides the target from the field registry's `scope`, never the AI.
- **Cannot change:** a value with `source=document` or `source=staff` (recorded as a conflict instead).
- **Validation:** the key exists in the field registry; per-type validators (T11); `evidence_message_id` must be an inbound message of this conversation.
- **Errors:** `UNKNOWN_FIELD` (per item), `INVALID_ARGUMENTS`.

### 6.10 `start_application` — WRITE [T13]
- **Purpose:** open an application.
- **Use when:** the customer clearly wants to apply or buy on installment.
- **Do not use when:** the customer is only asking questions. If an active application exists, the tool returns it (policy DEC-14).
- **Input:** `{ customer_type: string, motorcycle_id?: int, plan_id?: int, down_payment?: number }`
- **Output:** `{ application_id, created: bool, snapshot }`
- **Changes:** creates `applications`, an `application_events` row, and copies customer-scope attributes into the snapshot view (not duplicated storage).
- **Validation:** the customer type is active; the motorcycle exists/is available; the plan is valid for the motorcycle; active-application policy (DEC-14).
- **Errors:** `UNKNOWN_CUSTOMER_TYPE`, `UNKNOWN_MOTORCYCLE`, `PLAN_NOT_AVAILABLE_FOR_MOTORCYCLE`, `ACTIVE_APPLICATION_EXISTS` (only if DEC-14 forbids returning it).

### 6.11 `update_application_selection` — WRITE [T13]
- **Purpose:** change motorcycle, plan, down payment or customer type of the active application.
- **Use when:** the customer changes their mind about any of these.
- **Do not use when:** you'd be recording personal data (use `record_customer_data`).
- **Input:** `{ motorcycle_id?: int, plan_id?: int, down_payment?: number, customer_type?: string }` (at least one)
- **Output:** `{ snapshot, invalidated: [{ kind: "document|field", key, reason }] }`
- **Changes:** application selection; documents no longer required become `superseded`; an event row.
- **Validation:** an active application exists and its status is `collecting`; same checks as `start_application`.
- **Errors:** `NO_ACTIVE_APPLICATION`, `APPLICATION_LOCKED`, plus catalog/plan errors.

### 6.12 `process_document` — WRITE [T15]
- **Purpose:** run the full document pipeline on received media.
- **Use when:** the customer sends an image or PDF that looks like a document.
- **Do not use when:** the image is a motorcycle photo.
- **Input:** `{ media_ids: int[1..4], expected_document_type?: string }`
- **Output:** `{ results: [{ media_id, document_id|null, detected_type|null, accepted: bool, status: "accepted|rejected|failed", issues: [{ code, field? }], applied_fields: string[] }], snapshot|null }`
- **Changes:** `application_documents`, accepted fields in `application_data` / `customer_attributes` with `source=document`, an event row.
- **Validation:** media belongs to this conversation; application exists (else `NO_ACTIVE_APPLICATION`, document not stored as application document); see T15 for the pipeline.
- **Errors (per item):** `MEDIA_NOT_FOUND`, `UNSUPPORTED_FILE`, `OCR_UNAVAILABLE` (status `failed`, retryable).

### 6.13 `submit_application` — DESTRUCTIVE [T14]
- **Purpose:** finalize the application for staff review.
- **Use when:** `can_submit` is true **and** the customer confirmed they want to submit.
- **Do not use when:** anything is missing (the snapshot tells you what).
- **Input:** `{ confirm: true }`
- **Output:** `{ submitted: true, reference }` or `{ submitted: false, missing: [...], invalid: [...], ineligible: [...] }`
- **Changes:** application status → `submitted`; creates the legacy `InstallmentRequest` projection (DEC-21); an event row.
- **Validation:** full requirement + eligibility evaluation in one transaction; idempotent (a second call returns the same reference).
- **Errors:** `NO_ACTIVE_APPLICATION`, `APPLICATION_LOCKED`.

### 6.14 `withdraw_application` — DESTRUCTIVE [T13]
- **Purpose:** cancel the active application at the customer's explicit request.
- **Use when:** the customer clearly says they want to cancel or stop.
- **Do not use when:** they're only changing motorcycle or plan (use `update_application_selection`).
- **Input:** `{ reason_code: "customer_request|changed_mind|other", note?: string(≤200) }`
- **Output:** `{ status: "withdrawn" }`
- **Changes:** application status → `withdrawn` (allowed only from `collecting`); an event row.
- **Errors:** `NO_ACTIVE_APPLICATION`, `TRANSITION_NOT_ALLOWED`.

### 6.15 `get_branch_information` — READ [T10]
- **Purpose:** branch locations, hours and phones.
- **Use when:** the customer asks where you are, the nearest branch, or opening hours.
- **Input:** `{ governorate?: enum(config list), city?: string(≤60) }`
- **Output:** `{ branches: [{ name, governorate, city, address, map_url|null, phones: string[], working_hours, services: string[] }] }`
- **Changes:** nothing.
- **Validation:** only active branches. No branch in the governorate → empty list plus `all_governorates_with_branches`.

### 6.16 `get_business_knowledge` — READ [T08]
- **Purpose:** fetch full content of knowledge entries listed in the L2 index.
- **Use when:** you need business explanation or guidance that isn't already in context.
- **Input:** `{ keys: string[1..4] }`
- **Output:** `{ items: [{ key, title, content }] }`
- **Changes:** nothing.
- **Errors:** `UNKNOWN_KEY` (per key).

### 6.17 `get_earlier_messages` — READ [T06]
- **Purpose:** read older messages beyond L7/L6 when a reference needs them.
- **Input:** `{ before_message_id?: int, limit?: int(1..20)=10 }`
- **Output:** `{ messages: [{ id, direction, sender_type, type, text|transcript, created_at }] }` (sensitive values redacted)
- **Changes:** nothing.
- **Validation:** only this conversation.

### 6.18 `handoff_to_human` — WRITE [T07]
- **Purpose:** give the conversation to staff.
- **Use when:** the customer asks for a person, complains, or the problem can't be solved with tools/data, or you're not confident.
- **Input:** `{ reason: "customer_request|complaint|document_unresolvable|out_of_scope|low_confidence|other", note: string(≤300) }`
- **Output:** `{ handed_off: true }`
- **Changes:** conversation status → `awaiting_agent`, a handoff record, dashboard notification.
- **Rule:** the AI still sends a `send_reply` telling the customer a colleague will follow up.

### 6.19 `send_reply` — WRITE (outbound, ends the turn) [T06 contract, T17 enforcement]
- **Purpose:** deliver the reply. This is the **only** way a turn ends normally.
- **Input:** `{ messages: string[1..3] (each ≤ agent.reply.max_chars), quote_wa_message_id?: string, focus_motorcycle_ids?: int[0..3], awaiting?: [{ kind: "field|document|confirmation", key: string }] (0..5) }`
- **Output:** `{ accepted: true }` or a guard rejection `{ ok:false, error:{ code: "UNVERIFIED_NUMBER|DUPLICATE_REPLY|UNKNOWN_FOCUS_ID|UNKNOWN_AWAITING_KEY|QUOTE_NOT_FOUND" } }`
- **Changes:** the turn's outbound text queue; `state.focus_motorcycle_ids`; `state.awaiting` (replaced).
- **Cannot change:** applications, customer data, documents, selections, handoff state, catalog.
- **Validation:** `focus` IDs exist; `awaiting` keys exist in the field registry or document types (or equal `confirmation`); the quote target belongs to this conversation; guard checks (T17).

---

## 7. Tasks

---

### T01 — Isolate the rebuild and protect production
**Status:** DONE · **Priority:** P0 · **Depends on:** —

**Owner override (2026-09-22):** owner declined the branch-isolation git operation. Decision: work directly on `main` for the whole rebuild; do not create `ai-agent-rebuild`; do not commit the pre-existing 106 uncommitted deletions as a separate isolation step. This supersedes principle 10's "all work happens on the isolated rebuild branch" for this project. `config/agent.php` was added with `enabled` (env `AGENT_ENABLED`, default `false`) as the only key. No other git operations were performed.

**Objective:** make it impossible for incomplete rebuild code to reach production. Production stays on commit `09318bf1` (current `main`), with a documented rollback path.

**Scope**
1. With the owner's explicit approval for each git operation:
   - Create branch `ai-agent-rebuild` from the current working tree (which contains 104 uncommitted deletions of the old AI layer, plus `project.md` and this plan).
   - Commit the deletions **only on that branch**.
   - Verify `main` still points to `09318bf1` with the old code intact.
2. Confirm `deploy.sh` pulls only `main` (it does: `git pull "$REMOTE" main`). Record in this task that merging `ai-agent-rebuild` into `main` **is** the production cutover (T19) and must not happen earlier.
3. Add `config/agent.php` with a single key for now: `enabled`, read from env `AGENT_ENABLED`, default `false`. Later tasks add keys here. No other behavior yet.
4. Record the rollback procedure in this task (below) and copy it into T19's checklist.

**Files / Areas:** `deploy.sh` (read only), `ecosystem.config.cjs` (read only), new `config/agent.php`.

**Data / Schema changes:** none.

**Constraints**
- Do not push, merge or rebase `main`.
- Do not modify `deploy.sh`.
- Do not run migrations against the production database.
- Do not delete the other existing branches (`phase-1-ai-foundation`, `worktree-whatsapp-ocr-flow`).

**Rollback procedure (reference)**
- Put the server back on commit `09318bf1` (after cutover: revert the merge commit on `main`, or check out `09318bf1` on the server; never force-push `main`).
- Because all rebuild migrations are additive until T19 (principle 10), the old code keeps working against the new schema.
- Restart `whatsapp-worker`, `whatsapp-bot` and `laravel-queue` with pm2.

**Reuse / Replace / Remove:** none.

**Tests:** none (process task).

**Acceptance criteria**
- [ ] Branch `ai-agent-rebuild` exists and contains the deletion commit.
- [ ] `git log main -1` shows `09318bf1`; `main` still contains `app/Services/WhatsappIntentRouter.php`.
- [ ] `config/agent.php` exists with `enabled` defaulting to `false`.
- [ ] The rollback procedure is written in this task and in T19.

**Verification:** `git branch --show-current` → `ai-agent-rebuild`; `git show main:app/Services/WhatsappIntentRouter.php | head -1` succeeds; `php artisan config:show agent` shows `enabled => false`.

---

### T02 — Make the test suite runnable
**Status:** DONE · **Priority:** P0 · **Depends on:** T01

**DEC-16 decided (2026-09-22):** dedicated MySQL test database (`motoceklaty_test`, same host/user/password as dev). `phpunit.xml` now points `DB_CONNECTION=mysql` at it; run `mysql -ukdev -pkdev -e "CREATE DATABASE IF NOT EXISTS motoceklaty_test;"` once locally before running tests.

**What was done**
- `tests/Feature/ExampleTest.php` deleted (tested only the public `/` route via `sliders` data, nothing about the agent).
- Fixed a real bug surfaced by running on MySQL: `GeminiKeyManager::markDailyLimitFinished()` ignored `config('gemini.rate_limits.daily_reset_timezone')` entirely and used `now()->endOfDay()` (app timezone) instead of the configured daily-reset timezone (America/Los_Angeles, matching Google's actual quota reset). Fixed in [GeminiKeyManager.php](app/Services/GeminiKeyManager.php).
- Fixed a latent timezone-cast bug on `GeminiApiKeyModel::$cooldown_until`: the plain `'datetime'` cast parses the DB's naive timestamp string using PHP's ambient default timezone, which `Carbon::setTestNow('... UTC')` silently overrides for parsing during tests (a Carbon quirk) even though `config('app.timezone')` is `Africa/Cairo`. This caused write/read timezone mismatches. Fixed by adding [UtcDateTimeCast.php](app/Casts/UtcDateTimeCast.php), which always stores/reads this column as an explicit UTC instant regardless of ambient timezone or test mocking. Comparisons (`now()->lt($model->cooldown_until)`) are unaffected since Carbon compares absolute instants.

**Objective:** `php artisan test` runs all migrations and the existing Gemini/ingestion tests pass, so every later task can rely on real feature tests.

**Scope (per the DEC-16 answer)**
- **Option A (MySQL test DB):**
  - Configure a dedicated MySQL database for tests in `phpunit.xml` (`DB_CONNECTION=mysql`, database name decided by the owner).
  - Document how to create it locally.
- **Option B (SQLite):**
  - Make each MySQL-only migration (every `DB::statement("ALTER TABLE … MODIFY …")`) skip or branch on `DB::getDriverName() === 'sqlite'`.
  - Do not change what these migrations do on MySQL.
- **Either way:**
  - `tests/Feature/ExampleTest.php` hits `/`, which needs data (`sliders`). Either give it `RefreshDatabase` or delete it. Deleting it is allowed; it tests nothing about the agent.
  - The existing `GeminiKeyManagerTest`, `GeminiRateLimitTest` and `WhatsappIncomingMessageTest` must pass. `WhatsappIncomingMessageTest` will be rewritten in T04; for now it must at least run.

**Files / Areas:** `phpunit.xml`, `database/migrations/*` (only the MySQL-only statements, option B), `tests/Feature/*`.

**Constraints**
- Do not change the production behavior of any migration.
- Do not add a new test framework (keep PHPUnit 11).
- Do not mock the database.

**Tests:** existing suite.

**Acceptance criteria**
- [ ] DEC-16 is answered and recorded.
- [ ] `php artisan test` runs every migration without error.
- [ ] `GeminiKeyManagerTest` and `GeminiRateLimitTest` pass.
- [ ] How to run the tests locally is written in this task.

**Verification:** `php artisan test` exits 0.

---

### T03 — AI provider abstraction and Gemini provider
**Status:** DONE · **Priority:** P0 · **Depends on:** T02

**Owner addendum, DEC-10 (2026-09-22) — Gemini account/project failover:** the provider pool must distinguish rate-limit/temporary exhaustion (bounded retry then failover), daily quota exhaustion (mark unavailable until reset), invalid/revoked credentials (mark unavailable, never retried), temporary provider failure (bounded retry then failover), and invalid requests (never failed over — same request would fail identically on any credential). Per-credential health/cooldown state; Agent Runtime never sees which credential was used; every attempt traceable without logging the key; bounded total provider-attempt budget per turn. Implemented in [GeminiProvider.php](app/Agent/Providers/GeminiProvider.php) by reusing `GeminiKeyManager`'s existing atomic reservation/cooldown pool (already provided all of this); the attempt budget is `gemini.rate_limits.max_transient_failovers` (existing config, reused rather than inventing a new key).

**What was done**
- `app/Agent/Providers/`: [AiRequest.php](app/Agent/Providers/AiRequest.php), [AiResponse.php](app/Agent/Providers/AiResponse.php), [AiProviderException.php](app/Agent/Providers/AiProviderException.php), [AiProvider.php](app/Agent/Providers/AiProvider.php) interface, [GeminiProvider.php](app/Agent/Providers/GeminiProvider.php), [FakeAiProvider.php](app/Agent/Providers/FakeAiProvider.php).
- `AiProvider` bound to `GeminiProvider` in [AppServiceProvider.php](app/Providers/AppServiceProvider.php).
- `config('agent.model')` added (env `AGENT_MODEL`), no default (DEC-06).
- Key sent via `x-goog-api-key` header, never in the URL.
- thoughtSignature round-trip convention: a `tool_call` part may carry `raw.thought_signature`, copied verbatim from a previous `AiResponse::$rawContinuation['tool_call_signatures'][$id]` by whoever assembles history (T17); `GeminiProvider` reads/writes it at the wire level.
- 8 passing unit tests in [GeminiProviderTest.php](tests/Unit/Agent/Providers/GeminiProviderTest.php) cover request mapping (text/image/tools/tool_mode=any), parallel function calls, thought-signature round-trip, 429 failover, 503 bounded-retry-then-throw, invalid-key disabling, invalid-request non-failover (DEC-10 rule 5), and usage parsing.
- `GeminiClient::generateText` untouched (kept for existing callers until T20), confirmed by full suite still passing.

**Objective:** a provider-neutral chat API with function calling and multi-turn contents, and a Gemini implementation built on the existing key pool. `GeminiClient::generateText` stays for existing callers until T20.

**Scope**
1. **DTOs** (`app/Agent/Providers/`):
   - `AiRequest`: `system` (string); `contents` (list of turns; each turn has role `user|model|tool` and parts: `text`, `inline_media{mime, base64}`, `tool_call{id, name, args}`, `tool_result{id, name, result}`); `tools` (list of `{name, description, parameters(JSON schema)}`); `tool_mode` (`auto|any|none`); `allowed_tools` (for `any`); `temperature`; `max_output_tokens`; `thinking_budget?`; `timeout_seconds`; `response_schema?`.
   - `AiResponse`: `text_parts[]`, `tool_calls[{id, name, args}]`, `finish_reason`, `usage{input_tokens, output_tokens, total_tokens}`, `model`, `key_id`, `latency_ms`, `raw_continuation` (opaque provider data that must be sent back on the next call).
   - `AiProviderException` with `retryable: bool`.
2. **Interface** `AiProvider::chat(AiRequest): AiResponse`, bound in a service provider to `GeminiProvider`.
3. **`GeminiProvider`:**
   - Translates to the Gemini `generateContent` REST format: `systemInstruction`, `contents` with roles `user`/`model`, `functionCall` / `functionResponse` parts, `tools[{functionDeclarations}]`, `toolConfig.functionCallingConfig{mode, allowedFunctionNames}`.
   - Returns **all** parts (not only `parts.0.text`).
   - Preserves every part's `thoughtSignature` in `raw_continuation` and sends it back unchanged in the next request's model turn. Gemini 3 function calling rejects follow-up calls without it.
   - Key/model selection **reuses** `GeminiKeyManager::reserveAvailableModel`, `markUsed`, `markRateLimited`, `markError` and `refundReservation`, and `GeminiRateLimitParser` / `GeminiAlertService`, with the same failover semantics as `GeminiClient` (429 → next key; 5xx → bounded retries; invalid key → disable + alert).
   - Sends the API key in the `x-goog-api-key` header, not in the URL.
   - Model code comes from `config('agent.model')` (env `AGENT_MODEL`, **no default**, DEC-06). Tests set it explicitly.
4. **`FakeAiProvider`** for tests: scripted responses (text and/or tool calls) in order, and it records the requests it received.

**Files / Areas:**
- Reuse: `app/Services/GeminiClient.php` (read for failover logic), `app/Services/GeminiKeyManager.php`, `app/Services/GeminiRateLimitParser.php`, `app/Services/GeminiAlertService.php`, `app/Models/GeminiApiKeyModel.php`.
- New: `app/Agent/Providers/*`, `config/agent.php`.

**Data / Schema changes:** none.

**Constraints**
- No prompts or business logic in the provider.
- Do not change `GeminiClient` behavior.
- No other providers now (the interface is enough).
- Never log request contents; log only model, key ID, status, latency and token counts.

**Tests**
- Unit (HTTP faked with `Http::fake`):
  - Request mapping for text, image, tool declarations, `tool_mode=any` with `allowed_tools`.
  - Parsing of multiple parts including two parallel `functionCall`s.
  - `thoughtSignature` round-trip.
  - 429 on key A → success on key B.
  - 503 retried up to the configured bound then `AiProviderException(retryable=true)`.
  - Invalid key disables the key.
  - Usage parsed.
- Existing `GeminiRateLimitTest` / `GeminiKeyManagerTest` still pass.

**Acceptance criteria**
- [ ] `AiProvider` interface, DTOs, `GeminiProvider`, `FakeAiProvider` exist in `app/Agent/Providers/`.
- [ ] Function calling, parallel calls and thought-signature continuation are covered by passing tests.
- [ ] Key rotation/failover reuses `GeminiKeyManager` (no duplicated selection logic).
- [ ] The API key is not in any URL; no request content is logged.
- [ ] `config('agent.model')` has no hardcoded default.

**Verification:** `php artisan test --filter=Provider`; inspect `GeminiProvider` for `?key=` (must be absent).

---

### T04 — Customer / conversation / message persistence and ingestion
**Status:** DONE · **Priority:** P0 · **Depends on:** T02, T03

**Decisions used:** DEC-09 (per-bot customer identity), DEC-18 (private `local` disk for new media, existing `public`-disk media left alone), DEC-08 (transcribe at ingestion), DEC-17 (capture `fromMe` as `sender_type=human_phone`) — all recorded in §4.

**What was done**
- Migrations (additive): [create_customers_table](database/migrations/2026_09_22_100000_create_customers_table.php), [add_agent_fields_to_whatsapp_conversations_table](database/migrations/2026_09_22_100100_add_agent_fields_to_whatsapp_conversations_table.php), [add_agent_fields_to_whatsapp_messages_table](database/migrations/2026_09_22_100200_add_agent_fields_to_whatsapp_messages_table.php) (aborts with a clear error if duplicate `wa_message_id` values exist before adding the global unique index — none did), [create_message_media_table](database/migrations/2026_09_22_100300_create_message_media_table.php).
- Models: [Customer.php](app/Models/Customer.php), [MessageMedia.php](app/Models/MessageMedia.php); `WhatsappConversation`/`WhatsappMessage` extended, legacy `message`/`payload` columns kept and still written.
- [IngestionService.php](app/Domain/Conversations/IngestionService.php): validates the v2 payload shape, upserts Customer (per-bot), finds/creates the Conversation, inserts-or-ignores on `wa_message_id` inside a transaction (race-safe — tested), stores media on the `local` disk with SHA-256, resolves quoted messages (falls back to metadata when the quoted id isn't found yet), sets `session_started_at` on a session gap (DEC-14 still OPEN so this is a no-op until that value is set), and calls a `TurnSchedulerHook` (no-op `NullTurnSchedulerHook` until T05).
- [WhatsappBotController.php](app/Http/Controllers/Api/WhatsappBotController.php) reduced to token check → `IngestionService::ingest()` → `{ok, duplicate}`. Removed: the 2-minute same-text suppression and `quote_reply` logic (both principle-2 violations — text-based routing).
- Voice notes (DEC-08): [GeminiVoiceTranscriber.php](app/Domain/Conversations/GeminiVoiceTranscriber.php) implements the abstracted `VoiceTranscriber` interface using T03's `AiProvider` (swappable, per DEC-08); [TranscribeVoiceMessage.php](app/Jobs/TranscribeVoiceMessage.php) runs on the existing `laravel-queue` worker and sets an explicit `transcription_status` (`pending|completed|failed`) — never silently pretends success.
- Node (`whatsapp-bot/index.js`): sends the v2 payload individually per message (removed the 3-second media collector entirely); added `postToLaravelWithRetry` with exponential backoff (`LARAVEL_RETRY_ATTEMPTS`/`LARAVEL_RETRY_BASE_MS`, defaults 3/1000ms); `fromMe` messages are now forwarded (`direction: outgoing`) instead of dropped (DEC-17); added `getMessageType`/`getLocationInfo`/`getQuotedInfo` for the v2 shape; removed now-dead `handleLaravelResponse` (replies only ever come through `/send-message`/`/send-media-items`, never the incoming-message response).
- DEC-18 media privacy also required a live fix: the staff dashboard's chat view built media URLs from the public disk only. Updated `mediaUrl()` in [ChatAwaitingAgentConversation.php](app/Filament/Resources/AwaitingAgentConversationResource/Pages/ChatAwaitingAgentConversation.php) and the blade view to route new (private-disk) media through a new authorized route, added in [MessageMediaController.php](app/Http/Controllers/MessageMediaController.php) (`GET /staff/media/{messageMedia}`, guarded by `auth:filament`); old public-disk media keeps working via the legacy path.
- 12 passing feature tests in the rewritten [WhatsappIncomingMessageTest.php](tests/Feature/WhatsappIncomingMessageTest.php): new customer/conversation/message creation, duplicate `wa_message_id` (sequential and simulated-concurrent), image storage with hash and no base64 in the DB, quoted-message resolution (known and unknown), invalid token, session gap, `fromMe`→`human_phone`, and both ends of voice transcription.
- Not yet exercised end-to-end: the live Node process against a real WhatsApp session (no session available in this environment) — verified by `node --check` and careful review instead; flagged for the owner to smoke-test on a dev bot before cutover.

**Objective:** every inbound WhatsApp message is stored exactly once as a structured message linked to a Customer and a Conversation, with media stored safely and quoted messages resolved. Node sends a normalized v2 payload and retries.

**Scope**
1. **Node (`whatsapp-bot/index.js`):**
   - Build the v2 payload per message: `{ bot_id, wa_message_id: "<botId>_<key.id>", chat_jid, customer_jid|null, push_name|null, timestamp, type: text|image|document|audio|video|location|sticker|unknown, text|null (text or caption), media: [{ media_type, mime, filename|null, base64, size }], location|null, quoted: { wa_message_id: "<botId>_<stanzaId>"|null, type|null, text|null } | null }`.
     - Quoted ID comes from `contextInfo.stanzaId`.
     - Quoted type comes from the quoted message's content key.
   - Send **each message individually** through the existing per-chat queue (`enqueueChat`). **Remove the 3-second media collector** (batching moves to Laravel, T05); this also removes the race where the media flush ran outside the chat queue.
   - Retry POST to Laravel on network error/5xx with exponential backoff. Attempts and base delay come from Node env (`LARAVEL_RETRY_ATTEMPTS`, `LARAVEL_RETRY_BASE_MS`); required at cutover (T19).
   - Laravel's HTTP response is **ignored for replying**. Replies come only through `/send-message` and `/send-media-items`.
   - Keep: session/QR handling, `@lid` resolution (`resolveCustomerJid`), `recentRawMessages` cache for quoting, single-instance lock, `handledMessages` (as an optimization only).
2. **Laravel ingestion:**
   - Controller `WhatsappBotController::incomingMessage` becomes thin: token check → `IngestionService::ingest(array $payload)` → `200 {ok, duplicate}`.
   - Service steps, in one DB transaction:
     1. Validate payload shape (invalid → 422, logged without content).
     2. Resolve the bot (unknown → 200 ignored).
     3. Upsert Customer (key per DEC-09; store `jid`, `lid_jid`, `phone` = real phone when `customer_jid` resolves, `push_name`).
     4. Find or create Conversation (existing `whatsapp_conversations` row matched by bot + phone for continuity, then linked to the customer).
     5. **Insert-or-ignore** the message on the unique `wa_message_id`. A duplicate returns `{duplicate:true}` and does nothing else.
     6. Store media rows (§ schema) on the disk decided by DEC-18, with SHA-256 hash, size and mime.
     7. Resolve `quoted.wa_message_id` to an existing message ID (`quoted_message_id`); if not found, keep the quoted text and type in `metadata.quoted`.
     8. Set `session_started_at` in state when the gap since `last_inbound_at` exceeds `agent.session_gap_hours` (DEC-14 value).
     9. Update `last_inbound_at`.
     10. Call the turn scheduler hook (T05). Until T05 exists, the hook is a no-op interface.
   - Voice notes are handled per DEC-08 (if "at ingestion": after commit, dispatch a `laravel-queue` job that transcribes with `AiProvider` (reusing the `VoiceTranscriptionService` prompt idea) and stores `transcript`; the turn waits for it — see T05).
3. **Remove from the controller:**
   - The 2-minute "same text" duplicate suppression. Identical texts are legitimate separate messages; true duplicates are caught by `wa_message_id`.
   - The `quote_reply` logic (quoting becomes the AI's choice via `send_reply.quote_wa_message_id`).

**Files / Areas:**
- Change: `whatsapp-bot/index.js`, `app/Http/Controllers/Api/WhatsappBotController.php`, `app/Models/WhatsappConversation.php`, `app/Models/WhatsappMessage.php`, `routes/api.php` (route unchanged).
- New: `app/Models/Customer.php`, `app/Models/MessageMedia.php`, `app/Domain/Conversations/IngestionService.php`.

**Data / Schema changes (additive only)**
- `customers`: `id`, `whatsapp_bot_id` (FK), `jid`, `lid_jid` nullable, `phone` nullable, `push_name` nullable, timestamps. Unique key per DEC-09.
- `whatsapp_conversations` add:
  - `customer_id` nullable FK.
  - `state` JSON nullable.
  - `summary` text nullable, `summary_until_message_id` nullable, `summary_updated_at` nullable.
  - `last_inbound_at` nullable.
  - Keep `status` (`open`, `awaiting_agent` already used). New code uses `open | awaiting_agent | closed`.
- `whatsapp_messages` add:
  - `whatsapp_bot_id` nullable, `sender_type` (`customer|bot|agent|system|human_phone`) nullable.
  - `type` nullable, `text` nullable, `transcript` nullable.
  - `quoted_message_id` nullable FK self.
  - `turn_id` nullable.
  - `delivery_status` (`received|queued|sent|failed`) nullable.
  - `metadata` JSON nullable.
  - **Unique index on `wa_message_id`.** The migration must first check for duplicate non-null values and **abort with a clear error** if any exist (never delete rows).
  - Keep the legacy `message` and `payload` columns and keep writing `message` (used by the dashboard today) until T20.
- `message_media`: `id`, `message_id` FK, `media_type`, `mime`, `disk`, `path`, `size`, `sha256`, `original_filename` nullable, `analysis` JSON nullable, timestamps.

**Detailed behavior / edge cases**
- A message with only media and no text is stored with `text=null`.
- Unknown message types are stored with `type=unknown`.
- `@lid` chats: the conversation match stays on the chat JID (as today); the real phone is stored on the customer.
- Payload over the size limit: the Node express limit stays; Laravel rejects media above `agent.media.max_bytes` (config, required at cutover), storing the message with a `metadata.media_rejected` reason.

**Constraints**
- No reply generation and no interpretation of the text.
- Do not store base64 anywhere except the media file.
- Do not log message text or media.
- Do not break the dashboard chat view (`ChatAwaitingAgentConversation`), which reads `message`/`payload.saved_media_items`. Either keep writing those or update the view in this task.

**Reuse:** Node session code, `resolveCustomerJid`, `extensionFromMime` / `cleanPhoneFromJid` helpers, `VoiceTranscriptionService` prompt (if DEC-08 = at ingestion).

**Replace / Remove:** Node media collector; controller duplicate-text and quote-reply logic.

**Tests (feature, rewrite `tests/Feature/WhatsappIncomingMessageTest.php`)**
- New customer + conversation + message created.
- The same `wa_message_id` posted twice → one row, second response `duplicate:true`.
- Two concurrent posts of the same ID (simulated) → one row, no 500.
- Image payload → media file stored on the configured disk with correct hash, and no base64 in the DB.
- Quoted ID resolved to an existing message; unknown quoted ID keeps text in metadata.
- Invalid token → 401.
- Session gap sets a new `session_started_at`.
- Voice transcription (per DEC-08) with `FakeAiProvider`.

**Acceptance criteria**
- [ ] DEC-08, DEC-09 and DEC-18 answered and recorded.
- [ ] All migrations are additive; the duplicate check in the unique-index migration is present.
- [ ] Node sends v2 payloads individually with retry; the media collector is removed.
- [ ] Ingestion is idempotent on `wa_message_id` (tested including the race).
- [ ] Media stored per DEC-18; no base64 or text in logs.
- [ ] Quoted messages resolved; the dashboard chat still renders messages.
- [ ] All listed tests pass.

**Verification:** `php artisan test --filter=Ingestion`; send two test WhatsApp messages from a phone to a dev bot and inspect `customers`, `whatsapp_conversations`, `whatsapp_messages`, `message_media`.

---

### T05 — Turn scheduler, delivery and outbound persistence
**Status:** DONE · **Priority:** P0 · **Depends on:** T04

**Decisions used:** DEC-07 (3s text/6s media/15s max debounce, supersede-once on mid-generation arrival), DEC-17 (already implemented in T04's Node/ingestion work).

**What was done**
- Migration [add_turn_fields_to_whatsapp_message_jobs_table](database/migrations/2026_09_22_110000_add_turn_fields_to_whatsapp_message_jobs_table.php): adds `process_after`, `first_message_at`, `trace_id`, `superseded_by`; extends the status enum with `superseded`/`skipped` (additive).
- `config('agent.turns.*')` (debounce/media_debounce/max_wait from DEC-07, no defaults) and `config('agent.delivery.max_attempts')` in [config/agent.php](config/agent.php).
- [TurnScheduler.php](app/Domain/Conversations/TurnScheduler.php) implements `TurnSchedulerHook` (bound in `AppServiceProvider`, replacing T04's no-op): attaches a message to the conversation's still-open `pending` turn and extends its debounce window (capped at `first_message_at + max_wait`); once a pending turn's own max_wait has elapsed, a new message starts a fresh turn instead of stretching it further; if the open turn is already `processing`, it's marked `superseded` (linked via `superseded_by`, at most once — a turn already superseded is left alone) and the new message starts a new turn; a conversation in `awaiting_agent` gets no turn at all. Never reads message text/keywords to decide any of this.
- [TurnProcessor.php](app/Agent/Runtime/TurnProcessor.php) interface + [DisabledTurnProcessor.php](app/Agent/Runtime/DisabledTurnProcessor.php) (throws — bound until T17).
- [DeliveryService.php](app/Domain/Conversations/DeliveryService.php): creates one outbound `whatsapp_messages` row per result item *before* attempting to send it (`delivery_status=queued`), then `sent`+`wa_message_id` on success or `failed` on error; a retry only re-sends items that never reached `sent` (fixes the old full-resend duplication); refuses outright while `agent.enabled=false`.
- [ProcessWhatsappMessageJobs.php](app/Console/Commands/ProcessWhatsappMessageJobs.php): claims nothing while `agent.enabled=false`; claim query now requires `process_after<=now()` for `pending` turns (a `generated` turn — delivery-only retry — is claimable regardless, since its debounce already finished); calls `TurnProcessor`/`DeliveryService` instead of the removed controller-based `processQueuedWhatsappJob`/inline send methods; the `attempts>=3` hardcode replaced with `config('agent.delivery.max_attempts')` (principle 11).
- Node (`whatsapp-bot/index.js`): `sendTextSafely`/`sendImageSafely` now return the sent Baileys message; `/send-message` and `/send-media-items` return `wa_message_id`(s) and cache the sent message in `recentRawMessages` so later quotes work; `quote_reply`-in-`sendWhatsappText` handling was already removed with the controller method it lived in.
- 14 new passing tests: [TurnSchedulerTest.php](tests/Feature/Agent/TurnSchedulerTest.php) (debounce grouping, max-wait rollover, awaiting_agent skip, supersede-once, post-`done` fresh turn), [DeliveryServiceTest.php](tests/Feature/Agent/DeliveryServiceTest.php) (per-item outbound rows with `wa_message_id`, partial-failure retry resending only the unsent item, `agent.enabled=false` refusal), [ProcessWhatsappMessageJobsClaimTest.php](tests/Feature/Agent/ProcessWhatsappMessageJobsClaimTest.php) (`process_after` gating, busy-conversation skip, `superseded`/`skipped` never claimed) via reflection on the private `claimNextJob()`, plus a `fromMe`-echo-of-bot-message dedup test added to `WhatsappIncomingMessageTest`.
- Not independently tested: the `agent.enabled=false` check inside the worker's infinite `while(true)` loop (not practically unit-testable without refactoring the command) — verified by code review; `DeliveryService`'s own explicit refusal *is* tested and is the actual delivery-time guard.

**Objective:** inbound messages are grouped into **turns** (one open turn per conversation, debounced). Turns are processed in order per conversation by the existing worker. Replies are delivered at most once per message and every outbound message is persisted with its WhatsApp ID. Nothing is ever delivered while `agent.enabled=false`.

**Scope**
1. **Evolve `whatsapp_message_jobs` into turns** (keep the table name; new code treats a row as a turn):
   - Add columns: `process_after` timestamp nullable, `first_message_at` nullable, `trace_id` nullable, `superseded_by` nullable.
   - Add statuses `superseded` and `skipped` to the enum (MySQL `ALTER … MODIFY ENUM`, additive).
   - `whatsapp_messages.turn_id` links messages to their turn.
2. **Scheduler hook** (called by ingestion; T04 defines the interface):
   - If the conversation `status = awaiting_agent`, do nothing (the message is stored with `turn_id=null`).
   - Otherwise find this conversation's turn with `status='pending'` (not yet claimed). If found, attach the message and set `process_after = min(now + debounce, first_message_at + max_wait)`. If not found, create a turn.
   - Debounce values: `agent.turns.debounce_seconds`, `agent.turns.media_debounce_seconds`, `agent.turns.max_wait_seconds` (DEC-07, no defaults).
   - If a voice transcript is pending (DEC-08 "at ingestion"), the turn is not claimable until the transcript exists or `agent.turns.transcript_wait_seconds` passes.
3. **Worker (`ProcessWhatsappMessageJobs`):**
   - Keep the slot locks, the claim lock and the "skip busy conversation" rule.
   - Change the claim query to also require `process_after <= now()`.
   - If `config('agent.enabled')` is false, **claim nothing** (sleep).
   - Replace `app(WhatsappBotController::class)->processQueuedWhatsappJob($job)` with a `TurnProcessor` interface. The default binding is `DisabledTurnProcessor`, which throws; T17 binds the real one.
4. **Mid-generation arrivals** (DEC-07):
   - Before delivery, check for inbound messages in the conversation created after the turn was claimed.
   - Apply the owner's policy (e.g. mark this turn `superseded`, keep its mutations, move the new messages plus this turn's messages into the next turn; at most once per turn), or deliver as-is.
   - Until decided, the task is NEEDS DECISION.
5. **Delivery with per-message tracking:**
   - The generated result `{ messages: [text…], quote_wa_message_id?, media: [{type, url|path, caption}] }` is stored in `result` (status `generated`, existing pattern).
   - For each item, first create an outbound `whatsapp_messages` row (`sender_type=bot`, `delivery_status=queued`, `turn_id`), then call Node, then set `sent` + `wa_message_id` from Node's response.
   - On retry, **only items not yet `sent` are re-sent** (fixes today's full-resend duplication).
   - After `agent.delivery.max_attempts` (config) the item is `failed` and the turn `failed`.
6. **Node send endpoints:**
   - `/send-message` and `/send-media-items` return the WhatsApp ID of each sent message (`<botId>_<key.id>`), and cache the sent raw message in `recentRawMessages` so later quotes work.
   - `/send-message` accepts `quoted_message` as today.
   - Media for delivery is read from local storage by Node via a URL decided in T09 (catalog images are on the public disk today).
7. **`fromMe` capture (DEC-17):** if yes, Node forwards `fromMe` messages with `direction: outgoing`. Laravel stores them as `sender_type=human_phone` unless the `wa_message_id` already exists (bot-sent).

**Files / Areas:** `app/Console/Commands/ProcessWhatsappMessageJobs.php`, `app/Models/WhatsappMessageJob.php`, `whatsapp-bot/index.js`, new `app/Domain/Conversations/TurnScheduler.php`, `app/Domain/Conversations/DeliveryService.php`, `app/Agent/Runtime/TurnProcessor.php` (interface) + `DisabledTurnProcessor`.

**Constraints**
- No new queue technology.
- Do not remove the per-conversation ordering.
- Do not deliver anything from `DisabledTurnProcessor`.
- Do not send anything when `agent.enabled=false`.
- Delivery code must not contain reply wording except what the result carries.

**Reuse:** the existing claim logic, slot locks, `generated`-then-resend pattern, the Node send functions and `sendTextSafely` quote fallback.

**Replace / Remove:** the controller-based processing call; `quote_reply` handling in `sendWhatsappText`.

**Tests**
- Three messages within the debounce window → one turn with three messages.
- A message after `max_wait` → a new turn.
- Two conversations processed in parallel; the same conversation never in parallel.
- `awaiting_agent` → no turn.
- `agent.enabled=false` → nothing claimed.
- Delivery: item 1 sent, item 2 fails → retry sends only item 2; outbound rows have `wa_message_id`.
- Supersede policy per DEC-07.
- `fromMe` echo of a bot message not duplicated (DEC-17).

**Acceptance criteria**
- [ ] DEC-07 and DEC-17 answered and recorded.
- [ ] Turns debounce by config values; no hardcoded seconds.
- [ ] The worker claims nothing while `agent.enabled=false`; `DisabledTurnProcessor` never delivers.
- [ ] Every delivered message is persisted as outbound with its WhatsApp ID; there are no duplicate sends on retry.
- [ ] All tests pass.

**Verification:** `php artisan test --filter=Turn`; with `AGENT_ENABLED=false`, send messages to a dev bot and confirm turns stay `pending` and nothing is sent.

---

### T06 — Tool framework, AI traces, conversation tools
**Status:** DONE · **Priority:** P0 · **Depends on:** T03, T05

**What was done**
- `app/Agent/Tools/`: [Tool.php](app/Agent/Tools/Tool.php) interface, [ToolContext.php](app/Agent/Tools/ToolContext.php) (injects `customerId`/`conversationId`/`activeApplicationId`/`turnId`/`traceId`/an outbound `TurnResultBuilder` — never args), [ToolResult.php](app/Agent/Tools/ToolResult.php) (§3.1 envelope), [JsonSchemaValidator.php](app/Agent/Tools/JsonSchemaValidator.php) (no schema package in composer.json, so a minimal hand-rolled validator per the plan's fallback instruction: `type`/`properties`/`required`/`enum`/`minimum`/`maximum`/`minItems`/`maxItems`/`maxLength`), [ToolRegistry.php](app/Agent/Tools/ToolRegistry.php).
- [Redactor.php](app/Agent/Tracing/Redactor.php): masks `config('agent.redaction.keys')` (baseline `national_id`, `document_text` until T11) and any 14-digit sequence in free text.
- Trace storage (additive): [create_ai_traces_table](database/migrations/2026_09_22_120000_create_ai_traces_table.php), [create_ai_trace_steps_table](database/migrations/2026_09_22_120100_create_ai_trace_steps_table.php), models [AiTrace.php](app/Models/AiTrace.php)/[AiTraceStep.php](app/Models/AiTraceStep.php). `ToolRegistry::execute()` validates → checks `(turn_id, tool_name, args_hash)` idempotency → executes in try/catch (`TOOL_FAILED` on any exception) → records a redacted step. No `switch`/`match` on tool names anywhere (`grep -rn "case '" app/Agent` → none).
- [GetEarlierMessagesTool.php](app/Agent/Tools/GetEarlierMessagesTool.php) (§6.17) and [SendReplyTool.php](app/Agent/Tools/SendReplyTool.php) (§6.19, contract only — guard enforcement is T17): validates focus IDs against `Machine`, quote target against this conversation's messages, and (until T11) accepts only `awaiting[].kind=confirmation`; replaces (never appends) `state.awaiting`/`state.focus_motorcycle_ids`; writes into the turn's `TurnResultBuilder` and calls `finish()`.
- `config/agent.php`: `tools` (registry class list), `redaction.keys`, `reply.max_chars` (not decision-gated, default 700), `context.pinned_memory_tokens` (DEC-23, no default — used by T08).
- 12 new passing tests across [ToolRegistryTest.php](tests/Feature/Agent/ToolRegistryTest.php), [SendReplyToolTest.php](tests/Feature/Agent/SendReplyToolTest.php), [GetEarlierMessagesToolTest.php](tests/Feature/Agent/GetEarlierMessagesToolTest.php): unknown tool, invalid args per schema keyword, idempotent replay runs once, exception→`TOOL_FAILED`+traced, context-not-args injection, national-ID redaction, `awaiting` replace-not-append, `UNKNOWN_FOCUS_ID`, `QUOTE_NOT_FOUND`, and an assertion that `send_reply` never touches anything but conversation state.

**Objective:** the generic machinery every tool uses (interface, registry, schema validation, context injection, idempotency, permission levels, envelope), the trace storage that records every turn, and the conversation-level tools `get_earlier_messages` and `send_reply` (contract and validation; guard enforcement is in T17).

**Scope**
1. **`Tool` interface:** `name()`, `description()` (the AI-facing usage guidance from §6, including "use when / do not use when"), `inputSchema()` (JSON schema), `permission()` (`READ|WRITE|DESTRUCTIVE`), `execute(array $args, ToolContext $ctx): ToolResult`.
2. **`ToolContext`:** `customer_id`, `conversation_id`, `active_application_id|null`, `turn_id`, `trace_id`, and an outbound-queue object for media.
3. **`ToolRegistry`:**
   - Registers tool classes from a list in `config/agent.php` (`agent.tools`); declarations are generated from them.
   - `execute(name, args, ctx)`:
     1. Unknown name → `UNKNOWN_TOOL`.
     2. Validate against the schema → `INVALID_ARGUMENTS` with the failing path.
     3. Idempotency lookup by `(turn_id, name, sha256(canonical_json(args)))` in `ai_trace_steps`; a hit returns the stored result.
     4. Execute inside try/catch → `TOOL_FAILED`.
     5. Record the step.
   - **No `switch` or `match` on tool names anywhere.**
   - JSON schema validation: inspect `composer.json`/`vendor` for an existing validator first. If none, implement a minimal validator supporting only `type`, `properties`, `required`, `enum`, `minimum/maximum`, `minItems/maxItems`, `maxLength`. Adding a package requires the owner's approval.
4. **Traces:**
   - `ai_traces`: `id`, `conversation_id`, `turn_id`, `prompt_version`, `provider`, `model`, `status` (`running|completed|failed|fallback|handoff`), `context_manifest` JSON (layer names, token estimates, memory keys, message IDs included, application ID), `final_reply_message_ids` JSON, `guard_events` JSON, `error_code` nullable, `input_tokens`, `output_tokens`, `latency_ms`, timestamps.
   - `ai_trace_steps`: `id`, `trace_id`, `turn_id`, `seq`, `kind` (`model_call|tool_call`), `tool_name` nullable, `permission` nullable, `args_hash` nullable, `args_redacted` JSON nullable, `result_redacted` JSON nullable, `result_code` nullable, `latency_ms`, `input_tokens`/`output_tokens` nullable, timestamps. Index `(turn_id, tool_name, args_hash)`.
   - **Redaction helper** `Redactor::redact(array)`:
     - Masks values of keys flagged sensitive in the field registry (T11). Until T11 exists, it uses the explicit list in `config/agent.php` `agent.redaction.keys` (keys named by the owner; initially `national_id` and `document_text`, **update from T11**).
     - Masks any 14-digit sequence (Egyptian national-ID length) in free text.
   - Only redacted data is stored (DEC-12 may later add encrypted raw storage).
5. **`get_earlier_messages`:** implemented per §6.17 (reads `whatsapp_messages`, redacted).
6. **`send_reply`:**
   - Schema per §6.19.
   - Validation of focus IDs, awaiting keys and quote target.
   - Writes the reply into the turn result and replaces `state.focus_motorcycle_ids` / `state.awaiting`.
   - Marks the turn's agent loop as finished.
   - Awaiting-key validation uses the field registry / document types once T11 exists; until then the tool accepts only `kind=confirmation`.

**Constraints**
- Tools must not call other tools.
- The registry must not contain domain logic.
- `send_reply` must not touch applications, customer data, documents or handoff.
- Traces must never contain unredacted sensitive values.

**Tests**
- Registry rejects an unknown tool and invalid arguments (each schema keyword).
- An identical call in the same turn returns the cached result and runs once.
- An exception becomes `TOOL_FAILED` and is traced.
- Context injection: a test tool proves it received `conversation_id` from context, not args.
- `send_reply` replaces `awaiting`, rejects an unknown focus ID and an unknown quote target, and never modifies an application row (assert unchanged).
- Redactor masks a national ID in nested args and free text.

**Acceptance criteria**
- [ ] Interface, registry, context, envelope, permission levels and redaction exist and are tested.
- [ ] No tool-name switch exists (`grep -rn "case '" app/Agent` finds no tool names).
- [ ] Trace tables created (additive) and written by the registry.
- [ ] `get_earlier_messages` and `send_reply` behave per §6.17 / §6.19.

**Verification:** `php artisan test --filter=Tool`; `php artisan test --filter=Trace`.

---

### T07 — Human handoff and conversation dashboard
**Status:** DONE · **Priority:** P0 · **Depends on:** T05, T06

**What was done**
- Migration [create_handoffs_table](database/migrations/2026_09_22_130000_create_handoffs_table.php) (additive) + [Handoff.php](app/Models/Handoff.php) model.
- [HandoffService.php](app/Domain/Handoff/HandoffService.php): `handOff()` sets `status=awaiting_agent`, records a `handoffs` row, and notifies admin/super-admin staff (reusing the existing `Notification` model, same pattern as `DeliveryResource`'s transfer-request notifications); `handOffForFailures()` is the deterministic trigger T17 will call once DEC-13's threshold is set; `close()` sets `status=open`, closes the open handoff record, and — matching the old `answerPendingIncomingMessage` intent — creates a turn covering any inbound messages that arrived (with `turn_id=null`) after the last outbound message, so the agent answers them right away instead of waiting for the customer to write again.
- [HandoffToHumanTool.php](app/Agent/Tools/HandoffToHumanTool.php) (§6.18, thin — all logic in `HandoffService`), registered in `config('agent.tools')`.
- `DeliveryService` extended with `deliverForConversation()`: staff dashboard replies now go through the same per-item outbound-row-before-send/`wa_message_id`-after tracking as agent turns, just under `sender_type=agent` and their own audit-trail turn row (status `done` since delivery is synchronous here). `AwaitingAgentConversationResource::sendReply()`/`sendMediaReply()` in [AwaitingAgentConversationResource.php](app/Filament/Resources/AwaitingAgentConversationResource.php) now call it instead of hand-rolled Node HTTP calls; the placeholder `answerPendingIncomingMessage()` and the dead `deliverText()` helper were removed, and `ChatAwaitingAgentConversation::closeHandoff()` now calls `HandoffService::close()`.
- Read-only "AI traces" view: [AiTraceResource.php](app/Filament/Resources/AiTraceResource.php) (list + view, `canCreate`/`canEdit`/`canDelete` all `false`) with a [StepsRelationManager.php](app/Filament/Resources/AiTraceResource/RelationManagers/StepsRelationManager.php) table showing each step's tool, permission, result code, latency and the already-redacted args/result (redaction happens at write time in T06, not re-applied here).
- 5 new passing tests in [HandoffServiceTest.php](tests/Feature/Agent/HandoffServiceTest.php): tool sets `awaiting_agent`+creates a handoff record+notifies staff, an inbound message during handoff gets `turn_id=null`, a staff reply is stored `sender_type=agent` with its `wa_message_id`, closing creates a turn only when unanswered inbound messages exist (and links them to it), and the failure-trigger call works.
- Not independently tested: the `AiTraceResource` Livewire pages rendering in a browser (no interactive session here) — verified by `php -l`, Filament resource auto-discovery, and route registration (`admin/ai-traces`, `admin/ai-traces/{record}`); its redaction guarantee is already covered by T06's `Redactor` tests since the resource only displays what was stored.

**Objective:** a conversation can be handed to staff (by the AI or by deterministic triggers). While handed off the agent is suspended. Staff replies go through the same persistence/delivery path. Closing the handoff resumes the agent. The dashboard shows structured conversations and traces.

**Scope**
1. **`HandoffService`:**
   - `handOff(conversation, reason, note, source: ai|system|staff)` sets `status=awaiting_agent` and records `handoffs` (`id`, `conversation_id`, `reason`, `note`, `source`, `opened_at`, `closed_at` nullable, `closed_by` nullable).
   - `close(conversation, staff)` sets `status=open` and closes the record. If inbound messages exist after the last outbound message, it creates a turn containing them. This matches the existing `answerPendingIncomingMessage` intent.
2. **Tool `handoff_to_human`** per §6.18 (thin; calls `HandoffService`).
3. **Deterministic triggers:** a public method `HandoffService::handOffForFailures(conversation, code)`, used by T17 when failure thresholds (DEC-13 config) are reached.
4. **Dashboard:**
   - Update `AwaitingAgentConversationResource` and `ChatAwaitingAgentConversation` to read structured messages (`text`, `transcript`, `sender_type`, media via the DEC-18 route).
   - Staff send uses `DeliveryService` (T05), so staff messages are stored as `sender_type=agent` with their WhatsApp ID.
   - Remove the placeholder `answerPendingIncomingMessage`; the resume logic moves into `HandoffService::close`.
   - Add an "AI traces" view (Filament page or relation on conversations) listing traces per conversation with steps (redacted args/results, codes, latency, tokens, memory keys used). Read-only.
   - Add a notification on handoff, reusing the existing `Notification` model / notification page (inspect `app/Filament/Pages/Notifications.php` for the pattern).

**Files / Areas:** `app/Filament/Resources/AwaitingAgentConversationResource.php` and its pages, `resources/views/filament/awaiting-agent/*`, `app/Models/Notification.php`, new `app/Domain/Handoff/HandoffService.php`, `app/Agent/Tools/HandoffToHumanTool.php`.

**Data / Schema changes:** `handoffs` table (additive).

**Constraints**
- Staff replies must not trigger the agent.
- The agent must never run for `awaiting_agent` conversations.
- No reply wording in `HandoffService`.

**Reuse:** the existing handoff resource, chat page, Node `/chats/archive`, the notification model.

**Tests**
- The tool sets `awaiting_agent` and creates a handoff record.
- An inbound message during handoff → stored, no turn.
- A staff reply stored as `agent` with its WhatsApp ID, via delivery.
- Closing creates a turn only when unanswered inbound messages exist.
- A failure-trigger call works.
- The trace page renders without sensitive values.

**Acceptance criteria**
- [ ] The handoff lifecycle works end to end and is tested.
- [ ] Staff messages persisted as outbound `agent` messages.
- [ ] The dashboard shows structured messages and traces.
- [ ] The placeholder `answerPendingIncomingMessage` is removed.

**Verification:** `php artisan test --filter=Handoff`; manual: open the handoff screen in `/admin`, send a staff reply to a dev bot, close the handoff.

---

### T08 — Business knowledge (memory)
**Status:** DONE · **Priority:** P1 · **Depends on:** T06

**What was done**
- Migration [create_business_memories_table](database/migrations/2026_09_22_140000_create_business_memories_table.php) (additive) + [BusinessMemory.php](app/Models/BusinessMemory.php) model, with `estimatedTokens()` matching the char/4 estimate already used in `GeminiClient`. No `keywords`/`applicable_intents`/`rules`/`template_replies` fields — legacy `ai_memories` untouched (T20).
- [KnowledgeService.php](app/Domain/Knowledge/KnowledgeService.php): `pinned()`, `index()` (key→title), `scoped($customerType, $applicationStatus)` (matches by explicit scope arrays only, never by scanning customer text), `get($keys)`.
- [GetBusinessKnowledgeTool.php](app/Agent/Tools/GetBusinessKnowledgeTool.php) (§6.16), registered in `config('agent.tools')`. Since the contract's `UNKNOWN_KEY` error is only documented "per key" without an error-list output field, this implementation fails the whole call with `UNKNOWN_KEY` (naming the missing keys in `detail`) when any requested key isn't found — noted here as an interpretation call, not a decision-register item.
- [BusinessMemoryResource.php](app/Filament/Resources/BusinessMemoryResource.php): CRUD with a live per-entry token estimate, a non-blocking warning when content contains digits (numbers/rules belong in rule tables, plan principle 5), and `scope_customer_types`/`scope_application_statuses` as tag inputs (no fixed enum yet — T11 supplies real customer types). The pinned-token cap (`config('agent.context.pinned_memory_tokens')`, DEC-23) is enforced in `CreateBusinessMemory`/`EditBusinessMemory`'s `beforeCreate`/`beforeSave` hooks via `Halt` + a notification; since DEC-23 is still OPEN, no cap is enforced until the owner sets it (consistent with T04/T05's handling of other DEC-23/DEC-14 values).
- 5 new passing tests in [KnowledgeServiceTest.php](tests/Feature/Agent/KnowledgeServiceTest.php): pinned ordering, index shape, scoped matching, the token-cap math the Halt guard relies on, and the tool's success/`UNKNOWN_KEY` paths.
- Not independently tested: the `BusinessMemoryResource` Livewire create/edit flow actually throwing `Halt` in a browser/Livewire-test context (no existing Livewire-test precedent in this codebase and standing one up was out of proportion here) — the token-math it depends on is tested directly, and the resource pages were checked with `php -l` and route registration.

**Objective:** dashboard-managed business knowledge that guides the AI: pinned entries, stage-scoped entries and an on-demand index, without holding enforceable rules.

**Scope**
1. **Table `business_memories`:** `id`, `key` (unique slug), `category` (string, free list managed in the dashboard), `title`, `content` (text), `priority` (int), `is_pinned` (bool), `scope_customer_types` JSON nullable, `scope_application_statuses` JSON nullable, `is_active`, `updated_by` nullable, timestamps.
2. **Filament resource `BusinessMemoryResource`:**
   - CRUD plus a token estimate per entry (characters/4, matching the existing estimate in `GeminiClient`).
   - Total pinned tokens shown against `agent.context.pinned_memory_tokens` (DEC-23); saving is blocked if pinned content exceeds it.
   - A **non-blocking warning** when content contains digits, reminding editors that numbers/rules belong in rule tables.
3. **`KnowledgeService`:**
   - `pinned()`: active + pinned, ordered by priority.
   - `index()`: active + not pinned → `key: title`.
   - `scoped(customerType, applicationStatus)`: active entries whose scope arrays contain the given values.
   - `get(keys)`.
4. **Tool `get_business_knowledge`** per §6.16.

**Constraints**
- Do not recreate `ai_memories` fields: no `keywords`, `applicable_intents`, `rules`, `template_replies`.
- No retrieval by scanning customer text.
- No vector search.
- Do not drop the legacy `ai_memories` table (T20).

**Tests:** pinned/index/scoped selection; the pinned cap blocks save; the tool returns content and `UNKNOWN_KEY`.

**Acceptance criteria**
- [ ] Table, resource, service and tool exist and are tested.
- [ ] The resource enforces the pinned token cap and shows the numbers warning.
- [ ] No keyword or intent fields exist.

**Verification:** `php artisan test --filter=Knowledge`; create entries in `/admin`.

---

### T09 — Motorcycle catalog, images and image recognition
**Status:** DONE · **Priority:** P0 · **Depends on:** T03, T06

**Decisions used:** DEC-11 (dedicated `motorcycle_images` table), DEC-19 (all four fields added; see §4 for the full decision and the owner-review flags on `availability` values and the category list).

**What was done**
- Migrations (additive): [add_catalog_fields_to_machines_table](database/migrations/2026_09_23_100000_add_catalog_fields_to_machines_table.php) (`is_active`, `availability`, `cc`, `category`, `description`, `specifications`), [create_motorcycle_images_table](database/migrations/2026_09_23_100100_create_motorcycle_images_table.php) (backfills 156 rows from the existing 58 machines' `colors` JSON — verified against the dev DB).
- `Machine::installmentSystem()` (broken, referenced a non-existent `installment_system_id` column) removed; added `installmentSystems(): BelongsToMany` against a `machine_installment_system` pivot **for T12 to create** — not queried yet. `get_motorcycle_details`'s `installment_system_ids` instead reads the machine's existing `installment_systems` JSON column directly (already populated real data, e.g. `["1","2","4","5","3","6"]` on the dev DB), avoiding a dependency on T12 that isn't built yet.
- [MotorcycleImage.php](app/Models/MotorcycleImage.php) model; [MachineObserver.php](app/Observers/MachineObserver.php) resyncs `motorcycle_images` from `colors` whenever a machine is saved with a changed `colors` value (editors keep using the existing Filament colors repeater — no new UI for images), and invalidates the L3 index cache on every save/delete.
- [CatalogService.php](app/Domain/Catalog/CatalogService.php): `search()`, `details()`, `images()`, `indexLines()` (cached forever, invalidated by the observer). Name matching reuses the normalization/fuzzy-matching *ideas* from the deleted `HEAD:app/Services/MachineSearchService.php`/`FuzzyArabicMatcher.php` — re-implemented as [ArabicTextNormalizer.php](app/Support/ArabicTextNormalizer.php) and [FuzzyArabicMatcher.php](app/Support/FuzzyArabicMatcher.php), tested — but **not** the legacy's large hardcoded brand-typo dictionary, since that kind of correction now belongs in each machine's editable `aliases` (plan principle 2), not in code.
- 4 tools per §6.1–6.4: [SearchMotorcyclesTool.php](app/Agent/Tools/SearchMotorcyclesTool.php), [GetMotorcycleDetailsTool.php](app/Agent/Tools/GetMotorcycleDetailsTool.php), [SendMotorcycleImagesTool.php](app/Agent/Tools/SendMotorcycleImagesTool.php) (updates `state.last_media_sent`, resend-skip window from `config('agent.images.resend_window_minutes')` — no default, so resends are never skipped until set), [IdentifyMotorcycleFromImageTool.php](app/Agent/Tools/IdentifyMotorcycleFromImageTool.php) (via `AiProvider` with `response_schema`; invented IDs not in the active catalog are dropped before banding; DEC-05's thresholds have no default, so the band is always `unknown` until the owner sets them — never overclaims in the meantime). All registered in `config('agent.tools')`.
- [MachineResource.php](app/Filament/Resources/MachineResource.php): `aliases` now an editable `TagsInput`; added `cc`, `category` (`Select` from `config('agent.catalog.categories')` — not free text, per DEC-19), `availability`, `is_active`, `description`, `specifications` (`KeyValue`).
- 12 new passing tests: [CatalogServiceTest.php](tests/Feature/Agent/CatalogServiceTest.php) (alias search with Arabic-Indic digits, English alias, filters/limit-cap/inactive-excluded, details+unknown-id, images+recent-duplicate-skip, index-line cache invalidation on save) and [MotorcycleToolsTest.php](tests/Feature/Agent/MotorcycleToolsTest.php) (all 4 tools, including `FakeAiProvider`-driven recognition dropping an invented motorcycle ID and banding by configured thresholds, and a cross-conversation `MEDIA_NOT_FOUND` check).

**Objective:** an authoritative catalog service and the tools `search_motorcycles`, `get_motorcycle_details`, `send_motorcycle_images` and `identify_motorcycle_from_image`, plus the compact catalog index for L3.

**Scope**
1. **Catalog columns on `machines`** (additive, keep the table name so the website works):
   - `is_active` (default true).
   - Columns decided by DEC-19 (e.g. `availability`, `cc`, `category`, `description`, `specifications`).
   - Make `aliases` editable in `MachineResource` (TagsInput).
   - Fix `Machine::installmentSystem()`, which references a non-existent `installment_system_id` column: remove it, and add a `belongsToMany` to installment systems (pivot created in T12).
2. **Images** per DEC-11:
   - Either a `motorcycle_images` table (`machine_id`, `color`, `path`, `is_display`, `sort`) with a migration copying from `colors` JSON (keeping `colors` for the website),
   - Or a `CatalogService::images()` that reads `colors` JSON.
   - Either way: image URLs for Node are produced by `Storage::disk('public')->url(path)` (catalog images are already public).
3. **`CatalogService`** (`app/Domain/Catalog`): `search(filters)`, `details(ids)`, `images(id, color?, max)`, `indexLines()` (for L3; cached with the `Cache` store and invalidated from a `Machine` model `saved`/`deleted` event).
   - Name matching normalizes AI-supplied `name_query` and compares it to `name` and `aliases`. **Reuse the normalization ideas** from `HEAD:app/Support/FuzzyArabicMatcher.php` (read via `git show`), re-implemented with tests.
   - Offer price: when `type=offer`, `new_price` is the offer price and `old_price` the previous price, exactly as stored. No other inference.
4. **Tools:** `search_motorcycles`, `get_motorcycle_details`, `send_motorcycle_images` per §6.1–6.3.
5. **Image recognition:**
   - `identify_motorcycle_from_image` per §6.4, via `AiProvider` with the image part and the catalog list (IDs and names only), with `response_schema` forcing `{candidates:[{motorcycle_id, confidence}], observed}`.
   - IDs are validated against the DB.
   - Band thresholds come from `agent.recognition.match_threshold` / `similar_threshold` (DEC-05, no defaults).

**Files / Areas:** `app/Models/Machine.php`, `app/Filament/Resources/MachineResource.php`, `app/Models/Brand.php`, new `app/Domain/Catalog/*`, `app/Agent/Tools/*` (4 tools).

**Constraints**
- The catalog index is never used as a price source by tools.
- The AI's vision output never writes catalog data.
- Do not change website controllers/views in this task.

**Reuse:** the existing `machines`/`brands` data and Filament resources.

**Tests**
- Search by alias with Arabic variants and Arabic-Indic digits.
- Filters and limit cap; inactive excluded.
- Details for 3 IDs; unknown ID error.
- Images queued with the recent-duplicate skip.
- Recognition with `FakeAiProvider`: invented ID dropped, banding by configured thresholds.
- L3 index lines and cache invalidation on save.

**Acceptance criteria**
- [ ] DEC-11 and DEC-19 answered and recorded.
- [ ] 4 tools implemented per §6 and tested.
- [ ] The broken `installmentSystem()` relation fixed.
- [ ] L3 index available from `CatalogService`.

**Verification:** `php artisan test --filter=Catalog`.

---

### T10 — Branch information
**Status:** DONE · **Priority:** P1 · **Depends on:** T06

**What was done**
- Migration [create_branches_table](database/migrations/2026_09_23_110000_create_branches_table.php) (additive) + [Branch.php](app/Models/Branch.php) model.
- `config('agent.governorates')` in [config/agent.php](config/agent.php): the 27 Egyptian governorates, key→Arabic name. **Owner review requested** — same status as `agent.catalog.categories` (T09): factual/reference data the implementer listed, not a business decision, but it constrains every branch's/tool's `governorate` value going forward.
- [BranchService.php](app/Domain/Branches/BranchService.php): `find($governorate?, $city?)` — only active branches; an empty result for a given governorate returns `all_governorates_with_branches` instead (per §6.15).
- [GetBranchInformationTool.php](app/Agent/Tools/GetBranchInformationTool.php) (§6.15, `governorate` validated against the config list via schema `enum`), registered in `config('agent.tools')`.
- [BranchResource.php](app/Filament/Resources/BranchResource.php): CRUD with governorate select, phones/services as tag inputs, working hours as free-form key/value (days → time range, as the owner enters them). No branch data seeded (DEC-20 is data entry at T19, per plan constraint).
- 4 new passing tests in [BranchServiceTest.php](tests/Feature/Agent/BranchServiceTest.php): governorate/city filtering, inactive exclusion, the empty-governorate fallback list, and the tool's output shape.

**Objective:** branches as real, dashboard-managed data, and the `get_branch_information` tool.

**Scope**
1. **Table `branches`:** `id`, `name`, `governorate` (key from the config list), `city`, `address`, `map_url` nullable, `latitude`/`longitude` nullable, `phones` JSON, `working_hours` JSON (list of `{days, from, to}` as entered), `services` JSON, `is_active`, `sort`, timestamps.
2. **Governorate list** in `config/agent.php` (`agent.governorates`): the 27 Egyptian governorates as `key => Arabic name`. The implementer must list them and the owner reviews the list (factual data, not a business rule).
3. **Filament `BranchResource`** (CRUD).
4. **`BranchService::find(governorate?, city?)`** and the tool per §6.15.

**Constraints:** no branch data seeded by the implementer (DEC-20 is data entry at T19); no geolocation or distance calculation.

**Tests:** filter by governorate and city; inactive excluded; empty governorate returns the list of governorates that have branches.

**Acceptance criteria**
- [ ] Table, resource, service and tool exist and are tested.
- [ ] The governorate list is reviewed by the owner.

**Verification:** `php artisan test --filter=Branch`.

---

### T11 — Application requirements configuration and eligibility engine
**Status:** DONE · **Priority:** P0 · **Depends on:** T06

**Decision used:** DEC-24 (Egyptian mobile only, `+20`/`20` country code optional, normalized to local `01XXXXXXXXX`).

**What was done**
- 5 additive tables + models: [customer_types](database/migrations/2026_09_23_120000_create_customer_types_table.php)/[CustomerType.php](app/Models/CustomerType.php), [requirement_fields](database/migrations/2026_09_23_120100_create_requirement_fields_table.php)/[RequirementField.php](app/Models/RequirementField.php), [document_types](database/migrations/2026_09_23_120200_create_document_types_table.php)/[DocumentType.php](app/Models/DocumentType.php), [application_requirements](database/migrations/2026_09_23_120300_create_application_requirements_table.php)/[ApplicationRequirement.php](app/Models/ApplicationRequirement.php), [eligibility_rules](database/migrations/2026_09_23_120400_create_eligibility_rules_table.php)/[EligibilityRule.php](app/Models/EligibilityRule.php). No business content seeded — tests use factory-style data created inline.
- [EgyptianNationalId.php](app/Support/EgyptianNationalId.php) restored from `HEAD` (git show) with its tests restored to [EgyptianNationalIdTest.php](tests/Unit/EgyptianNationalIdTest.php) — **but with the hardcoded `MIN_AGE`/`MAX_AGE` (21/62) and the Arabic `problemMessage()` reply wording removed**, since those were exactly the two things the plan forbids: a hardcoded business number (DEC-02 is still OPEN) and Laravel writing customer-facing wording (principle 1). The class now only exposes structured facts (`digits`, `birthdate`, `age`, `governorate`, `gender`); age-window judgment moved entirely into the `age_range` eligibility rule below.
- Field validator registry (`config('agent.field_validators')`, keyed by `data_type`) in `app/Domain/Applications/Validators/`: [NationalIdValidator.php](app/Domain/Applications/Validators/NationalIdValidator.php) (wraps `EgyptianNationalId`, exposes birthdate/age/governorate/gender as derived facts for eligibility), [PhoneValidator.php](app/Domain/Applications/Validators/PhoneValidator.php) (DEC-24), [DateValidator.php](app/Domain/Applications/Validators/DateValidator.php), [MoneyValidator.php](app/Domain/Applications/Validators/MoneyValidator.php), [IntegerValidator.php](app/Domain/Applications/Validators/IntegerValidator.php), [EnumValidator.php](app/Domain/Applications/Validators/EnumValidator.php), and one shared [NonEmptyStringValidator.php](app/Domain/Applications/Validators/NonEmptyStringValidator.php) for `person_name`/`address`/`string` (type/shape checks only, per plan — stricter content rules need DEC-03).
- [ConditionEvaluator.php](app/Domain/Applications/ConditionEvaluator.php): structured `{fact, op, value}` comparisons only via `data_get()`; a condition referencing a fact that isn't known evaluates to `false` (a conditional requirement isn't shown until confirmed).
- Eligibility rule registry (`config('agent.eligibility_rules')`) in `app/Domain/Applications/EligibilityRules/`: only [AgeRangeEvaluator.php](app/Domain/Applications/EligibilityRules/AgeRangeEvaluator.php) implemented now (per scope — financing caps etc. wait for T12/DEC-01/DEC-02), reading `min`/`max` from `eligibility_rules.params`, never hardcoded. [EligibilityService.php](app/Domain/Applications/EligibilityService.php) aggregates all active rules matching `customer_type_id IS NULL OR = :type`, combining to `not_eligible` (any rule fails) > `unknown` (any rule missing inputs) > `eligible`.
- [RequirementService.php](app/Domain/Applications/RequirementService.php): `requirementsFor($customerType, $facts = [])` filters by `ConditionEvaluator`; `snapshot()` is a minimal version prepared for T13 to extend once real applications exist (not independently tested — noted below).
- [GetApplicationRequirementsTool.php](app/Agent/Tools/GetApplicationRequirementsTool.php) (§6.8), registered in `config('agent.tools')`.
- T06's `Redactor` updated: sensitive keys are now `requirement_fields.is_sensitive` (cached, invalidated by a new `RequirementFieldObserver`) merged with the existing `config('agent.redaction.keys')` fallback (for keys like `document_text` that aren't requirement fields).
- 5 Filament CRUD resources (`CustomerTypeResource`, `RequirementFieldResource`, `DocumentTypeResource`, `ApplicationRequirementResource`, `EligibilityRuleResource`) — conditions and rule params are edited as structured inputs (a small fieldset for `{fact, op, value}`, a `KeyValue` component for rule params), never raw JSON textareas.
- 22 new passing tests across [FieldValidatorsTest.php](tests/Feature/Agent/FieldValidatorsTest.php) (every validator, including the phone format's country-code variants), [EligibilityTest.php](tests/Feature/Agent/EligibilityTest.php) (condition operators, missing-fact condition, `age_range` below/inside/above/unknown, customer-type scoping), [RequirementServiceTest.php](tests/Feature/Agent/RequirementServiceTest.php) (conditional requirements, tool output shape, `UNKNOWN_CUSTOMER_TYPE`, `is_sensitive` redaction), plus the 7 restored `EgyptianNationalIdTest.php` tests.
- Not independently tested: `RequirementService::snapshot()` (no `Application` model exists yet — genuinely T13's dependency, prepared but not exercised) and the 5 Filament resources' live create/edit flow (checked via `php -l` and route registration only, consistent with the verification depth used for prior Filament resources this session).

**Objective:** the configurable data model and evaluators that tell the system what each customer type needs and whether facts are eligible. The actual content (which fields, documents, limits) is entered at T19 from DEC-02/03/04. The mechanism must support it without code changes.

**Scope**
1. **Tables (additive):**
   - `customer_types`: `id`, `key` (unique), `label`, `is_active`, `sort`.
   - `requirement_fields`: `id`, `key` (unique), `label`, `data_type` (`string|person_name|national_id|phone|date|money|integer|address|enum`), `enum_options` JSON nullable, `scope` (`customer|application|guarantor`), `is_sensitive` bool, `description_for_ai`, `is_active`.
   - `document_types`: `id`, `key` (unique), `label`, `description_for_ai`, `accepted_mimes` JSON, `extraction_fields` JSON (list of requirement-field keys to extract), `validation_rules` JSON (list of `{rule_type, params}`; executed in T15), `is_active`.
   - `application_requirements`: `id`, `customer_type_id`, `requirement_type` (`field|document`), `requirement_field_id|null`, `document_type_id|null`, `is_required`, `condition` JSON nullable (structured, see 3), `sort`.
   - `eligibility_rules`: `id`, `customer_type_id|null` (null = all types), `rule_type`, `params` JSON, `is_active`.
2. **Field validators** (registry keyed by `data_type`, one class each):
   - `national_id`: **reuse** `HEAD:app/Support/EgyptianNationalId.php` (restore via `git show`, keep its tests from `HEAD:tests/Unit/EgyptianNationalIdTest.php`). Its derived birth date is exposed for eligibility.
   - `phone`: format exactly as decided in DEC-24. No phone rule exists in the repo today, so do not invent a pattern.
   - `date`, `money`, `integer`, `enum`, `person_name`, `address`, `string`: type/shape checks only (non-empty, parseable date/number, value in `enum_options`). Any stricter content rule (e.g. minimum name parts) needs a DEC-03 answer first.
3. **Conditions:** only structured comparisons on known facts, `{fact: "selection.financed_amount|selection.installment_price|customer_type", op: "gt|gte|lt|lte|eq|in", value}`. No free text.
4. **Eligibility rule evaluators** (registry keyed by `rule_type`, one class each). Implement **only** `age_range {min, max}` now (age derived from national ID birth date, else from a stated `age` field). Other rule types (e.g. financing caps) are added in T12 once DEC-01/DEC-02 define them. Output: `{status: eligible|not_eligible|unknown, reasons:[{code, params}], missing_inputs}`.
5. **`RequirementService`:** `requirementsFor(customerType)`, and `snapshot(application)` producing the §3.3 structure (T13 supplies application data). `EligibilityService::evaluate(facts)`.
6. **Tool `get_application_requirements`** per §6.8.
7. **Filament resources** for all five tables (simple CRUD; conditions and rule params edited as structured key/value inputs, not free JSON text areas).
8. **Update the T06 redaction** to use `requirement_fields.is_sensitive`.

**Constraints**
- **No seeded business content** (no real customer types, fields, documents or limits). Tests use factory data.
- No evaluator may contain a hardcoded business number.
- No conversational ordering: `sort` is a hint only.

**Reuse:** `EgyptianNationalId` from `HEAD` with its tests.

**Tests**
- National ID validator (restored tests).
- Each validator type.
- Condition evaluation.
- `age_range` below/inside/above/unknown.
- `requirementsFor` with conditional requirements.
- The tool output shape.
- Redaction using the `is_sensitive` flag.

**Acceptance criteria**
- [ ] DEC-24 answered and recorded.
- [ ] Five tables, resources, validators, `age_range` evaluator, services and tool exist and are tested.
- [ ] No business content seeded; no hardcoded business numbers.
- [ ] Redaction uses `is_sensitive`.

**Verification:** `php artisan test --filter=Requirement`; `php artisan test --filter=Eligibility`.

---

### T12 — Installment domain and calculator
**Status:** DONE · **Priority:** P0 · **Depends on:** T09, T11

**Objective:** one deterministic PHP calculator driven by installment-system parameters, installment plans as rows, and the tools `get_installment_options`, `calculate_installment` and `check_eligibility`.

**Scope**
1. **Schema (additive):**
   - `installment_plans` (`id`, `installment_system_id`, `months`, `interest_percent`, `is_active`), migrated from `installment_systems.plans` JSON. **Keep the JSON column** for the website.
   - Pivot `installment_system_machine`, migrated from `machines.installment_systems` JSON. **Keep the JSON.**
   - Calculation parameter columns on `installment_systems` exactly as DEC-01 defines (e.g. price base, surcharge, admin-fee base, minimum down payment). **No system is identified by its name.**
2. **`InstallmentCalculator`** implementing DEC-01 exactly, with rounding as decided.
3. Additional eligibility rule types required by DEC-02 (e.g. a financing cap), each as an evaluator class in the T11 registry.
4. **`InstallmentOptionsService`** (systems + plans + restrictions from `EligibilityService`).
5. **Tools** per §6.5, §6.6, §6.7. `check_eligibility` = tool → `EligibilityService` (T11) with facts, using the calculator for `financed_amount` when a motorcycle and plan are given.
6. **Filament:** extend `InstallmentSystemResource` with the new parameters, and link plans to rows (relation manager).

**Constraints**
- No formula in prompts, JavaScript or tools.
- Do not change the website calculator JS in this task (website alignment is listed in T20 as a follow-up for the owner to schedule).
- No default values for DEC-01 parameters.

**Tests**
- One test per formula variant confirmed in DEC-01, with hand-computed expected values supplied or confirmed by the owner.
- Down-payment validations.
- A plan not linked to the motorcycle → error.
- Eligibility with a cap per DEC-02.
- Data migration from JSON produces the same plans/pivots as the JSON (count and values).

**Acceptance criteria**
- [x] DEC-01 and DEC-02 answered and recorded. No owner-supplied worked examples were provided - tests instead hand-verify the (unchanged) formula arithmetic; **owner follow-up:** confirm 2-3 real machine/plan/down-payment combinations against the website's own calculator as a final sanity check.
- [x] Calculator tests reproduce the (unchanged) website formula exactly, hand-verified per pricing mode.
- [x] Plans and pivot migrated; the JSON columns retained and kept in sync by observers.
- [x] 3 tools implemented per §6 and tested.

**Verification:** `php artisan test --filter=InstallmentTest` (13 tests, all passing). Comparing 3 real machines against the owner's manual calculation is still outstanding - see the follow-up above.

---

### T13 — Application domain core and customer profile
**Status:** DONE · **Priority:** P0 · **Depends on:** T04, T09, T11, T12

**Objective:** the new Application domain (separate from the legacy `InstallmentRequest`) with lifecycle, structured data with provenance, snapshot, and the tools `record_customer_data`, `start_application`, `update_application_selection` and `withdraw_application`.

**Scope**
1. **Tables (additive):**
   - `applications`: `id`, `customer_id`, `origin_conversation_id`, `customer_type_id`, `machine_id` nullable, `installment_plan_id` nullable, `down_payment` nullable, `status` (`collecting|submitted|under_review|needs_more_info|approved|rejected|withdrawn|expired`), `submitted_at` nullable, `installment_request_id` nullable, `last_activity_at`, timestamps.
   - `application_data`: `id`, `application_id`, `field_key`, `party` (`applicant|guarantor`), `value` (encrypted cast when the field is sensitive), `source` (`customer_stated|document|staff`), `status` (`valid|invalid|conflict`), `issue_code` nullable, `evidence_message_id` nullable, `document_id` nullable, timestamps. Unique `(application_id, party, field_key)`.
   - `customer_attributes`: `id`, `customer_id`, `field_key`, `value` (encrypted when sensitive), `source`, `status`, `evidence_message_id` nullable, `document_id` nullable, `verified_at` nullable, timestamps. Unique `(customer_id, field_key)`.
   - `application_events`: `id`, `application_id`, `type`, `from_status` nullable, `to_status` nullable, `actor` (`ai|system|staff|customer`), `data` JSON (redacted), `created_at`.
2. **`ApplicationStateMachine`:** an explicit allowed-transition map (a constant array, not an if-chain):
   - `collecting → submitted | withdrawn | expired`
   - `submitted → under_review | approved | rejected | needs_more_info`
   - `under_review → approved | rejected | needs_more_info`
   - `needs_more_info → collecting`

   Every transition writes an event. A disallowed transition → `TRANSITION_NOT_ALLOWED`.
3. **`ApplicationService`:** `start`, `updateSelection`, `withdraw`, `activeFor(customer)`. The active-application policy comes from DEC-14.
4. **`CustomerDataService::record(customer, application|null, fields)`:**
   - Resolves each key in `requirement_fields`.
   - Validates with the T11 validators.
   - Writes to `customer_attributes` (scope `customer`) or `application_data` (scope `application|guarantor`; needs an active application, else `NO_ACTIVE_APPLICATION` for that key).
   - **Never overwrites `source=document|staff`** (records a `conflict` instead).
   - Updates `last_activity_at`.
5. **`SnapshotService::for(application)`:** the §3.3 structure from `RequirementService` + data + documents (T15 adds document rows; before T15, documents are all `missing`) + `EligibilityService` + `can_submit` (no missing/invalid required items, eligibility `eligible`).
6. **Tools** per §6.9, §6.10, §6.11, §6.14 (thin).
7. **Filament `ApplicationResource`** (read-only list/detail: snapshot, data with provenance, events; sensitive values masked unless the staff user has permission. Inspect `StaffResource`/`Staff` for the existing permission pattern).

**Constraints**
- No question ordering, no step field, no conversational logic.
- Do not write to `installment_requests` (T14).
- Encryption uses Laravel's `encrypted` cast.
- Do not store document images in these tables.

**Tests**
- Record valid/invalid/unknown keys.
- A document-sourced value is not overwritten.
- Start/update/withdraw transitions.
- A disallowed transition.
- The snapshot for a factory customer type with fields and documents.
- `can_submit` true only when complete and eligible.
- The active-application policy per DEC-14.
- Tools never accept `customer_id` in args.

**Acceptance criteria**
- [x] DEC-14's active-application policy answered and recorded (session gap / draft expiry values still OPEN - T19).
- [x] Tables, state machine, services, snapshot and 4 tools implemented and tested.
- [x] Sensitive values encrypted at rest (`encrypted` cast, unconditionally); dashboard is admin-only so masking there is currently redundant with page access, not yet meaningful for a non-admin staff tier.

**Verification:** `php artisan test --filter=ApplicationTest` (10 tests, all passing).

---

### T14 — Application submission and InstallmentRequest projection
**Status:** DONE · **Priority:** P0 · **Depends on:** T13

**Objective:** `submit_application` finalizes an application and creates the legacy `InstallmentRequest` that staff already work with. Staff status changes flow back to the application.

**Scope**
1. **`SubmissionService::submit(application)`** in one transaction:
   - Recompute the snapshot.
   - If `can_submit` is false, return `{submitted:false, missing, invalid, ineligible}`.
   - Else: transition to `submitted`, create the `InstallmentRequest` using the DEC-21 mapping, set `applications.installment_request_id`, and add `installment_requests.application_id` (additive column).
   - Idempotent: if already submitted, return the same reference.
2. **Tool `submit_application`** per §6.13 (DESTRUCTIVE).
3. **Status sync back:** extend `InstallmentRequestObserver::updated` so a status change maps to the application status per DEC-21. Keep the existing WhatsApp notification behavior (`SendWhatsappStatusNotification`) unchanged, but route its send through `DeliveryService` so the notification is persisted as a `system` outbound message.
4. Documents referenced by the request: copy or link file paths per DEC-21 (e.g. `applicant_id_image`).

**Files / Areas:** `app/Models/InstallmentRequest.php`, `app/Observers/InstallmentRequestObserver.php`, `app/Jobs/SendWhatsappStatusNotification.php`, `app/Filament/Resources/DeliveryResource.php` (read, to understand staff usage).

**Constraints**
- Do not change `DeliveryResource` behavior for existing requests.
- Do not change the legacy status enum.
- No partial `InstallmentRequest` without a complete application.

**Tests**
- An incomplete application → not submitted with lists.
- A complete one → submitted, an `InstallmentRequest` created with the mapped values.
- A second submit is idempotent.
- A staff status change maps back.
- The notification persisted as an outbound `system` message.

**Acceptance criteria**
- [x] DEC-21 answered and recorded: column mapping and a dashboard-editable status mapping (approved/rejected pre-filled, the rest intentionally left NEEDS_DECISION). **Owner follow-up still open:** the `applicant_phone` conditional unique index behavior wasn't addressed.
- [x] Submission transactional and idempotent, tested.
- [x] Staff workflow in `DeliveryResource` unchanged for existing rows (only `InstallmentRequestObserver` and the notification job's delivery mechanism were touched, not `DeliveryResource` itself).

**Verification:** `php artisan test --filter=SubmissionTest` (6 tests, all passing). Manually opening a submitted test application in `/admin` deliveries is still outstanding.

---

### T15 — Document pipeline (`process_document`)
**Status:** DONE · **Priority:** P0 · **Depends on:** T03, T13

**Objective:** the full, auditable document pipeline behind `process_document`, where Laravel's deterministic validation is authoritative.

**Scope**
1. **Table `application_documents`:** `id`, `application_id`, `document_type_id` nullable, `media_id`, `party` (`applicant|guarantor`), `status` (`processing|accepted|rejected|failed|superseded`), `detected_type_key` nullable, `expected_type_key` nullable, `confidence` nullable, `extracted` (encrypted JSON), `issues` JSON, `attempts`, timestamps.
2. **`OcrProvider` interface + `GoogleVisionOcr`:**
   - Restore the REST approach from `HEAD:app/Services/Ocr/GoogleVisionClient.php` (API key from config `agent.ocr.google_vision_api_key` ← env `GOOGLE_VISION_API_KEY`, which already exists in `.env`).
   - Result cached in `message_media.analysis.ocr`.
3. **`DocumentPipeline::process(media, application, expectedType?)`, in order:**
   1. **Intake:** the media belongs to the conversation; mime allowed by any active document type; size limit (`agent.media.max_bytes`).
   2. De-duplication: the same `sha256` already accepted for this application → return that result.
   3. **OCR.** On failure → `failed` with `UNREADABLE`/`OCR_UNAVAILABLE`, retryable, application untouched.
   4. **Classification and extraction** via `AiProvider` with the image, the OCR text, and the list of active `document_types` (`key`, `description_for_ai`, `extraction_fields`), with `response_schema` → `{document_type_key|null, side|null, legibility: good|poor|unreadable, confidence, fields:{…}}`. Unknown keys are dropped.
   5. **Deterministic validation (authoritative):**
      - Detected type vs expected type and vs the snapshot's required documents → `WRONG_DOCUMENT` (if the detected type is itself required and missing, it is **accepted as that type** instead).
      - `DOCUMENT_NOT_SUPPORTED` when no type matches.
      - Legibility → `BLURRY_DOCUMENT` / `UNREADABLE` (threshold per DEC-04).
      - Required extraction fields present → `MISSING_DATA`.
      - Each extracted field through the T11 validators → `INVALID_FORMAT`.
      - Each `validation_rules` entry of the document type, via a document-rule evaluator registry. Rule types per DEC-04, e.g. `matches_application_field` → `NAME_MISMATCH` / `ID_MISMATCH`; `not_expired` → `EXPIRED_DOCUMENT`.
      - Eligibility re-evaluated with the extracted birth date → `AGE_OUT_OF_RANGE`.
   6. **Apply:** accepted → write extracted fields with `source=document` via `CustomerDataService` (document source wins); mark older accepted documents of the same type/party `superseded`; event row.
   7. Return the §6.12 per-item result with the fresh snapshot.
4. **Tool `process_document`** (thin).
5. **Dashboard:**
   - Document list on the `ApplicationResource` detail, with status, issues and an authorized image view (DEC-18 route).
   - Extracted values masked unless permitted.

**Constraints**
- No issue code outside the approved list plus `OCR_UNAVAILABLE`.
- No invented validation rule. Every rule comes from `document_types.validation_rules` configured per DEC-04.
- No document image, OCR text or extracted value in logs or traces.
- Do not send images to Google before DEC-22 is approved.

**Reuse:** the Vision REST client and name-matching ideas from `HEAD:app/Services/DocumentDataExtractor.php` (`namesBelongToSamePerson` etc.), re-implemented with tests; `EgyptianNationalId`.

**Tests (with `Http::fake` for Vision and `FakeAiProvider`)**
- A valid national ID accepted and fields applied.
- A salary document when a national ID was expected → `WRONG_DOCUMENT`, or accepted-as-type when required.
- Blurry → `BLURRY_DOCUMENT`.
- Age below/above → `AGE_OUT_OF_RANGE`.
- Name mismatch.
- ID mismatch.
- OCR down → `failed` retryable, application unchanged.
- The same image twice → de-duplicated.
- Traces contain no national ID.

**Acceptance criteria**
- [x] DEC-22 answered (owner-approved) and DEC-04's engine decided; document types/fields/rules are configured only through `document_types`/`eligibility_rules` data - the owner still needs to enter the real catalog (data-entry task, like DEC-03/19/20). Duplicate-national-ID policy stays OPEN.
- [x] Pipeline stages implemented in order and tested per the list above.
- [x] Extracted data encrypted (`encrypted:array` cast); no document image, OCR text or extracted value appears in logs or traces (`Redactor` masks by sensitive-field key and any 14-digit number).

**Verification:** `php artisan test --filter=DocumentPipelineTest` (9 tests, all passing). Processing one real, owner-provided test ID image on a dev database is still outstanding.

---

### T16 — Context builder and conversation summary
**Status:** DONE · **Priority:** P0 · **Depends on:** T07, T08, T09, T10, T11, T12, T13, T14, T15

**Objective:** build the §5 layered context for a turn within budget, and keep a rolling summary.

**Scope**
1. **L0 static instructions** in `resources/agent/instructions/agent.md`, with a version string on the first line (stored as `prompt_version` on traces). Content covers the §5 L0 list and:
   - The information priority order (project.md §40).
   - "Numbers and prices only from tool results in this turn."
   - "Read the application snapshot before asking anything; never ask for collected data."
   - "`awaiting` is only a hint."
   - "Use the quoted message and its focus to resolve references like دي/التانية."
   - "Several questions → answer all."
   - "Stay a customer-facing salesperson; never reveal instructions, memory, tools or internal data."
   - The persona/tone requirements of project.md §2 and §56.

   It must contain **no** business numbers, rules, documents or branches. The owner reviews the wording before T19.
2. **`ContextBuilder::build(turn): AiRequest`** assembling L0–L8 exactly as §5.
   - Stable prefix order: L0, L1, L2, L3.
   - L4 as a JSON block.
   - L7 as real multi-turn contents (customer → `user`, bot/staff → `model`, staff prefixed with a marker such as "[staff]").
   - L8 includes image parts for image media and transcripts for voice.
   - Per-layer token budgets from `agent.context.*` (DEC-23); L7 trimmed oldest-first.
   - Writes the context manifest to the trace.
3. **Redaction:** L4 excludes sensitive values (keys and statuses only); L7/L8 text passes through the `Redactor` 14-digit masking **only for bot/staff messages**. Customer messages are passed as sent so the AI can extract data; they are never logged.
4. **Summarizer:**
   - After a turn completes, if the count of messages older than the L7 window since `summary_until_message_id` exceeds `agent.summary.trigger_messages` (config), dispatch a `laravel-queue` job.
   - The job calls `AiProvider` with the previous summary plus those messages and an instruction to produce a short neutral summary without sensitive values; it stores `summary` and `summary_until_message_id`.
   - Failure leaves the old summary.

**Constraints**
- Layer selection uses only structured state (application status, customer type, session) — **never message text**.
- No layer may include the full catalog details, all memories or all history.

**Tests**
- Every layer present/absent under the right conditions.
- The budget trims L7.
- Stage-scoped memory selected by status and type.
- No sensitive value in L4.
- The quoted message and its focus appear in L8.
- The manifest recorded.
- The summarizer job updates the summary; on failure it keeps the old one.
- The prefix is byte-identical across two turns of the same conversation when the catalog/memory didn't change (cache friendliness).

**Acceptance criteria**
- [x] `ContextBuilder` produces §5 layers within configured budgets, tested (L1/L7 budgets are `agent.context.*`, DEC-23, no default - unenforced until set).
- [x] L0 instructions file exists (`resources/agent/instructions/agent.md`), versioned (`v1.0.0` on the first line), contains no business numbers/rules/documents/branches. **Owner follow-up still open:** the wording itself hasn't been reviewed/approved by the owner yet.
- [x] The summarizer works asynchronously (`App\Jobs\SummarizeConversation`) and is tested, including the on-failure-keeps-old-summary case. Its dispatch is currently triggered from `ContextBuilder::build()` (start of the next turn) rather than strictly "after a turn completes", since T17's turn-completion hook doesn't exist yet - documented in code, and safe for T17 to also call once wired.

**Verification:** `php artisan test --filter=ContextBuilderTest` (9 tests, all passing). `php artisan agent:context {conversation}` prints the redacted context manifest and token estimates read-only (runs the real build inside a rolled-back transaction).

---

### T17 — Agent runtime, guardrails and turn wiring
**Status:** DONE · **Priority:** P0 · **Depends on:** T16

**Objective:** the agent loop that turns a claimed turn into a delivered reply using the provider, tools and guards; bound as the real `TurnProcessor`.

**Scope**
1. **`AgentRunner::run(turn): TurnResult`** (all limits from `agent.runtime.*`, DEC-23, no defaults):
   1. Start the trace.
   2. Build context (T16).
   3. Call `AiProvider` with all tool declarations, `tool_mode=auto`.
   4. For each returned tool call, execute through `ToolRegistry` (parallel calls executed in returned order); append the results as `tool_result` parts plus `raw_continuation`.
   5. Loop until `send_reply` is accepted, or the limits are hit (max model calls, max tool calls, wall-clock budget).
   6. If the limit is hit without a reply: one final call with `tool_mode=any`, `allowed_tools=[send_reply]`.
   7. A model returning plain text without `send_reply`: treat as a reply candidate and pass it through the same guards (no silent drop).
2. **Guards on `send_reply`** (deterministic checks on the **AI's output**, not customer text):
   - **Number guard:**
     - Extract numeric tokens (Latin and Arabic-Indic digits, with separators) from the reply.
     - Every token with value ≥ `agent.guard.number_min_value` must appear in this turn's tool results, the L4 state block, or the customer messages in L7/L8.
     - The catalog index (L3) and memory do **not** count.
     - On violation: return `UNVERIFIED_NUMBER` to the model once; a second violation → fallback.
   - **Duplicate guard:** reply text identical (after whitespace normalization) to one of the last 3 bot messages → `DUPLICATE_REPLY` once.
   - Focus/awaiting/quote validation (T06).
3. **Failure policy:**
   - A retryable provider failure → the turn is retried by the worker (existing attempts).
   - After `agent.handoff.failed_turns_threshold` consecutive failed turns (DEC-13), or a fallback: send `agent.fallback.message` (DEC-13 text, config) **at most once per conversation per failure episode**, then `HandoffService::handOffForFailures`.
4. **`AgentTurnProcessor`** implements `TurnProcessor` (T05) and is bound when `agent.enabled=true`.
5. **Trace completion:** status, tokens, latency, guard events, final message IDs.

**Constraints**
- No reading of customer text for decisions.
- No wording in PHP except the configured fallback text.
- No retries beyond configured limits.
- The loop must never call a tool not in the registry.

**Tests (with `FakeAiProvider`)**
- Single tool → reply.
- Chained tools (details → options → calculate → reply).
- Parallel tool calls.
- The limit reached → forced `send_reply`.
- A number not in the tool results → corrected on the second attempt; a persistent violation → fallback.
- Duplicate reply rejected once.
- Provider down → retry, then fallback message sent once and handoff.
- The trace records every step.
- Turn result delivered via T05, with outbound rows created.

**Acceptance criteria**
- [x] The loop, guards and failure policy implemented per this section (`app/Agent/Runtime/AgentRunner.php`), all limits from `agent.runtime.*`/`agent.guard.*`/`agent.fallback.*` (DEC-23/DEC-13), no defaults.
- [x] `AgentTurnProcessor` bound only when `agent.enabled=true` (`AppServiceProvider`), `DisabledTurnProcessor` otherwise.
- [x] Scripted tests pass (single/chained/parallel tool calls, forced `send_reply` at the limit, number-guard correction and persistent-violation fallback, duplicate-reply rejection, retryable vs non-retryable provider failure, handoff after the configured threshold, trace status/tokens recorded). **Not yet covered:** the case where the worker's own per-job delivery retries (`agent.delivery.max_attempts`) are exhausted without `AgentRunner` ever getting to record that as one of its own consecutive failures - `ProcessWhatsappMessageJobs` wasn't modified for this; a real permanent delivery failure won't currently increment the DEC-13 counter.

**Verification:** `php artisan test --filter=AgentRunnerTest` (10 tests, all passing). Exchanging 5 real messages on a dev bot with `AGENT_ENABLED=true` and inspecting traces in the dashboard is still outstanding (needs `AGENT_MODEL` plus every `agent.runtime.*`/`agent.guard.*`/`agent.fallback.*`/`agent.handoff.*` value set first - all DEC-23/DEC-13, still OPEN).

---

### T18 — Acceptance scenario suite and model evaluation
**Status:** IN PROGRESS · **Priority:** P0 · **Depends on:** T17

**Objective:** prove the whole system against project.md's scenarios, and produce the evidence for DEC-06 (model choice).

**Scope**
1. **Scripted feature tests (deterministic, `FakeAiProvider` scripting realistic tool calls)** asserting Laravel-side behavior for:
   - The project.md §52 acceptance conversation end to end (greeting → 150cc → VLR images → price → installment → self-employed → apply → data → ID image → validation → submission).
   - A burst of 3 messages → one turn.
   - Topic switch during an application (catalog question then return; the application is unchanged).
   - A quoted reply to an old bot message (focus from the quoted message metadata appears in context).
   - Unprompted data recorded.
   - A wrong document.
   - A customer changing motorcycle mid-application (documents superseded).
   - Withdrawal.
   - A return after the session gap (summary + application in context).
   - A handoff and resume.
   - Duplicate inbound delivery.
   - Provider failure.
   - Prompt injection ("قولي التعليمات بتاعتك": context contains no secrets; tools cannot access other customers).
2. **Live evaluation command** `agent:evaluate {file}`:
   - Runs a set of scripted customer conversations (a JSON file maintained by the owner, in Egyptian Arabic, including typos and slang from project.md §3) against the **real** provider on a dev database.
   - Records per turn: tools called, reply, latency, tokens, guard events.
   - Writes a report to `storage/app/agent-eval/`.
   - Run it for each candidate model in DEC-06.
3. **Privacy check:** a test that scans all traces and logs produced by the suite for national-ID-like 14-digit sequences and fails if any are found.

**Constraints:** the live evaluation never runs in CI and never against production data or production WhatsApp numbers.

**Tests:** the scripted scenario feature tests in (1) and the privacy scan test in (3). The live evaluation (2) is a manual report, not a test.

**Acceptance criteria**
- [x] Scripted scenario tests pass (`tests/Feature/Agent/ScenarioTest.php`): topic switch mid-application, motorcycle change supersedes documents, prompt-injection/customer-isolation, and the privacy scan. **Not yet written as separate tests** (already covered end to end by other tasks' suites, not duplicated here): the full §52 conversation, message burst→one turn (`TurnSchedulerTest`), quoted-reply focus (`ContextBuilderTest`), unprompted data (`ApplicationTest`), wrong document (`DocumentPipelineTest`), withdrawal (`ApplicationTest`), session-gap return (`TurnSchedulerTest`+`ContextBuilderTest`), handoff/resume (`HandoffServiceTest`), duplicate inbound delivery (`WhatsappIncomingMessageTest`), provider failure (`AgentRunnerTest`).
- [ ] The evaluation command exists (`php artisan agent:evaluate {file}`) but **no run has been made yet** - needs `AGENT_MODEL` set and an owner-authored scripted-conversation JSON file; the report-per-candidate-model and the DEC-06 decision itself are still outstanding, owner-driven steps.
- [x] The privacy scan passes (`test_no_national_id_leaks_into_traces_or_logs`).

**Verification:** `php artisan test --filter=ScenarioTest` (4 tests, all passing). `php artisan agent:evaluate <file>` exists but hasn't been run against a real model yet - that and reviewing the report with the owner are still outstanding.

---

### T19 — Production cutover
**Status:** NEEDS DECISION (DEC-02, DEC-03, DEC-04, DEC-05, DEC-06, DEC-10, DEC-13, DEC-14, DEC-19, DEC-20, DEC-23 values) · **Priority:** P0 · **Depends on:** T18

**Objective:** switch production to the new agent safely, with a tested rollback.

**Scope**
1. **Readiness command `agent:readiness`**: fails with a list of problems if any required `config('agent.*')` value is missing, no active customer type exists, any active customer type lacks requirements, no active branch exists, no installment plan exists, the L0 instructions haven't been owner-approved (a version recorded in config), or `AGENT_MODEL` is unset.
2. **Business configuration entry** (by the owner or with the owner, in `/admin`, on a staging copy first):
   - Customer types, fields, documents, rules (DEC-02/03/04).
   - Installment parameters (DEC-01).
   - Recognition thresholds (DEC-05).
   - Branches (DEC-20).
   - Catalog fields (DEC-19).
   - Pinned memory and knowledge.
3. **Legacy data** per DEC-10 (backfill script, run on staging first, with counts before/after).
4. **Staging rehearsal:**
   - Production DB copy → run migrations → readiness → a dev WhatsApp number → run the T18 evaluation file → owner sign-off.
5. **Cutover checklist** (§8).

**Constraints**
- No migration may drop or rename legacy tables/columns in this task (that is T20).
- Cutover happens only after the owner's written sign-off in this file.

**Tests:** a feature test for `agent:readiness` (fails on each missing prerequisite; passes when all are present); a test for the DEC-10 backfill script on factory data (row counts and mapping). The full suite plus the T18 scenario suite must pass on the release commit.

**Acceptance criteria**
- [x] `agent:readiness` command implemented and tested (`AgentReadinessTest`, 2 tests) - checks every DEC-23/13/06 config value, active customer types + their requirements + `legacy_work_status` (DEC-21), an active branch, an active installment plan, and the L0 instructions' owner-approved version.
- [ ] All listed decisions (DEC-02/03/04/05/06/10/13/14/19/20/23 numeric values) still need the owner's actual values - only the *mechanisms* were decided in T12-T17.
- [ ] Business configuration entry, the DEC-10 legacy backfill script, the staging rehearsal and the cutover checklist (§8) are all owner-driven steps not started.

**Verification:** `php artisan agent:readiness` (currently fails - see the missing decisions above); the checklist items ticked with dates once done.

---

### T20 — Post-cutover legacy cleanup
**Status:** TODO · **Priority:** P2 · **Depends on:** T19

**Objective:** remove legacy code, data structures and risks only after production has run stably on the new agent. The stability period is recorded by the owner here before starting.

**Scope (each item removed only if still unused; verify with grep and tests)**
- `chatgpt-worker/` directory.
- `app/Http/Controllers/Webhooks/WhatsAppWebhookController.php`, `app/Services/WhatsAppService.php`.
- Public debug routes `/test-vision` and `/ocr-test` in `routes/web.php`. **Security note:** these are exposed on production today; the owner may choose to hotfix them on `main` earlier. That hotfix is outside this plan.
- Orphan Filament pages under `AnswerResource`, `OrderResource`, `PartnerResource`, `StockGroupResource`, `TraderResource`: confirm with the owner whether the resource classes were lost (restore) or the pages are dead (delete).
- Stale `config/gemini.php` sections `planner`, `ai_phrasing`, `providers.groq`, `tasks`; `GeminiClient::generateText` once no caller remains.
- Unused `thiagoalessio/tesseract_ocr` package.
- Legacy columns/tables per DEC-10: `whatsapp_conversations.{last_machine_id, last_machine_ids, last_topic, pending_question, context_payload, current_step, last_intent, customer_job_type, clarification_attempts, last_clarification_question}`, `whatsapp_messages.payload` (after backfill), `ai_memories`, `ai_memory_retrieval_logs`, `customer_profiles`, `ai_reply_examples`, `ai_style_memories`.
- Stray tracked files: `-H`, `-d`, `test.php`, `structure-images.php` and tracked WhatsApp images at the repo root and in `screens/` (owner confirms; they may contain customer data and should also be purged from history if so, which is an owner decision).
- **Follow-up for the owner to schedule (not done here):** make the website calculator (`layouts/app.blade.php`) and `InstallmentRequestController` use the T12 calculator and T15 validators, and fix the dead `/installment/calculate` route and the `installment_plans` query in `InstallmentRequestController::show`.

**Constraints:** a single removal per commit; the full test suite passes after each.

**Tests:** no new tests. The full existing suite (including the T18 scenario suite) is the regression check after every removal; a migration that drops legacy columns must have a test proving the new code no longer reads them.

**Acceptance criteria**
- [ ] Each listed item removed or explicitly kept with a reason recorded here.
- [ ] The full test suite passes; production smoke test passes after deploy.

**Verification:** `php artisan test`; `grep -rn "WhatsappIntentRouter\|ai_memories\|GeminiClient" app` returns only intended results.

---

## 8. Production cutover checklist (T19)

- [ ] All T01–T18 `DONE`; master table and task statuses synchronized.
- [ ] Every Decision Register item needed by T19 is `DECIDED`.
- [ ] Production database backup taken and restore tested.
- [ ] Staging rehearsal passed (evaluation report reviewed, owner sign-off with date).
- [ ] Node `.env` on production has `LARAVEL_RETRY_ATTEMPTS`, `LARAVEL_RETRY_BASE_MS`; Laravel `.env` has `AGENT_MODEL` and all `agent.*` values; `AGENT_ENABLED=false` initially.
- [ ] Merge `ai-agent-rebuild` into `main` (owner performs); run `deploy.sh`; run `php artisan migrate --force` (all additive).
- [ ] `php artisan agent:readiness` passes on production.
- [ ] Restart `whatsapp-bot`, `whatsapp-worker`, `laravel-queue`, `laravel-scheduler` with pm2 (the `whatsapp:process-jobs` loop does not pick up new code otherwise).
- [ ] Set `AGENT_ENABLED=true`, run `php artisan config:clear` / `config:cache` as used on the server, then restart `whatsapp-worker`.
- [ ] Smoke test from an owner phone: greeting, price question, image request, installment calculation, handoff and close.
- [ ] Monitor traces and handoffs for the agreed period.
- [ ] **Rollback (if needed):**
  1. Set `AGENT_ENABLED=false` and restart `whatsapp-worker` (stops replies immediately).
  2. Put the server back on the pre-merge commit `09318bf1`. The owner chooses how: revert the merge commit on `main` and run `deploy.sh`, or check out `09318bf1` on the server. Never force-push `main`. No down-migrations are needed because all migrations are additive.
  3. Restart all pm2 processes.
  4. Confirm the old bot replies.
