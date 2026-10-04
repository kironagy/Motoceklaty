# QA Fix Tasks — 2026-10-04

Scope: every issue from the E2E QA report plus the owner's new requests. Local code only, nothing pushed to GitHub.
Rules: Code > Memory > Prompt. No pile of if-conditions: decisions come from the AI readings (WorkClassifier, document reader) plus rules stored in the DB. One question should cost one model call wherever possible.

## A. Models and providers
- [x] A1. Documents read on **gpt-5-mini** (`agent.document_model`); the conversation stays on gpt-5-nano. Verify on the real documents (neon shop sign, work letter, Uber profile, ID back).
- [x] A2. Voice notes transcribed by **OpenAI transcription** (gpt-4o-mini-transcribe); Gemini only as a fallback. Test with a real Arabic voice recording.
- [x] A3. Reasoning effort by call type: work reading = low, documents = medium on mini, conversation = medium (done earlier; verify).

## B. Business rules in code
- [x] B1. **Card-only route** (عمل حر بالبطاقة) as the last resort: a customer who can't bring the papers his work needs gets offered applying as عامل حر with the ID only, under its own rules (financing cap, pricing). The bot offers it itself, without the customer asking for a label. Blocked for: women, people not working, government work, foreigners, age.
- [x] B2. Hard refusals before any numbers: woman on free income, not working, foreigner, government work, daily labour with no trade (توكتوك/قهوة/خردة, per the instructions). Applies even below the financing cap.
- [x] B3. Someone else applying (H): when the customer can't apply himself (not working, under 21, still going to work), another working person may apply wholly in his own name. Tool route + guard alignment.
- [x] B4. Stated income below the minimum (J): the work reading also reads the stated income/pension; the minimum rule from the DB is checked → he is told the condition before an application opens (the statement still decides).
- [x] B5. Structured state for the last quoted offer (months/system/down payment) → start_application / submit use it; no asking the duration again.
- [x] B6. Searching by name is not filtered by kind ("اللايفان ده" after a scooter list).
- [x] B7. Catalog data: Keeway brand stored as "Scooters" (triggered NOT_THE_MODEL_HE_NAMED 4×).
- [x] B8. Not eligible (age/other) = the snapshot's next_step says stop and offer cash: no more document requests, no "موديل تاني يناسب عمرك".
- [x] B9. Financing cap explanation without stating the internal 60,000 number.

## C. Staff takeover
- [x] C1. A staff message (dashboard or phone, sent or edited) pauses the bot on a sliding window (default 10 min from the last staff message). Customer messages during the pause are not lost: a turn is deferred to the end of the pause.
- [x] C2. Staff writes again → the window extends. Staff quiet past the window and the customer waiting → the bot continues after the staff (handed-off conversations too).

## D. Dashboard (installment_requests)
- [x] D1. Address split into governorate/area/street/branch street/building/floor/apartment/landmark for home and work, with a deterministic fallback when the AI split is unavailable.
- [x] D2. Workplace name (company/shop/café): collected with the work address and taken from documents (employer_name on a salary slip, business_name on a tax card/sign) → into the dashboard columns (free_work_name / work_address / notes).

## E. Documents
- [x] E1. Stale rejections in the snapshot (a later accepted copy of the same type clears them); missing_hints shortened to one line.
- [x] E2. Identity replacement reply: a clear line about which ID counts now, no "confirm the name" after an accepted replacement.
- [x] E3. Guard: offering a substitute ("أوصفلك بالكلام") for a required document.

## F. Conversation style and flow
- [x] F1. New compact instruction version (local DB): natural greeting, propose models instead of open questions, one question at most, no English/internal words, no repeated confirmations, polite refusal wording (no "للمسنين"), no guarantor, card-only fallback line, someone-else route.
- [x] F2. Submission flow: one confirmation only, the bot's line never contradicts the stored summary, and "اه/تمام" after the summary → submit.
- [x] F3. send_reply `awaiting.kind=field` schema mismatch; fallback text without emoji.

## G. Performance and consumption
- [x] G1. Documents processed **before** the model call when a turn brings photos to an open application (results go into the state) → one model round instead of 2-3 + a guard.
- [x] G2. Cosmetic guard issues (emoji, leaked internal keys) fixed locally instead of re-calling the model.
- [x] G3. Smaller snapshot/tool outputs (no full snapshot repeated in every tool result where not needed).
- [x] G4. Measure calls/turn, tokens/turn and latency before vs after.

## H. Verification
- [x] H1. Re-run every scenario (delivery, business owner, employee, A–M, identity, media, voice, staff takeover) on the simulator and check the DB.
- [x] H2. `php artisan test` (config:clear first) — no regressions.
- [x] H3. Restart pm2 whatsapp-worker; php -l on every touched file.
- [x] H4. Update memory notes; final report.

## Verification results (2026-10-04)
- Delivery (Youssef, real docs): submitted **#3667** in 8 turns. Plan Milo 18m with 3,000 down came from the quote (no repeated duration question); all 5 documents read before the model call; dashboard address split into 8 columns; free_work_name = Uber.
- Business owner (Ali, real docs on gpt-5-mini): ID front/back, tax card and the **neon shop sign** all accepted (after the careful re-read on mismatch).
- Employee (Salam): full name with the first-name line; the work letter (date misprinted 20246) accepted with 9,000 income.
- Card-only: insured employee without a salary slip → offer on free-work terms → `route=card_only` → type self_employed, 3,000 down, event `card_only_route`.
- Refusals with no numbers before them: girl on free income, pension 3,500 (STATED_INCOME_BELOW_MINIMUM), tuk-tuk by the day (DAILY_WORK_NOT_ACCEPTED), age 64 (not_eligible stops all paperwork); "صاحبي يقدم" accepted when the customer does not work.
- Voice: gpt-4o-mini-transcribe in 0.8s, $0.0007 per note.
- Staff takeover: the customer's message during the pause waits in a turn deferred to the end of the window; staff answering last = the bot stays quiet; the customer writing after = the bot carries on.
- Tests: 55 failing = exactly the same 55 on HEAD before these changes (stale tests); no regressions.
- Tokens/turn: 33.5k → 24.5k (−27%); turns needing a guard retry 24% → 19%; document turns ~13k (one model call).

## Round 2 (owner 2026-10-04): tuk-tuk + table 2 (bad responses)
- [x] R1. Tuk-tuk / day labour is accepted on the ID only (card-only on free-work terms) - no longer refused.
- [x] R2. Spelling and English words fixed locally without a model call (تقرّط، عايب، تشري، "تطبيق التقسيط"، front/back/mismatch/Driving license/upfront، "للمسنين"...).
- [x] R3. Earnings screenshots: a ready line saying which months arrived and which are missing.
- [x] R4. "مكنة" = motorcycle: a search does not stay on scooters only unless he asked for a scooter.
- [x] R5. Instructions: no unrequested information (المصريين بس), no adjectives added to documents (مختومة/حديثة), no "تطبيق", tool hints never passed to the customer as text, gender agreement, propose models straight away for delivery.
- [x] R6. Re-test the table 2 lines one by one (light) and check the reply quality.

### Round 2 results
- Tuk-tuk by the day: application opened, work_type other, documents = the ID only (no refusal, no delivery papers).
- Greeting: "وعليكم السلام ورحمة الله وبركاته يا باشا، بتدور على موتوسيكل ولا سكوتر؟". Delivery: proposes two motorcycles right away (no scooters).
- "المطلوب ايه؟" = "المطلوب: بطاقة الرقم القومي، ضهر بطاقة الرقم القومي، مفردات المرتب" (no data list, no "مختومة").
- Earnings: "وصلني أغسطس وسبتمبر، فاضل سكرين يوليو".
- "صغير شوية" = asks his age; his mother = "هي بتشتغل إيه؟" (nothing invented).
- Extra fixes found while testing: the ID number glued to the card number in OCR (per-line digit normalisation + the OCR's own 14 digits within 2 of the front's); "البوكسر" without a number was matched to the Pulsar (model-name check no longer needs a number); a tuk-tuk read as an app rider.
