# AI WhatsApp Bot — Deep Conversation, Memory & Intelligence Audit

> **Scope:** Audit + analysis only. No code was modified.
> **Method:** Real execution-path tracing through `app/Http/Controllers/Api/WhatsappBotController.php` → `app/Console/Commands/ProcessWhatsappMessageJobs.php` → `app/Services/WhatsappIntentRouter.php` → `AiIntentClassifier` / `AiMemoryContextBuilder` / `AiPromptBuilder` / `GeminiClient` / `Handlers\ApplicationHandler`, plus live queries against the `motoceklaty` database (`ai_memories`, `ai_memory_retrieval_logs`, `gemini_api_key_models`, `whatsapp_message_jobs`) and **measured prompt sizes** rendered from a real conversation (`whatsapp_conversations.id = 251`, 34 messages).
> **Date:** 2026-08-26

---

## 1. Executive Summary

The bot is **not** failing because of Gemini. It is failing because of four structural decisions, each provable from code and production data:

1. **There is no memory retrieval.** `AiMemoryContextBuilder::buildRelevantMemoryContext()` sends *every* active memory whenever the whole set fits under 18,000 characters. The set is currently **12,135 characters / 46 memories**, so the "relevance" branch has literally never executed in production: `ai_memory_retrieval_logs` has **400 rows, 400 of them `retrieval_method = 'full_set'`, `selected_memory_ids` length = 46 on every single row**. `AiMemoryResolver::relevantMemories()` and `candidateMemories()` are dead code at runtime.

2. **The same full memory blob is injected twice per message** — once into the intent planner (`AiIntentClassifier::prompt()`) and once into the reply prompt (`AiPromptBuilder::buildChatReplyPrompt()`). Measured on conversation 251: planner prompt = **44,370 chars**, reply prompt = **18,197 chars**. That is ~62 KB of prompt for one short Arabic follow-up.

3. **The AI that writes the reply cannot see the application state.** `AiPromptBuilder` injects memory, last 15–20 messages, `last_machines`, `customer_profile` and an installment snapshot — but **never** `pending_question`, `missing_fields`, `context_payload['application']`, or the documents checklist. So the only component with real language understanding is blind to the single most important structured fact about where the customer is.

4. **Understanding is decided twice and then overridden ~12 times.** `AiIntentClassifier` returns a plan, then `handleInternal()` (lines 383–620) rewrites `$intent` through a chain of Arabic-regex heuristics, and `detectIntent()` is a *second*, keyword-based classifier that can disagree with the first. Each new bug has been fixed by adding one more `elseif` to that chain. That chain — not Gemini — is what produces "technically correct but contextually wrong" answers.

The memory content itself is also a live liability: it contains a **fake branch ("فرع خالتك / ابن ابوك")** that will be sent to real customers, **internal commercial secrets** (Aman's 5% commission, the ~1,500 EGP per-customer inquiry cost, the 45-day payout), and **instructions that contradict the system prompt** (`تاكيد البيانات` tells the model to emit `[[ORDER_DATA]]` JSON while the reply prompt forbids JSON).

**Verdict:** the architecture is sound in its *deterministic* half (prices, installments, state machine, key rotation). It is broken in its *contextual* half (retrieval, context assembly, source-of-truth hierarchy). The fix is not a rewrite — it is roughly **five targeted changes** listed in §23.

---

## 2. System Architecture

### 2.1 Components in the reply path

| Layer | Component | Role |
|---|---|---|
| Ingress | Node worker → `POST /api/whatsapp/incoming-message` | Baileys worker posts normalized messages |
| Ingress | `Api\WhatsappBotController::incomingMessage()` | auth, dedupe, media persist, enqueue |
| Queue | `whatsapp_message_jobs` table | durable queue (not Laravel queues) |
| Worker | `ProcessWhatsappMessageJobs` (`--workers=3`) | slot locks + claim lock + per-conversation ordering |
| Dispatch | `WhatsappBotController::processQueuedWhatsappJob()` | legacy `pending_order_data` path, then router |
| Brain | `WhatsappIntentRouter::handle()` → `handleInternal()` | 3,442 lines; owns everything |
| Understanding | `AiIntentClassifier::classify()` | Gemini call #1 (plan JSON) |
| Memory | `AiMemoryContextBuilder` + `AiMemoryResolver` + `AiMemoryParser` | memory → prompt text |
| Reply (free) | `AiComplexReplyService` → `AiPromptBuilder` | Gemini call #2 |
| Reply (money) | `InstallmentCalculator` → `InstallmentVariablesBuilder` → `AiReplyPhraser` | deterministic + Gemini call #3 (rewording) |
| Application | `Handlers\ApplicationHandler` + `ApplicationStateService` | state machine; Gemini call for extraction |
| Infra | `GeminiClient` + `GeminiKeyManager` | multi-key/model rotation, quota reservation |
| Egress | `ProcessWhatsappMessageJobs::sendWhatsappResult()` | HTTP back to Node worker |

### 2.2 Notable architectural facts

- **`Webhooks\WhatsAppWebhookController` is dead/parallel code.** It is the Meta Cloud API webhook and replies with a hardcoded echo (`"وصلتني رسالتك: {$text}"`). It is not in `routes/api.php`. Only the Baileys worker path is live. Two ingress implementations exist; one of them would send an echo reply if ever routed.
- **State lives in three places**: `whatsapp_conversations` columns (`last_machine_id`, `last_machine_ids`, `last_topic`, `pending_question`, `clarification_attempts`), the JSON `context_payload`, and `customer_profiles`. There is no single accessor; `context_payload` is read and written directly by the router, `ApplicationHandler`, and `ApplicationStateService`.
- **`Schema::hasColumn()` is called on the hot path** (`rememberMachines`, `lastMachinesFromConversation`, `activeMachineFromConversation`) — a DB introspection query per message per call site.

---

## 3. Message Lifecycle

```
Node worker
  → POST /api/whatsapp/incoming-message  (X-BOT-TOKEN)
     ├─ cleanIncomingMessage / cleanPhoneFromJid
     ├─ WhatsappConversation::firstOrCreate(bot_id, phone)
     ├─ dedupe A: wa_message_id already in whatsapp_messages → drop
     ├─ direction=outgoing || from_me → store, return
     ├─ media persisted to storage/public
     ├─ dedupe B: identical text as last incoming within 2 minutes → store, no job
     ├─ burst detection: any pending/processing job for this conversation → quote_reply=true
     ├─ whatsapp_messages INSERT (direction=incoming)
     └─ whatsapp_message_jobs INSERT (status=pending)
  → ProcessWhatsappMessageJobs (loop)
     ├─ acquireWorkerSlot()  (flock, N slots)
     ├─ claimNextJob()       (global claim lock + per-conversation "busy" exclusion + lockForUpdate)
     └─ processQueuedWhatsappJob($job)
          ├─ quoted_text prepended into $message as parenthetical context
          ├─ legacy: isOrderConfirmationMessage + latestPendingOrderData → createInstallmentRequestFromBot
          └─ WhatsappIntentRouter::handle()
                └─ handleInternal()
                     ├─ status == awaiting_agent → store message, return null   ← writes a 2nd incoming row
                     ├─ all-voice media → handleVoiceMessage
                     ├─ isHumanSupportRequest → handoffToAgent
                     ├─ media → ApplicationHandler::handleDocument | MachineImageRecognitionHandler | MediaOcrHandler
                     ├─ AiIntentClassifier::classify()                          ← GEMINI CALL 1
                     ├─ ~12 heuristic intent overrides (lines 383–620)
                     ├─ needs_clarification → ClarificationService::recordAttempt → question | handoff
                     ├─ lastTurnExtraSteps = plan.steps
                     ├─ resolveMachinesFromPlan → brand filter → variant narrowing
                     └─ dispatch:
                          application*  → ApplicationHandler::handle()          ← GEMINI CALL (extraction)
                          installment_* → InstallmentCalculator → AiReplyPhraser ← GEMINI CALL (phrasing)
                          price/images/brand_models → DB + renderMemory templates
                          branches/delivery/general/unknown → handleAiFallback   ← GEMINI CALL 2
                     └─ handle(): appendExtraSteps → up to 2 more handlers       ← MORE GEMINI CALLS
     └─ sendWhatsappResult() → POST worker /send-message (+1.2s gap between replies[])
        └─ on failure: throw → job back to 'pending' → FULL REPLAY
```

### 3.1 Stage-by-stage table

| Stage | Input | Output | Reads state? | Reads memory? | Uses AI? |
|---|---|---|---|---|---|
| `incomingMessage` | HTTP payload | job row | conversation only | no | no |
| `claimNextJob` | queue | one job | job table | no | no |
| `processQueuedWhatsappJob` | job | result array | `context_payload` (legacy order path) | no | no |
| `AiIntentClassifier::classify` | message + 20 msgs + **full `context_payload`** + profile + **full memory** | plan JSON | yes (all of it) | **yes (12,135 chars)** | yes |
| heuristic overrides | plan + normalized text | mutated intent | `last_topic`, `pending_question`, `missing_fields` | no | no |
| `resolveMachinesFromPlan` | plan | `Collection<Machine>` | `last_machine_ids` | aliases via `MachineSearchService` | no |
| deterministic handlers | machines + parsed installment | reply text | `context_payload` | yes, by **exact title** | phrasing only |
| `handleAiFallback` | message + 15 msgs + snapshot + profile | reply text | `last_machine_ids`, `last_months/deposit/system` | **yes (12,135 chars)** | yes |
| `ApplicationHandler::handle` | message | reply text | full application state | rules only (`AiMemoryRules`) | yes (extraction) |
| `sendWhatsappResult` | result | HTTP | job payload | no | no |

### 3.2 Information that is lost between stages

| Lost item | Where | Consequence |
|---|---|---|
| `pending_question`, `missing_fields`, `application{}` | never reaches `AiPromptBuilder` | free replies contradict the application flow; AI re-asks or invents next steps |
| Plan fields `months` / `deposit` / `system` | not passed to `handleAiFallback` | a general question after "12 شهر بمقدم 5 آلاف" has no access to what was just said unless a calc was persisted |
| Deterministic handler intent | `saveOutgoing` writes `source`, not `intent` (e.g. `'installment_calc_ai_state'`) | `dropAlreadyAnsweredSteps()` matches `payload['intent'] ?? payload['source']` against `['branches','delivery_question','installment_system','admin_fee_explanation']` — for most handlers this never matches, so its dedupe only half works |
| `$machines` on exception | `catch` re-calls `rememberMachines` but returns `handled=false, reply=null` | customer gets **silence**, not an error message |

### 3.3 Information sent to the AI with no purpose

- The entire `context_payload` JSON (including `documents_collected` paths, `no_progress_streak`, `installment_repeat_streak`, `last_calc_machine_ids`) is serialized into the **planner** prompt via `conversation_state.context_payload`. None of it helps classify "طب القسط؟".
- All 21 `رد ...` template memories (see §5.3) are prose-dumped into both prompts although they are only ever consumed by `renderMemory()` via exact title lookup.
- `ai_memories` rows tagged `ocr` (`استخراج الاسم من البطاقه`, `مراجعه صور النشاط`) are injected into every price question.

---

## 4. Memory Architecture Audit

### 4.1 Lifecycle

| Question | Answer (from code) |
|---|---|
| Storage | `ai_memories` table: `title`, `content` (prose), `category`, `scope`, `applicable_intents` (json), `keywords` (json), `rules` (json), `priority`, `template_replies` (json), `is_active`, `sort` |
| Creation | **Manual only**, via Filament `AiMemoryResource`. Nothing in the reply path ever writes a memory. |
| Update | Manual. `AiMemory::booted()` clears both caches on save/delete. |
| Deletion / replacement | Manual (`is_active = 0`). No supersession, no versioning. |
| Structured vs raw | Both: `content` is free prose; `rules` is a structured mirror read by `AiMemoryRules`. **They are not kept in sync by anything.** |
| Summary | None. |
| Conversation history | Separate (`whatsapp_messages`), not part of "memory". |
| Selection into prompt | `AiMemoryContextBuilder::buildRelevantMemoryContext()` |
| Relevance-based? | **No, in practice.** Full-set branch always wins. |
| Recency-based? | No — ordered by `sort`, `id`. |
| Priority? | `priority` column exists; only used inside the dead `relevantMemories()` scorer. All 46 active rows have `priority = 0`. |
| TTL? | **None anywhere.** |
| Short-term vs long-term | No distinction. `ai_memories` = business knowledge (global). Conversation state = `context_payload`. Customer facts = `customer_profiles`. These three are never reconciled. |
| Scoped to conversation/customer? | `ai_memories` is **global**, shared by every customer. |
| Can carry an old conversation's data? | `ai_memories` no. **`context_payload` yes** — see §4.4. |
| Can be correct but wrongly used *now*? | **Yes, systematically** — see §4.3. |

### 4.2 Proof that retrieval never runs

`app/Services/AiMemoryContextBuilder.php`:

```php
private const FULL_SET_CHAR_BUDGET = 18000;
...
$totalChars = $all->sum(fn (AiMemory $memory) => mb_strlen((string) $memory->content));

if ($totalChars <= self::FULL_SET_CHAR_BUDGET) {
    $this->logRetrieval(..., 'full_set', false);
    return $this->toToon($all);          // ← every active memory, every turn
}
```

Live measurement:

```
SELECT COUNT(*), SUM(CHAR_LENGTH(content)) FROM ai_memories WHERE is_active=1;
→ 46 memories, 10,535 chars   (12,135 chars after toToon() formatting)

SELECT retrieval_method, COUNT(*), AVG(JSON_LENGTH(selected_memory_ids))
FROM ai_memory_retrieval_logs GROUP BY retrieval_method;
→ full_set | 400 | 46.0000
```

**Every production retrieval so far has selected all 46 memories.** The `RELEVANCE_LIMIT = 18` path, `candidateMemories()`, the intent boost (`+40/+15`), the keyword boost (`+25`), and the `always_include` (`+1000`) rank are unreachable.

Additional dead-in-practice detail: 164 of 400 retrieval log rows have `intent = NULL`, because `AiIntentClassifier::prompt()` passes `'intent' => $conversation->last_topic ?? null` — the **previous** turn's topic, not this turn's intent (which does not exist yet at that point). Even if the filter ran, it would filter by the wrong topic.

### 4.3 Named lifecycle defects

| Defect | Present? | Evidence |
|---|---|---|
| Memory generation problem | **Yes (design)** | No memory is ever written by the system; everything is a hand-typed Filament row. Quality is unmanaged. |
| Memory retrieval problem | **Yes, CRITICAL** | `full_set` on 400/400 turns; scorer unreachable. |
| Memory relevance problem | **Yes, CRITICAL** | OCR rules, application document lists and branch addresses are injected into a "بكام؟" question. |
| Memory ranking problem | Yes (moot) | `priority = 0` on all rows; ranking code unreachable. |
| Memory contamination | **Yes** | Fake branch row (`فرع خالتك`) and internal commercials (§5.2) are in the same blob as real facts. |
| Memory stale data | Partly | `ai_memories` has no timestamped facts; but `المخزون والموديلات` hardcodes availability ("مفيش ياباني", variant availability) that no process re-validates against the `machines` table. |
| Memory **over**-injection | **Yes, CRITICAL** | 12,135 chars × 2 prompts × every message. |
| Memory **under**-injection | No | The opposite problem. |
| Memory conflicting with current user message | **Yes** | The reply prompt says *"الميموري تحت دي هي المصدر الحالي والمحدّث للمعلومات دايمًا … اعتمد على الميموري الحالية فقط وصحح المعلومة"* — memory is declared to outrank everything, including what the customer just said. |
| Memory conflicting with structured application state | **Yes** | `تاكيد الرفع` (#56) tells the model to treat data as confirmed and submit; the real submission is gated by `ApplicationHandler::handleDocument()`. |
| Memory conflicting with latest conversation messages | **Yes** | Same prompt rule as above explicitly instructs overriding earlier turns. |

### 4.4 The *real* stale-memory vector is `context_payload`, not `ai_memories`

`context_payload` is append-only and has **no expiry**:

- `last_machine_ids` / `last_machine_id` are never cleared except in `restartApplicationForNewMachine()`. A customer who asks "بكام؟" three weeks after his last message resolves to the machine from three weeks ago (`resolveMachinesFromPlan` → `lastMachinesFromConversation`).
- `last_months`, `last_deposit`, `last_system`, `last_calc_machine_ids` persist forever and feed `installmentSnapshot()`, which is rendered into the reply prompt under the heading **"أرقام السيناريو الحالي (محسوبة من النظام - دي أرقام صح)"**. A month-old calculation is presented to the model as the *current* scenario.
- `restartApplicationForNewMachine()` deletes `machine_id`, `machine_name`, `payment_method`, `documents_*` — but **leaves `last_months` / `last_deposit` / `last_system`**. `ApplicationHandler::handle()` then does:

```php
if (empty($application['payment_method']) && !empty($payload['last_months'])) {
    $application['payment_method'] = 'installment';
    if (empty($application['installment_months'])) {
        $application['installment_months'] = $payload['last_months'];
    }
}
```

→ **the term chosen for machine A silently becomes the term for machine B.**

---

## 5. Memory Text Quality Audit

Rendered format (`AiMemoryContextBuilder::toToonBlock`):

```
## {title}
- {line}
- {line}
```

Every line is flattened into a bullet regardless of whether it is a fact, an instruction, an alias table, or a JSON schema. There is no `source`, no `confidence`, no `valid_from`, no fact/assumption marker. **The LLM cannot tell a hard rule from a style tip from a leaked internal note.**

### 5.1 Corrupted / test data shipped to customers — `CRITICAL`

`ai_memories#31 — الشركة والفروع`:

```
 فرع عين شمس
عين شمس ش الزهراء مع تقاطع شارع العشرين امام مدرسة الخنساء
وده اللوكيشن :  https://maps.app.goo.gl/hyyoCboo2ccQfDBz7

 فرع خالتك
ابن ابوك
وده اللوكيشن :  https://maps.app.goo.gl/hyyoCboo2ccQfDBz7      ← same link as عين شمس
```

`WhatsappIntentRouter::handleInternal()` routes `intent === 'branches'` straight to `handleAiFallback()`, whose prompt says *"ابعتله الفروع بعناوينها وروابط اللوكيشن **زي ما هي في الميموري**"*. A joke row is therefore instructed to be reproduced verbatim to a paying customer.

### 5.2 Confidential internal data in every prompt — `HIGH`

`ai_memories#39 — التسعير وطريقة عرض التقسيط`:

```
شركة امان نفسها بتاخد مننا عموله حوالي 5٪
والعميل نفسه بيكلف الشركه حوالي ١٥٠٠ ج استعلامات سكن وعمل وايسكور
غير ان الشركه بتنزل فلوسي بعد ٤٥ يوم
... مش عايز اعرفها للعميل
```

This is `scope = always_include`, so it is in **both** prompts on **every message**, protected only by a soft prose instruction. One jailbreak-ish or naive question ("ليه سعر التقسيط أعلى؟") and the model has margin data, financing cost and cashflow terms in its immediate context. The deterministic handlers never need this text at all.

### 5.3 Reply templates dumped as if they were knowledge — `HIGH`

21 of 46 active memories are titled `رد ...` (`رد سعر موديل واحد`, `رد صور موديل واحد`, `رد تحويل لدعم فني`, …). They exist to be fetched **by exact title** by `WhatsappIntentRouter::renderMemory()`. Their `content` is template text with placeholders:

```
### [64] رد سعر موديل واحد
 {machine_name} موجودة يافندم سعرها كاش {cash_price}.
 تحب احسبلك قسطها علي قد ايه ؟
```

All of that — unrendered placeholders included — is also concatenated into the memory blob given to the planner and the free-reply model. The model is reading `{machine_name}` and `{cash_price}` as content. This is ~40% of the memory budget spent on text that is *never* meant for the model.

Related: `AiReplyBuilder::fromMemories()` picks a template with **`array_rand()`** — identical questions produce different templates at random, and `render()` strips any unfilled `{placeholder}`, so a template like `نظام 20%`'s `"{content}"` renders to an empty string and silently falls back to the hardcoded default in `renderMemoryOrDefault()`.

### 5.4 Memory that contradicts the system prompt — `CRITICAL`

`ai_memories#55 — تاكيد البيانات`:

```
وفي آخر الرد حط JSON بين:
[[ORDER_DATA]] {...} [[/ORDER_DATA]]
{"action": "create_installment_request", "applicant_name": "", ...}
```

`AiPromptBuilder::buildChatReplyPrompt()` ممنوع list:

```
- تستخدم markdown أو JSON.
```

The same prompt therefore contains a direct instruction to emit JSON and a direct prohibition on emitting JSON. This is legacy from the pre-`ApplicationHandler` flow (`processQueuedWhatsappJob`'s `latestPendingOrderData` path still exists for it). Whichever the model obeys, one of them is a defect.

`ai_memories#56 — تاكيد الرفع` is worse:

```
لا تطلب تأكيد إضافي من العميل.
اعتبر البيانات مؤكدة وارفع الطلب مباشرة.
ما تقولش للعميل إنك لا تستطيع رفع الطلب.
```

The free-reply path has **no ability** to create an `InstallmentRequest`. This memory instructs the model to claim an action it cannot perform → a manufactured "تم رفع طلبك" is a *prompted* hallucination, not a model failure.

### 5.5 Business rules stated in two places with different semantics — `HIGH`

| Rule | Memory text | Code |
|---|---|---|
| Admin fee | #32: `7٪ المصاريف الادارية **من تمن المنتج** فالسعر فالتقسيط`; #35: `فيه مصاريف إدارية 7%`; #75: `(7% من سعرها)` | `InstallmentCalculator`: `$adminFee = round($financeAmount * 0.07)` where `$financeAmount = price − deposit` |
| Banned professions | #51: bare `أمين`, `معاون` | `ApplicationHandler::bannedProfessionReason()` requires interior-ministry context; the seed migration (`2026_08_25_000002`) documents this divergence explicitly: *"مقصود: «معاون» و«أمين» لوحدهم مش هنا"* |
| Freelance cap | #37 `قواعد الدخل الحر` (prose, 150 chars) | `InstallmentCalculator::FREELANCE_FINANCE_CAP = 60000` |
| Terms available | #35/#36: `من 6 شهور لـ 3 سنين` | `validMonthsForMachines()` reads per-machine DB columns |

So when a customer with a deposit asks "المصاريف الإدارية كام؟" on the free path, the memory says *7% of the price* and the deterministic handler says *7% of price − deposit*. **Two different numbers for the same field, both "authoritative".**

### 5.6 Other text-level problems

- `ai_memories#49 — الميكروباص`: content is **17 characters**. It is a keyword row with no information, but it consumes a `##` block and 7 keywords.
- `ai_memories#33 — المخزون والموديلات` mixes an alias table (`هوجن جمبو = هوجن 4`), catalogue claims (`المتوفر صيني وهندي بس. مفيش ياباني`), and *speech policy* (`ومتقولش للعميل ارنبه ولا تفاحه`). The alias half is also parsed programmatically by `AiMemoryParser::aliasRules()` — so the same rows are both data and prose in the same prompt.
- **Memory titles are an undocumented API.** `renderMemory()` looks up by normalized exact title. Renaming `رد سعر موديل واحد` in Filament silently degrades to a hardcoded PHP fallback; the only trace is `Log::warning('ai_memory_title_miss')`.
- Instructions embedded in memory (`#31`: "اعمل مسافات وخلي شكل الرساله منسقه"; `#38`: "خليك بني ادم بمعني اصح") mean **memory is a second, unversioned system prompt** written by non-engineers, injected at a lower position than the real one.

**Can memory itself become a hallucination source? Yes — proven:** #56 (claim you submitted the request), #55 (emit JSON), #31 (a branch that does not exist), #32 (an admin-fee rule the code does not implement).

---

## 6. Context Construction Audit

### 6.1 Measured sizes (conversation 251, 34 messages, message = "طب القسط كام")

| Prompt | Total chars | Memory blob | Rest |
|---|---|---|---|
| `AiIntentClassifier::prompt()` | **44,370** | 12,135 | ~32,200 (static rules ~9k + `recent_messages` JSON **20,443** + state/profile) |
| `AiPromptBuilder::buildChatReplyPrompt()` | **18,197** | 12,135 | 6,062 |
| **Per fallback turn** | **≈ 62,570 chars** | 24,270 (2×) | — |

`GeminiClient` estimates tokens as `mb_strlen($prompt) / 4`. For Arabic that is optimistic; the reservation accounting in `GeminiKeyManager` is therefore based on a number materially below reality (see §15.3).

### 6.2 What actually goes in

**Short-term context**
- Planner: last **20** messages, `direction` + `message` + `payload` (full payload kept only for the most recent OCR-bearing message, older ones summarised — a genuine, working fix).
- Reply: last **15** messages (`handleAiFallback`), rendered by `formatConversation()` which slices `-20`. The prompt heading literally says **"آخر 20 رسالة"** while only 15 are ever passed. Cosmetic, but the model is told a number that is false.

**Long-term context**
- All 46 memories. Unconditionally. Twice.

**Structured context**

| Field | In planner prompt | In reply prompt |
|---|---|---|
| product/model | ✅ `last_machines_shown_to_customer` (id, name, brand, cash_price, installment_price) | ✅ `last_machines` (id, name, cash_price, installment_price) |
| price | ✅ | ✅ |
| installment numbers | via raw `context_payload` | ✅ `installment_snapshot` (13 labelled lines from `InstallmentCalculator`) |
| customer data | ✅ `customer_profile` one-liner | ✅ same |
| **application state** | ✅ raw `pending_question` + `context_payload` dump | ❌ **absent** |
| **missing fields** | ✅ (inside raw dump) | ❌ **absent** |
| **current step** | ✅ (`pending_question`) | ❌ **absent** |
| previous answers | ❌ (only as chat text) | ❌ (only as chat text) |

This asymmetry is the single most consequential context defect: **the planner knows the application state but must not answer; the answerer must answer but does not know the application state.**

**Current message merge**
- Planner: `current_message` field inside a pretty-printed JSON payload, *after* ~9k chars of rules and 12k chars of memory.
- Reply: `رسالة العميل الحالية:` near the end, followed only by `{$focusBlock}`. Reasonable position.
- Quoted replies: `processQueuedWhatsappJob` rewrites the message as `(العميل بيرد على رسالة سابقة نصها: "…") {$message}` — good, but it means the *stored* message and the *classified* message differ, and `RepetitionGuard` / dedupe compare different strings.

### 6.3 Duplication

- Memory: 2×.
- Machine list: in `last_machines_shown_to_customer` **and** in the chat transcript **and** in `installment_snapshot`.
- Installment numbers: in `installment_snapshot` **and** in the outgoing message text in the transcript **and** in `context_payload` (planner).
- The full `context_payload` (1,041 chars on conv 251, unbounded in general) is JSON-dumped into the planner while its useful fields are already surfaced as named keys.

---

## 7. Source of Truth Analysis

### 7.1 Current, de-facto hierarchy (as implemented)

| Information | Actual source of truth | Competing sources | Conflict? |
|---|---|---|---|
| Cash / installment price | `machines` table | memory `#39`, transcript text | Guarded: `intent=price` with no machine refuses to answer rather than let the AI guess (`handleInternal` line ~890). **Good.** |
| Monthly installment | `InstallmentCalculator` | `AiReplyPhraser` (digit-locked), `installment_snapshot`, transcript | Guarded by `rejectionReason()` digit check. **Good.** |
| Admin fee | `InstallmentCalculator` (7% of `price − deposit`) | memories #32/#35/#75 (7% of price) | **CONFLICT** |
| Admin fee, freelance customer | `handleInstallmentCalc` passes `$isFreelance`; **`handleAdminFeeExplanation` does not** | — | **CONFLICT (code vs code)** — see §17 A-4 |
| Available terms | `validMonthsForMachines()` (DB) | memories #35/#36 ("6 شهور لـ 3 سنين") | Latent conflict |
| Machine identity for "دي/ده" | `last_machine_ids` | planner `machine_query`, `MachineSearchService` | Resolved by `resolveMachinesFromPlan` priority order. Acceptable. |
| Application field values | `context_payload['application']` via `mergeApplicationData` | AI extraction, OCR, `customer_profiles` | Last non-null wins; **no provenance recorded** |
| Identity documents | conversation-stated values beat OCR (`createInstallmentRequest` `array_merge` order) | — | Explicit and correct. **Good.** |
| Branch addresses | memory #31 | — | Single source, but corrupted |
| "What did the customer already tell us" | transcript + `application{}` | `customer_profiles` | Profile may be from another conversation and is injected as fact |
| Banned professions | `ApplicationHandler::bannedProfessionReason()` + `AiMemoryRules` (additive) | memory #51 prose | Code floor is correct by design; **prose disagrees** |

### 7.2 The prompt asserts the wrong hierarchy

`AiPromptBuilder`:

> `الميموري تحت دي هي المصدر الحالي والمحدّث للمعلومات دايمًا. لو أي معلومة فيها … بتختلف عن حاجة إنت قلتها في ردودك السابقة في نفس المحادثة، اعتمد على الميموري الحالية فقط وصحح المعلومة`

This rule was written to stop the model repeating an outdated earlier answer. But as phrased, **global business memory outranks the conversation**. Combined with `#39`'s "قول سعر الكاش بس" and `#32`'s admin-fee formula, the model is licensed to "correct" a number that `InstallmentCalculator` produced.

### 7.3 Hierarchy the project's own business logic implies

Derived from what the code *guards* (prices refused rather than guessed; digits locked in phrasing; conversation beats OCR; code floor beats memory rules):

1. **Deterministic computation** — `InstallmentCalculator`, `machines` rows, `validMonthsForMachines`. Never overridable by any text.
2. **Latest explicit customer statement in the current message** — corrections, "لا ده رقم غلط", "خليها 24 شهر".
3. **Current application state** — `pending_question`, `missing_fields`, `application{}` (what we already collected).
4. **Recent conversation (this session)** — pronoun resolution, last machine, last term.
5. **Structured database facts about the customer** — `InstallmentRequest`, `customer_profiles` (must be labelled "from a previous visit", not asserted as current).
6. **Relevant business memory** (`ai_memories`) — policy, eligibility, branches, tone.
7. **AI inference** — only for wording, pronoun resolution and intent; never for a number, a policy or a state transition.

Note this is *not* the ordering the current prompt states, and #1/#3 are exactly the two the reply path cannot see or is told to distrust.

---

## 8. Application State Audit

### 8.1 State machine

States are encoded in `whatsapp_conversations.pending_question` (there is no enum):

| `pending_question` | Meaning | Entered by | Exited by |
|---|---|---|---|
| `null` | free conversation | default / `handleDocument` completion / `restartApplicationForNewMachine` | any |
| `choose_machine` | asked which model for a calc | `handleInstallmentCalc` | next machine mention |
| `choose_months` | asked for term | `handleInstallmentCalc` | next term mention |
| `application_missing_data` | collecting fields | `ApplicationHandler::saveState` (missing non-empty) | all fields present |
| `application_documents` | collecting documents | `finalizeApplicationTurn` | last document accepted |
| — | `status = 'awaiting_agent'` | `handoffToAgent` | **manual only** (dashboard) |

Required fields (`ApplicationStateService::REQUIRED_FIELDS`): `full_name`, `national_id`, `phone`, `job_type`, `income_proof`, `work_address`, `home_address`, `installment_months`; plus `work_vehicle` when `requiresVehicleAnswer($incomeCategory)`. `income_proof` is dropped when `categorizeIncome() === 'freelance'`.

Income categories (`categorizeIncome`): employee / pension / business_owner / delivery / taxi_owner / freelance (+ `AiMemoryRules::jobCategoryKeywords()` extensions).

Validation: `ApplicantDataVerifier` (name, national ID → birthdate + age gate), `ApplicantNameValidator`, `AddressPlausibilityValidator`, `AddressParser` (component-level completeness), `detectConflicts` on `phone` / `national_id` only.

### 8.2 Who decides what

| Decision | Owner | Correct? |
|---|---|---|
| Which fields are required | code (`REQUIRED_FIELDS`) | ✅ |
| Whether a value was provided | AI extraction (`mode=application_data_extraction`) | acceptable, but see 8.4 |
| Whether an address is complete | code (`AddressParser`) | ✅ (explicitly migrated away from the LLM) |
| Whether a profession is banned | code + additive memory rules | ✅ |
| Age eligibility | code (`EgyptianNationalId`) | ✅ |
| State transition | code only | ✅ — **the AI cannot write `pending_question`** |
| Document acceptance | `DocumentDataExtractor` (AI) + `violation_message` | AI has real authority here |
| Request creation | code (`createInstallmentRequest`) | ✅ |

**Can the AI corrupt the state?** Not directly — no AI output is written to `pending_question` or `missing_fields`. It can corrupt *values* (a hallucinated `full_name` from a short message; the extraction prompt explicitly says "اعتبرها full_name = نص الرسالة كامل" for any ≤5-word message when `full_name` is null — so "ازيك يا باشا" becomes a name). `ApplicantNameValidator` is the only backstop.

**Does the AI know the current state?** The *planner* does. The *replier* does not (§6.2). `ApplicationHandler` is deterministic and does not consult the free-reply model at all.

### 8.3 Leaving the flow gracefully

`handleInternal` implements a genuine interrupt:

```php
$answerableDuringApplication = ['price','images','installment_system','installment_calc','delivery_question','admin_fee_explanation'];
$isConfidentInterruptingQuestion = $applicationIsPending
    && ! $isAnswerToPendingApplicationQuestion
    && in_array($intent, $answerableDuringApplication, true)
    && (float) ($plan['confidence'] ?? 1.0) >= 0.5;
```

…then `withApplicationResume()` appends `"ولسه ناقصني … عشان أكمل طلب التقديم."`.

**Gaps:**
- `branches`, `application_status`, `general`, `unknown` are **not** interruptible. "فين مكانكم؟" mid-application → forced back into `intent = 'application'` → the customer's actual question is dropped and he gets the missing-fields list again.
- `withApplicationResume()` calls `missingFields($application, $isFreelance)` **without** `$requiresVehicle`, so the resume line can omit `work_vehicle` that the main flow is blocking on.
- It duplicates a third copy of the field-label map (the other two are `ApplicationStateService::FIELD_LABELS` and `FIELD_LABELS_DETAILED`).

### 8.4 The document-collection dead end — `CRITICAL` for perceived intelligence

`ApplicationHandler::handle()` line ~48:

```php
if (($conversation->pending_question ?? null) === 'application_documents') {
    return $this->reply($conversation, $this->documentPrompt($payload));
}
```

Any text message while awaiting documents returns **the same static prompt**, forever. "هبعتها بكرة الصبح", "معنديش سكانر", "ممكن أبعتها لحد في الفرع؟", "شكراً" — all get the identical sentence back. The router's interrupt list is the only escape and it requires a confident price/images/installment intent. This is the most literal "workflow automation, not an assistant" behaviour in the system.

---

## 9. Intent System Audit

### 9.1 Inventory

Classifier-emitted (`normalizePlanFields::$validIntents`, 13):
`price`, `images`, `installment_calc`, `installment_total`, `installment_system`, `admin_fee_explanation`, `brand_models`, `branches`, `application`, `application_status`, `delivery_question`, `general`, `unknown`.

Targets (6): `new_machine`, `previous_selection`, `single_previous_machine`, `selected_index`, `unknown`, `last_machines` (aliased to `previous_selection`).

A **second** classifier exists: `WhatsappIntentRouter::detectIntent()` — pure keyword matching, returns `branches | installment_total | installment_system | installment_calc | images | price | general`. It is invoked when the LLM returns `general`/`unknown` but heuristics decided the message is a follow-up or a narrowing reply.

### 9.2 Overlap and ambiguity

| Pair | Overlap | How it's handled |
|---|---|---|
| `installment_system` ↔ `admin_fee_explanation` | Very high; both about "التقسيط" | Three separate prompt paragraphs + a PHP safety net (`isAdminFeeExplanationIntent`) that explicitly overrides a *high-confidence* `installment_system`. The code comment admits the classifier gets it wrong. |
| `installment_calc` ↔ `installment_total` | High | `isInstallmentTotalIntent()` overrides `installment_calc` "even if the classifier is confident". |
| `admin_fee_explanation` ↔ `installment_calc` | "هدفع 20 ألف مقدم والمصاريف كام؟" | Overridden to `installment_calc` when a deposit/term is mentioned. |
| `price` ↔ `brand_models` | "أسعار دايو" | `isBrandOnlyRequest()` re-searches and reroutes after intent dispatch. |
| `application` ↔ everything | `isApplicationIntent()` matches bare `اشتري`, `المطلوب`, `اعمل ايه` | Very broad; any `general` message containing `المطلوب` enters the application flow. |
| `general` ↔ `unknown` | No behavioural difference | Both fall to `handleAiFallback`. One of them is redundant. |

**Missing intents:** `specs_question` (memory #34 `شرح المواصفات` exists with no intent to route it), `complaint` (detected by `isComplaintMessage()` but only used as a *suppressor*, never as a routed intent — a complaint just goes to the free path), `greeting` (handled by `isPureGreeting()` only inside the application flow), `stock_availability` ("متاح؟").

**Unnecessary:** `general` vs `unknown` duplication.

### 9.3 Ordering and contamination

- Classification happens **before** memory retrieval *for the reply*, but the classifier itself pulls memory using `intent = last_topic` — i.e. **retrieval for understanding is driven by the previous turn's topic**. Because the full set is always returned this is currently harmless; the moment the memory base exceeds 18k chars it becomes a live "wrong memory chosen because of stale topic" bug.
- `$this->lastTurnExtraSteps = $plan['steps']` is assigned **after** the heuristics have possibly rewritten the primary intent. So the primary can be rewritten to `admin_fee_explanation` while `steps[]` still reflects the model's original reading.
- `containsAny()` is **substring** matching on normalized text. `isPureFollowUp()` includes the needles `'سنه'` and `'سنة'` — so any message containing that substring (e.g. `حسنة`, `السنه دي`, `سنه كام`) is classified as a pure follow-up on the previous machine.
- `normalizeText()` strips the definite article via `preg_replace('/\bال(?=[\p{Arabic}]{2,})/u', '', $text)` — it also mangles legitimately `ال`-initial words.

### 9.4 The specific short-message cases

| Message | Path | Verdict |
|---|---|---|
| "طيب بكام؟" | classifier → `price` + `single_previous_machine` (prompt has explicit pronoun rules); fallback `isPureFollowUp('بكام')` → `detectIntent` → `price` | ✅ Works, doubly guarded |
| "طب والقسط؟" | `isPureFollowUp('قسطها'/'القسط كام')` — **"والقسط" alone is not in the list**; relies entirely on the classifier | ⚠️ Single point of failure |
| "طب دي؟" | classifier pronoun rule; `isPureFollowUp` has no bare-pronoun needle | ⚠️ Classifier-only; `detectIntent` would return `general` → free AI |
| "ينفع؟" | No heuristic. `general` → `handleAiFallback` with all 46 memories and no application state | ❌ Generic answer likely |
| "تمام كمل" | `isBareConfirmation()` is **exact match** on a 16-item list — `'تمام كمل'` is not in it | ❌ Falls through to classifier/free path |
| "طب لو أنا على المعاش؟" | Not in any heuristic; classifier likely `installment_system` → static paragraph #61 | ⚠️ Answers generically instead of recomputing for this customer |
| "طب لو شغال؟" | Same | ⚠️ Same |

**Conclusion:** short follow-ups are handled by an ad-hoc union of (a) the LLM's pronoun rules and (b) two hardcoded Arabic phrase lists. Anything outside both lists depends on a classifier that is reading a 44k-char prompt.

---

## 10. Follow-up Questions Analysis

### Case A — "العربية X بكام؟" → "سعرها X" → "طب القسط؟"

1. `handleCashPrice` called `rememberMachines()` → `last_machine_ids = [X]`, `last_machine_id = X`.
2. Next turn, planner sees `last_machines_shown_to_customer = [{position:1, id, display_name, cash_price, installment_price}]` and the rule *"لو آخر رد عرض مكنة واحدة والعميل سأل عليها، target = single_previous_machine"*.
3. `resolveMachinesFromPlan` → `activeMachineFromConversation()` → machine X.
4. `handleInstallmentCalc` → no months → asks for the term.

**✅ Architecturally correct.** The mechanism (`last_machine_ids` + `target`) is sound.

### Case B — "موديل X" → "سعره X" → "طب ده متاح؟"

No `availability` intent exists. Classifier most likely returns `general`/`unknown`. Then:

```php
in_array($intent, ['general','unknown']) && !$isComplaint && $lastMachines->isNotEmpty()
    && ($this->isPureFollowUp($message) || $this->isBareConfirmation($normalized))
```

`"طب ده متاح"` matches **neither** list → falls through → `resolveMachinesFromPlan` with `target=unknown`, no `machine_query`, `uses_last_machines=false` → **`MachineSearchService::search("طب ده متاح")`** → probably empty → `handleAiFallback` with `$machines = null`.

The free AI *does* get `last_machines`, so it can answer — but it answers from memory #33's blanket availability prose, not from the `machines` row. **⚠️ Correct-ish by luck, not by design.**

### Case C — "أنا شغال" → "تمام" → "والحد الأقصى؟"

`"أنا شغال"` inside an application sets `job_type`. `"والحد الأقصى؟"`:
- not in `isPureFollowUp`, not `isBareConfirmation`,
- `applicationIsPending` is true and the intent is not in `answerableDuringApplication` (unless the classifier says `installment_system`),
- → forced to `intent = 'application'` → `ApplicationHandler` → extraction finds nothing → **the missing-fields list again**.

The customer asked about the financing ceiling (memory #37: 60k freelance cap; `FREELANCE_FINANCE_CAP` in code). He gets an unrelated form. **❌ This is the canonical "the bot ignored my question" failure**, and it is a routing decision, not a model failure.

### Architectural conclusion

Follow-up resolution works **only when the follow-up carries a machine-scoped keyword**. Follow-ups about *policy* ("الحد الأقصى", "لو على المعاش", "لو اترفضت"), *availability*, or *process* have no route, and inside an application they are actively swallowed.

---

## 11. Prompt Audit

### 11.1 `AiIntentClassifier::prompt()` — planner

- ~9,000 chars of hand-written rules, ~60 bullet lines, several of them near-duplicates:
  - `application` + `single_previous_machine` when `last_topic = application` is stated **three times** (lines about "إيه المطلوب؟", "اقدم ازاي", "last_topic = application").
  - "ممنوع تسأل عن اسم المكنة لو last_machine_ids موجودة" repeats the same constraint a fourth time.
- Contains the **entire memory blob** under `معلومات المعرض (للفهم فقط - ممنوع ترد بيها)`. 12k chars to disambiguate a 3-word message.
- The rules are ordered by accretion, not by priority — `admin_fee_explanation` has a whole paragraph pleading *"ممنوع ترجع installment_system لسؤال عن المصاريف الإدارية"*, which is prompt-as-bug-tracker.
- `thinkingBudget = 512`, `timeout = 12s`, `responseMimeType=application/json`, retry with `thinkingBudget=0`. **Well engineered.**

### 11.2 `AiPromptBuilder::buildChatReplyPrompt()` — reply

Contradictions and defects:

| # | Problem | Evidence |
|---|---|---|
| P-1 | Forbids JSON while memory #55 mandates `[[ORDER_DATA]]` JSON | §5.4 |
| P-2 | Declares memory the highest authority, above the conversation | §7.2 |
| P-3 | Claims "آخر 20 رسالة" but 15 are passed | `handleAiFallback` `->take(15)` |
| P-4 | Tells the model Laravel owns price/images/installments **and** that it may compute — the boundary is prose, not enforced | `مهمتك` block vs `إنت مسموحلك تحسب` |
| P-5 | No application-state block at all | §6.2 |
| P-6 | Negative-instruction heavy (8 `ممنوع` bullets before any positive guidance) — pushes the model toward terse, defensive, template-like output | prompt body |
| P-7 | "ممنوع تبدأ بتحية إلا لو العميل بدأ بتحية" + "ممنوع ترد كأن دي أول رسالة" + "ممنوع تقول يا بطل" — three separate anti-robot patches instead of one voice spec | prompt body |
| P-8 | `focusBlock` says "ممنوع تقفل بسؤال لو مش لازم" only for extra steps; the primary reply has no such rule, so almost every reply ends with a question → reads scripted | prompt body |
| P-9 | Leaks internal margin data through memory #39 with only a prose guard | §5.2 |

### 11.3 `extractApplicationData()` — extraction prompt

- **Broken variable reference:** `لو known_context.required_fields فيها full_name و**ken_context**.current_application.full_name` — a truncated token. The model is being asked to reason about a path that does not exist.
- **Aggressive name heuristic:** any ≤5-word message with few digits becomes `full_name`. Combined with the router forcing `intent = 'application'` for most messages during a pending application, an off-topic short message can overwrite the applicant's name (mitigated, not prevented, by `ApplicantNameValidator`).
- Runs over the **last 20 messages** every turn, so previously-corrected values can be re-extracted from history. `mergeApplicationData` then overwrites the corrected value with the historical one, because it has no notion of recency or provenance:

```php
foreach ($extracted as $key => $value) { if ($value === null) continue; ... $current[$key] = $value; }
```

`detectConflicts()` protects only `phone` and `national_id`. **Name, job, and both addresses can silently regress.**

### 11.4 `AiReplyPhraser::prompt()`

Correct by construction: rewording is discarded unless every digit and every `must_keep` string survives and the length stays in range (`rejectionReason()`). **This is the best-designed AI boundary in the codebase and should not be touched.**

---

## 12. AI vs Deterministic Logic

### 12.1 Currently deterministic (and correctly so — do not move to AI)

`InstallmentCalculator` · `validMonthsForMachines` · `MachineSearchService` / `FuzzyArabicMatcher` · `AddressParser` + `ApplicationStateService::missingFields` · `EgyptianNationalId` age gate · `bannedProfessionReason` · `blockedByExistingRequest` · `GeminiKeyManager` reservation · state transitions · `InstallmentRequest` creation · `AiReplyPhraser::rejectionReason`.

### 12.2 Currently AI (and correctly so)

Intent + target + pronoun resolution (`AiIntentClassifier`) · field extraction from free text · document field extraction (`DocumentDataExtractor`) · wording (`AiReplyPhraser`) · free-form answers for branches/delivery/general.

### 12.3 Currently AI but should be deterministic

| Item | Why |
|---|---|
| Selecting which memories to send | Purely mechanical; today it is "send everything and let the model sort it out". That *is* delegating retrieval to the LLM's attention. |
| Deciding whether to answer a question mid-application | Currently a confidence float + a hardcoded intent whitelist. Should be an explicit policy table. |

### 12.4 Currently deterministic but should be AI-assisted

| Item | Why |
|---|---|
| `isPureFollowUp` / `isBareConfirmation` / `isInstallmentTotalIntent` / `isAdminFeeExplanationIntent` / `isApplicationIntent` — ~250 lines of Arabic phrase lists | These re-implement, in substring matching, exactly what the classifier was hired to do — and they *override* it. Each is a patch for a classifier that was, at the time, running on a large blind prompt. Shrinking the classifier prompt (§20) is the precondition for deleting most of them. |

### 12.5 Boundary violations found

- **Business logic overriding AI:** `isAdminFeeExplanationIntent` overrides a confident `installment_system`; `isInstallmentTotalIntent` overrides a confident `installment_calc`; `applicationIsPending` overrides everything not in a 6-item whitelist.
- **AI overriding business logic:** memory #56 instructs the model to declare an application submitted; the reply prompt authorises free arithmetic on snapshot numbers while memory #32 supplies a *different* admin-fee formula.

---

## 13. Why the Bot Feels Robotic

| Symptom | Root cause in code |
|---|---|
| Same document prompt repeated forever | `ApplicationHandler::handle()` early return on `pending_question === 'application_documents'` (§8.4) |
| Missing-fields list re-sent verbatim | `questionForMissing()` mitigates with `NO_PROGRESS_OPENERS` (3 variants) and acknowledgments — but the **list body** is identical every turn |
| Every answer ends with a question | Templates (`رد سعر موديل واحد` → "تحب احسبلك قسطها علي قد ايه ؟") + no prompt rule against closing questions on the primary reply |
| Off-topic question mid-application ignored | 6-intent whitelist + `confidence >= 0.5` (§8.3) |
| Policy follow-ups answered with a static paragraph | `installment_system` → memory #61 verbatim, never personalised to the customer's job/machine |
| Identical wording for identical questions | `isRepeatOfLastCalc` varies only the **opener**; body is deterministic |
| Short replies never reworded | `ai_phrasing.min_chars = 80` — most conversational replies are shorter, so the "make it sound human" layer applies almost exclusively to money messages |
| Random template variation | `AiReplyBuilder` `array_rand()` — variation without intent; sometimes changes register mid-conversation |
| Resume line bolted onto every interrupted answer | `withApplicationResume()` appends the same sentence, unconditionally |
| Bot "forgets" and asks for the model again | `resolveMachinesFromPlan` returns empty → `'تقصد سعر أنهي موديل بالظبط يا فندم؟'` even when `last_machine_ids` is populated (the `price`-with-no-machine guard runs before any `lastMachines` fallback, unlike `installment_calc`/`application`) |
| Silence on error | Router `catch` returns `reply => null` → `textMessagesFromResult()` returns `[]` → nothing is sent |
| Repeats itself then escalates | `RepetitionGuard` ≥ 0.75 → `recordAttempt` → handoff at 3. Correct as a *last* resort, but it triggers because the model has nothing new to say — which is a context problem, not a repetition problem |

---

## 14. Token Efficiency Audit

### 14.1 Current cost per message (measured)

| Turn type | Gemini calls | Approx. prompt chars |
|---|---|---|
| Deterministic (price/images), reply < 80 chars | 1 (planner) | ~44,400 |
| Deterministic money reply (≥ 80 chars) | 2 (planner + phraser) | ~45,500 |
| Free / general / branches | 2 (planner + reply) | ~62,600 |
| Free + 1 extra step | 3 | ~81,000 |
| Application turn | 2 (planner + extraction) | ~75,000 |
| Application + interrupt answered | 3–4 | ~95,000+ |

### 14.2 Where the waste is

| Waste | Size | Fix direction |
|---|---|---|
| Memory blob in the **planner** prompt | 12,135 chars/turn | The planner needs the alias table (#33) and intent vocabulary, not branch addresses, OCR rules, document lists or 21 reply templates. **~10k chars removable.** |
| 21 `رد ...` templates in **both** prompts | ~2,000 chars × 2 | Exclude `scope`/`category = template` from the model-facing blob entirely. |
| Memory blob in the **reply** prompt | 12,135 chars/turn | Only the memories relevant to the classified intent are needed. |
| `recent_messages` JSON in the planner | **20,443 chars** on a 34-message conversation | 20 messages with `direction`/`message`/`payload` as pretty-printed JSON. Plain `العميل:`/`المعرض:` lines (as the reply prompt already uses) would be ~4× smaller; 8–10 messages suffice for pronoun resolution. |
| Raw `context_payload` dump in the planner | 1,041 chars (unbounded) | Send named fields only. |
| Static planner rules | ~9,000 chars | ~30% is duplicated `application` guidance. |
| Same machine list in 3 places | ~300 chars | Consolidate. |

**Realistic target without losing any understanding: ~14k chars planner + ~9k chars reply ≈ 23k total vs today's 62.6k — a ~63% reduction.**

### 14.3 Always / conditionally / never

| Always include | Include when relevant | Never include |
|---|---|---|
| Current message | Memories matching classified intent | Reply templates (`رد ...`) |
| Last 6–10 messages (role-prefixed text) | `installment_snapshot` (only when a calc exists **and** is recent **and** matches the active machine) | Internal margin/commission notes (#39 tail) |
| Active machine(s) + their DB prices | Application state block (only during an application) | OCR/document extraction rules on non-document turns |
| `always_include` policy memories (excluded professions, tone) | Branch list (only for `branches`/`delivery_question`) | Raw `context_payload` JSON |
| Explicit "what the customer already told us" | `customer_profile` (only when non-empty **and** labelled as historical) | `documents_collected` paths, streak counters |

---

## 15. Gemini Account Rotation Audit

### 15.1 How selection works

`GeminiKeyManager::reserveAvailableModel()` runs a single `DB::transaction` with `lockForUpdate()`:

- Filters: `provider`, `is_active`, `requests_today < rpd_limit`, minute window (`requests_this_minute < rpm_limit` or window expired), second window (`tokens_this_second + est <= tps_limit` or expired), `cooldown_until`, parent key active + not cooling down, `model_code = $preferred`, `is_embedding = false`, `id NOT IN $triedIds`.
- Ordering: `priority`, `requests_today`, `requests_this_minute`, never-used first, `last_used_at`, `id`.
- Then **increments counters before dispatch** and saves.

**This is a correct atomic reservation.** No TOCTOU race between workers. `priority` + `requests_today` ordering does spread load across keys.

### 15.2 Failure handling (`GeminiClient::generateText`)

| Condition | Action |
|---|---|
| 401 / `api_key_invalid` | disable **key and model**, alert, retry next |
| 403 | disable model for that key, alert, retry next |
| 404 | disable model, 24h cooldown, alert, retry next |
| 429 / quota | `GeminiRateLimitParser::analyze()` → per-model cooldown or `markDailyLimitFinished`, retry next |
| 500/502/503/504 | 120s cooldown, retry until `max_transient_failovers` (2) |
| exception | same as 5xx |
| no model left | downgrade `reasoning → fast` **once**, then fail with an Arabic error string |

Exhaustion alerting: `startAllKeysExhaustedAlert()` / `modelExhaustedAlert()` via WhatsApp.

### 15.3 Real defects

| # | Defect | Severity |
|---|---|---|
| G-1 | **Failed calls permanently consume RPD.** `requests_today++` happens at reservation; nothing decrements it on 429/503/timeout/invalid-key. A flapping key burns its 500/day quota on calls that never returned a token. | HIGH |
| G-2 | **`markUsed()` discards the real token count.** It receives `usageMetadata.totalTokenCount` and does nothing with it — `tokens_this_second` keeps only the `strlen/4` estimate. TPS accounting is permanently an estimate, and the estimate is optimistic for Arabic (44,370 chars → estimated 11,092 tokens; real Arabic tokenization is materially higher). | MEDIUM |
| G-3 | **`refreshWindows()` is a blind bulk reset.** It sets `requests_this_minute = 0` for every row whose window is older than a minute, in a separate statement from the reservation transaction. Run concurrently with reservations it can grant more than `rpm_limit` in a minute. | MEDIUM |
| G-4 | **Downgrade is a no-op today.** `gemini.models.reasoning` and `gemini.models.fast` are both `gemini-3.1-flash-lite`, so `$modelCode !== $fastModel` is false and the single-downgrade escape hatch never fires. There is no second model to fall back to. | MEDIUM |
| G-5 | **Capacity ceiling.** `gemini-3.1-flash-lite` has `rpd_limit = 500` on 4 key-model rows, but only **2 of 4 `gemini_api_keys` are active** (`SELECT provider,COUNT(*),SUM(is_active) FROM gemini_api_keys` → `gemini, 4, 2`). At 2–4 Gemini calls per customer message, effective capacity is roughly **250–500 customer messages/day**. `gemini-3.7-flash` rows are all inactive; `gemini-2.5-flash-lite` rows inactive. | HIGH |
| G-6 | **No idempotency across job retries.** A retried job re-issues every Gemini call from scratch (planner + extraction/reply + phrasing), doubling spend on exactly the failure paths where quota is already tight. | HIGH |
| G-7 | **Conversation behaviour does depend on which key answers** only via model identity — and since all live traffic is on one model code, there is currently no cross-model drift. This is a *latent* risk the moment a second model is enabled: `temperature 0.6` on the reply path and a different model would change register mid-conversation. | LOW (today) |
| G-8 | **No hidden context loss between keys** — the whole prompt is rebuilt per call and Gemini is stateless here. ✅ | — |

---

## 16. Production Failure Analysis

| Scenario | Behaviour today | Risk |
|---|---|---|
| **Long conversations** | Planner prompt grows with the 20-message JSON (measured 20,443 chars at 34 messages). No summarisation. | HIGH — latency and cost scale with conversation length |
| **Thousands of customers** | Bounded by G-5 capacity ceiling and 3 worker slots | HIGH |
| **Topic change** | `last_machine_ids` only changes when a new machine is *resolved*; a topic change to policy/branches keeps the old machine as "active" | MEDIUM |
| **Returning to an old topic** | `context_payload` never expires — old machine, old term, old deposit are still "current" (§4.4) | HIGH |
| **Contradictory customer info** | `detectConflicts` covers `phone` + `national_id` only. Name/job/address silently overwritten by the newest extraction — including a re-extraction from stale history | HIGH |
| **Customer corrects a value** | Works for phone/ID (explicit conflict question). For everything else: last write wins, no confirmation, no audit | MEDIUM |
| **Very short messages** | §9.4 — several fall through both heuristic lists into the free path | HIGH |
| **Burst messages** | `hasUnansweredJob` → `quote_reply`; `claimNextJob` serialises per conversation. **Good.** But each burst message pays a full planner call, and the second message's context includes the first's reply only if it was already saved | MEDIUM |
| **Duplicate webhooks** | `wa_message_id` dedupe + 2-minute identical-text dedupe. **Good** — but the 2-min rule silently swallows a genuine repeated question after a slow reply | LOW |
| **Retry** | **CRITICAL** — see below |
| **Timeout** | Planner 12s, reply 20s, phraser 12s. `ai_fallback_failures >= 2` → handoff. Reasonable | LOW |
| **Gemini failure** | Graceful: `'ثواني يا فندم، هراجعلك التفاصيل وأرد عليك.'` then handoff on the second failure | LOW |
| **Quota exhaustion** | Alert + Arabic error string → `ok=false` → same failure path | MEDIUM |
| **Concurrent messages, same customer** | `claimNextJob` excludes busy conversations and busy senders. **Correct.** | LOW |
| **Race conditions** | Key reservation is atomic. `context_payload` read-modify-write is **not** — `ApplicationHandler` and `updateConversationState` both do `$conversation->context_payload` → mutate → `forceFill()->save()`. Serialised per conversation by the claim lock, so safe *today*, but it depends on the worker lock, not the DB | MEDIUM |
| **Stale memory** | §4.4 | HIGH |
| **Corrupted memory** | Live: fake branch #31 | CRITICAL |
| **Partial application state** | `missingFields` recomputed every turn from values — resilient. ✅ | LOW |
| **Failed DB transaction** | No transactions wrap the turn. A failure mid-turn leaves `context_payload` half-updated | MEDIUM |
| **Reply generated, state update fails** | Router already saved the outgoing message before the state write in several handlers → transcript claims something the state does not reflect | MEDIUM |
| **State updated, send fails** | **CRITICAL** — see below |

### 16.1 The send-failure replay bug — `CRITICAL`

`ProcessWhatsappMessageJobs::handle()`:

```php
$result = $controller->processQueuedWhatsappJob($job);   // all state mutations + saveOutgoing happen here
$this->sendWhatsappResult($job, $result);                // throws on worker failure
...
} catch (\Throwable $e) {
    ... 'status' => ((int) $job->attempts >= 3) ? 'failed' : 'pending',
```

Production evidence: **7 jobs failed with `WhatsApp text send failed: 404 - {"ok":false,"error":"session not found","bot_id":"70"}`** — meaning each of them ran the full pipeline up to 3 times.

Consequences of one replay:
1. **Duplicate outgoing rows** in `whatsapp_messages` (every handler calls `saveOutgoing`/`reply` before returning). These rows then feed the next turn's context and `RepetitionGuard` → the bot's own duplicated messages make it *look* repetitive to itself.
2. **Application state advances again** — `no_progress_streak`, `installment_repeat_streak`, `clarification_attempts` all increment per attempt; 3 attempts can push `clarification_attempts` past the threshold and escalate the customer to a human for a *network* problem.
3. **Documents re-OCR'd** — `handleDocument` re-runs Google Vision on the same image (cost + latency), and on the completion turn a replay yields `'طلبك مكتمل بالفعل ومحتاجش مستندات تانية'` instead of the success message. The customer never sees "تم رفع طلبك بنجاح".
4. **Duplicate `InstallmentRequest` risk** — `createInstallmentRequest()` is a bare `create()` with no idempotency key, executed *before* `pending_question` is cleared. A crash between those two statements produces two requests for one customer.
5. **Duplicate `customer_profiles.applications_count` increment.**

### 16.2 `awaiting_agent` double-write — `MEDIUM`

`WhatsappBotController::incomingMessage()` already inserts the incoming `whatsapp_messages` row. Then `handleInternal()`:

```php
if (($conversation->status ?? 'open') === 'awaiting_agent') {
    $conversation->messages()->create(['direction' => 'incoming', ...]);   // second row
    return $this->textResult(null);
}
```

Every message sent while a conversation is with a human agent is stored **twice**. When the agent hands back, the AI's 15/20-message window is polluted with duplicates — which both wastes tokens and makes the model believe the customer repeated himself.

---

## 17. Architectural Smells

### A-1 · God object: `WhatsappIntentRouter` — `CRITICAL`
**Location:** `app/Services/WhatsappIntentRouter.php` (3,442 lines, 78 methods).
**Problem:** routing, intent heuristics, machine resolution, price/image/installment rendering, memory templating, state persistence, escalation and logging in one class.
**Why it matters:** `handleInternal()` alone is 600 lines of sequential `if/elseif` where order is semantics. Every new behaviour is a new branch inserted at a position nobody can reason about.
**Production impact:** every conversational bug fix risks regressing a different intent; the branch chain is why "correct but wrong" answers happen.
**Direction:** extract `IntentResolution` (classifier + overrides), `MachineResolution`, and per-intent handler classes. Keep the deterministic handlers exactly as they are.

### A-2 · Retrieval that never retrieves — `CRITICAL`
**Location:** `AiMemoryContextBuilder::FULL_SET_CHAR_BUDGET`.
**Problem:** an escape hatch became the only path (400/400 turns).
**Impact:** 24k chars/turn of mostly-irrelevant text; the LLM must find the needle itself; irrelevant memories (OCR rules, submission instructions) actively mislead it.
**Direction:** always score; keep `always_include` unconditional; cap by intent, not by total size.

### A-3 · Memory is prompt, data, config and template at once — `HIGH`
**Location:** `ai_memories` schema + `AiMemoryRules` + `AiReplyBuilder` + both prompt builders.
**Problem:** one table serves four consumers with four different contracts, and the `content` prose and `rules` JSON have no consistency check.
**Impact:** #51's prose disagrees with the code; #32's prose disagrees with `InstallmentCalculator`; #55/#56 disagree with the system prompt.
**Direction:** split by `scope`: `model_context` (goes to the LLM) / `template` (renderMemory only) / `rules` (code only) / `internal` (never leaves the DB).

### A-4 · Same calculation invoked with different parameters — `HIGH`
**Location:** `WhatsappIntentRouter.php` `handleAdminFeeExplanation()`:

```php
$candidate = app(InstallmentCalculator::class)->calculate($machine, $months, $deposit, $system);   // no $isFreelance
```

vs `handleInstallmentCalc()` and `installmentSnapshot()`, both of which pass `$isFreelance`.
**Impact:** for a freelance customer above the 60,000 cap, "المصاريف الإدارية كام؟" returns a **different** admin fee than the installment message did minutes earlier. The very next lines then read `$calc['freelance_extra_deposit']`, which is now always `0` — dead code that was written to handle exactly this case.
**Direction:** one `resolveCalculationContext(conversation)` used by all three call sites.

### A-5 · Three copies of the field-label map — `MEDIUM`
`ApplicationStateService::FIELD_LABELS`, `::FIELD_LABELS_DETAILED`, and the inline `$labels` array in `WhatsappIntentRouter::withApplicationResume()`. The third omits `work_vehicle`.

### A-6 · Two classifiers, one of which overrides the other — `HIGH`
`AiIntentClassifier` (LLM) vs `detectIntent()` + ~10 `is*Intent()` predicates (regex). The regex layer wins by construction.

### A-7 · Substring matching for intent — `MEDIUM`
`containsAny()` is `str_contains`. Needles like `'سنه'`, `'كام'`, `'اه'` are trivially matched inside longer words.

### A-8 · Schema introspection on the hot path — `LOW`
`Schema::hasColumn()` in `rememberMachines`, `lastMachinesFromConversation`, `activeMachineFromConversation`. These columns shipped in migrations dated 2026-06; the guards are permanent overhead.

### A-9 · Dead ingress controller — `LOW`
`Webhooks\WhatsAppWebhookController` echoes messages back. Unrouted, but one `routes/` edit away from live.

### A-10 · No transaction boundary per turn — `MEDIUM`
State writes, outgoing-message writes and `InstallmentRequest` creation are independent statements across a multi-second turn.

### A-11 · Silence as an error mode — `MEDIUM`
Router `catch` → `reply => null` → `textMessagesFromResult()` → `[]` → nothing sent, job marked `done`.

---

## 18. Root Cause Matrix

| Problem | Symptom | Root Cause | Component | Severity | Production Impact | Recommended Direction |
|---|---|---|---|---|---|---|
| No memory relevance | Generic answers; irrelevant policy text quoted | `FULL_SET_CHAR_BUDGET=18000` > 12,135-char corpus → `full_set` on 400/400 turns (`ai_memory_retrieval_logs`) | `AiMemoryContextBuilder` | **CRITICAL** | Every reply reasons over 46 memories; irrelevant ones bias output | Always score; select by classified intent; `always_include` unconditional |
| Memory sent twice | 2× cost, 2× dilution | Memory injected in `AiIntentClassifier::prompt()` **and** `AiPromptBuilder` | both prompt builders | **CRITICAL** | 24,270 chars/turn; measured 44,370 + 18,197 | Planner gets aliases + intent vocabulary only |
| AI blind to application state | Bot answers while ignoring the open application; re-asks | `AiPromptBuilder` has no `pending_question` / `missing_fields` / `application{}` block | `AiPromptBuilder`, `handleAiFallback` | **CRITICAL** | "Didn't read what I sent" | Add a compact application-state block |
| Off-topic question swallowed mid-application | "والحد الأقصى؟" → missing-fields list | `answerableDuringApplication` is a 6-intent whitelist; everything else forced to `intent='application'` | `handleInternal` ~560 | **HIGH** | Customer feels unheard; abandonment | Invert: interrupt by default, resume with one line |
| Document loop | Same document prompt to any text | `ApplicationHandler::handle()` early return on `application_documents` | `ApplicationHandler` ~48 | **CRITICAL** | Most robotic behaviour in the product | Classify first; answer/acknowledge, then re-prompt |
| Fake branch sent to customers | "فرع خالتك / ابن ابوك" | Test row in `ai_memories#31`; `branches` intent instructs verbatim reproduction | data + prompt | **CRITICAL** | Direct reputational damage | Clean the row; move branches to a validated table |
| Internal margins in every prompt | Potential leak of commission/cost/cashflow | `ai_memories#39` `scope=always_include` | data | **HIGH** | Confidential disclosure | Move to a non-model-facing scope |
| Prompt/memory contradiction | JSON in replies; false "تم رفع طلبك" | #55 mandates `[[ORDER_DATA]]`; #56 mandates claiming submission; prompt forbids JSON | data vs `AiPromptBuilder` | **CRITICAL** | Hallucinated submissions | Retire #55/#56 or scope them out of the model context |
| Admin fee inconsistency | Two different fees for one customer | Memory says 7% of price; code computes 7% of (price − deposit); `handleAdminFeeExplanation` omits `$isFreelance` | `InstallmentCalculator`, router, memory | **HIGH** | Wrong money quoted | Single calculation-context resolver; delete the formula from memory |
| Stale scenario presented as current | Month-old term/deposit shown as "أرقام السيناريو الحالي" | `context_payload` has no TTL; `installmentSnapshot` reads it unconditionally | `WhatsappIntentRouter`, schema | **HIGH** | Confidently wrong numbers | Timestamp `last_*`; expire after N hours/days |
| Term bleeds across machines | Customer restarts on machine B, gets machine A's term | `restartApplicationForNewMachine` clears `machine_*` but not `last_months`; `ApplicationHandler` auto-fills from it | router + handler | **MEDIUM** | Wrong application data | Clear calc state on restart |
| Send failure replays the turn | Duplicate outgoing rows, re-OCR, escalation counters bumped, duplicate-request risk | Side effects execute before send; retry re-runs everything | `ProcessWhatsappMessageJobs` | **CRITICAL** | 7 real occurrences observed | Split "generate" (idempotent, persisted) from "send" (retryable) |
| Duplicate incoming rows during handoff | Polluted context after handback | `handleInternal` re-inserts an already-stored message | `WhatsappIntentRouter` ~340 | **MEDIUM** | Token waste, false repetition signal | Remove the second insert |
| Silent failure | Customer gets nothing | Router `catch` returns `reply=null`; job marked done | router + command | **MEDIUM** | Dead conversations | Always emit a fallback sentence |
| Failed calls burn daily quota | Premature exhaustion | `requests_today++` at reservation, never decremented on failure | `GeminiKeyManager` | **HIGH** | ~250–500 msgs/day ceiling with 2 active keys | Refund on non-2xx that returned no tokens |
| Value regression on correction | Corrected name/address reverts | `mergeApplicationData` last-write-wins; extraction reads 20 messages of history; conflicts detected only for `phone`/`national_id` | `ApplicationHandler`, `AiIntentClassifier` | **HIGH** | Wrong data submitted | Provenance + turn index per field; widen conflict detection |
| Short follow-ups misrouted | "تمام كمل", "ينفع؟", "طب دي؟" | `isBareConfirmation` exact-match list of 16; `isPureFollowUp` substring list | `WhatsappIntentRouter` | **MEDIUM** | Restarts the conversation | Let a smaller, focused classifier own this |
| Intent overrides fight the classifier | Confident plans discarded | ~12 heuristic overrides in `handleInternal` | router | **HIGH** | Unpredictable routing | Shrink the planner prompt, then retire overrides one by one behind tests |
| Substring intent matching | Odd misclassification | `containsAny()` uses `str_contains` with needles as short as 2 chars | router | **MEDIUM** | Rare but confusing misroutes | Word-boundary matching |
| Templates injected as knowledge | Model reads `{machine_name}` | 21 `رد ...` rows in the model-facing blob | data + builder | **MEDIUM** | ~2k wasted chars ×2, model confusion | Exclude templates from model context |
| Broken extraction prompt token | Weaker extraction | `ken_context.current_application.full_name` typo | `AiIntentClassifier` ~570 | **MEDIUM** | Names missed → re-asked | Fix the reference |
| Planner prompt says 20 msgs, 15 sent | Minor | `handleAiFallback` `take(15)` vs prompt text | `AiPromptBuilder` | **LOW** | Model told a falsehood | Align |

---

## 19. Memory Decision Matrix

Applied to information that actually exists in this project.

| Information | Always Include | Include When Relevant | Never Include | Source of Truth |
|---|---|---|---|---|
| Current customer message | ✅ | — | — | webhook payload |
| Last 6–10 messages (role-prefixed) | ✅ | — | full 20-message JSON with payloads | `whatsapp_messages` |
| Active machine + DB cash/installment price | ✅ | — | — | `machines` |
| Excluded professions (#51) | ✅ (`always_include`) | — | — | `ApplicationHandler::bannedProfessionReason` + `AiMemoryRules` |
| Sales tone/style (#38) | ✅ (trimmed) | — | its embedded "don't say ارنبه/تفاحه" belongs in aliases | `ai_memories` |
| Model aliases (#33 alias block) | ✅ **planner only** | — | in the reply prompt | `AiMemoryParser::aliasRules` |
| Application state (step, missing, collected) | — | ✅ whenever `pending_question` is set | — | `context_payload` |
| `installment_snapshot` | — | ✅ when a calc exists, is < N hours old, and matches the active machine | when stale or for a different machine | `InstallmentCalculator` |
| Branch list + map links (#31) | — | ✅ `branches` / `delivery_question` | every other intent | should be a `branches` table, not prose |
| Installment systems 20%/30% (#35/#36) | — | ✅ `installment_system`, `installment_calc`, `admin_fee_explanation` | `price`, `images`, `branches` | `InstallmentCalculator` for numbers; memory for policy prose |
| Admin-fee **formula** | — | — | ❌ **never** (code owns it) | `InstallmentCalculator` |
| Required documents per category (#41,#43–#50) | — | ✅ `application`, `installment_system` | price/images/branches | `ApplicationHandler::requiredDocuments` + `AiMemoryRules` |
| OCR rules (#57,#58) | — | ✅ document turns only | all text turns | `DocumentDataExtractor` |
| Reply templates (21× `رد ...`) | — | — | ❌ **never** in any prompt | `renderMemory()` by exact title |
| `[[ORDER_DATA]]` schema (#55) | — | — | ❌ **never** (retire) | legacy |
| "Submit the request yourself" (#56) | — | — | ❌ **never** (retire) | `ApplicationHandler` |
| Aman commission / 1,500 EGP cost / 45-day payout (#39 tail) | — | — | ❌ **never** | internal only |
| `customer_profile` line | — | ✅ when non-empty, labelled "من زيارة سابقة" | asserted as current fact | `customer_profiles` |
| `documents_collected` paths, streak counters | — | — | ❌ never | `context_payload` |
| Raw `context_payload` JSON | — | — | ❌ never (send named fields) | `context_payload` |

---

## 20. Ideal Context Architecture

Derived from what is actually broken above — not a generic template.

### 20.1 Planner context (`AiIntentClassifier`) — target ≈ 14k chars

| Block | Why | When | From | Priority | Max |
|---|---|---|---|---|---|
| Intent vocabulary + target rules | The planner's only job | always | static | 1 | ~4,000 (after de-duplicating the 3× `application` rules) |
| **Model alias table only** (#33 alias lines) | Resolving "جامبو"/"النحلة"/"الارنبه" is genuine comprehension | always | `AiMemoryParser::aliasRules()` | 2 | ~700 |
| Named conversation state | `last_topic`, `pending_question`, `missing_fields` (names only), `last_machine_ids` | always | `whatsapp_conversations` | 3 | ~200 |
| `last_machines_shown_to_customer` | pronoun + index resolution | always | `machines` | 4 | ~400 |
| Last **8** messages as `العميل:` / `المعرض:` lines | pronoun resolution needs recency, not JSON | always | `whatsapp_messages` | 5 | ~3,000 |
| Document-turn payload | only the newest OCR blob | document turns | `whatsapp_messages.payload` | 6 | ~2,000 |
| Current message | the question | always | job | 7 | 4,000 cap |

**Removed:** the full memory blob (−12,135), the raw `context_payload` dump (−1,041), 12 messages of pretty-printed JSON (−16,000).

### 20.2 Reply context (`AiPromptBuilder`) — target ≈ 9k chars

| Block | Why | When | From | Priority | Max |
|---|---|---|---|---|---|
| Role + hard prohibitions | identity, no-invention | always | static | 1 | ~1,200 |
| **Source-of-truth hierarchy** (§7.3) | replaces "memory always wins" | always | static | 2 | ~300 |
| Deterministic numbers (`installment_snapshot`) | the only numbers allowed | when fresh + machine-matched | `InstallmentCalculator` | 3 | ~600 |
| **Application state block** *(new)* | step, collected, missing, next question | when `pending_question` set | `context_payload` | 4 | ~500 |
| Relevant memories (intent-scoped + `always_include`) | policy/tone | always, filtered | `ai_memories` (`scope=model_context`) | 5 | ~3,500 |
| Active machine(s) + prices | grounding | always | `machines` | 6 | ~300 |
| Last 8 messages | continuity | always | `whatsapp_messages` | 7 | ~2,000 |
| `customer_profile`, labelled historical | returning customers | when non-empty | `customer_profiles` | 8 | ~150 |
| Current message + `step_focus` | the question | always | job | 9 | 4,000 cap |

### 20.3 Conflict resolution rules to state **in** the prompt

1. Numbers in the "أرقام السيناريو" block are the only permitted figures. Never derive a rate or a fee from prose.
2. If the customer's latest message contradicts anything below it, the customer wins and the contradiction is acknowledged.
3. If the application-state block says a field is collected, never ask for it again.
4. If a memory contradicts the application-state block, the state block wins.
5. If memory contradicts an *earlier bot message*, memory wins (the current rule — keep it, but scoped to policy only, not to numbers).

### 20.4 What this buys

- ~62.6k → ~23k chars/turn (**−63%**) with *more* usable signal, because the application state finally reaches the answerer.
- Relevance filtering becomes real, which is the precondition for retiring most of the regex override chain (§12.4).

---

## 21. Conversation Quality Scores

| Dimension | Score | Reason |
|---|---|---|
| **Context awareness** | **4/10** | Machine context is genuinely well handled (`last_machine_ids` + planner targets). Application context never reaches the answerer; policy follow-ups have no route; `context_payload` never expires. |
| **Memory accuracy** | **3/10** | Corpus contains a fake branch, an obsolete JSON protocol, an instruction to falsely claim submission, and an admin-fee formula the code contradicts. |
| **Memory relevance** | **1/10** | Provably zero filtering: 400/400 retrievals returned all 46 memories. |
| **Follow-up understanding** | **6/10** | Strong for machine-scoped follow-ups (two independent mechanisms). Fails for policy/availability/process follow-ups, and is actively swallowed during an application. |
| **Application state awareness** | **5/10** | The state machine itself is well built and deterministic — but the AI cannot see it, and the document phase is a dead end. |
| **Naturalness** | **4/10** | `AiReplyPhraser` and `NO_PROGRESS_OPENERS` are real wins; undone by the repeated document prompt, the appended resume line, closing questions on every template, and the 80-char phrasing floor. |
| **Hallucination resistance** | **7/10** | Excellent guards where they exist: price refused without a machine match, digits locked in phrasing, no invented models. Lowered by memories #55/#56 which *instruct* false claims, and by the "memory outranks everything" rule. |
| **Token efficiency** | **2/10** | 62.6k chars/turn measured; ~63% is removable without losing signal; memory duplicated across two prompts. |
| **Production reliability** | **4/10** | Queue ordering, dedupe, burst handling and key reservation are genuinely well engineered. Undone by full-pipeline replay on send failure (7 real occurrences), non-idempotent side effects, and no per-turn transaction. |
| **Error recovery** | **5/10** | Graceful Gemini degradation and a sensible two-strike handoff. But router exceptions produce **silence**, and network retries can escalate a customer to a human. |

**Weighted overall: 4.1 / 10** — a strong deterministic core wrapped in a context layer that is not doing its job.

---

## 22. Top Root Causes

1. **Memory retrieval does not exist.** `FULL_SET_CHAR_BUDGET = 18000` against a 12,135-char corpus turned an escape hatch into the only path. Proof: `ai_memory_retrieval_logs`, 400/400 rows `full_set`, 46 memories selected every time. Every downstream "memory misuse" symptom descends from this.

2. **The reply model cannot see the application state.** `AiPromptBuilder` receives memory, transcript, machines, profile and installment numbers — and not one field of `pending_question` / `missing_fields` / `application{}`. The component that understands language is blind to the fact that matters most.

3. **The memory corpus is unsafe as text.** A fake branch that will be sent verbatim; internal commission and cost data in every prompt; two memories (#55, #56) that instruct behaviour the system prompt forbids and the code cannot perform; an admin-fee formula that disagrees with `InstallmentCalculator`.

4. **Understanding is decided twice and then overridden twelve times.** `AiIntentClassifier` → ~12 regex overrides in `handleInternal()` → `detectIntent()` as a second classifier. Order is semantics, nothing is tested, and confident plans are discarded by keyword matches.

5. **Side effects run before the send, and the send is what retries.** State mutation, outgoing-message persistence, OCR and request creation all complete before `sendWhatsappResult()` throws — after which the entire pipeline replays up to 3 times. Observed 7 times in `whatsapp_message_jobs`.

---

## 23. Recommended Fix Priorities

**Top 5 changes, in order. Each is `Evidence → Root Cause → Impact → Solution`.**

### 1. Make retrieval real, and stop sending memory to the planner
*Evidence:* 400/400 `full_set`; planner prompt 44,370 chars of which 12,135 is memory; 21 of 46 memories are reply templates.
*Root cause:* `FULL_SET_CHAR_BUDGET` bypass + memory injected into both prompts.
*Impact:* −60% tokens; the model stops reasoning over OCR rules and document lists when asked "بكام؟".
*Solution:* remove the full-set bypass (or lower it far below the corpus size); give the planner **only** the alias table; add a `scope` value that excludes templates and internal notes from any model-facing context.

### 2. Give the reply prompt an application-state block
*Evidence:* `AiPromptBuilder` has no such block; §10 Case C.
*Root cause:* structured state stops at the planner.
*Impact:* eliminates the largest class of "it ignored what I already told it".
*Solution:* one compact block — current step, fields collected, fields missing, the question currently pending — plus a prompt rule that a collected field is never re-asked.

### 3. Clean and re-scope the memory corpus
*Evidence:* #31 fake branch; #39 commission/cost/cashflow; #55 `[[ORDER_DATA]]`; #56 "claim you submitted it"; #32 wrong admin-fee formula; #49 empty.
*Root cause:* one table for four consumers, no review process.
*Impact:* removes the two live hallucination *instructions* and a reputational risk.
*Solution:* delete/repair the fake branch; move internal notes to a non-model scope; retire #55/#56; delete the admin-fee formula from prose (code owns it); move branches to a validated structured source.

### 4. Split "generate" from "send" in the job worker
*Evidence:* 7 jobs failed at `sendWhatsappResult` with `session not found`, each retried up to 3×.
*Root cause:* all side effects precede the network call that retries.
*Impact:* stops duplicate outgoing rows, duplicate OCR spend, false escalations and duplicate-request risk.
*Solution:* persist the generated result on the job row and mark it `generated`; a second, purely idempotent step performs delivery and retries alone. Wrap the generation turn in a transaction; add an idempotency key to `InstallmentRequest` creation.

### 5. Fix the document dead end and widen the interrupt policy
*Evidence:* `ApplicationHandler::handle()` early return; `answerableDuringApplication` is 6 intents; §10 Case C.
*Root cause:* the application flow treats every non-document message as noise.
*Impact:* the single biggest driver of "this is a bot, not a person".
*Solution:* classify first even during document collection; answer or acknowledge, then re-prompt with varied phrasing; add `branches`, `application_status`, `delivery_question` and a new `policy_question` to the interruptible set; make the "still missing" line conditional rather than automatic.

### Things that work — do not touch

- `InstallmentCalculator` and `InstallmentVariablesBuilder` — correct, single-owner, well documented.
- `AiReplyPhraser::rejectionReason()` — the digit/`must_keep`/length lock is the right AI boundary.
- `GeminiKeyManager::reserveAvailableModel()` — atomic reservation with `lockForUpdate`; correct ordering and failover.
- `ProcessWhatsappMessageJobs::claimNextJob()` + `withClaimLock()` — per-conversation ordering under N workers is right.
- `wa_message_id` dedupe, 2-minute identical-text dedupe, and the burst `quote_reply` mechanism.
- `ApplicationStateService` + `AddressParser` component-level address completeness (an explicit, correct migration away from LLM guessing).
- `ApplicantDataVerifier` / `EgyptianNationalId` age gating and the early banned-profession gate.
- Conversation-over-OCR precedence in `createInstallmentRequest()`.
- The `price`-with-no-machine refusal (`'تقصد سعر أنهي موديل بالظبط'`) — correctly prefers asking over inventing.
- `AiMemoryRules`' additive-only design (memory can raise the floor, never lower it).

---

## 24. Phased Implementation Roadmap

*(Design only — nothing implemented.)*

### Phase 0 — Data hygiene & observability (no code)
**Objective:** stop the two live data hazards and get baseline numbers.
**Affected:** `ai_memories` rows 31, 39, 49, 55, 56, 32.
**Solves:** fake branch, secret leakage, contradictory instructions.
**Risks:** removing #55/#56 could affect any customer still on the legacy `pending_order_data` path — verify none are open first.
**Tests:** snapshot the rendered memory blob before/after; assert no `[[ORDER_DATA]]`, no map-link duplicates, no commission text.
**After:** no prompt instructs JSON or false submission claims; branch list is truthful.

### Phase 1 — Context surgery
**Objective:** two lean, purpose-built prompts (§20).
**Affected:** `AiMemoryContextBuilder`, `AiMemoryResolver`, `AiIntentClassifier::prompt()`, `AiPromptBuilder`, `AiMemory` schema (`scope` values).
**Solves:** root causes 1 & 2; the token bill.
**Risks:** a needed memory could be filtered out — mitigate by keeping `always_include` unconditional and logging `selected_memory_ids` per turn (the log table already exists).
**Tests:** golden-set replay (`ai:golden-set` exists) comparing intent + reply for N archived conversations before/after; assert prompt sizes; assert `retrieval_method != 'full_set'`.
**After:** ~23k chars/turn; the answerer knows the application state; retrieval logs show varying selections.

### Phase 2 — Delivery reliability
**Objective:** exactly-once side effects.
**Affected:** `ProcessWhatsappMessageJobs`, `whatsapp_message_jobs` schema (+`result`, +`generated_at`), `WhatsappBotController::processQueuedWhatsappJob`, `ApplicationHandler::createInstallmentRequest`.
**Solves:** replay bug, duplicate transcript rows, duplicate OCR spend, false escalations.
**Risks:** schema change on a live queue; deploy with both paths tolerated for one release.
**Tests:** inject a send failure and assert exactly one outgoing row, one OCR call, one `InstallmentRequest`, and unchanged `clarification_attempts`.
**After:** a worker outage costs a delayed message, not a corrupted conversation.

### Phase 3 — Application flow humanisation
**Objective:** remove the document dead end; widen interrupts.
**Affected:** `ApplicationHandler::handle()`, `handleInternal()` interrupt block, `withApplicationResume()`, `ApplicationStateService::questionForMissing()`.
**Solves:** the dominant "feels like automation" symptoms.
**Risks:** interrupting more often could stall applications — gate on classifier confidence and always resume.
**Tests:** scripted conversations that ask branches/status/policy questions at each application step and assert both the answer and that state is preserved.
**After:** any question is answered mid-application, and the flow resumes where it was.

### Phase 4 — Consolidate understanding
**Objective:** retire the override chain now that the planner has a clean prompt.
**Affected:** `handleInternal()` lines 383–620, `detectIntent()`, `isPureFollowUp`, `isBareConfirmation`, `isAdminFeeExplanationIntent`, `isInstallmentTotalIntent`, `containsAny`.
**Solves:** unpredictable routing; substring misclassification.
**Risks:** highest-regression phase — must be preceded by Phase 1 and a golden set with ≥100 real conversations. Remove overrides **one at a time**, each behind a passing test.
**Tests:** per-override regression cases captured from production logs *before* removal.
**After:** one classifier, one routing table, testable.

### Phase 5 — State hygiene & source-of-truth
**Objective:** kill stale context and cross-machine bleed.
**Affected:** `context_payload` shape (add `updated_at` per `last_*` group), `installmentSnapshot()`, `restartApplicationForNewMachine()`, `mergeApplicationData()` (+ provenance), `detectConflicts()` (widen), `handleAdminFeeExplanation()` (pass `$isFreelance`).
**Solves:** month-old numbers presented as current; term bleed; value regression on corrections; the freelance admin-fee discrepancy.
**Risks:** low; all additive.
**Tests:** time-travel tests asserting a stale snapshot is omitted; a correction test asserting the corrected value survives 5 more turns.
**After:** the bot never presents old numbers as current, and a correction sticks.

### Phase 6 — Structural cleanup
**Objective:** make the router maintainable and lift the capacity ceiling.
**Affected:** `WhatsappIntentRouter` decomposition (A-1), the three label maps (A-5), `Schema::hasColumn` guards (A-8), dead webhook controller (A-9), `GeminiKeyManager` quota refund on failed calls (G-1), real token accounting in `markUsed()` (G-2), activate the two inactive Gemini keys (G-5).
**Risks:** pure refactor — do last, with Phase 1–5 tests as the safety net.

---

## 25. Testing Strategy

*(What to test and why — no test code written.)*

| Area | What to test | Why |
|---|---|---|
| **Context retention** | After a price answer, assert `last_machine_ids` is set and that the next turn's rendered prompt names that machine | The `rememberMachines` → `resolveMachinesFromPlan` chain is the backbone of every follow-up |
| **Follow-up questions** | "بكام؟" / "طب القسط؟" / "طب دي؟" / "ينفع؟" / "تمام كمل" / "والحد الأقصى؟" after each of price, images, installment, application | §9.4 shows several fall through both heuristic lists today; these must become regression locks before Phase 4 |
| **Memory relevance** | For each intent, assert which memory IDs are selected and that OCR/template/internal-scope rows are **never** selected | Guarantees Phase 1 does not regress to `full_set` and that secrets never re-enter a prompt |
| **Stale memory** | Build a conversation, advance the clock N days, assert `installment_snapshot` is omitted and no stale machine is asserted as current | §4.4 |
| **Conflicting memory** | Assert the rendered memory blob contains no admin-fee formula, no JSON protocol, no "you can submit the request" text | Prevents #32/#55/#56 class regressions |
| **Application state visibility** | Assert the reply prompt contains the current step and the missing-field names whenever `pending_question` is set | Direct test for root cause 2 |
| **Application state machine** | Every income category × every required-field permutation → correct `missingFields`, correct required documents, correct banned-profession block, correct age gate | The deterministic half is the product's safety layer |
| **Topic switching** | Machine A price → machine B price → "طب القسط؟" resolves to **B**; then "ورجعلي الأولى" resolves to A | `narrowMachinesByVariant` / `selected_index` correctness |
| **Short messages** | The full "تمام / اه / ماشي / اوك / كمل / ok" matrix in each `last_topic` | `isBareConfirmation` is an exact-match list; every miss restarts the conversation |
| **Customer corrections** | Give name/phone/ID/address, then correct each; assert the corrected value survives 5 further turns and is what reaches `InstallmentRequest` | `mergeApplicationData` has no provenance; only phone/ID are conflict-guarded |
| **Long conversations** | 50-turn conversation → assert both prompts stay under their budgets | Prompt growth is currently unbounded in message count |
| **Interrupt & resume** | Ask branches/status/delivery/policy at each application step → assert answered **and** `pending_question` unchanged | Phase 3 acceptance |
| **Document phase** | Send text (not media) while `application_documents` → assert the reply is not byte-identical to the previous one | Direct test for the §8.4 dead end |
| **Gemini failures** | Planner fails / reply fails / both fail twice → assert graceful message, then handoff, and that `clarification_attempts` is **not** incremented by network faults | Prevents escalating customers for infrastructure problems |
| **Quota switching** | Exhaust key 1's RPD → assert key 2 is used; exhaust all → assert alert + graceful reply; assert `requests_today` is not consumed by calls that returned no tokens | G-1, G-5 |
| **Concurrent messages** | Two messages, same conversation, two workers → assert strict ordering and no interleaved `context_payload` writes | `claimNextJob` contract |
| **Send-failure idempotency** | Force `sendWhatsappResult` to throw → assert exactly one outgoing row, one OCR invocation, one `InstallmentRequest`, unchanged counters | Phase 2 acceptance; 7 real occurrences exist |
| **Duplicate webhooks** | Same `wa_message_id` twice; identical text twice inside/outside 2 minutes | Existing dedupe must not regress |
| **`awaiting_agent`** | Message during handoff → assert exactly **one** `whatsapp_messages` row and no reply | §16.2 |
| **Prompt contracts** | Golden-file snapshots of both rendered prompts | Prompts are the real source code of behaviour and are currently untested — there are 17 test files and **none** covers the router, the classifier, or memory retrieval |

---

## 26. Final Verdict

### Why the bot does not feel like a real AI assistant

Because the only component capable of understanding a conversation is given **everything except what matters**. On every message it receives all 46 business memories — branch addresses, OCR instructions, document checklists, 21 reply templates, and the shop's own margin data — and none of the customer's application state. It is told, in writing, that this global memory outranks both the conversation and its own earlier answers. Meanwhile the actual routing decision has already been made by a chain of about twelve Arabic-substring heuristics that can override a confident classifier, and during an application any question outside a six-item whitelist is discarded and answered with a form.

The result is a system that is *correct* far more often than it is *responsive*. Prices are right, installments are right, the state machine is right, key rotation is right. What is missing is the connective tissue: relevance, state visibility, and a coherent source-of-truth hierarchy.

### The five root causes

1. Memory retrieval never executes — 400 of 400 production retrievals returned the entire corpus.
2. The reply model has no access to the application state.
3. The memory corpus contains corrupted, confidential and self-contradicting text, including two rows that *instruct* hallucination.
4. Intent is decided twice and overridden twelve times by untested keyword heuristics.
5. Side effects execute before delivery, and delivery is the step that retries.

### The five first changes

1. Make retrieval real; stop sending memory to the planner; exclude templates and internal notes from every prompt.
2. Add an application-state block to the reply prompt, with a rule that a collected field is never re-asked.
3. Clean and re-scope `ai_memories` (fake branch, internal margins, `[[ORDER_DATA]]`, "claim you submitted it", the admin-fee formula).
4. Split generation from delivery in the job worker; make the turn transactional and request creation idempotent.
5. Remove the document dead end and widen the mid-application interrupt policy.

### What must not be touched

`InstallmentCalculator`, `AiReplyPhraser`'s digit lock, `GeminiKeyManager::reserveAvailableModel()`, `claimNextJob()`'s per-conversation ordering, the `wa_message_id` / identical-text dedupe, the burst `quote_reply` mechanism, `ApplicationStateService` + `AddressParser`, `ApplicantDataVerifier`'s age gate, the conversation-over-OCR precedence, the price-without-a-match refusal, and `AiMemoryRules`' additive-only design. These are the parts that already behave like a careful human, and every one of them is documented in code with the reason it exists.

### Classification of every problem found

| Class | Findings |
|---|---|
| **AI / model limitations** | *None proven.* Every misclassification traced here has a prompt, context or routing explanation. Gemini is not the bottleneck; a 44k-char prompt and a regex override chain are. |
| **Prompt problems** | JSON forbidden vs mandated; memory declared supreme over the conversation; "20 messages" vs 15; 8 leading prohibitions; no closing-question rule; triple-stated application rules; broken `ken_context` token |
| **Memory problems** | Fake branch; internal margins; `[[ORDER_DATA]]`; "claim you submitted it"; admin-fee formula divergence; banned-professions divergence; 21 templates as prose; a 17-char empty row; titles as an undocumented API |
| **Retrieval problems** | `full_set` on 400/400 turns; scorer, intent boost and priority all unreachable; retrieval keyed on the *previous* turn's topic |
| **Context construction problems** | Memory injected twice; no application state in the reply prompt; raw `context_payload` dump; 20-message JSON; triple-duplicated machine list |
| **State management problems** | No TTL on `context_payload`; term bleeds across machines; last-write-wins merges without provenance; conflict detection limited to phone/ID; three label maps; no per-turn transaction |
| **Business logic problems** | Document dead end; 6-intent interrupt whitelist; twelve intent overrides; two classifiers; substring matching; silence on exception |
| **Data problems** | Fake branch row; empty memory row; `priority = 0` everywhere; `applicable_intents` NULL on 19 of 46 rows |
| **Infrastructure / API problems** | Failed calls consume RPD; real token counts discarded; `refreshWindows()` bulk reset; downgrade path inert (both model slots identical); only 2 of 4 keys active → ~250–500 messages/day ceiling; full-pipeline replay on send failure |

---

*End of audit. No source files were modified.*
