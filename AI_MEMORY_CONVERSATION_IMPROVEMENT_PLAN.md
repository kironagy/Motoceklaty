# AI Memory & Conversation System Improvement Plan

> Audit performed by direct code inspection (Serena symbolic tools) of the Laravel app in this repo — `app/Services/*`, `app/Services/Handlers/*`, `app/Models/*`, `database/migrations/*`, `app/Filament/Resources/AiMemoryResource.php`, `tests/*` — plus a live read of the 40 rows currently in the `ai_memories` table. No implementation has been done. Every claim below is cited to a file/method; nothing is guessed from filenames.

## 1. Executive Summary

The system (a WhatsApp sales + installment-application bot for a motorcycle dealership, "Moto Gate") is built on Laravel 12 with a single ~1,050-line orchestrator, [WhatsappIntentRouter.php](app/Services/WhatsappIntentRouter.php), calling out to Gemini via [GeminiClient.php](app/Services/GeminiClient.php) for intent planning and free-text fallback, and reading business knowledge from a flat `ai_memories` table (40 active rows today).

The architecture is **better than the brief assumed** in one respect: deterministic intents (price, images, installment calc, brand list) already bypass the LLM memory-fallback path entirely and pull from the database plus exact-title template memories ([AiMemoryResolver::memoryByExactTitle](app/Services/AiMemoryResolver.php:27)). The memory-retrieval scoring problem (Problem 1) only affects the *conversational fallback* path ([AiComplexReplyService](app/Services/AiComplexReplyService.php)), not the whole bot.

It is **worse than the brief assumed** in three respects that explain most of the reported symptoms directly from code, not speculation:

1. **Application-flow intent lock-in is unconditional.** [WhatsappIntentRouter::handle()](app/Services/WhatsappIntentRouter.php:17), lines ~79–92, forcibly overwrites *any* classified intent to `application` whenever `pending_question` is `application_missing_data`/`application_documents` — unless the LLM specifically said `application_status`. A customer asking "بكام التوصيل؟" mid-application is coerced straight back into the missing-field flow before a general-question handler ever sees the message. This is Problem 7's exact root cause.
2. **The missing-field question is regenerated from the full required-field list on every turn.** [ApplicationHandler::questionForMissing()](app/Services/Handlers/ApplicationHandler.php:613) has no concept of "what was just filled in" — it re-lists everything still empty, every time, with no acknowledgment of progress. This is Problems 4, 5, 6's root cause, together.
3. **There is no clarification-attempt counter or semantic escalation path anywhere in the codebase** (confirmed by grep — zero hits for `clarification_attempts`, `MAX_CLARIFICATION`). The only escalation trigger is either an explicit "I want a human" keyword match ([isHumanSupportRequest](app/Services/WhatsappIntentRouter.php:901)) or two consecutive *technical* Gemini failures ([handleAiFallback](app/Services/WhatsappIntentRouter.php:350), `ai_fallback_failures`). Repeated failure to *understand* the same message never escalates — the bot will ask a generic clarification question indefinitely.

Memory retrieval (Problem 1) is real but subtler than "not enough memories are picked": [AiMemoryContextBuilder](app/Services/AiMemoryContextBuilder.php) filters to at most 18 memories by keyword-substring score, but falls back to dumping **all 40 active memories** whenever fewer than 5 score above zero (`MIN_RELEVANT_TO_TRUST_FILTER = 5`, line 21). With pure substring matching and no metadata, short customer messages routinely fail to clear that 5-match bar, so in practice a large fraction of fallback replies already get the full unfiltered dump — the "smart" filter is closer to a no-op than a curator today, and will break down hard (token bloat, blind spots) once memory count grows past ~100.

Addresses are the clearest concrete case of Problem 3: the business's *own* memory record ([`#30 قواعد العناوين`](#memory-30)) already defines the exact structured schema required — street, branch-of, area, governorate, landmark, building number, floor, apartment — but the database only stores `applicant_address` and `work_address` as single free-text `VARCHAR` columns ([migration](database/migrations/2025_10_21_055816_add_address_to_installment_requests_table.php)), and completeness is decided per-turn by an LLM prompt heuristic ("if two address-shaped lines appear, the first one is work, the second is home" — [AiIntentClassifier::extractApplicationData](app/Services/AiIntentClassifier.php:377)) rather than structured field tracking. That heuristic is also why partial addresses ("٦ أكتوبر") can't be asked about component-by-component.

## 2. Current Architecture

```
                         WHATSAPP (Node worker, whatsapp-bot/index.js)
                                       │  webhook
                                       ▼
                    App\Http\Controllers\Api\WhatsappBotController
                                       │
                                       ▼
                       App\Services\WhatsappIntentRouter::handle()
                     (single ~1,050-line orchestrator, 50+ methods)
              ┌─────────────┬─────────────┬─────────────┬─────────────┐
              ▼             ▼             ▼             ▼             ▼
   AiIntentClassifier  MachineSearchService  ApplicationHandler  MediaOcrHandler  AiComplexReplyService
   (Gemini JSON plan)   (DB machine search)  (installment app)   (OCR intake)     (free-text fallback)
              │                                     │                                  │
              ▼                                     ▼                                  ▼
        GeminiClient                     AiIntentClassifier (2nd prompt,        AiMemoryContextBuilder
     (multi-key rotation,                 'application_data_extraction'          → AiMemoryResolver
      rate-limit backoff)                       mode)                          (keyword-substring score
                                                                                  over ai_memories table)
```

Data stores:
- `whatsapp_conversations` — the *de facto* conversation+application state, but unstructured: `current_step`, `last_intent`, `last_topic`, `pending_question`, `context_payload` (JSON blob holding `application`, `missing_fields`, `documents_required/collected`, etc. — [migrations](database/migrations/2026_07_13_095551_add_ai_state_to_whatsapp_conversations_table.php)).
- `whatsapp_messages` — raw message log (`direction`, `message`, `payload`).
- `ai_memories` — 40 active rows, columns: `id, title, content (longtext), template_replies (json), is_active, sort`. No category/type/scope/keywords/intent/priority/conditions metadata ([migration](database/migrations/2026_05_31_233717_create_ai_memories_table.php), confirmed against the [Filament form](app/Filament/Resources/AiMemoryResource.php) which only edits `title`, `content`, `template_replies`, `is_active`, `sort`).
- `installment_requests` — the final structured application record, built by [ApplicationHandler::createInstallmentRequest](app/Services/Handlers/ApplicationHandler.php). Addresses here are flat strings, not structured components.

## 3. Current Runtime Flow (traced, not assumed)

Inside [WhatsappIntentRouter::handle()](app/Services/WhatsappIntentRouter.php:17):

1. If `conversation.status === 'awaiting_agent'`, the AI is fully silent (message just logged for the human agent) — correct behavior, already implemented.
2. Voice-only media → `handleVoiceMessage`; other media → OCR/image-recognition handlers.
3. `isHumanSupportRequest($message)` — a fixed Arabic keyword list ("دعم فني", "اكلم موظف", "مش فاهم حاجه", …) → immediate `handoffToAgent`. This is a real handoff path, but it is keyword-triggered only, not usage-pattern-triggered.
4. `AiIntentClassifier::classify()` calls Gemini with a large hand-written Arabic prompt (line 54–179) and expects strict JSON: `intent, target, machine_query, months, needs_clarification, clarification_question, confidence, …`.
5. **The override block** (lines ~79–139): if the conversation has a pending application question, or the customer just gave a bare confirmation after an installment calculation, or the raw message matches an `isApplicationIntent` regex — and the classified intent isn't `application_status` — the router throws away the classified intent/target/clarification and **forces** `intent = 'application'`. This runs *before* any other intent (price/images/general) gets a chance to be handled.
6. If `needs_clarification`, reply with the one clarification question and return — no state is written recording that a clarification was asked, so a second unclear message asks again from a blank slate.
7. Machine resolution, brand filtering, variant narrowing (a large, well-commented block handling Egyptian-Arabic model aliasing via memory `#33`).
8. Branch to intent handler: `application_status`, `application` → `ApplicationHandler::handle`, `installment_system`, `installment_calc`, `brand_models`, `images`, `price`.
9. Anything left over → `handleAiFallback` → `AiComplexReplyService::reply` → `AiMemoryContextBuilder::buildForMessage` (the memory-retrieval path from Problem 1) → Gemini free text.

Inside [ApplicationHandler::handle()](app/Services/Handlers/ApplicationHandler.php:14) (runs on *every* turn while an application is in progress):

1. Resolve current machine and payment method.
2. Call `AiIntentClassifier::classify()` **again**, in `application_data_extraction` mode, with a second, separate prompt ([extractApplicationData](app/Services/AiIntentClassifier.php:377)) that asks the model to guess which free-text field a message belongs to, including an explicit line-order heuristic for addresses.
3. `mergeApplicationData()` — blind `$current[$key] = $value` overwrite, no diff, no conflict check.
4. `missingFields()` — binary per-field check; address "partial-ness" reduces to a single `incomplete`/`complete` string the LLM assigned this same turn.
5. `questionForMissing()` — **always rebuilds the full missing list**, regardless of what was just filled in this turn.

## 4. Current Problems (ranked by evidence strength — all confirmed in code)

| # | Problem | Confirmed in |
|---|---|---|
| 1 | Application flow overrides customer intent unconditionally | [WhatsappIntentRouter.php:79-139](app/Services/WhatsappIntentRouter.php) |
| 2 | Missing-field response is not progress-aware / re-lists everything | [ApplicationHandler.php:613](app/Services/Handlers/ApplicationHandler.php) |
| 3 | No clarification-attempt counter or repeated-failure escalation | grep-confirmed absent; only keyword-handoff and technical-failure-handoff exist |
| 4 | Address is unstructured in DB despite a memory record defining the structured schema | [migration](database/migrations/2025_10_21_055816_add_address_to_installment_requests_table.php) vs [memory #30](#memory-30) |
| 5 | No conflict detection on field updates (e.g. phone number changes) | [ApplicationHandler::mergeApplicationData](app/Services/Handlers/ApplicationHandler.php:556) |
| 6 | Memory retrieval degrades to full-dump for a large share of messages | [AiMemoryContextBuilder.php:38-65](app/Services/AiMemoryContextBuilder.php) |
| 7 | `ai_memories` has no metadata for filtering/ranking (category, scope, intent, priority, conditions) | [migration](database/migrations/2026_05_31_233717_create_ai_memories_table.php), [Filament form](app/Filament/Resources/AiMemoryResource.php) |
| 8 | Two separate, misaligned intent taxonomies (LLM planner vs. deterministic scorer) | [AiIntentClassifier.php](app/Services/AiIntentClassifier.php) intents list vs. [AiIntentDetector.php](app/Services/AiIntentDetector.php) intents list |
| 9 | Near-duplicate memory content not deduplicated | memories `#41` and `#42` ("المستندات الأساسية المطلوبة") |
| 10 | Zero test coverage for memory retrieval, intent classification, router branching, clarification, repetition | [tests/Unit](tests/Unit), [tests/Feature](tests/Feature) — only `ApplicationHandlerTest`, `ApplicationStatusHandlerTest`, Gemini key/rate-limit tests exist |
| 11 | No observability into why a memory was/wasn't selected | no logging calls in `AiMemoryResolver`/`AiMemoryContextBuilder` |

## 5. Root Cause Analysis

**Why does the AI sometimes ignore a customer's question mid-application?**
Not an LLM reasoning failure — the LLM's classified intent is discarded by deterministic Laravel code before it reaches any handler. The override condition at [WhatsappIntentRouter.php:79](app/Services/WhatsappIntentRouter.php) treats "an application is pending" as higher priority than "what did the customer just say," with only one carve-out (`application_status`). This is a one-line-of-reasoning bug in otherwise careful code — the fix is architectural (interrupt-and-resume), not a prompt tweak.

**Why does the AI sound robotic / repeat itself?**
Because there is no state comparison between turns anywhere in the response-generation path. `questionForMissing()` takes only the *current* missing-field list — it never receives "what changed since last turn." The fix is a progress-delta computation (Section 11) feeding a response strategy layer, not "tell the LLM to vary its wording" (explicitly the wrong fix per the brief, and confirmed structurally wrong here — the message text isn't LLM-generated at all in this path, it's PHP string concatenation of a static label array).

**Why can the AI not ask for just the missing piece of an address?**
Address completeness is a single LLM-assigned enum (`complete`/`incomplete`) per turn, re-decided from scratch each time using an order-based heuristic, not a structured field set. There is no data model component for "which part of the address is missing." Fix: structure the address (Section 9/10), let deterministic code compute exactly which sub-field is absent.

**Why does memory retrieval feel unreliable at ~40 memories?**
The scoring function is Arabic-normalized *substring containment* with no synonyms, stemming, or metadata boost — a message can easily share zero or one token with a topically-relevant memory (e.g. a message about "مقدم" won't score against the "أصحاب المهن الحرة" memory even though it may be relevant in context) while accidentally over-matching an unrelated memory that happens to contain a common word. Because the fallback-to-full-dump threshold is a flat count of 5 matches, this either silently reverts to full-dump (masking the ranking problem, at token cost) or occasionally returns a genuinely wrong top-18. Root cause: **no metadata layer and no semantic signal**, not "top-K is too small."

**Why is there no escalation after repeated confusion?**
Because clarification state isn't persisted. `needs_clarification` is a stateless per-call output of the LLM planner; nothing increments a counter or records that "we already asked this once." The fix is a `clarification_attempts` counter tied to `pending_question`/topic, with a configurable threshold — pure deterministic backend logic, no LLM call needed.

## 6. Proposed Architecture

The brief's target diagram is directionally right but is missing an explicit **Progress Delta** stage and an explicit **Interrupt/Resume** stage — both required to fix Problems 4–7 without more prompt engineering. Refined:

```
                         CUSTOMER MESSAGE
                                │
                                ▼
                    MESSAGE UNDERSTANDING (LLM, JSON-only)
                     - intent, target, entities (existing AiIntentClassifier,
                       kept, but taxonomy unified — Section 12)
                                │
              ┌─────────────┬──┴──────────┬─────────────┐
              ▼             ▼              ▼             ▼
           INTENT       ENTITIES      APP STATE      CONVERSATION STATE
        (unified enum) (extracted    (structured,   (pending_question,
                        fields)       not JSON blob) clarification_attempts)
              └─────────────┴──────────────┴─────────────┘
                                ▼
                     INTERRUPT / RESUME DECISION   ◄── NEW, deterministic
                     - is this a real question that should be answered
                       even though an application is pending?
                     - Section 12.2 decision table, no LLM call needed
                                │
                                ▼
                        PROGRESS DELTA            ◄── NEW, deterministic
                     - state_before vs state_after
                     - newly_filled / still_missing / partial / conflicting
                                │
                                ▼
                        MEMORY RETRIEVAL
                     - metadata filter (category/scope/intent) FIRST
                     - keyword+metadata score SECOND (Section 7)
                                │
                         ┌──────┴──────┐
                         ▼             ▼
                      FILTER          RANK
                         │             │
                         └──────┬──────┘
                                ▼
                       RELEVANT CONTEXT
                                │
                                ▼
                      RESPONSE STRATEGY          ◄── NEW
                     - progress-aware template selection
                     - only call the LLM for genuinely free-text replies;
                       missing-field acknowledgment stays deterministic PHP
                                │
                                ▼
                        LLM GENERATION (only when strategy requires it)
                                │
                                ▼
                     RESPONSE VALIDATION
                     - repetition check (Section 16)
                     - clarification counter check → escalate if over threshold
                                │
                     ┌──────────┴──────────┐
                     ▼                     ▼
                   SEND                ESCALATE
                     │
                     ▼
               UPDATE STATE (conversation + application, atomically)
```

Key principle carried through every section below: **keep field extraction, missing-field computation, conflict detection, progress delta, clarification counting, and escalation thresholds as deterministic PHP** (cheap, testable, debuggable). Reserve the LLM for what it's already doing well here — intent/entity understanding from free Arabic text, and genuinely open-ended replies. This matches what's already partially true in the code (deterministic intents bypass the LLM memory path) — the plan extends that separation rather than introducing it from scratch.

## 7. Memory Architecture

**Current state:** flat table, keyword-substring scoring, hard fallback-to-full-dump. Works passably at 40 memories only because the fallback masks the ranking weakness by dumping everything.

**Diagnosis of what's actually needed at each scale:**
- **40–100 memories:** the fallback-to-full-dump strategy is still *affordable* token-wise but the ranking needs a metadata pre-filter so the fallback triggers far less often and produces a more precise top-K when it doesn't.
- **500+ memories:** full-dump is no longer viable (token cost, latency, and — per the brief's own concern — the model's ability to pick the right rule out of 500 degrades). Metadata filtering becomes mandatory *before* any scoring; embeddings become worth their cost at this scale.

**Recommendation: two-phase hybrid, introduced in phases (do not build embeddings for 40 memories — see Section 23 cost analysis):**

**Phase A (works today, no new infra):**
1. Add metadata columns to `ai_memories` (Section 17): `category`, `scope` (`sales`|`application`|`support`), `applicable_intents` (json array), `keywords` (json array, admin-curated, supplements auto-tokens), `priority` (int).
2. Retrieval becomes: metadata filter (match `applicable_intents` against the classified intent, when known) → keyword score over the *filtered* set → take top-K with a **confidence-scaled K** (more matches above a score floor ⇒ smaller K is trusted; few matches ⇒ widen K instead of falling back to a full dump, since the metadata filter already bounds the candidate set to something sane even at 500 rows).
3. Log every retrieval: `memory_id, score, matched_tokens, matched_keywords, included|excluded, reason`. This directly answers "why was Memory X selected/ignored" (Section 22).

**Phase B (only once memory count or subjective quality actually requires it — a real trigger, not a default):**
4. Add a nullable `embedding` column (pgvector if the DB supports it, else a JSON float array + a scheduled re-rank job) and use it as a *re-ranker* over the metadata-filtered candidate set (never as the sole retrieval mechanism — metadata filtering stays the first, cheap cut).

**What NOT to do:** don't replace the existing exact-title template-reply lookup ([memoryByExactTitle](app/Services/AiMemoryResolver.php:27)) with anything fuzzy — that deterministic path (used for price/images/installment canned replies) is already correct and should be left alone. The retrieval overhaul in this section applies only to the conversational-fallback memory context (`AiMemoryContextBuilder`), not to the deterministic intent handlers.

## 8. Memory Normalization

All 40 active memories were read directly from the database. Summary table (full detail only for rows with an actual issue, per the "don't rewrite things that are already fine" instruction):

| Memory | Quality | Problem | Recommended Action | Risk |
|---|---|---|---|---|
| #30 قواعد العناوين | Good | None — this is the canonical address schema; currently unused by the data model (Section 9 fixes that) | Add metadata only (`category=application`, `applicable_intents=[application]`) | None |
| #31 الشركة والفروع | Good | None | Add metadata (`category=support`) | None |
| #32 طريقة حساب سعر القسط | Good | None | Add metadata (`category=pricing`) | None |
| #33 المخزون والموديلات | Good | Long alias list — fine for parsing (`AiMemoryParser::aliasRules` needs `=`/`:` lines, not prose) | Add metadata (`category=catalog`); do not touch content | None |
| #34 شرح المواصفات | Good | None | Add metadata | None |
| #35 نظام 20% | Good | None | Add metadata (`category=pricing`, `applicable_intents=[installment_system,installment_calc]`) | None |
| #36 نظام 30% | Good | None | Add metadata, same as #35 | None |
| #37 قواعد الدخل الحر | Good | None | Add metadata (`category=eligibility`) | None |
| #38 أسلوب البيع والكلام | Good | This is a *style* rule, not domain knowledge — different retrieval need (should always be in context, not scored) | Tag `scope=always_include` rather than scoring it against a message | None — behavior-preserving, just changes *when* it's included, not what it says |
| #39 التسعير وطريقة عرض التقسيط | Good | Same as #38 — a hard rule that must never be filtered out | Tag `scope=always_include` | None |
| #40 نظام التقسيط | Good | None | Add metadata | None |
| **#41** المستندات الأساسية المطلوبة | **Duplicate of #42, but not identical** — #41 has 2 extra lines (`لو العميل متأمن عليه مش بحتاج منه عنوان عمل`, `تسأله دايما اذا كان قدم طلب تقسيط ف مكان تاني`) that #42 lacks | Two memories with the same title, overlapping but non-identical content — the resolver will score both and may surface conflicting instructions to the model in the same context | **NEEDS_HUMAN_REVIEW** — cannot safely merge without knowing which version is current; the extra lines in #41 look newer/more complete but may have been intentionally split | Medium — a wrong merge could silently drop the "متأمن عليه" exemption rule |
| #42 المستندات الأساسية المطلوبة | See #41 | See #41 | See #41 | See #41 |
| #43 الموظفين | Good | None | Add metadata (`category=eligibility`) | None |
| #44 أصحاب المعاشات | Good | None | Add metadata | None |
| #45 أصحاب الأنشطة التجارية | Good | None | Add metadata | None |
| #46 أصحاب المهن الحرة | Good | None | Add metadata | None |
| #47 الدليفري | Good | None | Add metadata | None |
| #48 التاكسي | Good | None | Add metadata | None |
| #49 الميكروباص | Good | Trivial one-liner referencing #48 by name (not by ID/link) | Add metadata; optionally note the reference as a soft dependency in `applicable_when`, but content unchanged | None |
| #50 كشف الحساب | Good | None | Add metadata | None |
| #51 الفئات الممنوعة | Good | None — a hard exclusion rule | Tag `scope=always_include` (same reasoning as #38/#39 — an eligibility exclusion must never be filtered out by a relevance score) | None |
| #52 متابعة العميل | Good | None | Add metadata (`category=support`) | None |
| #53 مراجعة البيانات مع العميل | Good | None | Add metadata (`category=application`) | None |
| #54 الاسعار والانواع | **Needs review, not rewrite** | Freeform tab-separated price table inside `content` (not structured data) — works for LLM context but can't be validated/queried and is a second source of truth for prices that could drift from the `machines` DB table | **NEEDS_HUMAN_REVIEW**: recommend confirming whether the `machines` table is authoritative for price (it is, per `MachineSearchService`/`handleCashPrice` reading DB directly) — if so, this memory is redundant for deterministic price replies and should be scoped `scope=fallback_context_only` so it's never used to *contradict* the DB, only as extra color for the free-text fallback | Low if scoped correctly; Medium if left unscoped (a stale row here could make the fallback quote a wrong price while the deterministic path quotes the right one) |
| #55 تاكيد البيانات | Good | Contains an embedded JSON-output instruction (`[[ORDER_DATA]]...[[/ORDER_DATA]]`) — verify this JSON contract is still consumed somewhere (search shows no current reader for this marker in the audited files) | **NEEDS_HUMAN_REVIEW**: confirm whether this order-confirmation flow is still wired up, since `ApplicationHandler` builds `installment_requests` directly without this marker | Medium — if dead, it's wasted context tokens on every fallback call; if live, it's on a code path not covered by this audit and must not be touched blind |
| #56 تاكيد الرفع | Good | None | Add metadata | None |
| #57 استخراج الاسم من البطاقه | Good | None (OCR instruction) | Add metadata (`category=ocr`) | None |
| #58 مراجعه صور النشاط | Good | None | Add metadata (`category=ocr`) | None |
| #59–#68 (رد …) | Good | These are template-reply memories consumed by exact title lookup, not the scorer — already correctly separated from the relevance problem | No content change; add `type=template_reply` metadata for clarity in the admin UI only | None |
| #69 انظمه التقسيط | Terse | Very short — may not carry enough vocabulary to ever win the relevance score against a real question | Add metadata + a couple of admin-curated `keywords` (e.g. "بلينك", "انظمة") rather than rewriting content | None |

No memory in the current set required a wording rewrite under the strict meaning-preservation rule — the set is generally well-written for a human sales rep. The real problem was never the *prose*, it's that the table has no machine-usable structure around that prose. The two `NEEDS_HUMAN_REVIEW` items (#41/#42 duplicate, #55's JSON contract) should be resolved by the business owner before Phase 3 (Section 26) ships metadata for them.

## 9. Application State Architecture

**Current state:** `whatsapp_conversations.context_payload` (JSON blob) holds an ad-hoc `application` array with flat keys (`full_name`, `national_id`, `phone`, `job_type`, `income_proof`, `work_address`, `home_address`, `installment_months`) plus a parallel `missing_fields` array recomputed every turn. No schema enforcement, no per-field status beyond a string the LLM writes into `*_address_status`.

**Recommended structure** (deterministic PHP value object, e.g. `App\Support\ApplicationState`, serialized into `context_payload['application_state']`, replacing today's flat `application` array — not a new table, since `installment_requests` already exists as the terminal record and duplicating it pre-submission would be premature):

```
required_fields:      [full_name, national_id, phone, job_type, income_proof,
                        work_address, home_address, installment_months]
known_fields:          { field => value }             (only fields with a value)
field_status:          { field => COMPLETE|PARTIAL|MISSING|INVALID|CONFLICTING }
address_components:    { home: {governorate, city, area, street, building,
                                 floor, apartment, landmark}, work: {...} }
                        — component keys sourced directly from memory #30
partial_fields:        [field, ...]                    (derived from field_status)
missing_fields:        [field, ...]                    (derived from field_status)
conflicting_fields:    { field => {previous, new} }     (derived, see Section 10.4)
newly_received_fields: [field, ...]                    (this turn only — Section 11)
current_stage:         payment_method|application_data|documents|review|submitted
clarification_attempts: int                             (Section 14)
```

Address fields specifically move from a single `VARCHAR` + LLM-assigned `incomplete`/`complete` string to the 8-component structure memory #30 already defines. `field_status['home_address']` becomes a deterministic function: `COMPLETE` iff all required components are present (street + area/governorate + building, at minimum — floor/apartment/landmark stay optional, matching the memory's own wording that only some parts are always required), `PARTIAL` iff at least one but not all are present, `MISSING` iff none are.

## 10. Missing Information Detection

Given `ApplicationState`, missing-detection becomes pure deterministic logic (no LLM call needed for the *decision*, only for the *extraction* of raw values from free text, which the LLM already does reasonably well per Section 3 step 2):

**10.1 — Field status function** (replaces [ApplicationHandler::missingFields](app/Services/Handlers/ApplicationHandler.php:577)):
```
for each required field:
    if field is address-type: status = address_component_status(field)
    elif value present and passes format validation: COMPLETE
    elif value present but fails format validation (e.g. national_id not 14 digits): INVALID
    elif value absent: MISSING
```

**10.2 — Address component status:**
```
present = components filled (from extraction)
required_min = [street, (area OR governorate), building]
if all(required_min) present: COMPLETE
elif any present: PARTIAL, missing = required_min - present
else: MISSING
```
This directly satisfies the brief's Test 1 (governorate+city+street known, building missing ⇒ ask only for building) and Test 3 (only "٦ أكتوبر" given ⇒ city known, PARTIAL, ask for the rest — never re-ask for the whole address).

**10.3 — Question generation** — becomes a function of `missing_fields` **and** `partial_fields`'s specific missing components, not a flat required-field list:
- Single overall-missing field → "محتاج {label} بس." (already the single-field case in [questionForMissing](app/Services/Handlers/ApplicationHandler.php:613), keep it)
- Address PARTIAL → "محتاج {missing_component_labels} في {street/area label}." — new case, doesn't exist today.
- Multiple fields missing → keep the bulleted list, but see Section 11 for how the *surrounding sentence* changes based on progress.

**10.4 — Conflict detection** (replaces the blind overwrite in [mergeApplicationData](app/Services/Handlers/ApplicationHandler.php:556)):
```
for each extracted field with a non-null new value:
    if field already has a known value AND new value differs AND both look validly-formatted:
        record as conflicting_fields[field] = {previous, new}
        do NOT overwrite known_fields[field] yet
    else:
        overwrite as today
```
When `conflicting_fields` is non-empty, the response strategy (Section 11) asks a single disambiguation question per conflicting field before continuing, e.g. "حضرتك آخر رقم بعتّه ١١١١١١١١١١ مختلف عن الرقم اللي بعتّه قبل كده ١٢٣٤٥٦٧٨٩٠ — أعتمد أنهي واحد؟" This satisfies Test 11.

## 11. Progress-Aware Conversation

**State-before / state-after / delta**, computed once per turn in `ApplicationHandler::handle` right after extraction (Section 3 step 2) and before `mergeApplicationData`:

```
delta.newly_filled   = fields where status changed *_MISSING/PARTIAL → COMPLETE this turn
delta.still_partial  = fields still PARTIAL (may have gained a component)
delta.still_missing  = fields still MISSING
delta.corrected      = fields where the customer's new value differs from a MISSING/PARTIAL
                        previous guess (not a conflict — a conflict is COMPLETE → different value)
```

**Response strategy** (deterministic template selection, replacing [questionForMissing](app/Services/Handlers/ApplicationHandler.php:613) wholesale):

```
if delta.newly_filled is empty and this is not the first ask this turn:
    → same behavior as today (nothing changed, re-ask plainly) — no artificial variation needed,
      matching the brief's "don't force variation when repetition is logically necessary"
elif delta.newly_filled is non-empty:
    opening = "تمام يا فندم، استلمت " + join(labels(delta.newly_filled), " و")
    if delta.still_missing or delta.still_partial:
        body = "ناقصني " + bulleted(labels(delta.still_missing) + partial_component_labels(delta.still_partial))
    else:
        body = "" (all required fields complete → move to documents stage, Section 3 already does this)
    reply = opening + ". " + body
```

This is exactly the brief's worked example ("تمام يا فندم، استلمت الاسم والرقم القومي وطبيعة الشغل. ناقصني: …") produced by string composition over `delta`, with **no LLM call** for the acknowledgment itself — cheap, deterministic, testable, and impossible to regress into "just paraphrasing" because it's not natural-language-generated at all, it's templated from real state.

## 12. Intent & Multi-Intent Architecture

**12.1 — Unify the two intent taxonomies.** Today [AiIntentClassifier](app/Services/AiIntentClassifier.php) and [AiIntentDetector](app/Services/AiIntentDetector.php) disagree (the detector emits `comparison`/`recommendation`/`specs`/`documents`/`order`/`followup` that the classifier's prompt never produces and the router has no branch for). Recommendation: extend the classifier's prompt enum to a single authoritative list covering what the router can actually act on:

```
price | images | installment_calc | installment_system | brand_models |
application | application_status | delivery_question | payment_question |
warranty_question | complaint | support | faq | small_talk | unknown
```
`delivery_question`/`payment_question`/`warranty_question`/`complaint`/`faq`/`small_talk` are new first-class intents pulled out of today's undifferentiated `general`/`unknown` bucket, each routed to `handleAiFallback` (unchanged handler) but now *logged* as a distinct intent for observability (Section 22) and eligible for intent-scoped memory filtering (Section 7 Phase A).

**12.2 — Interrupt/resume decision table** (the fix for Problem 7, replacing the unconditional override at [WhatsappIntentRouter.php:79](app/Services/WhatsappIntentRouter.php)):

| Classified intent while `pending_question` is set | Action |
|---|---|
| `application_status` | handled today — keep as-is |
| `application`, or unclassifiable text matching `isApplicationIntent` | continue application flow — keep as-is |
| bare confirmation ("تمام"/"ماشي") with no other content | continue application flow — keep as-is |
| `price`, `images`, `delivery_question`, `payment_question`, `warranty_question`, `faq`, `installment_system` (a genuine question with its own confident intent) | **answer the question via its normal handler, then append a one-line resume prompt** ("ولسه ناقصني {missing_fields_summary}") and leave `pending_question` unchanged |
| `small_talk`, `complaint`, `support`, `unknown` with low confidence | fall through to today's override (safe default — ambiguous input during an active application still nudges back to the application) |

This directly implements the brief's multi-intent example ("اسمي كيرو ورقم موبايلي ... وبالمناسبة التوصيل بكام؟"): the extraction step (Section 3, still runs unconditionally on every message while `pending_question` is application-related) captures name+phone into the delta regardless of which branch fires, and the branch decides only what to *say* — answer-then-resume vs. resume-only.

## 13. Typo / Arabic / Ambiguity Handling

**Current state:** [AiMemoryParser::normalize()](app/Services/AiMemoryParser.php) already handles the standard Egyptian-Arabic normalization set (hamza forms → ا, ة→ه, ى→ي, Arabic-Indic digits → Latin digits) — this part is solid and should not be touched. What's missing is **confidence-tiered handling of genuinely unresolvable input** (the brief's "VLR" / "بكم في الار" cases).

**Recommendation:** use the `confidence` field the classifier prompt *already returns* (it's in the JSON schema at the end of [the prompt](app/Services/AiIntentClassifier.php:54), currently computed by the model but never read anywhere downstream — confirmed by grep, `$plan['confidence']` is written into the plan array but no code branches on it). Wire it up:
```
confidence >= 0.7  → act on the classified intent directly (today's behavior)
0.4 <= confidence < 0.7 → ask ONE targeted clarification question using clarification_question
                          from the plan, and increment clarification_attempts (Section 14)
confidence < 0.4   → ask a broader clarification ("مش قادر أفهم قصدك بالظبط، ممكن توضح أكتر؟")
                     and increment clarification_attempts
```
This is a threshold change on data already computed, not new LLM calls — near-zero added cost.

## 14. Clarification System

New, small, deterministic addition to conversation state: `context_payload['clarification_attempts']` (int, reset to 0 whenever a message is confidently understood — i.e. `confidence >= 0.7` in Section 13, or any deterministic-intent handler fires successfully).

```
MAX_CLARIFICATION_ATTEMPTS = config('ai.max_clarification_attempts', 3)   // config, not hardcoded
```

On each low-confidence turn: increment the counter, ask a clarification question that **varies by attempt number** (this is the one place slight wording variation is actually warranted, because the brief's own example escalates the specificity of the question across attempts — attempt 1: topic guess, attempt 2: open question, attempt 3 triggers escalation instead of a third question). Store the last 1–2 clarification questions asked (not full history) so attempt 2's wording can avoid literally repeating attempt 1's.

## 15. Support Escalation

Extend the existing [handoffToAgent](app/Services/WhatsappIntentRouter.php:1008) trigger set (currently: explicit keyword match, or 2 consecutive technical Gemini failures) with a third trigger: `clarification_attempts >= MAX_CLARIFICATION_ATTEMPTS`. On this trigger, log a structured escalation record (Section 22) containing the customer's last message, all prior interpretations attempted, the conversation context snapshot, and `reason = 'clarification_exhausted'` — reusing the existing `handoffToAgent` mechanics (sets `status = awaiting_agent`, notifies staff) so no new handoff plumbing is needed, only a new trigger condition and a richer log payload.

## 16. Response Repetition Prevention

Two different mechanisms for two different response types, matching how responses are actually generated today:

- **Templated responses** (missing-field questions, Section 11): repetition is prevented *structurally* — the response strategy already only re-asks the same thing when `delta.newly_filled` is genuinely empty, which is the brief's explicitly allowed case ("repeating a request is fine when nothing changed"). No extra repetition-detection code is needed here because the content itself is state-derived, not independently generated each time.
- **LLM free-text responses** (`AiComplexReplyService` fallback, clarification questions): add a lightweight semantic-repetition check — store the last 2 outgoing message bodies on the conversation (already logged in `whatsapp_messages`, just need to query them), compute a cheap similarity signal (normalized-token Jaccard overlap is sufficient here — no need for embeddings just for this check) against the candidate reply before sending; if similarity is high, that's a *signal* fed back into the clarification-attempt escalation (Section 14/15) rather than a prompt to "reroll" the LLM — a near-duplicate reply after a clarification attempt is itself evidence the bot isn't converging, and should count toward escalation, not trigger a cosmetic reword.

## 17. Database Changes

All additive (new nullable columns / new table), no destructive changes to existing data:

```sql
-- ai_memories: metadata for retrieval filtering (Section 7)
ALTER TABLE ai_memories ADD COLUMN category VARCHAR(50) NULL;
ALTER TABLE ai_memories ADD COLUMN scope VARCHAR(50) NULL;            -- e.g. always_include, fallback_context_only
ALTER TABLE ai_memories ADD COLUMN applicable_intents JSON NULL;
ALTER TABLE ai_memories ADD COLUMN keywords JSON NULL;                -- admin-curated, supplements auto-tokens
ALTER TABLE ai_memories ADD COLUMN priority INT DEFAULT 0;

-- whatsapp_conversations: clarification tracking (Section 14)
ALTER TABLE whatsapp_conversations ADD COLUMN clarification_attempts INT DEFAULT 0;
ALTER TABLE whatsapp_conversations ADD COLUMN last_clarification_question VARCHAR(500) NULL;

-- installment_requests: structured address (Section 9/10) — additive alongside
-- the existing applicant_address/work_address strings, which stay as a
-- human-readable rendered summary and are NOT dropped (avoids breaking any
-- existing report/PDF/Filament view that reads them directly)
ALTER TABLE installment_requests ADD COLUMN home_governorate VARCHAR(100) NULL;
ALTER TABLE installment_requests ADD COLUMN home_city VARCHAR(100) NULL;
ALTER TABLE installment_requests ADD COLUMN home_area VARCHAR(100) NULL;
ALTER TABLE installment_requests ADD COLUMN home_street VARCHAR(255) NULL;
ALTER TABLE installment_requests ADD COLUMN home_building VARCHAR(50) NULL;
ALTER TABLE installment_requests ADD COLUMN home_floor VARCHAR(20) NULL;
ALTER TABLE installment_requests ADD COLUMN home_apartment VARCHAR(20) NULL;
ALTER TABLE installment_requests ADD COLUMN home_landmark VARCHAR(255) NULL;
-- mirrored work_* columns for work_address

-- new: structured retrieval observability (Section 22) — append-only log,
-- not queried on the hot path, safe to write asynchronously if volume grows
CREATE TABLE ai_memory_retrieval_logs (
  id BIGINT PRIMARY KEY,
  whatsapp_conversation_id BIGINT NULL,
  message_excerpt VARCHAR(500),
  intent VARCHAR(50) NULL,
  candidate_memory_ids JSON,
  selected_memory_ids JSON,
  scores JSON,
  retrieval_method VARCHAR(50),           -- keyword | metadata_filtered | full_dump | embedding_rerank
  fell_back_to_full_dump BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP
);
```

## 18. Backend Changes

- `App\Support\ApplicationState` value object + `ApplicationStateService` (compute status, delta, conflicts) — new, pure PHP, no LLM dependency. Replaces `ApplicationHandler::missingFields`/`mergeApplicationData` internals; `ApplicationHandler::handle` becomes a thinner orchestrator calling this service.
- `App\Support\AddressParser` — deterministic best-effort component splitter (governorate/city keyword list + heuristics) that runs *before* asking the LLM to fill gaps, so the LLM extraction prompt only needs to fill what deterministic parsing couldn't, shrinking its guesswork surface.
- `WhatsappIntentRouter`: replace the unconditional override block (Section 3 step 5) with the decision table from Section 12.2. This is a behavior-sensitive change to the busiest file in the codebase — must ship behind the test suite in Section 21 before touching production traffic.
- `AiMemoryResolver`/`AiMemoryContextBuilder`: add metadata-filter pre-pass (Section 7 Phase A); keep the existing keyword scorer as the second pass, unchanged in its scoring math (it's fine, it just needs a better candidate set to run over).
- New `ClarificationService`: attempt counting, threshold check (config-driven), escalation trigger call.
- New `RepetitionGuard`: Jaccard-overlap check against last 2 outgoing messages (Section 16), invoked only on the LLM free-text path.

## 19. AI / Prompt Changes

- `AiIntentClassifier` prompt: extend the `intent` enum (Section 12.1); no other prompt-structure change needed — the existing prompt is detailed, grounded in real conversation examples, and already returns `confidence` (just unused downstream today).
- `AiIntentClassifier::extractApplicationData` prompt: once `AddressParser` (Section 18) exists, simplify this prompt's address-guessing instructions to "fill only the components the deterministic parser couldn't," removing the fragile line-order heuristic ("first line = work, second = home") entirely — that heuristic is the single most fragile piece of prompt logic in the codebase and is exactly the kind of thing Section 9's structured state removes the need for.
- No change needed to `AiPromptBuilder`/`AiComplexReplyService` prompts beyond what Section 7's metadata filtering naturally produces (a more precise `memoryContext` string, same prompt shape).

## 20. Retrieval / Search Changes

Covered in full in Section 7. No new retrieval mechanism for the deterministic intent handlers (price/images/installment) — they correctly bypass memory retrieval today and should continue to.

## 21. Testing Strategy

Current coverage: `ApplicationHandlerTest`, `ApplicationStatusHandlerTest`, `GeminiKeyManagerTest`, `GeminiRateLimitTest` only. Zero coverage of `WhatsappIntentRouter`'s branching, `AiMemoryResolver`, `AiIntentDetector`, clarification, or repetition. New tests required, mapped to the brief's own test list:

| Test | Target | Type |
|---|---|---|
| Exact missing address field (Test 1) | `ApplicationStateService::fieldStatus` for a home address with governorate/city/street known, building missing | Unit |
| Multiple fields received (Test 2) | `ApplicationStateService::computeDelta` given a message extracting name+national_id+job in one turn | Unit |
| Partial address, no full re-ask (Test 3) | `AddressParser` + `ApplicationStateService` given only "٦ أكتوبر" | Unit |
| Customer question during application (Test 4) | `WhatsappIntentRouter` decision table (Section 12.2) with `pending_question=application_missing_data` and a `delivery_question`-intent message | Feature |
| Typo interpretation (Test 5) | `AiIntentClassifier` confidence-threshold branching (Section 13) with a mocked low-confidence Gemini response | Unit (mocked LLM) |
| Unknown term, no hallucination (Test 6) | Same harness as Test 5, asserting the reply never contains machine/price data it wasn't given | Unit (mocked LLM) |
| Three failed clarifications escalate (Test 7) | `ClarificationService` counter reaching `MAX_CLARIFICATION_ATTEMPTS` triggers `handoffToAgent` | Feature |
| Repeated response is not meaningless paraphrase (Test 8) | `RepetitionGuard` given three sequential low-confidence turns, asserting the 3rd triggers escalation rather than a 3rd generic reply | Unit |
| 50 memories, relevant subset only (Test 9) | Seed 50 `ai_memories` rows with metadata, assert retrieval log shows `retrieval_method=metadata_filtered` (not full_dump) for a topical message | Feature |
| 500 memories, scalability (Test 10) | Seed 500 rows, assert retrieval latency stays bounded and `fell_back_to_full_dump=false` | Feature/benchmark |
| Conflicting customer data (Test 11) | `ApplicationStateService::detectConflicts` given phone A then phone B in sequential turns | Unit |

All new backend logic (Sections 9–16) is deterministic PHP and unit-testable without hitting Gemini. Only the classifier/extraction prompt changes need mocked-LLM feature tests.

## 22. Monitoring & Observability

Structured log entry per turn (single `Log::info('ai_turn', [...])` call in `WhatsappIntentRouter::handle`, cheap, no new infra):
```
message, intent, confidence, entities_extracted,
application_state_before, application_state_after, progress_delta,
memory_retrieval: {candidate_ids, selected_ids, scores, method, fell_back_to_full_dump},
clarification_attempts, escalated (bool, reason),
response_source: templated | llm_fallback | deterministic_handler,
repetition_score (when applicable),
latency_ms, gemini_tokens_in, gemini_tokens_out, gemini_model, gemini_key_id
```
Combined with the `ai_memory_retrieval_logs` table (Section 17), this answers every "why" question in the brief (why this field was asked, why this memory was/wasn't picked, why it escalated, why it repeated) via a single conversation-scoped query, without needing a tracing platform.

## 23. Performance & Cost

| Component | Extra LLM calls? | Extra DB cost | Notes |
|---|---|---|---|
| Metadata pre-filter (Section 7 Phase A) | No | One indexed `WHERE` on `ai_memories` before existing scan | Net cheaper than today (fewer rows scored) |
| Embeddings (Section 7 Phase B) | No (embedding call, not generation — cheap, and only on memory *write*, not per message) | New nullable column + occasional batch re-embed job | **Do not build until memory count or measured retrieval-quality data actually justifies it** — premature at 40 rows |
| ApplicationState/delta/conflict detection | No | None (computed from already-fetched `context_payload`) | Pure PHP, replaces existing PHP |
| Clarification counter/escalation | No | One int column read/write per turn | Negligible |
| Confidence-tiered clarification (Section 13) | No — reuses a field already returned by the existing classifier call | None | Zero marginal cost |
| Repetition guard | No | Reads last 2 rows of `whatsapp_messages` (already indexed by conversation) | Negligible |
| Structured address extraction | No new call — same `extractApplicationData` call, simplified prompt | New columns | Prompt gets *shorter* (heuristic removed), likely nets slightly cheaper per call |

Net effect: this plan adds **zero new LLM calls per message** in the common case, and removes fragile prompt heuristics — cost should hold flat or improve slightly, while quality/debuggability improve substantially.

## 24. Rollout Strategy

Additive-first, no destructive migrations, each phase independently shippable and testable:
1. Ship DB columns (Section 17) — no behavior change, nothing reads them yet.
2. Ship `ApplicationStateService`/`AddressParser`/delta computation, wired into `ApplicationHandler` behind the existing test suite (Section 21) — this alone fixes Problems 3, 4, 5, 11 without touching `WhatsappIntentRouter`.
3. Ship the memory metadata columns + Filament form fields + retrieval pre-filter — fixes Problem 1/6, independent of application-flow changes.
4. Ship the interrupt/resume decision table in `WhatsappIntentRouter` (the highest-risk change, touches the busiest file) — only after 2 and 3 are stable in production, since it changes when `ApplicationHandler` is even invoked.
5. Ship clarification counter + escalation trigger + repetition guard — lowest risk, purely additive triggers.
6. (Optional, data-gated) embeddings re-ranker, only if Section 3's production retrieval logs show the metadata+keyword approach genuinely under-performing at the memory count actually reached.

## 25. Implementation Tasks

### Task 2.1 — Add `ai_memories` metadata columns and Filament fields

**Status:** []

**Priority:** CRITICAL

**Problem:**
`ai_memories` has no `category`, `scope`, `applicable_intents`, `keywords`, or `priority` columns, so retrieval has nothing to filter on before scoring (Section 4, row 7).

**Root Cause:**
The table was designed for content storage only ([migration](database/migrations/2026_05_31_233717_create_ai_memories_table.php)); metadata was never added even as the memory count grew to 40.

**Solution:**
Additive migration + Filament form fields, backfilled from the audit table in Section 8 (category/scope only — `applicable_intents`/`keywords` can start empty and be curated by the admin over time).

**Implementation:**
New migration adding the five nullable columns from Section 17. Update `AiMemoryResource` form with a `Select` for `category`/`scope`, a `TagsInput` for `keywords`, a `CheckboxList` (or `Select` multiple) for `applicable_intents` sourced from the unified intent enum (Section 12.1), and a numeric `priority` input. Write a one-off seeder/console command applying the Section 8 table's recommended `category`/`scope` values to the 40 existing rows (do NOT touch `title`/`content`).

**Files / Components:**
- Create: `database/migrations/xxxx_add_metadata_to_ai_memories_table.php`
- Modify: [app/Filament/Resources/AiMemoryResource.php](app/Filament/Resources/AiMemoryResource.php)
- Modify: [app/Models/AiMemory.php](app/Models/AiMemory.php) (add to `$fillable`/`$casts`)
- Create: `app/Console/Commands/BackfillAiMemoryMetadata.php` (one-off, idempotent)

**Dependencies:** None — first task in the sequence.

**Testing:** Migration up/down test; Filament resource smoke test (form saves/loads new fields); backfill command test asserting known IDs get expected category/scope per Section 8's table.

**Acceptance Criteria:**
- [ ] All 40 existing memories have non-null `category`; `scope='always_include'` set on #38, #39, #51 specifically
- [ ] Admin can edit the new fields in the dashboard
- [ ] No existing memory's `title`/`content`/`template_replies` changed

**Risks:** Backfill script mis-tagging a memory's scope — mitigated by the explicit ID list above rather than heuristic tagging.

**Rollback:** Drop the migration's columns; Filament form reverts with a code revert (no data loss to existing fields).

---

### Task 2.2 — Metadata-filtered retrieval pre-pass

**Status:** []

**Priority:** CRITICAL

**Problem:**
Retrieval scores every active memory with no pre-filter, and falls back to a full dump of all active memories whenever fewer than 5 score above zero (Section 4 row 6, Section 5).

**Root Cause:**
[AiMemoryContextBuilder::buildRelevantMemoryContext](app/Services/AiMemoryContextBuilder.php:38) calls `AiMemoryResolver::relevantMemories` over the *entire* active set with no candidate-narrowing step.

**Solution:**
Two-step retrieval: (1) metadata filter — always include `scope=always_include` rows; when an intent is known, prefer rows whose `applicable_intents` contains it; (2) keyword score (existing, unchanged) over the filtered candidate set, with confidence-scaled K replacing the flat full-dump fallback (Section 7 Phase A).

**Implementation:**
Add `AiMemoryResolver::candidateMemories(?string $intent)` returning the pre-filtered `Collection` before scoring. Replace the `MIN_RELEVANT_TO_TRUST_FILTER` full-dump fallback with: if the metadata-filtered candidate set is itself small (e.g. <25 rows, which it will be even at 500 total memories once categories are populated), score and return all of it above a score floor rather than an arbitrary count threshold; only genuinely fall back to a bounded "recent + always_include" set (never *all* memories) if the candidate set is empty. Write the retrieval log row (Section 17/22) on every call.

**Files / Components:**
- Modify: [app/Services/AiMemoryResolver.php](app/Services/AiMemoryResolver.php)
- Modify: [app/Services/AiMemoryContextBuilder.php](app/Services/AiMemoryContextBuilder.php)
- Create: `database/migrations/xxxx_create_ai_memory_retrieval_logs_table.php`
- Create: `app/Models/AiMemoryRetrievalLog.php`

**Dependencies:** Task 2.1 (needs the metadata columns to exist and be populated).

**Testing:** Unit tests per Section 21 Tests 9/10 (seed 50 and 500 memories, assert `fell_back_to_full_dump=false` and bounded latency); regression test asserting `scope=always_include` rows are present in every retrieval regardless of message content.

**Acceptance Criteria:**
- [ ] A topical fallback message against 50 seeded memories never triggers full-dump
- [ ] #38/#39/#51 (`always_include`) appear in every retrieval result
- [ ] Retrieval log row written on every `AiComplexReplyService::reply` call
- [ ] Existing fallback reply quality does not regress (manual spot-check against 10 real historical conversations from `whatsapp_messages`)

**Risks:** Over-narrow metadata filtering could exclude a genuinely relevant memory that wasn't tagged for the right intent — mitigated by keeping `applicable_intents` empty ⇒ "always eligible for keyword scoring" as the default, so untagged memories degrade to today's behavior rather than being silently excluded.

**Rollback:** Feature-flag the pre-filter (`config('ai.metadata_prefilter_enabled')`); flip off to restore Section 3's exact current behavior instantly.

---

### Task 4.1 — Structured address fields + `AddressParser`

**Status:** []

**Priority:** CRITICAL

**Problem:**
Addresses are single free-text columns with binary LLM-assigned completeness; the business's own memory #30 defines an 8-component schema that the data model doesn't capture (Section 4 row 4, worked example in Section 1).

**Root Cause:**
`applicant_address`/`work_address` were added as plain strings ([migration](database/migrations/2025_10_21_055816_add_address_to_installment_requests_table.php)) before the structured requirement in memory #30 existed as an explicit rule.

**Solution:**
Add structured component columns (Section 17); build `AddressParser` to deterministically extract known Egyptian governorate/city names and structural markers ("شارع", "عمارة", "الدور", "شقة") from free text; fall back to the LLM (simplified prompt, Section 19) only for components the parser couldn't confidently assign.

**Implementation:**
`AddressParser::parse(string $text): array` returns `{governorate, city, area, street, building, floor, apartment, landmark}` with nulls for unresolved components, using a static governorate/major-city list plus regex for numbered markers (building/floor/apartment numbers following "عمارة"/"دور"/"شقة"). `ApplicationStateService::addressStatus(array $components): array{status, missing}` implements the COMPLETE/PARTIAL/MISSING logic from Section 10.2.

**Files / Components:**
- Create: `database/migrations/xxxx_add_structured_address_to_installment_requests.php`
- Create: `app/Support/AddressParser.php`
- Create: `app/Support/ApplicationState.php`
- Create: `app/Services/ApplicationStateService.php`
- Modify: [app/Services/Handlers/ApplicationHandler.php](app/Services/Handlers/ApplicationHandler.php) (`missingFields`, `questionForMissing`, `mergeApplicationData` → delegate to `ApplicationStateService`)
- Modify: [app/Services/AiIntentClassifier.php](app/Services/AiIntentClassifier.php) (`extractApplicationData` prompt simplified per Section 19)

**Dependencies:** None (independent of the memory-retrieval track).

**Testing:** Section 21 Tests 1, 2, 3 exactly. Additional case: fully complete address in one message parses to `COMPLETE` with zero LLM address-guessing needed (parser-only).

**Acceptance Criteria:**
- [ ] Test 1 (governorate/city/street known, building missing) asks only for the building number
- [ ] Test 3 ("٦ أكتوبر" only) never re-asks for the full address
- [ ] Existing `installment_requests.applicant_address`/`work_address` string columns still populate (rendered from components) so no downstream PDF/report/Filament view breaks
- [ ] `ApplicationHandlerTest`/`ApplicationStatusHandlerTest` still pass unmodified in assertions (behavior-compatible for already-complete-address cases)

**Risks:** `AddressParser`'s governorate/city list is necessarily incomplete for very informal place names — mitigated by the LLM-fallback for unresolved components, same safety net as today, just narrower in scope.

**Rollback:** `ApplicationStateService` behind the same pattern as Task 2.2 — a config flag to route back to the legacy `missingFields`/`mergeApplicationData` methods (kept, not deleted, until this task is verified in production).

---

### Task 4.2 — Conflict detection on field updates

**Status:** []

**Priority:** HIGH

**Problem:**
`mergeApplicationData` blindly overwrites any previously-known field with a new extracted value, with no detection or disambiguation (Section 4 row 5, Test 11).

**Root Cause:**
[ApplicationHandler::mergeApplicationData](app/Services/Handlers/ApplicationHandler.php:556) has no "already known and different" branch.

**Solution:** Section 10.4's conflict-detection algorithm, integrated into `ApplicationStateService`.

**Implementation:** `ApplicationStateService::detectConflicts(array $known, array $extracted): array` returns the conflicting subset; `ApplicationHandler::handle` checks this before calling `missingFields`/`questionForMissing` and, if non-empty, asks the single disambiguation question (Section 10.4 wording) instead of proceeding, storing the pending conflict in `context_payload` so the next reply resolves it deterministically ("الرقم الجديد" / "الرقم القديم" / re-stating either number resolves it).

**Files / Components:**
- Modify: `app/Services/ApplicationStateService.php` (from Task 4.1)
- Modify: [app/Services/Handlers/ApplicationHandler.php](app/Services/Handlers/ApplicationHandler.php)

**Dependencies:** Task 4.1 (`ApplicationStateService` must exist first).

**Testing:** Section 21 Test 11 exactly, plus a resolution-turn test (customer answers "الجديد صح" → conflict clears, new value kept).

**Acceptance Criteria:**
- [ ] Sequential different phone numbers trigger a disambiguation question, never a silent overwrite
- [ ] The conversation can resolve the conflict and continue without restarting the application

**Risks:** False-positive conflicts if the LLM extraction re-emits the *same* value with different formatting (e.g. spaces) — mitigated by normalizing both values (strip whitespace/formatting) before comparing.

**Rollback:** Config flag alongside Task 4.1's.

---

### Task 7.1 — Interrupt/resume decision table in `WhatsappIntentRouter`

**Status:** []

**Priority:** CRITICAL

**Problem:**
Any customer question during a pending application is unconditionally coerced into the application flow, so genuine questions (price, delivery, etc.) never get answered (Section 4 row 1, Section 3 step 5, Test 4).

**Root Cause:**
The override block at [WhatsappIntentRouter.php:79-139](app/Services/WhatsappIntentRouter.php) has only one carve-out (`application_status`) and no notion of "confidently a different, answerable intent."

**Solution:** Section 12.2's decision table — a confident non-application intent gets answered by its normal handler, then a one-line resume prompt is appended, `pending_question` stays unchanged.

**Implementation:** Extract the current override block into `WhatsappIntentRouter::shouldForceApplicationIntent(array $plan, WhatsappConversation $conversation): bool`, add the confident-question carve-out per Section 12.2's table, and add `WhatsappIntentRouter::appendResumePrompt(string $reply, WhatsappConversation $conversation): string` that appends the missing-field summary (reusing `ApplicationStateService`) when a question was answered mid-application.

**Files / Components:**
- Modify: [app/Services/WhatsappIntentRouter.php](app/Services/WhatsappIntentRouter.php)
- Modify: [app/Services/AiIntentClassifier.php](app/Services/AiIntentClassifier.php) (unified intent enum, Section 12.1/19)

**Dependencies:** Task 4.1 (needs `ApplicationStateService` for the resume-prompt's missing-field summary) and Task 2.2 recommended-but-not-required first (this is the highest-risk task and should ship last per Section 24).

**Testing:** Section 21 Test 4 exactly, plus regression tests for every existing override case (application continuation, bare confirmation after calc, `isApplicationIntent` regex match) asserting unchanged behavior.

**Acceptance Criteria:**
- [ ] "اسمي كيرو وبالمناسبة بكام التوصيل؟" mid-application: name extracted, delivery question answered, application resumed in one reply
- [ ] All pre-existing `ApplicationHandlerTest`/`ApplicationStatusHandlerTest` assertions still pass unmodified
- [ ] A genuinely ambiguous message mid-application still defaults to continuing the application (no regression toward over-interrupting)

**Risks:** This is the single highest-blast-radius change in the plan — it touches the most heavily-used branch of the busiest file. Mitigated by shipping last (Section 24 step 4), behind a config flag, with the full regression suite from Section 21 green first.

**Rollback:** Config flag (`ai.interrupt_resume_enabled`) reverting to the exact current override block, kept in code as the `false` branch until this task has run in production without incident for a defined observation window.

---

### Task 9.1 — Clarification attempt counter + escalation trigger

**Status:** []

**Priority:** HIGH

**Problem:** No memory of prior clarification attempts; escalation never fires on repeated misunderstanding (Section 4 row 3, Test 7).

**Root Cause:** `needs_clarification` handling ([WhatsappIntentRouter.php:172](app/Services/WhatsappIntentRouter.php)) is stateless.

**Solution:** Section 14/15 — counter column, configurable threshold, escalation trigger reusing `handoffToAgent`.

**Implementation:** `ClarificationService::recordAttempt(WhatsappConversation $conversation, string $question): bool` increments `clarification_attempts`, stores `last_clarification_question`, returns whether the threshold is now exceeded. Wire into the `needs_clarification` branch: if threshold exceeded, call `handoffToAgent` with `reason='clarification_exhausted'` and the log payload from Section 15, instead of asking a 4th question. Reset the counter to 0 on any confidently-handled turn (any deterministic-handler branch, or `confidence >= 0.7` per Section 13).

**Files / Components:**
- Create: `database/migrations/xxxx_add_clarification_attempts_to_whatsapp_conversations.php`
- Create: `app/Services/ClarificationService.php`
- Modify: [app/Services/WhatsappIntentRouter.php](app/Services/WhatsappIntentRouter.php)
- Create: `config/ai.php` (`max_clarification_attempts` default 3)

**Dependencies:** None (independent track, safe to ship any time after Task 2.1/2.2 or standalone).

**Testing:** Section 21 Test 7 exactly; test that the counter resets on a confidently-handled turn between two low-confidence turns (doesn't accumulate across unrelated confusion).

**Acceptance Criteria:**
- [ ] Three consecutive low-confidence turns on the same topic trigger `handoffToAgent`
- [ ] The escalation log contains the customer's message, all prior clarification questions asked, and the reason
- [ ] `MAX_CLARIFICATION_ATTEMPTS` is read from config, not hardcoded

**Risks:** Counter reset logic too aggressive (resets on unrelated confident turns, masking a genuinely stuck sub-topic) — acceptable trade-off, matches the brief's own framing of the counter as per-topic-ish rather than strictly global; revisit if real usage shows premature resets.

**Rollback:** Config flag to disable the new trigger while keeping the counter recorded (observe-only mode) before enforcing escalation.

---

### Task 12.1 — Repetition guard for LLM free-text replies

**Status:** []

**Priority:** MEDIUM

**Problem:** No detection of near-duplicate free-text replies (Section 4, Test 8).

**Root Cause:** `AiComplexReplyService::reply` has no awareness of prior outgoing messages in the conversation.

**Solution:** Section 16 — Jaccard-overlap check against the last 2 outgoing messages; high overlap feeds the clarification-escalation signal rather than triggering a cosmetic reroll.

**Implementation:** `RepetitionGuard::score(string $candidate, array $recentOutgoing): float` (token-set Jaccard, reusing `AiMemoryParser::tokens` for normalization — no new tokenizer needed). Called from `WhatsappIntentRouter::handleAiFallback` after generating a reply; if score exceeds a threshold (e.g. 0.75) AND this is already a clarification-path reply, treat it as a failed clarification attempt (increment via `ClarificationService`) instead of sending a near-duplicate.

**Files / Components:**
- Create: `app/Support/RepetitionGuard.php`
- Modify: [app/Services/WhatsappIntentRouter.php](app/Services/WhatsappIntentRouter.php)

**Dependencies:** Task 9.1 (`ClarificationService` must exist to receive the signal).

**Testing:** Section 21 Test 8; unit tests for the Jaccard scorer on known near-duplicate/distinct Arabic sentence pairs.

**Acceptance Criteria:**
- [ ] Three sequential low-confidence turns never produce 3 near-identical replies — the 3rd escalates instead
- [ ] A legitimately repeated missing-field question (nothing changed, Section 16 first bullet) is NOT flagged — this guard only applies to the LLM free-text path, not the templated path

**Risks:** False positives on short, naturally similar Arabic replies (e.g. two different price quotes share a lot of boilerplate) — mitigated by only applying the guard on the clarification path, not on ordinary fallback replies.

**Rollback:** Config flag; guard becomes a no-op logger (score computed and logged, not acted on) if disabled.

---

### Task 14.1 — Structured observability logging

**Status:** []

**Priority:** MEDIUM

**Problem:** No way to answer "why did the AI ask/pick/escalate/repeat X" without re-reading code (Section 4 row 11).

**Root Cause:** No structured per-turn log exists; only ad-hoc `Log::error` calls on failure paths.

**Solution:** Section 22's structured log entry, plus the `ai_memory_retrieval_logs` table from Task 2.2.

**Implementation:** Single `Log::info('ai_turn', [...])` call assembled at the end of `WhatsappIntentRouter::handle`, gathering the fields listed in Section 22 from values already computed earlier in the method (no new computation, just aggregation and logging).

**Files / Components:**
- Modify: [app/Services/WhatsappIntentRouter.php](app/Services/WhatsappIntentRouter.php)

**Dependencies:** Tasks 4.1, 2.2, 9.1 (pulls fields each of those introduces) — ship last, purely additive.

**Testing:** Assert the log entry is written with all expected keys on a representative set of intents (unit test using a log spy/fake).

**Acceptance Criteria:**
- [ ] Every handled turn produces one `ai_turn` log entry with non-null `intent`, `application_state_before/after`, `progress_delta`
- [ ] A memory-retrieval-triggering turn also has a matching `ai_memory_retrieval_logs` row queryable by `whatsapp_conversation_id`

**Risks:** Log volume — mitigated by this being a single structured entry per turn (not per sub-step), consistent with existing log usage patterns in the codebase.

**Rollback:** Remove the log call; no state dependency.

## 26. Final Architecture

```
                     CUSTOMER MESSAGE
                            │
                            ▼
                  MESSAGE UNDERSTANDING          (AiIntentClassifier, unified intent enum — Task 7.1/19)
                            │
              ┌─────────────┼─────────────┐
              ▼             ▼             ▼
           INTENT        ENTITIES      APP STATE          (ApplicationState — Task 4.1)
      (confidence-tiered) │      (AddressParser first,
              │            │       LLM fills gaps — 4.1)
              └─────────────┼─────────────┘
                            ▼
                    PROGRESS DELTA              (ApplicationStateService::computeDelta — Section 11)
                            │
                            ▼
              INTERRUPT / RESUME DECISION       (Task 7.1 — replaces unconditional override)
                            │
                            ▼
                    MEMORY RETRIEVAL             (metadata pre-filter → keyword score — Task 2.2)
                            │
                     ┌──────┴──────┐
                     ▼             ▼
                  FILTER         RANK
                     │             │
                     └──────┬──────┘
                            ▼
                   RELEVANT CONTEXT
                            │
                            ▼
                  RESPONSE STRATEGY             (templated for app-flow, Section 11;
                            │                     LLM only for genuine free text)
                            ▼
                   LLM GENERATION                (only when strategy requires it)
                            │
                            ▼
                 RESPONSE VALIDATION             (RepetitionGuard — Task 12.1;
                            │                      ClarificationService threshold check — Task 9.1)
                  ┌─────────┴─────────┐
                  ▼                   ▼
                SEND               ESCALATE       (handoffToAgent, extended trigger set — Task 9.1)
                  │
                  ▼
             UPDATE STATE                        (conversation + application, single write —
                                                    logged via ai_turn — Task 14.1)
```

Every new box in this diagram maps to a task in Section 25 and is deterministic PHP except "MESSAGE UNDERSTANDING" (existing LLM call, extended prompt only) and "LLM GENERATION" (existing LLM call, now invoked less often because the templated response strategy handles the application-flow acknowledgment path without it).

## Recommended Implementation Order

### Phase 1 — Foundation
Task 2.1 (memory metadata columns), Task 4.1's migration half (structured address columns) — schema only, zero behavior change, safe to ship immediately.

### Phase 2 — Application State
Task 4.1 (`ApplicationStateService`, `AddressParser`, wired into `ApplicationHandler`), Task 4.2 (conflict detection). Fixes Problems 3, 4, 5, 11, 12 without touching the router.

### Phase 3 — Memory
Task 2.2 (metadata-filtered retrieval). Fixes Problems 1, 6. Independent of Phase 2; can run in parallel.

### Phase 4 — Intent & Understanding
Task 7.1 (interrupt/resume — highest risk, ships after Phases 2–3 are stable since it depends on `ApplicationStateService` for its resume prompt and benefits from Phase 3's cleaner memory context on the answer-then-resume path).

### Phase 5 — Response Quality
Task 9.1 (clarification counter + escalation), Task 12.1 (repetition guard) — both purely additive, low risk, can ship any time after Phase 2.

### Phase 6 — Testing & Observability
Task 14.1 (structured logging) plus the full Section 21 test suite backfilled across every task above before Phase 4 goes live (Task 7.1 specifically must not ship without its regression suite green, per its own Risks note).

### Phase 7 — Production Rollout
Config-flagged rollout per Section 24: schema first, application-state logic second (flagged), memory retrieval third (flagged), interrupt/resume fourth (flagged, last, longest observation window before flag removal), clarification/escalation fifth, observability throughout. Remove flags and delete the legacy code paths (`ApplicationHandler`'s old `missingFields`/`mergeApplicationData`, the old override block) only after each flagged path has run incident-free in production — do not mark any Section 25 task `[DONE]` until that verification has actually happened.
