# Phase 1 — ConversationFacts: one deterministic factual view for the AI

Status: approved by the owner on 2026-10-07 (design in chat, with three corrections folded in below).
Scope: Phase 1 of 5 only. Phases 2-5 (dynamic tool sets, multi-part understanding, guard diet, product/finance/image/document QA) get their own specs.

## 1. Goal

The AI receives **one** small, deterministic, model-agnostic factual view of the customer and the conversation, and cannot invent business facts from it.

- Same conversation state in → byte-identical facts out, for any model/provider.
- Facts + progress only. **No scripted flow**: no `step`, `next_step`, `current_step`, `intent`, `next_question`, `ask_for`, `still_to_ask`.
- Prices and installments come **only** from a valid quote-ledger entry.

Non-goals (explicit): no new tables, no storage migration, no change to business rules, no tool-set filtering (Phase 2), no `ReplyGuard` change (Phase 4), no model change.

## 2. Today (what is being replaced)

`ContextBuilder::buildL4` + `buildL6` + the memory block + three history notes read seven stores directly:
conversation `state` JSON (interest, awaiting, quotes, cash prices, work profile), `WorkProfiles`, `customer_attributes`, `customers.memory`, the open application's snapshot, `CustomerRequestStatus`, `Handoff`, plus the AI summary. They overlap (name/income/work appear in 3-4 of them), and the snapshot also carries scripted fields (`next_step`, `progress`, `full_list`, `missing_hints`, `ask_together`).

## 3. Components

All in `app/Agent/Context/Facts/`.

| Class | Job | Depends on |
|---|---|---|
| `ConversationFacts` | `for(WhatsappConversation, ?int $turnId): FactsResult` — assembles the facts. **Read-only**: no `update()`, no dispatch, no log writes except conflict logging. Rebuilt every turn. | stores below |
| `FactResolver` | Applies the precedence table to candidate values of ONE fact; returns winner (+ source tier) and the list of conflicts. Pure function. | none |
| `ToldSoFar` | `for(WhatsappConversation): array` — what the customer was already told, from authoritative trace/ledger data only. | `QuotedOffer`, `AiTraceStep`, `WhatsappMessage.metadata` |

`ContextBuilder` stops calling `WorkProfiles`, `CustomerMemory::forPrompt`, `CustomerRequestStatus`, `QuotedOffer::*`, `customerProfileFacts`, `cleanedState`, `afterFullList`, `recordedApplicantNote` directly; it renders `FactsResult` + `ToldSoFar` + recent messages. The `awaiting` clean-up that used to *write* during build (`cleanedState`) is filtered at read time in the facts; the tidy-up write stays as a separate, explicit step in `ContextBuilder::build` (not part of the facts).

`FactsResult` = `{ facts: array, conflicts: list<array> }`. Only `facts` goes to the AI; `conflicts` is logged (`Log::info('agent.facts.conflict', …)`) and returned for tests.

## 4. Precedence (deterministic)

Highest authority first. A candidate must first pass its **validity filter** (§5); a stale/invalid candidate is dropped *before* ranking and never wins merely for being "a tool result".

1. **Current authoritative business state** — read live now: catalog price/version of a machine, handoff row, submitted-request status, application row/status.
2. **Current conversation tool evidence** — valid quote-ledger entries, valid cash-price-shown entries, documents read in this conversation.
3. **Open application snapshot** — collected values (`ApplicationData`, with its own `source`: document/staff > customer), selection, missing, blockers, eligibility.
4. **Conversation work profile / state** — `record_work_profile` reading, `state.interest`, `state.awaiting`, gender.
5. **Durable customer memory** — `customers.memory` facts (non-expired, non-`ai_inference`) and `customer_attributes`.
6. **AI summary** — context only, **never a fact source** (§8).

Different facts, not different copies of one fact, may sit in different tiers: `deal` (what is being discussed *now*) and `application.selection` (what is *committed on the application*) are separate fields, so a customer comparing another bike while an application is open loses nothing.

Per-fact candidate lists (first non-empty valid candidate wins):

| Fact | Candidates in order |
|---|---|
| `deal.motorcycle` | newest valid quote → application selection machine → `state.interest.id` → memory stage `selected`/`preferred` |
| `deal.financing_months` | newest valid quote months → application plan months → memory `preferred_duration` (marked `stated`, never a price) |
| `person.name` | application `full_name` (document/staff-sourced beats customer-sourced inside the tier) → memory `name` → profile attribute |
| `person.age`, `nationality`, `gender` | application facts (from ID) → work profile applicant_* → memory |
| `work.*` (occupation, customer_type, insured, working_now, workplace) | work profile → application snapshot values → memory |
| `customer.stated_income` | application `monthly_income` → work profile `stated_monthly_income` → memory |
| `applicant` (self/other + relation) | application `applicant` → work profile |

Equality is compared after `ArabicTextNormalizer::normalize`. If two *valid* candidates of different tiers disagree, the higher tier wins, the lower is dropped from the AI view and a conflict `{fact, winner:{tier,value}, loser:{tier,value}}` is logged. The AI never receives both. Memory stays unmodified (this phase does not write).

## 5. Validity rules

- **Quote** (`quotes[]`): kept only if `at` < 24 h old **and** the machine's `updated_at` still equals the entry's `price_version`. Otherwise it is *expired*.
- **Cash price shown**: same two conditions; value is read from the catalog **now**, never from the remembered number.
- **Expired quotes never reach the AI as numbers.** They appear as `needs_new_lookup: [{motorcycle, months, why: "older_than_24h"|"price_changed"}]` — no price, no installment — so the model knows it must call `get_installment_offer` again instead of repeating or recalling a figure.
- **Memory**: `CustomerMemory::current()` drops what `withoutExpired` expires (motorcycle stages after 30 days, topic/objections). Facts with `source = ai_inference` are guesses and are not facts: excluded. `topic`, `open_question` and `objections` are AI-written and are not facts either: excluded. Facts about someone else's application are used only when `applicant_facts.for` equals the current applicant label.
- **Two different people** (customer vs the person applying): when the application is for someone else, the customer's own memory never competes with the applicant's data; it appears separately under `customer.himself`.
- **Free text** (memory `insured: "اه متأمن"` vs `yes`) can read differently without contradicting: a candidate marked `free_text` never produces a conflict, but still ranks below structured ones.
- **Awaiting entries** drop out once the field is collected / document accepted in the snapshot (as today, computed at read time).

## 6. Numbers rule (prices / installments)

- The only price/installment values in the context are in `facts.quotes[]` (valid ledger) and `facts.cash_prices[]` (catalog-now for a bike he was shown).
- Memory fields `monthly_budget`, `cash_budget`, `down_payment`, `preferred_duration` are what **he** said he can afford, labelled `his_budget`; they are never presented as an offer.
- The summary, memory and raw messages are never a source for a price (§8).
- Documented exception to confirm with the owner: `requests[]` (his **submitted** requests, `CustomerRequestStatus`) keeps `installment_price`/`monthly_installment`/`down_payment` as `as_submitted` — it is the business record of an existing request, not a quote, and is needed for "طلبي قسطه كام". `ReplyGuard`'s number checks remain the backstop.

## 7. The facts shape

Rendered as JSON under one heading, Arabic keys not used (stable English keys; values as stored). Empty values are omitted.

```
{
  "person":      { name, age, gender, nationality, applicant: {who: self|other, relation} },
  "work":        { occupation, customer_type, work_type, insured, working_now, his_words },
  "customer":    { governorate, area, usage, has_driving_license, his_budget{...} },   // durable memory, with provenance only where verified
  "deal":        { motorcycle:{id,name}, interest:{label,cc}, financing_months },
  "quotes":      [ {motorcycle, months, monthly_payment, cash_due_upfront, admin_fee_at_pickup, total_paid, cash_price, quoted_at} ],
  "cash_prices": [ {motorcycle, cash_price} ],
  "needs_new_lookup": [ {motorcycle, months, why} ],
  "application": { id, status, customer_type, selection:{motorcycle_id, plan_months, down_payment},
                   collected:{non-sensitive values}, missing:{fields:[labels], documents:[labels]},
                   invalid:[{key,code}], documents:{accepted, rejected, processing},
                   eligibility, blockers, can_submit, applicant },
  "requests":    [ {request_number, status, motorcycle, months, as_submitted:{...}} ],
  "asked_and_waiting_for": [ {kind,key} ],       // past: things he was asked and has not given
  "handoff":     {status, reason?},       // omitted when status is none
  "unprocessed_media": [ {media_id, sent_at, caption} ],
  "notes":       { conversation_ended?, back_after? }   // factual: ended / silence gap
}
```

`missing` is **purely factual**: what data/documents are not yet present, each as `{key, label}` plus, when the field has them, its **definition** (`options` = values the field accepts, `hint`/`what_it_is` = what the paper is). It carries no order, no priority, no "next". `snapshot.next_step`, `progress`, `full_list`, `ask_together`, `both_sides`, `skipped_after_two_asks`, `reason: decides_documents` and work-profile `question` (`still_to_ask`) are **not** part of the facts. (They stay in `SnapshotService` for PHP callers — nudges, runner fallback, guard.) `application.documents.if_unavailable` (the owner's substitute-paper rule) and `application.staff_request` (what staff asked, a record) are kept: they are rules/records, not steps. `customer.sensitive_on_file` lists the keys of sensitive attributes we hold without their values.

## 8. Told-so-far, recent messages, summary — three separate things

**`told_so_far`** (structured, derived from authoritative data, no text parsing). It holds only what is told nowhere else; prices are not repeated (measured: repeating them cost tokens and added nothing):
- prices/installments told → the facts' `quotes[]` and `cash_prices[]` *are* that record (both come from the ledger a tool wrote, valid ones only; an expired one is in `needs_new_lookup` without a number);
- requirements/documents list given → `requirements_listed: true` from a successful `get_application_requirements` / `start_application` trace step in this conversation, or from the existing "a bot reply went out after the application opened" signal that replaces `full_list_sent`;
- bike photos sent → `bike_photos_sent: [names]` from outgoing messages' `metadata.motorcycle_id` (failed deliveries do not count);
- branch information given → `branch_info_given: true` from a successful `get_branch_information` step.

Use rule (instructions): a topic in `told_so_far` is not re-explained after a bare acknowledgement ("تمام"); if he asks again, answer again.

**`recent_messages`**: today's history `contents`, as conversational context, **not** a source of facts. Measured finding: a hard cut at the last 8 would make messages vanish (the summarizer only fires at 20 messages / 1,500 tokens, so what falls between is in neither the window nor a summary). So the window is **soft**: everything the summary has not absorbed yet stays visible (still bounded by `recent_messages_tokens`), and the summary is triggered up to the oldest of the last `agent.context.recent_messages_count` (default 8) raw messages, with `agent.summary.trigger_messages` now defaulting to 6. Net: in steady state the window settles at about 8-14 messages; nothing is ever hidden.

**AI summary**: older context only. Rendered under a heading that says so ("للسياق بس — مش مصدر للأرقام ولا للبيانات"), after the facts. It can never override, fill or contradict `ConversationFacts`; no value from it is copied into the facts. The summarizer and its trigger are unchanged.

## 9. What is removed from the context

| Removed (buildL4 and friends) | Replaced by |
|---|---|
| `customer_profile` (attributes) | `person`/`customer` via resolver |
| `customer_gender`, `customer_is_after`, `conversation_ended` | `person.gender`, `deal.interest`, `notes` |
| `waiting_for` | `asked_and_waiting_for` |
| `quotes_given_to_him`, `cash_prices_shown` | `quotes`, `cash_prices`, `needs_new_lookup` |
| `application` = `afterFullList(snapshot)` incl. `next_step`, `progress`, `full_list`, `missing_hints`, `full_list_sent` | `application` (facts only) + `told_so_far` |
| `his_work` incl. `still_to_ask` | `work` |
| `my_requests` | `requests` |
| `handoff`, `unprocessed_media` | same names inside facts |
| `buildL4m` memory prose block (stated/guessed/changed/conflicts/bikes/topic/open_question/objections/"ما تسألوش") | resolved into the facts; guessed/`open_question`/`objections` are not facts and are dropped |
| `recordedApplicantNote` (history note, which repeated recorded values) | `applicant`, `work` in the facts; **the reminder is kept** as a constant, state-free line next to his newest message (see below) |
| summary `facts` shown as facts | summary shown as context only |

`ReplyGuard` (one line, required to make the numbers rule true): its "is this number sourced?" check accepted any number anywhere in the prompt, including the old summary and memory prose. It now excludes the `## الكلام الأقدم` (summary) block from number sources, so a price that only a summary holds is rejected as `UNVERIFIED_NUMBER`. No other guard change in this phase.

**Measured correction (simulation, 2 runs per side):** removing the per-message reminder "if this message changes who applies or his work, call `record_work_profile` first" and relying on one instruction line made the agent record his work less often (warehouse scenario: 3 → 2 calls) and applications stopped opening in that scenario in both AFTER runs. The reminder is therefore restored in `ContextBuilder::buildL8` as a constant line with no recorded values (those live in the facts), shown only when the facts contain `work` or `applicant`.

Kept as is (not facts, not in scope): L0 instructions, catalog index L3, customer-type keys L3b, `ownStyleNote`, `asksSeveralThings` note, per-message media notes in history, `unprocessedMedia` detection.

Instruction file `resources/agent/instructions/agent.md` (v4.2.0 → v4.3.0): only lines that name a context key that changed are rewritten to the facts vocabulary (`quotes`, `cash_prices`, `needs_new_lookup`, `work`, `applicant`, `deal.interest`, `requests`, `application.missing`, `told_so_far`); the `still_to_ask` / `full_list` / context `next_step` guidance is replaced by "ask what is in `application.missing`, a thing or two related things (address parts, both ID sides), not repeating `told_so_far`", and the old history note ("if his message changes who applies or his work, call `record_work_profile` first") moves into one instruction line. `next_step` inside **tool results** (`application_now`) is untouched here — Phase 2. The live (DB) instruction version is a separate publish step the owner controls; this phase does not publish.

## 10. Token measurement

Same turns, same estimator (`TokenEstimator`), before and after:
1. `scratchpad/measure_context.php` rebuilds the context for the latest turn of each local conversation inside a rolled-back transaction with queues faked (no model call, no data change) and records system tokens, content tokens, total.
2. Existing `agent:baseline` / `agent:simulate-customers` for the behavioural check.
3. Report: average and per-turn before → after, and the size of the facts block alone.

## 11. Acceptance criteria

1. `ConversationFacts::for()` twice on unchanged data → identical output; it performs no writes (asserted by query log / model events).
2. The AI system prompt contains none of: `next_step`, `still_to_ask`, `full_list`, `full_list_sent`, `missing_hints`, `ask_together`, `progress`, `intent`, `ask_for`, `next_question`, `current_step` as keys outside tool results.
3. No price/installment number appears in the context outside `quotes`, `cash_prices` (and the documented `requests.as_submitted`).
4. Memory/summary never supply a value that a higher tier has; conflicts are logged, the AI sees one value.
5. Stale quotes (age or price version) are absent as numbers and present as `needs_new_lookup`.
6. Context tokens on the measured turns drop; no turn grows.
7. Existing test suite: no new failures vs. the pre-change baseline (failures classified: regression / pre-existing / environment). Assertions are not weakened.
8. `agent:simulate-customers` baseline run completes; guard events and fallback rates are compared to the pre-change run.

## 12. Tests required

New `tests/Feature/Agent/Facts/`:
- **Contradictory data**: profile attribute vs application value (application wins, conflict logged); memory job vs work profile job (work profile wins); ID-document name vs customer-stated name; applicant switched self → other.
- **Stale data**: quote 25 h old; quote with changed machine price; cash price changed; expired memory fact; `ai_inference` memory fact → none reaches the facts as numbers/facts, `needs_new_lookup` present.
- **Quote validity**: fresh valid quote present with all numbers; newest of two quotes drives `deal.financing_months`.
- **Told so far**: quote, requirements list, photos, branch → each present; nothing present when nothing happened.
- **Summary never a fact**: a summary containing a price and a name changes nothing in `facts`; it renders after facts under the context-only heading.
- **Recent messages**: capped at N, order preserved, current turn excluded.
- **Read-only/deterministic**: no writes, stable output.
- **Customer changes answer**: work profile re-recorded → latest wins; memory history untouched.
- **Several missing fields answered at once**: application with 3 missing fields, one message supplies them via tools → `missing` shrinks by all three; no step field exists anywhere.
- Existing tests updated only where they asserted a removed/renamed block or a private method that no longer exists: `ContextBuilderTest` (summary heading), `HistoryWindowTest` ×3 (summary heading/label, summary reaches the last N), `SimulatorRun1FixesTest` ×2 (`work`, `cash_prices`), `Conversation206Test` ×2 (recorded-applicant note and full-list tests rewritten against the facts / `ToldSoFar`), `FactsNotProseTest` (key `deal.interest` replaces `customer_is_after`). None weakened: each assertion now states the same behaviour in the new vocabulary.

## 13. Known gaps carried to later phases

- Tool results still embed `application_now` with `next_step` (Phase 2: small, focused tool results).
- All 20 tools are still declared on every call (Phase 2).
- Physical consolidation of the seven stores (read model today; storage later, behind this view).
- `ReplyGuard` size/role (Phase 4).
- The AI is still asked to write `topic` / `open_question` / `objections` into its memory update (instructions §٣); they are no longer shown back, so those output tokens are wasted. Remove in a later phase together with the memory write contract.
- `AGENT_SUMMARY_TRIGGER_MESSAGES=20` in a deployed `.env` overrides the new default of 6, so the window there stays large until it is lowered.
- Live (DB) instruction version is separate from the file; the new vocabulary only reaches the model when the owner publishes v4.3.0.
