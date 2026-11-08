# WhatsApp Sales Bot Rescue: Design (2026-11-08)

Owner's four demands, in their words:
1. The bot is dumb and repeats messages more than once.
2. It does not understand context and forgets quickly.
3. Rules must be strict: it may never break a rule the owner stated.
4. Token use must be as low as possible without lowering reply quality, abilities or reasoning.

The owner chose no further questions. Policy conflicts are resolved from the owner's own stated rules (memory files), never from later developer patches.

## 1. Evidence (what is actually wrong)

Measured on the local DB (data up to 2026-10-20) and the 44 commits d2f6c5a0f..60671ad86.

### Repetition
- **Code paths, not the model, cause most exact repeats.** There are 64 duplicate bot texts across 13 conversations. Examples:
  - Conversation 375: the form-link reminder went out 7 times to a silent customer, although `FormLinkFollowUpService` claims a cap of 2 and `followups_sent` stayed at 1.
  - Conversation 231: the same greeting went out 5 times in 20 seconds.
  - Conversation 372: a status/fix notice went out 6 times.
- Nothing checks outbound text across senders. Senders include the AI reply, form follow-ups, application nudges, status notifications, greeting shortcuts and 12 hardcoded regex shortcuts.
- The 11-06 patch `continueWithoutRepeating` replaces repeated questions with fixed robotic text ("مش هكرر عليك سؤال الموديل..."). That text is itself repeated.
- `REPEATED_REPLY` is the most frequent guard event (72). On the server, `safety.reply_guard_mode=advisory` turns it into a log line only.

### Forgetting / no context
- Selection, duration and work live in five places. All five are re-derived from message text every turn:
  - `MotorcycleSelection` (regex over messages)
  - `QuotedMotorcycle`
  - `conversation.state.interest`
  - `CustomerMemory`
  - `WorkProfiles`
- When the regex misses (X ROAD, KTX, "اللي بـ46 ألف", a bare "3"), the bot asks again (incident 2026-11-06).
- Per-turn tool filtering by regex on the customer's current message removed tools after short answers ("الزد", "٣"). The bot then could not price or send photos (documented in bot-reliability-2026-11-06).
- `max_model_calls` was set to 2 on the server. Turns ended before record → price → reply finished.

### Rules broken
- `safety.reply_guard_mode=advisory` on the server. All but 3 of the ~45 guard checks only log.
- Rule text has 7 sources that contradict each other:
  - DB instruction version
  - `agent.md` (59 KB, stale)
  - 21 `resources/agent/policies/*.md` appended by 14 `scripts/deploy-*.php`
  - `bot_lessons`
  - `business_memories`
  - settings texts
  - hardcoded PHP replies
- `CurrentBusinessPolicy` refuses by regex on the raw message. Verified wrong outputs:
  - "اخويا محامي وانا عايز اقسط" → the customer is refused.
  - "امين مخازن مدني في الشرطه" → refused.
  - "والدي ظابط" → the customer is asked whether *he* is army or police.
- The correct check already exists in the tool path: `WorkClassification::problem` runs on the recorded applicant's job.

### Tokens
- Each model call is about 16.5k input tokens: ~7.5k instructions, ~6k for 18 tool declarations (21k chars; `record_work_profile` alone is 4.8k chars, `send_reply` 2.4k), plus facts and history.
- Turns: 178 used 1 call, 416 used 2, 158 used 3, 63 used 4–5. A 2-call turn is ~32k tokens and a 4-call turn ~70k.
- Cached share is 55–75% on Gemini.
- Per-turn tool filtering changes the tool list between calls. That breaks the provider prefix cache, so the "saving" costs more than it saves.
- Bormaa calls get only `+256` completion headroom for reasoning on top of 384 output tokens. Reasoning can eat the reply, so the bot gets truncated or empty answers and is less smart.

### Tests
- 45 tests were already failing at d2f6c5a0f and 44 fail at HEAD.
- `tests/Smoke/*` are not in any phpunit suite.

## 2. Goals / non-goals / anti-goals

**Goals:**
- No identical or near-identical bot message to the same customer unless he asked again.
- Never ask for something the customer already answered.
- Every owner rule enforced in code (tools/guards), with the instructions only explaining.
- Fewer tokens per turn than today, with the same model and full tool set.

**Non-goals:**
- No server deploy (local only, per owner rule).
- No change to Bormaa / Work Factory / Guide product decisions; they are flagged in the report.
- No website or dashboard UI work.

**Anti-goals (a failure even if it "works"):**
- Saving tokens by answering with canned regex text instead of the model.
- Removing tools from the model.
- A "safe" fallback text sent repeatedly.
- Hiding a rule violation in a log instead of stopping it.

## 3. Design

### 3.1 One brain: remove the regex shortcut layer
Delete the pre-model and post-model regex content paths in `AgentRunner`:
- daily-ad explanation
- Talabat disambiguation
- `CurrentBusinessPolicy`
- requirements question
- `SimpleFaqReply`
- `GenericInstallmentReply`
- `DatabaseBackedReply`
- 250cc batch
- the "letter fee" and "government" reply overrides
- `EXACT_INSTALLMENT_REPLY`
- `WORK_TO_OFFER_DIRECT`
- `DIRECT_TOOL_RESULT`
- `continueWithoutRepeating`
- regex tool filtering

The model writes every reply. Laravel only checks it and executes tools.

Kept, because they decide nothing about content:
- ack/punctuation-only → no reply
- greeting reply (once per session, §3.4)
- awaiting-agent → no reply

The business facts those shortcuts carried move into one short **business facts** block:
- no letter fee
- Aman only, no sale ban
- first installment after N days
- warranty/licence confirmed at branch
- 100 EGP/day ad = 3000/month example
- Mylo/Valu not accepted
- Talabat = employer, not an order

The model answers from them in its own words in the same single call.

### 3.2 Deal state: one memory that does not forget
Add `conversation.state.deal`, written only by tools and read by `ConversationFacts`:

```
deal: {
  model: {id, name, set_by: tool, at},
  months: [24],
  wants_to_apply: yes|no|refused|null,
  link_sent_at,
  asked: {model|duration|work|insured|sector|age: {at_message_id, answered: bool}}
}
```

Writes:
- `model` is set when a pricing, details or images tool succeeds for exactly one motorcycle in a turn. That is the model's own reading of what the customer meant, not a regex.
- `months` is set by `get_installment_offer` args.
- `asked` is written by the outbound path. It classifies the bot's own question with the existing `ConversationIntent::questionKind`.
- `answered` flips when the matching fact appears (model, months, work, insured…).

The facts block shows `deal` first. The instructions say "known in deal = never ask". Selection resolution through `MotorcycleSelection` regex stays only as a fallback reader for old conversations.

### 3.3 Strict rules
- **Enforce mode is the code default and cannot be set to advisory for truth/rule guards.** The setting keeps only a "style guards advisory" meaning.
- **One owner-rules list** (`resources/agent/owner-rules.md`) carries short numbered rules, and the instructions include it once. Each rule maps to an enforcing tool or guard:
  - age 21–62; above 62 never, even with a guarantor
  - foreigners: no installments
  - women: no free-income route
  - pension always accepted
  - refused jobs apply to current work only; the job on the ID back decides the salary slip; برنت التأمينات is the only substitute
  - every required document is required
  - documents only on the form, never in chat
  - prices, rates and fees only from tools; rates from active DB systems
  - no promises nobody recorded
  - handoff only on a real trigger
  - no zero-upfront offer that does not exist
- **One instruction source:** the active DB version. A new version is built from the current text minus the appended policy blocks, merged and deduped, then published locally. `scripts/deploy-*.php` are retired; `policies/*.md` remain as history.
- Guard failure: up to 2 repairs, then a non-repeating safe reply. The safe reply never contains the violating sentence, and it varies by reason, not one canned line.

### 3.4 No repetition: one outbound gate
Add `OutboundGate`, called inside `DeliveryService` for every sender.

**Rules:**
- (a) drop a bot text whose normalized form equals or nearly equals (≥0.9 word overlap) any bot text sent since the customer's last incoming message
- (b) automated templates (follow-up, nudge, status) are sent at most once per template per conversation until the customer writes again
- (c) greeting at most once per 12 h

**Logging:** dropped messages are logged with a reason code. If everything in an AI reply is dropped and the customer asked something new, the runner gets `REPEATED_REPLY` and repairs before delivery. The gate is the last safety net.

**Form follow-up cap bug:** find why 7 went out with `followups_sent=1`, and fix it.

### 3.5 Tokens without losing intelligence
1. **A stable cacheable prefix.** The full tool list is always sent (only "has application" vs "no application" sets). Instructions and tools come first; dynamic facts come after. Bormaa/OpenAI requests get `prompt_cache_key` = bot id. Expected effect: cached share goes from ~65% to 85%+, and cached input costs ~10%.
2. **Compact tool declarations.** Rewrite descriptions to the minimum the model needs. Moves:
   - `record_work_profile` 4.8k → ≤1.5k chars
   - `send_reply` 2.4k → ≤0.8k
   - all tools 21k → ≤10k

   The runtime still validates the full schemas.
3. **Compact instructions:** the merged single version ≤ ~4.5k tokens, with no duplicated rules.
4. **Fewer calls per turn, without removing thinking:**
   - The facts carry the selected model's price and the last computed offer, so follow-ups ("طب على 3 سنين؟") need only the offer tool plus the reply. Many questions then need no tool at all.
   - The model may return data tools and `send_reply` together when the reply does not depend on the result (images + caption).
   - `max_model_calls` default 4 (was 2 on the server).
5. **Bormaa reasoning headroom:** completion budget = output + 1024 reasoning. You pay only for what is used, and it prevents truncated answers.

Expected: ~16.5k → ~10k tokens per call, a higher cached share, and a similar or lower number of calls. Net ≥40% cheaper per turn at equal or better intelligence. This is measured, not assumed, by comparing `context_manifest` before and after on the same simulator scenarios.

### 3.6 Tests and hygiene
- Bring the 44 failing tests to green: fix the code where a test encodes an owner rule, and update the test where the behaviour change was approved.
- Add regression tests for every verified failure above.
- Delete dead code (`$recent = []` loop) and unused shortcut classes.

## 4. Verification
- Full phpunit suite green, with config cleared.
- New regression tests:
  - outbound gate (AI, nudge, greeting)
  - deal state survives short answers ("الزد", "٣", "اللي بـ46")
  - no question for a known fact
  - relatives' jobs never refuse the customer
  - guards enforce
  - token manifest smaller
- A local simulator replay of the conversations above (no WhatsApp sends), comparing tokens and repeats before and after.

## 5. Risks
- Removing the shortcuts raises AI calls for trivial questions. Mitigation: the cacheable prefix plus compact prompts make each call much cheaper; ack-only messages stay at zero calls.
- Enforce mode can raise fallbacks if a guard is over-strict. Mitigation: guards that over-fire on real data get fixed, never silenced.
- The live DB on the server differs from local; deployment stays the owner's decision.


## 6. Revision after the critic review (supersedes §3 where they differ)

The critic checked every claim against the code. Accepted changes:

**Evidence:**
- The local DB is mostly simulator conversations; real server data could not be read in this session. Root causes are therefore proven in code and tests, not by local statistics.
- Conv 372's link resends were legitimate (he asked again).
- Conv 375 is a real code bug: the follow-up counter was saved after the send, and every attempt was a new turn.

**Delivery (done, test-first):**
- `dedupe_key` on automated turns reuses the same turn and outbound row, so the worker's `client_id` registry sends a retry at most once. Keys:
  - form follow-up per link+number
  - nudge per application+number
  - status notice per queued-job uuid
  - handoff wait per handoff+interval
  - form receipt per link+submission
- The exact-repeat net in `DeliveryService`: the same text (digits kept, so different numbers are never "the same") already sent or queued since his last incoming message is dropped. Staff (`agent`) text is never dropped. No near-duplicate threshold.
- §3.4 rules (b) and (c) are dropped.

**No new store (replaces §3.2):**
- Delete `continuityFacts` (a second, regex-derived "confirmed model" that contradicts the facts block).
- Stop reading `MotorcycleSelection` regex as truth.
- Selection comes from the existing tool-written stores that `ConversationFacts::deal` already reads.
- Facts and "told so far" count an outbound message only when it was delivered (`sent`). Failed or queued rows are not "told".

**Not asking again:**
- `send_reply` gets an `asks` array (enum: model, duration, work, insured, sector, age), filled by the model.
- Code rejects a reply whose `asks` contains something the facts already know, then repairs.

**Shortcuts (replaces §3.1 list), all removed as reply writers:**
- the early content paths (daily ad, Talabat, `CurrentBusinessPolicy`, requirements, social, FAQ, generic installment, database-backed, image identification, direct link, catalog photos, 250cc batch)
- the post-reply overrides (letter fee, government, `PaymentWording`, exact installment, `WORK_TO_OFFER_DIRECT`, `DIRECT_TOOL_RESULT`, handoff permission text)
- `continueWithoutRepeating`
- the برنت override, which broke "every required document is required"
- regex tool filtering

Kept as **guards that make the model repair**, never as writers:
- refused to apply → no work interview
- `FORM_LINK_ONLY` (no private data or documents in chat)
- the tool's `age_blocked` message
- stale rate percentages (the `PERCENT_DURATION_MISMATCH` guard)

`withApplicationLink` keeps only appending the real open link when the reply talks about the link. It never replaces the reply text.

**Occupations:**
- `record_work_profile` gets an `occupation_category` enum: `police_officer_or_amin`, `army_officer`, `lawyer`, `judge_or_prosecutor`, `civilian_in_police`, `other`.
- `WorkClassification` maps the enum to the rule instead of regex-matching his quote.
- `CurrentBusinessPolicy` is deleted.

**Fallbacks and extra calls:**
- Remove the `EvidenceReview` AI judge and the language AI review (extra calls; their failure produced one canned line).
- Keep the local banned-words language check.
- Fallback text rotates through `keepGoingReply` lines and is never the same line twice in a row.
- `customer_only` no longer disables the failure handoff: 3 failed turns in a row = quiet handoff. My call, since leaving a stuck customer with nobody contradicts the 90% self-service goal less than abandoning him.

**Guard mode:**
- Truth/rule guards always enforce.
- `reply_guard_mode=advisory` only affects style guards (`FORMAL_ARABIC`, `JOB_AS_TITLE`, `MASCULINE_ADDRESS_TO_WOMAN`, `KIND_NOT_ASKED`).

**Routing:** the Bormaa-only rule for replies applies only when a Bormaa model is configured. Otherwise every configured model may answer. Locally, with Gemini, every reply had been failing with "No permitted model".

**Tokens:**
- No `prompt_cache_key` and no bigger reasoning headroom (both unverified on Bormaa).
- Move the static forms/Talabat text in front of the per-customer facts.
- Drop the English continuity paragraph.
- Remove the two judge calls per reply.
- Compact tool descriptions (no schema change, except `asks` and `occupation_category`).
- The full tool list is always sent.
- The implicit plain-text final reply path stays.

**Verification additions:**
- an owner-rule matrix (age >62 with guarantor, foreigner, woman on free income, pensioner with a refused former job, ID-back job, relative's job, civilian in police)
- a superseded/failed-delivery "told" test
- a guard enforce test
- a repeat-ask test
