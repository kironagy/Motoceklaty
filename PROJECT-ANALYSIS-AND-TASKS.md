# Project Analysis

> Scope note: this analysis focuses on the WhatsApp bot pipeline (Laravel ⇄ Node/Baileys ⇄ OCR ⇄ Gemini AI), because that is where every reported symptom (token cost, silent bot, session conflicts) lives. Unrelated Filament resources (Staff, Delivery, Notifications, etc. — visible as modified in `git status`) were **not** audited line-by-line; they are out of scope unless they touch the WhatsApp/AI/OCR path. No `database.sql` file exists in the repo root or anywhere in the tree — migrations under `database/migrations/` were used instead as the schema source of truth.

> **Status legend**: ✅ Done — verified working · 🟡 Partial — some subtasks done, rest open · ⬜ Not started · N/A — informational only, no action planned.
> This file is kept up to date as work happens — check back here instead of asking "what's left."

## Status at a Glance

| ID | Item | Status | Notes |
|---|---|---|---|
| B1 | Session creds in git | 🟡 Partial | `.gitignore` + `git rm --cached` **committed locally** (not pushed — your call). Credential rotation (new QR scan on your phone) is the only piece left; cannot be done remotely. |
| B2 | Duplicate/unsupervised worker | ✅ Done | Single-instance `flock()` lock added and **verified live** (second start attempt correctly refused). OS-level auto-restart supervisor still open. |
| B3 | `markRateLimited()` signature mismatch | ✅ Done | Signature fixed, cooldown now actually persists. No regression test added yet. |
| B4 | `BOT_TOKEN` via raw `env()` | ✅ Done | All 7 call sites moved to `config('services.whatsapp.bot_token')`. |
| B5 | Dead ChatGPT-era code | ✅ Done | Call-graph traced from the two live entry points; 39 unreachable private methods removed (2389 → 1588 lines). Verified: `php -l` clean, both entry points intact, no duplicate/dangling method names, no dynamic (`$this->{$var}()`) call patterns that could hide a real caller. |
| B6 | In-memory dedup lost on reconnect | ✅ Done | Node now sends `wa_message_id`; `whatsapp_messages` has a unique `(whatsapp_conversation_id, wa_message_id)` index + a pre-insert check in the controller. Verified live: DB constraint blocks a duplicate insert, `exists()` check confirmed. In-memory `handledMessages` Set left in place as a cheap first-layer guard. |
| B7 | No Node process supervision | ⬜ Not started | |
| B8 | Model config drift (suspected) | ⬜ Not started | |
| B9 | Oversized `LARAVEL_TIMEOUT` (suspected) | ⬜ Not started | |
| B10 | 12h duplicate-order window | N/A | Informational, not a bug. |
| §3 AI token findings #1–#10 | Token usage issues | ⬜ Not started | Diagnosed only, per original request. |
| §5 / T1.3 | Google Cloud Vision OCR | ⬜ Not started | Plan only, not to be implemented yet per instructions. |
| T1.0 | Baseline and Safety | 🟡 Partial | `.gitignore`/untrack committed; `.env.example`, correlation IDs, documented startup sequence, DB backup step still open. |
| T1.1 | Bugs and Reliability Fixes | 🟡 Partial | B2/B3/B4/B6 done; B7 (Node supervisor) and alerting hooks open; no automated tests added yet for any of the fixes. |
| T1.2 | AI Token Usage and Refactoring | ⬜ Not started | |
| T1.3 | Google Cloud Vision OCR | ⬜ Not started | |

## 1. Architecture Overview

```
WhatsApp (Baileys/Node, whatsapp-bot/index.js)
   │  messages.upsert → enqueueChat(per chatKey) → handleIncomingMessage()
   ▼
POST http://127.0.0.1:8000/api/whatsapp/incoming-message   (X-BOT-TOKEN header)
   │
Laravel: WhatsappBotController::incomingMessage()
   │  - stores incoming WhatsappMessage row
   │  - INSERT INTO whatsapp_message_jobs (status=pending)   ← custom DB-table queue, NOT Laravel's `jobs` table
   │  - returns { queued: true } immediately (no AI is run synchronously)
   ▼
php artisan whatsapp:process-jobs   (custom infinite while(true) worker, single process, polls whatsapp_message_jobs)
   │  claimNextJob() → WhatsappBotController::processQueuedWhatsappJob()
   ▼
App\Services\WhatsappIntentRouter::handle()
   │  - media present → MediaOcrHandler → PaddleOcrClient (HTTP to PADDLE_OCR_URL, separate OCR microservice)
   │  - else → AiIntentClassifier::classify() (Gemini call #1, JSON intent plan)
   │  - resolved by DB/heuristics where possible (price, images, installment calc)
   │  - unresolved → AiComplexReplyService::reply() (Gemini call #2, uses AiMemoryContextBuilder + AiPromptBuilder)
   │  - application flow → Handlers\ApplicationHandler (Gemini call, mode=application_data_extraction)
   ▼
GeminiClient::generateText() → GeminiKeyManager::reserveAvailableModel() (DB-backed multi-key/model rate limiter) → Gemini REST API
   ▼
ProcessWhatsappMessageJobs::sendWhatsappResult() → POST http://127.0.0.1:3010/send-message (Node)
   ▼
Node: sock.sendMessage() → WhatsApp
```

Key architectural facts:
- **Decoupled ingestion**: the Node bot never talks to Gemini directly; it only posts raw messages to Laravel and later receives a reply to push back over `/send-message` / `/send-media-items`. Laravel is the sole AI orchestrator.
- **Custom queue, not Laravel Queue**: `whatsapp_message_jobs` is a hand-rolled table + hand-rolled worker (`ProcessWhatsappMessageJobs`), independent of `QUEUE_CONNECTION=database` and Laravel's `jobs`/`failed_jobs` tables. Laravel's queue system exists in config but is not what drives WhatsApp processing.
- **OCR is a separate HTTP microservice** (`PaddleOcrClient` → `config('ocr.url')`, default `http://127.0.0.1:8100`), not called from Node and not calling Gemini — it's pure text extraction, independent of AI token cost.
- **Gemini access is multiplexed** across many stored API keys/models (`gemini_api_keys` / `gemini_api_key_models` tables) via `GeminiKeyManager`, which does DB-row-level reservation (`lockForUpdate`) for RPM/RPD/TPS budgets before every call.
- **WhatsApp session state** (Baileys multi-file auth) lives on disk under `whatsapp-bot/sessions/{botId}/` and is **tracked in git** (see Bug B1).

## 2. Bugs and Problems

### B1 — CRITICAL — WhatsApp session credentials committed to git, causing session conflicts and a credential leak
**Status: 🟡 Partial** — `.gitignore` (`/whatsapp-bot/sessions/`) and `git rm -r --cached whatsapp-bot/sessions` are **committed locally** (commits `8e2294e4`/`a3c3dc74`). **Not pushed** — per your instruction, nothing has been sent to GitHub. `git ls-files whatsapp-bot/sessions` returns empty, confirming the untrack worked. Still open, and cannot be done remotely: rotating every bot's session (fresh QR scan on your phone) — a file already exposed in git history isn't invalidated by untracking it, so the credentials already pushed in earlier commits should still be treated as compromised until rotated.
- **File**: `whatsapp-bot/sessions/69/creds.json` and all `whatsapp-bot/sessions/*` (tracked, confirmed via `git ls-files whatsapp-bot/sessions`); no `.gitignore` exists in the repo.
- **Exact problem**: Baileys' `creds.json` (the WhatsApp Web session/encryption keys) for ~50 bot IDs, including the live bot `69`, is committed to `github.com/monspace-2202/motocyklaty`.
- **Why it happens**: no `.gitignore` was ever added for `whatsapp-bot/sessions/`, so every `git add` picked the session files up.
- **User impact**: WhatsApp only allows **one live socket per session**. If this repo is cloned/deployed anywhere else (another server, another dev machine, CI) and that copy starts the bot (`AUTO_START_BOT_ID=69` in `.env`, or the "start" button in `WhatsappBotResource`), it opens a second socket using the *same* creds. WhatsApp kicks whichever socket is "not current" with `stream:error conflict/replaced` (matches the log the user posted verbatim: `"reasonNode":{"tag":"conflict","attrs":{"type":"replaced"}}`, `statusCode: 440`). The bot then reconnects, gets kicked again, and loops — during the loop `sessions[botId]` is deleted on every `close` ([index.js:420](whatsapp-bot/index.js:420)), so `/send-message` returns `session not found` and incoming events are missed. **This is the direct explanation for "the chat randomly stops replying."** It is also a live secret-leak: anyone with repo/GitHub access can hijack the bot's WhatsApp session.
- **Recommended fix**: add `whatsapp-bot/sessions/` to `.gitignore`, `git rm -r --cached whatsapp-bot/sessions`, then force a fresh QR-code login (rotate) for every bot — removing the file from a future commit does not invalidate a key already exposed in git history.
- **Validation method**: `git ls-files whatsapp-bot/sessions` returns empty after cleanup; confirm no other clone/server is running the same `bot_id` by checking `WA_CONNECTED_AT`/logs for unexpected `conflict` events after rotation.

### B2 — CRITICAL — Two independent `whatsapp:process-jobs` workers running with no process supervisor
**Status: ✅ Done (core fix) / 🟡 supervisor still open** — added an `flock()`-based single-instance lock in `handle()` ([ProcessWhatsappMessageJobs.php](app/Console/Commands/ProcessWhatsappMessageJobs.php)); killed the duplicate processes and restarted exactly one with the fix. **Verified live**: a second `php artisan whatsapp:process-jobs` now exits immediately with "Another whatsapp:process-jobs instance is already running." The lock auto-releases if the process crashes, so no stale-lock cleanup is needed. Still open: an OS-level supervisor (pm2/systemd) to auto-*restart* the worker if it dies — the lock only prevents duplicates, it doesn't bring a dead worker back.
- **File**: `app/Console/Commands/ProcessWhatsappMessageJobs.php` (whole file — it's a `while(true)` loop, not a Laravel queue job).
- **Exact problem**: `ps aux` on the current machine shows **two** live processes: `php artisan whatsapp:process-jobs --sleep=2` (pid 31463, started 7:19PM, and pid 34975, started 7:43PM). No `supervisord`/systemd unit/pm2 ecosystem file exists anywhere in the repo to manage this command.
- **Why it happens**: the command was started manually more than once (different terminal sessions) and never stopped; nothing prevents a duplicate instance from starting, and nothing restarts it if it dies.
- **User impact**: (a) reliability — if the *only* running instance crashes (uncaught fatal error, OOM, terminal closed, SSH session dropped) or is killed, **no WhatsApp reply is ever sent again** until a human notices and reruns the artisan command by hand — this independently explains "the bot stops responding" even without the session-conflict issue in B1. (b) throughput — this worker is strictly single-threaded (`while(true)` claiming one row at a time), so one slow Gemini/OCR call serializes every other customer's message behind it. (c) duplicate instances double the DB polling load for no throughput gain, since each instance still processes one row at a time (row locking via `lockForUpdate()` under MySQL correctly prevents double-processing of the same job, so this specific case is not a data-corruption risk given `DB_CONNECTION=mysql`, but it is still an unmanaged, undiagnosed process leak).
- **Recommended fix**: run exactly one supervised instance (systemd service / Supervisor / pm2) with `autorestart=true`, and add a startup guard (PID lock file, or `php artisan schedule` heartbeat) so a second manual start refuses to run while one is already alive. Consider migrating this into Laravel's real queue system with `--tries`, backoff, and multiple workers instead of a single hand-rolled loop.
- **Validation method**: `ps aux | grep whatsapp:process-jobs` shows exactly one process after fix; kill it and confirm the supervisor restarts it within seconds; confirm `whatsapp_message_jobs` rows move `pending → processing → done` without ever staying `processing` past the 10-minute stale-lock window ([ProcessWhatsappMessageJobs.php:103](app/Console/Commands/ProcessWhatsappMessageJobs.php:103)).

### B3 — CRITICAL — `GeminiKeyManager::markRateLimited()` signature does not match its caller (fatal error on every 429/quota response)
**Status: ✅ Done** — `markRateLimited()` now accepts `dailyLimit`/`cooldownSeconds`, matching the call in `GeminiClient`; a daily-limit hit routes to `markDailyLimitFinished()`, otherwise `cooldown_until` is set from `cooldownSeconds`. PHP-linted clean. Still open: the regression test described below (mocked 429 → assert `cooldown_until` updates) has not been written yet.
- **Files**: call site [GeminiClient.php:156-161](app/Services/GeminiClient.php:156), definition [GeminiKeyManager.php:180-187](app/Services/GeminiKeyManager.php:180).
- **Exact problem**: `GeminiClient` calls:
  ```php
  $manager->markRateLimited(
      model: $modelRow,
      error: $body,
      dailyLimit: $rateLimit['daily_limit'],
      cooldownSeconds: $rateLimit['cooldown_seconds']
  );
  ```
  but `GeminiKeyManager::markRateLimited(GeminiApiKeyModel $model, string $error = '...')` only accepts two parameters — there is no `$dailyLimit` or `$cooldownSeconds` parameter.
- **Why it happens**: the method was refactored (it used to accept those params, per the naming) but the signature was trimmed down without updating the call site — or vice versa.
- **User impact**: in PHP 8, calling a method with an unknown named argument throws `Error: Unknown named parameter $dailyLimit`. This happens **every single time Gemini returns 429/quota-exceeded**. The `Error` is a `\Throwable`, so it's caught by `GeminiClient`'s outer `catch (\Throwable $e)` block ([GeminiClient.php:232](app/Services/GeminiClient.php:232)), logged as `"Gemini request exception"`, and treated as a *transient* failure (`transientFailures++`) instead of a proper rate-limit. Critically, **the exhausted key/model is never actually put into cooldown** (the `$model->update([...cooldown_until...])` inside `markRateLimited` never runs because the call throws before entering the method body). The result: the same exhausted key gets re-selected on the very next request, fails again with 429, throws again, burns through `maxTransientFailovers` (2), and returns a hard failure to the user faster than intended — wasting requests against an already-known-exhausted key instead of properly rotating away from it for the cooldown window.
- **Recommended fix**: align the method signature with the call site (add `int $dailyLimit = 0, int $cooldownSeconds = 60` params and use them to set `cooldown_until`/`requests_today`), or simplify the call site to match the existing 2-arg method — pick one and add a regression test that asserts `cooldown_until` is actually set after a simulated 429.
- **Validation method**: unit test that mocks a 429 HTTP response through `GeminiClient::generateText()` and asserts (1) no `\Throwable` escapes, (2) `gemini_api_key_models.cooldown_until` is updated on the affected row.

### B4 — HIGH — `BOT_TOKEN` read via raw `env()` at runtime instead of `config()`
**Status: ✅ Done** — added `config('services.whatsapp.bot_token')` and replaced all 7 raw `env('BOT_TOKEN')` call sites (controller, worker command, Filament resource) with it. Confirmed `BOT_TOKEN` is set in `.env` and all touched files pass `php -l`.
- **Files**: [WhatsappBotController.php:31](app/Http/Controllers/Api/WhatsappBotController.php:31), [ProcessWhatsappMessageJobs.php:184,217](app/Console/Commands/ProcessWhatsappMessageJobs.php:184), [WhatsappBotResource.php:193,278,312,347](app/Filament/Resources/WhatsappBotResource.php:193).
- **Exact problem**: `env('BOT_TOKEN')` is called directly in controller/command/Filament code, not through a `config/*.php` value.
- **Why it happens**: no `config('services.bot_token')` entry was ever added for this token; developers reached for `env()` directly.
- **User impact**: once `php artisan config:cache` is run (standard for any production deploy), Laravel's documented behavior is that `env()` calls outside of `config/*.php` files return `null`. That would make `$request->header('X-BOT-TOKEN') !== env('BOT_TOKEN')` compare against `null` — either breaking all inbound WhatsApp webhook auth (locking the bot out) or, if `X-BOT-TOKEN` header is ever also empty/null from a caller, silently authenticating a request that shouldn't be. Either failure mode is severe for a webhook endpoint.
- **Recommended fix**: add `'bot_token' => env('BOT_TOKEN')` to `config/services.php` (or a dedicated `config/whatsapp.php`) and replace every raw `env('BOT_TOKEN')` call with `config('services.bot_token')`.
- **Validation method**: run `php artisan config:cache` locally, then hit `/api/whatsapp/incoming-message` with the correct `X-BOT-TOKEN` and confirm it's still accepted (currently it would likely fail after caching — reproduce before fixing to confirm).

### B5 — HIGH — Large amount of dead/legacy "ChatGPT era" code still live inside `WhatsappBotController`
**Status: ✅ Done** — computed exact reachability from the two live entry points (`incomingMessage`, `processQueuedWhatsappJob`) with a script that maps every `$this->method(` call to its enclosing method; 39 private methods (including `askChatGPTDirectly`, `buildOrderDataFromConversation`, `imagesResponse`, and everything only they called) had zero callers and were removed — file went from 2389 → 1588 lines. `isOrderConfirmationMessage`'s branch (the one still-active piece of the legacy order flow, per the original note below) was confirmed reachable and kept untouched. Verified: `php -l` clean, both entry points and all previously-live methods still present with no duplicate names, and a grep for dynamic call patterns (`$this->{$var}(`, `call_user_func`, `[$this, ...]`) found none — so no caller could have been missed by the static analysis. No test suite exists for this controller, so this was verified by static analysis only, not a test run.
- **File**: `app/Http/Controllers/Api/WhatsappBotController.php` (2389 lines total; only read through line ~1712 in this pass) — now 1588 lines after cleanup.
- **Exact problem**: the file contains a full parallel implementation — `askChatGPTDirectly()`, `aiMemoryPrompt()`, `freshOrderPrompt()`, `buildOrderDataFromConversation()`, `extractOrderJson()`, `createInstallmentRequestFromBot()`, plus extensive Arabic text/address/name extraction helpers — alongside an explicit code comment: *"من هنا خلاص مفيش ChatGPT... كل الردود من Gemini Intent Router + Database"* ("from here on, no more ChatGPT — all replies come from the Gemini Intent Router + Database", [WhatsappBotController.php:217-220](app/Http/Controllers/Api/WhatsappBotController.php:217)).
- **Why it happens**: the system was migrated from a ChatGPT-worker-based flow to the current `WhatsappIntentRouter` + Gemini flow, but the old code path was not removed — only the *dispatch* comment marks the cutover point in one function (`processQueuedWhatsappJob`).
- **User impact**: this is not confirmed to run in the current flow (needs a caller-graph check across the full 2389 lines before deleting anything), but it is a major duplicated-logic/maintenance risk: two independent implementations of "extract order data from conversation text" and "build AI memory prompt" exist (`AiMemoryContextBuilder` vs. `aiMemoryPrompt()`), which is exactly the kind of place where a future fix gets applied to the wrong copy.
- **Recommended fix**: before deleting anything, grep for real callers of `askChatGPTDirectly`, `buildOrderDataFromConversation`, `createInstallmentRequestFromBot`, `isOrderConfirmationMessage` (the last one **is** still called, see [WhatsappBotController.php:183](app/Http/Controllers/Api/WhatsappBotController.php:183) inside `processQueuedWhatsappJob` — so this legacy path is only *partially* dead, the order-confirmation branch is still active). Map exactly which legacy functions are reachable vs. orphaned, then remove the orphaned ones and consolidate the still-active order-confirmation logic into the current `AiIntentClassifier`/`WhatsappIntentRouter` flow.
- **Validation method**: static call-graph search (`grep -rn "askChatGPTDirectly\|buildOrderDataFromConversation\|createInstallmentRequestFromBot"`) plus integration test coverage for the still-active `isOrderConfirmationMessage` path before touching it.

### B6 — MEDIUM — Node `handledMessages` in-memory dedup set is lost on every reconnect
**Status: ✅ Done** — added a persisted second layer: Node now sends `wa_message_id` (the Baileys `msg.key.id`, prefixed with `botId`) on both the text and batched-media payloads; a new migration adds a unique index on `whatsapp_messages(whatsapp_conversation_id, wa_message_id)`, and `WhatsappBotController::incomingMessage()` checks for an existing row with that id before creating anything, returning `{ok:true, queued:false, duplicate:true}` instead of re-queuing. Verified live via `php artisan tinker`: a duplicate insert at the DB level throws `UniqueConstraintViolationException`, and the `exists()` check the controller relies on correctly returns `true`. The original in-memory `handledMessages` Set in Node is left in place as a cheap first-layer guard (harmless, unchanged).
- **File**: [whatsapp-bot/index.js:23,292-298](whatsapp-bot/index.js:23).
- **Exact problem**: `handledMessages` is a plain in-process `Set`, capped at 5000 and cleared wholesale when it overflows. It is the only guard against re-processing the same WhatsApp message twice.
- **Why it happens**: it's process memory, not persisted.
- **User impact**: combined with B1/B2 (frequent reconnects), every reconnect cycle starts with an empty dedup set (new process or same process after `startSession()` retry keeps the set, but a full process restart — e.g., a crash-restart by a future supervisor fix for B2 — wipes it). If WhatsApp resends the same offline message after reconnect, it can be posted to Laravel again, generating a duplicate AI call and possibly a duplicate reply to the customer.
- **Recommended fix**: dedupe using the persisted `whatsapp_message_jobs`/`whatsapp_messages` table (e.g., unique constraint on `(whatsapp_bot_id, wa_message_id)`) instead of/in addition to the in-memory set.
- **Validation method**: force a reconnect while offline messages are pending and confirm no duplicate `whatsapp_messages` rows or duplicate outbound replies.

### B7 — MEDIUM — No process supervision for the Node WhatsApp worker either
**Status: ⬜ Not started**
- **File**: `whatsapp-bot/index.js` (whole process); no pm2/systemd unit found in the repo.
- **Exact problem**: same class of issue as B2 but for the Node side — a single `node index.js` process with no auto-restart wrapper.
- **User impact**: any uncaught exception outside the `try/catch` blocks already present (e.g., inside `express` middleware, or a Node crash from an unhandled promise rejection) stops the whole WhatsApp connection with nothing to bring it back up.
- **Recommended fix**: run under pm2 (`pm2 start index.js --name whatsapp-bot -i 1`) or systemd with `Restart=always`.
- **Validation method**: `kill -9` the node process and confirm it's back within a few seconds under the supervisor.

### B8 — LOW / SUSPECTED — `config/gemini.php` seed model list doesn't include the hard-coded default model used at runtime
**Status: ⬜ Not started**
- **Files**: [config/gemini.php:18-52](config/gemini.php:18) (`default_models` lists `gemini-1.5-flash`, `gemini-1.5-flash-8b`, `text-embedding-004`) vs. [GeminiClient.php:10](app/Services/GeminiClient.php:10) and [AiComplexReplyService.php:23](app/Services/AiComplexReplyService.php:23) which both hard-code `'gemini-3.1-flash-lite'` as the preferred model.
- **Why it matters**: if a fresh environment is ever seeded purely from this config's `default_models`, the actual model the code asks for (`gemini-3.1-flash-lite`) won't exist in `gemini_api_key_models`, and `reserveAvailableModel()` will simply return `null` for every request. This is **suspected**, not confirmed, because the live DB may already have the correct rows created out-of-band through Filament (`GeminiApiKeyResource`) rather than from this config seed.
- **Recommended fix**: reconcile the config seed list with the model codes actually referenced in code, or centralize the "current preferred model" as a config value instead of a literal string repeated in two services.
- **Validation method**: `SELECT model_code FROM gemini_api_key_models WHERE is_active = 1` and confirm `gemini-3.1-flash-lite` is present.

### B9 — LOW / SUSPECTED — `LARAVEL_TIMEOUT=700000` (≈11.6 minutes) in `.env`
**Status: ⬜ Not started**
- **File**: `.env` (`LARAVEL_TIMEOUT=700000`), consumed in `whatsapp-bot/index.js:28` for the axios call from Node → Laravel webhook.
- **Why it matters**: since the webhook now responds immediately after queuing the job (it doesn't wait for AI), this timeout should rarely matter — but a value this large looks like a leftover from a previous synchronous-AI design (before `whatsapp_message_jobs` existed) and, if a code path ever regresses to synchronous processing, would let a single request block a Node HTTP client slot for nearly 12 minutes.
- **Recommended fix**: lower to a few seconds (enough for a DB insert) now that the flow is fully asynchronous, and confirm no code path still awaits AI synchronously before responding.
- **Validation method**: time the `/api/whatsapp/incoming-message` response under normal load; it should return in well under 1 second.

### B10 — LOW — Duplicate-order guard window is time-boxed, not state-boxed, for the machine+phone check
**Status: N/A** — informational only, not planned as an action item.
- **File**: [WhatsappBotController.php:1160-1177](app/Http/Controllers/Api/WhatsappBotController.php:1160).
- **Exact problem**: `InstallmentRequest::where('applicant_phone', ...)->where('machine_id', ...)->whereIn('status', [...])->where('created_at', '>=', now()->subHours(12))` — a legitimate second request for the same machine after 12 hours (e.g., customer changed their mind and came back) is allowed, which is probably intended, but note it's a business-logic assumption, not a verified bug. Listed here for completeness/awareness, not as an action item.

## 3. AI Token Usage Analysis

[AI_TOKEN_USAGE_ISSUES.md](AI_TOKEN_USAGE_ISSUES.md) was produced from direct code reads earlier in this engagement and is **re-verified here against the current file contents** — all six findings still hold with the exact line numbers cited. Summary cross-check:

| Original finding | File:line verified | Status |
|---|---|---|
| #1 Duplicate AI calls per message (classify + fallback, classify + application extraction) | [WhatsappIntentRouter.php:37](app/Services/WhatsappIntentRouter.php:37), [:254](app/Services/WhatsappIntentRouter.php:254), [ApplicationHandler.php:32](app/Services/Handlers/ApplicationHandler.php:32) | Confirmed |
| #2 Full unfiltered memory dump on every fallback reply | [AiMemoryContextBuilder.php:37-50](app/Services/AiMemoryContextBuilder.php:37) | Confirmed |
| #3 OCR text repeated in up to 20 subsequent classify() prompts | [AiIntentClassifier.php:13-24](app/Services/AiIntentClassifier.php:13), [MediaOcrHandler.php:98-140](app/Services/Handlers/MediaOcrHandler.php:98) | Confirmed |
| #4 No fast-path before AI classify for trivial messages | [AiIntentClassifier.php:11-59](app/Services/AiIntentClassifier.php:11) | Confirmed |
| #5 Token estimate = `mb_strlen($prompt)`, not real tokenization | [GeminiClient.php:24](app/Services/GeminiClient.php:24) | Confirmed |
| #6 No truncation cap on message/payload/memory injected into prompts | `AiPromptBuilder.php`, `AiIntentClassifier.php` (no `mb_substr`/limit calls found) | Confirmed |

### Additional findings not in the original file

- **#7 Duplicate AI calls amplified by B3.** Because `markRateLimited()` throws (B3) instead of properly cooling down an exhausted key, `GeminiClient`'s retry loop burns through `maxTransientFailovers` (2 by default, [config/gemini.php:174](config/gemini.php:174)) re-hitting the *same* exhausted key/model before giving up — each retry is a wasted network round trip (not extra Gemini tokens billed, since 429 responses are typically free, but it is wasted latency and log noise, and it starves other keys of a chance to be selected during that request).
- **#8 No token/cost metrics captured anywhere.** `GeminiClient::generateText()` receives `usageMetadata.totalTokenCount` from the Gemini response ([GeminiClient.php:95-99](app/Services/GeminiClient.php:95)) and immediately discards it except to feed `markUsed()`, which doesn't persist it either ([GeminiKeyManager.php:119-126](app/Services/GeminiKeyManager.php:119) — `markUsed()` only updates `last_used_at`/`last_error`, the real token count is never written anywhere). There is no per-request, per-day, or per-conversation token/cost log — this is why "which part is eating tokens" could only be answered by reading code, not by querying data. This is the root gap behind the user's original question.
- **#9 `AiIntentClassifier::classify()` fetches `Machine::with('brand')` fresh on every call** ([AiIntentClassifier.php:209-224](app/Services/AiIntentClassifier.php:209)) for `lastMachines()` — not a token issue per se, but it means every single incoming message (even ones a fast-path would resolve) pays a DB round trip before any AI-cost decision is even made.
- **#10 `AiMemoryContextBuilder` cache key is global, not per-tenant/bot.** `Cache::remember('ai_full_memory_context', ...)` ([AiMemoryContextBuilder.php:25](app/Services/AiMemoryContextBuilder.php:25)) is a single cache key shared across all bots/conversations. Not a token-cost bug by itself (it's the same memory content for everyone today), but it means there is no way to scope memories per bot without a code change, and it's worth flagging alongside the "no filtering" issue (#2) since both point at the same root cause: memory context is all-or-nothing.

## 4. Refactoring Plan

Ordered to fix root causes first (things actively breaking the bot), then cost, then structural cleanup — while preserving current behavior (no prompt/behavior changes bundled with reliability fixes).

1. **Stop the bleeding (session + process reliability)**: remove session files from git and rotate all bot credentials (B1); put exactly one supervised `whatsapp:process-jobs` worker and one supervised `node index.js` behind a process manager (B2, B7); kill the duplicate worker found running today.
2. **Fix the two runtime bugs**: `markRateLimited()` signature (B3); `BOT_TOKEN` via `config()` (B4).
3. **Add observability before touching AI logic** (see §7) — token usage per request/day, correlation IDs — so every subsequent AI change can be measured instead of guessed at.
4. **AI token reduction, in order of impact** (mirrors the priority table in `AI_TOKEN_USAGE_ISSUES.md`):
   a. Stop resending raw `payload` (OCR text) in `AiIntentClassifier::classify()`'s `$recent` messages — send a short summary/flag instead of the full OCR blob.
   b. Add a deterministic fast-path in `WhatsappIntentRouter::handle()` that runs the existing heuristics (`isPureFollowUp`, `isInstallmentSystemIntent`, `isApplicationIntent`) **before** calling `AiIntentClassifier::classify()`, only falling through to AI when heuristics are inconclusive.
   c. Cap `AiMemoryContextBuilder` output length and/or filter memories by relevance instead of dumping all active rows.
   d. Add `mb_substr` caps on `$message`, `$m->payload` text, and `$memoryContext` before prompt interpolation.
   e. Replace `mb_strlen($prompt)` with a real tokenizer-based (or Gemini-documented ratio-based) estimate in `GeminiClient::generateText()`.
   f. Re-evaluate whether `classify()` + `AiComplexReplyService` truly need to be two separate calls, or whether the fallback reply can reuse the classify() call's output.
5. **Dead-code cleanup**: map and remove orphaned legacy ChatGPT-era functions in `WhatsappBotController` (B5), after confirming zero live callers.
6. **OCR provider abstraction** (see §5) — independent of the above, can be done in parallel once T1.0/T1.1 are stable.

## 5. Google Cloud Vision OCR Plan

Current implementation: `PaddleOcrClient` ([app/Services/Ocr/PaddleOcrClient.php](app/Services/Ocr/PaddleOcrClient.php)) is a thin HTTP client that multipart-uploads a file to a self-hosted PaddleOCR microservice (`config('ocr.url')`, default `http://127.0.0.1:8100/v1/ocr`) with `language=ar`, and expects back `{ ok, text, lines, pages, average_confidence, document, display_text, engine }`. It's called only from `MediaOcrHandler::handle()` ([app/Services/Handlers/MediaOcrHandler.php:46](app/Services/Handlers/MediaOcrHandler.php:46)). This is a clean seam: **one call site, one interface-shaped return value** — good conditions for a provider abstraction.

### Required Laravel changes
- Introduce `App\Services\Ocr\OcrProviderInterface` with a single method `recognize(string $absolutePath, ?string $mime): array` returning the same shape `PaddleOcrClient` already returns (so `MediaOcrHandler` needs zero changes).
- Add `App\Services\Ocr\GoogleVisionOcrClient implements OcrProviderInterface` — normalizes Google Cloud Vision's `DOCUMENT_TEXT_DETECTION` response into the same `{ok, text, lines, pages, average_confidence, document, display_text, engine}` shape.
- Make `PaddleOcrClient` also implement `OcrProviderInterface` (rename nothing, just add `implements`).
- Bind the interface in a service provider based on `config('ocr.provider')`, and inject `OcrProviderInterface` into `MediaOcrHandler` instead of concretely resolving `app(PaddleOcrClient::class)`.
- Add an `App\Services\Ocr\FallbackOcrClient implements OcrProviderInterface` that wraps a primary + secondary provider: try primary, on `ok=false` or exception, retry with secondary, tag the result with which engine actually answered.

### Required OCR service changes
- None to the existing PaddleOCR microservice — it keeps running as the optional fallback/self-hosted path.
- New: a Google Cloud project with the Vision API enabled, and a service-account JSON key (or Workload Identity if deployed on GCP) — no new microservice process needed, since Google's PHP client (`google/cloud-vision`) or a plain REST call can be used directly from Laravel.

### Configuration and environment variables
Add to `config/ocr.php`:
```php
'provider' => env('OCR_PROVIDER', 'paddle'),           // 'paddle' | 'google_vision' | 'fallback'
'fallback_order' => ['google_vision', 'paddle'],         // used only when provider = 'fallback'

'google_vision' => [
    'enabled' => (bool) env('GOOGLE_VISION_ENABLED', false),
    'credentials_path' => env('GOOGLE_VISION_CREDENTIALS', storage_path('app/google-vision-credentials.json')),
    'project_id' => env('GOOGLE_VISION_PROJECT_ID', ''),
    'timeout' => (int) env('GOOGLE_VISION_TIMEOUT', 30),
    'max_file_size_kb' => (int) env('GOOGLE_VISION_MAX_FILE_SIZE_KB', 20480), // Vision API limit is 20MB per image
    'language_hints' => ['ar', 'en'],
],
```
New `.env` keys: `OCR_PROVIDER`, `GOOGLE_VISION_ENABLED`, `GOOGLE_VISION_CREDENTIALS`, `GOOGLE_VISION_PROJECT_ID`, `GOOGLE_VISION_TIMEOUT`, `GOOGLE_VISION_MAX_FILE_SIZE_KB`.

### Authentication requirements
- A GCP service account with role `roles/cloudvision.imageAnnotator` (least privilege — no broader "Editor" role).
- The service-account JSON key **must never be committed to git** — store outside the repo or in `storage/app/` with that path added to `.gitignore` (directly relevant given B1's lesson about committed secrets). Prefer loading via `GOOGLE_APPLICATION_CREDENTIALS` env var pointing at a path injected by the deploy process/secret manager, not a file checked into the repo.

### Supported image and PDF formats
- Vision API `DOCUMENT_TEXT_DETECTION` supports JPEG, PNG, GIF, BMP, WEBP, RAW, ICO, PDF, and TIFF (PDF/TIFF go through the async `files.asyncBatchAnnotate` or `documents.annotate` batch endpoint, not the simple sync endpoint used for images — this is a real implementation branch, not just config).
- Current `config('ocr.allowed_mimes')` already covers `image/jpeg, image/png, image/webp, image/bmp, image/tiff, application/pdf` — compatible, no changes needed to the allow-list, but the client implementation must route PDF/TIFF to the batch API and images to the sync `images:annotate` API.

### Arabic text handling
- Set `imageContext.languageHints = ['ar']` (or `['ar', 'en']` for mixed documents) per request — Vision's OCR auto-detects but hints materially improve Arabic accuracy, matching PaddleOCR's explicit `language: 'ar'` parameter today.
- Vision returns text in visual (not always logical) reading order for RTL scripts in some layouts — Egyptian ID cards and mixed Arabic/number documents should be spot-tested against the current PaddleOCR output before cutover, since `national_id`/name extraction downstream (`AiIntentClassifier::extractApplicationData`) is regex/AI-parsed from this text.

### Timeout and retry strategy
- Sync image calls: `timeout` ~15-30s (mirrors current `PADDLE_OCR_TIMEOUT=90` but Vision is typically faster); on timeout, retry once, then fall back per the fallback strategy below.
- Batch (PDF/TIFF) calls: these are asynchronous and write results to a GCS bucket — requires a poll-until-done loop with a max wait (e.g. 60s) and a distinct timeout path; do not block the single-threaded `whatsapp:process-jobs` worker (see B2) on a long poll — this is a strong argument for fixing B2 (or moving OCR calls to Laravel's real queue) before adding a slower batch-mode provider.
- Retries: exponential backoff, max 2 retries, only on transient errors (5xx, timeout) — never retry on 4xx (bad image, auth failure).

### Fallback strategy
- Default `OCR_PROVIDER=paddle` initially (no behavior change).
- Roll out as `OCR_PROVIDER=fallback` with `fallback_order=['google_vision','paddle']` for a canary period — Vision tried first, PaddleOCR as safety net if Vision fails/is disabled/quota-exhausted.
- Full cutover: `OCR_PROVIDER=google_vision` once accuracy/latency are validated; keep PaddleOCR service running (don't decommission) so `fallback` can be re-enabled instantly if Vision has an outage.

### Cost and token implications
- Google Cloud Vision `DOCUMENT_TEXT_DETECTION` is billed per image (first 1000 units/month free, then per-1000-unit tiers) — this is **unrelated to Gemini token cost**, a separate GCP bill. It does not affect the AI token issues in §3, since OCR output text still flows into the same `AiIntentClassifier::classify()` payload — so §3 finding #3 (repeated OCR payload in classify() history) applies **regardless of which OCR engine produced the text** and should be fixed independently of this migration.
- Add a per-bot/day OCR call counter (mirrors the token-metrics gap in §3 finding #8) so OCR spend is visible from day one, unlike the current Gemini token blind spot.

### Security concerns
- Service-account key handling as above (never in git, restrict IAM role).
- Uploaded documents (national IDs, tax cards) leaving the self-hosted PaddleOCR box and going to a third-party (Google) API is a data-residency/privacy consideration the business owner should explicitly sign off on before enabling — flagged here, not decided.
- Enforce `max_file_size_kb` before upload (Vision sync API caps at 20MB) to avoid sending oversized files and wasting quota on requests that will fail anyway.

### Tests required
- Unit: `GoogleVisionOcrClient` response-normalization test using a recorded/mocked Vision API JSON response → asserts it matches `OcrProviderInterface` contract shape.
- Unit: `FallbackOcrClient` test asserting it calls the secondary provider only when the primary returns `ok=false` or throws.
- Integration (sandboxed/mocked HTTP): full `MediaOcrHandler::handle()` flow with `OCR_PROVIDER=google_vision`.
- Regression: replay a handful of real historical documents (IDs, tax cards) through both PaddleOCR and Vision and diff extracted `national_id`/name fields to catch accuracy regressions before full cutover.

### Rollback plan
- Since provider selection is a single config value (`OCR_PROVIDER`), rollback is: set `OCR_PROVIDER=paddle` (or `fallback`) and deploy — no code rollback needed if the interface abstraction is in place first. Keep the PaddleOCR microservice running throughout the migration window specifically to make this rollback instant.

## 6. Testing Strategy

- **Unit**: `AiPromptBuilder` (prompt assembly given fixed inputs), `AiMemoryContextBuilder` (Toon formatting, cache behavior), `GeminiClient` (HTTP mocked via `Http::fake()` — cover success, 401, 403, 404, 429, 5xx, and the B3 rate-limit-signature regression), `GeminiKeyManager::reserveAvailableModel` (RPM/RPD/TPS boundary conditions), OCR provider normalization (per §5).
- **Feature**: `POST /api/whatsapp/incoming-message` — auth header rejection/acceptance (covers B4 after fix), job row creation, immediate `{queued:true}` response without waiting on AI.
- **Integration**: full pipeline with `Http::fake()` for Gemini + OCR HTTP calls — incoming message → job row → `ProcessWhatsappMessageJobs`-equivalent processing → outbound `send-message` call payload assertion.
- **Queue/worker**: `whatsapp_message_jobs` claim/lock semantics under simulated concurrent claim attempts (two workers claiming from the same table) — regression test for B2's "two workers" scenario to prove row-locking prevents double-send even before the supervisor fix lands.
- **WhatsApp (Node)**: cannot unit test Baileys itself meaningfully; instead add a smoke script that starts `index.js` against a mocked/sandboxed Baileys socket (or, pragmatically, a manual QR-based staging bot) and asserts `/status` reports `connected` and `handledMessages` dedup works across a simulated duplicate `messages.upsert` event.
- **OCR**: contract test asserting both `PaddleOcrClient` and `GoogleVisionOcrClient` satisfy `OcrProviderInterface` with the same shape for a success and a failure case.
- **AI regression**: golden-file tests — freeze a set of real (anonymized) conversation histories + expected `intent`/`target` JSON from `AiIntentClassifier`, run them after every prompt/heuristic change in §4 to catch behavior drift, since the goal is "preserve current behavior."
- **Load/serialization**: single-worker throughput test for `whatsapp:process-jobs` — measure messages/minute today (pre-fix) as a baseline before any queue-architecture change.

## 7. Observability and Debugging

Currently: `Log::error`/`Log::warning`/`Log::info` calls exist ad hoc throughout (`GeminiClient`, `AiIntentClassifier`, `WhatsappBotController`) but there is **no correlation ID** tying one customer message → its job row → its Gemini call(s) → its OCR call → its outbound WhatsApp send. Recommended additions:

- **Correlation ID**: generate a UUID at `WhatsappBotController::incomingMessage()` (or reuse `whatsapp_message_jobs.id`), store it on the job row, and pass it through `WhatsappIntentRouter → AiIntentClassifier/AiComplexReplyService/MediaOcrHandler → GeminiClient` as a log context field (`Log::withContext(['request_id' => ...])`) so every log line for one customer message can be grepped together.
- **Job IDs**: already exist (`whatsapp_message_jobs.id`) — just need to be included in every log line during processing (`ProcessWhatsappMessageJobs` already logs `job.id` in some places, e.g. [ProcessWhatsappMessageJobs.php:194](app/Console/Commands/ProcessWhatsappMessageJobs.php:194), but not consistently in `WhatsappIntentRouter`/`AiIntentClassifier`).
- **AI request IDs + token metrics**: persist a row per Gemini call (new table, e.g. `ai_requests`: `job_id`, `model_code`, `key_id`, `prompt_chars`, `usage_total_tokens` from `usageMetadata.totalTokenCount` — currently discarded, see §3 finding #8 — `latency_ms`, `status`, `error`). This single table answers "where did tokens go" going forward without re-reading source code.
- **OCR metrics**: same pattern — `ocr_requests` table or reuse `ai_requests` with a `provider` column covering both Gemini and OCR calls, so §5's per-bot/day OCR volume is visible.
- **Response timing**: wrap `GeminiClient::generateText()` and `PaddleOcrClient::recognize()` calls with a timer, log/store `latency_ms`.
- **Retry logs**: log every failover inside `GeminiClient`'s while-loop (key excluded, reason, attempt number) at `info` level — today only the final outcome and per-branch warnings are logged, not a clean per-attempt trail.
- **Failure alerts**: `GeminiAlertService` already exists for "all keys exhausted" — extend the same alert channel to also fire when: (a) `whatsapp:process-jobs` has zero `done` transitions in N minutes while `pending` rows exist (detects B2's failure mode), (b) a WhatsApp session hits `conflict/replaced` more than once in a short window (detects B1's failure mode), (c) `markRateLimited`/`markError` throws (detects B3-class bugs proactively instead of relying on generic exception logs).

---

# Tasks

## T1.0 - Baseline and Safety
**Status: 🟡 Partial** — subtask 2 (`.gitignore`) done, subtask 3 mostly done (untrack committed locally, not pushed; rotation still needs your phone). Subtasks 1 (`.env.example`), 4 (documented startup sequence), 5 (correlation IDs), 6 (backup/rollback step) not started.

**Objective**: establish a reproducible, observable, safely-rollback-able starting point before any behavior-changing work begins.

**Current evidence**: no `.env.example`, no supervisor config, no correlation IDs, secrets committed to git (B1), two untracked worker processes already running (B2) — there is currently no clean "known good" baseline to diff against.

**Files likely affected**: `.env.example` (new), `.gitignore` (new/updated), `whatsapp-bot/sessions/` (git history), process-manager config (new: systemd/pm2/supervisor), `app/Providers/AppServiceProvider.php` or a new logging service provider (correlation ID middleware).

**Ordered subtasks**:
1. Inventory every env var actually read (`grep -rn "env(" app/ whatsapp-bot/index.js config/`) and produce `.env.example` with placeholders (no real values).
2. Add `.gitignore` covering `whatsapp-bot/sessions/`, `vendor/`, `node_modules/`, `storage/`, `.env`.
3. `git rm -r --cached whatsapp-bot/sessions` and commit; rotate every bot's WhatsApp session (fresh QR login) — coordinate downtime with the business owner since every bot goes offline until re-scanned.
4. Document exact startup sequence: `php artisan serve` (or real web server), `php artisan whatsapp:process-jobs`, `node whatsapp-bot/index.js`, PaddleOCR service — with health-check commands for each (`/status` endpoints, `ps aux` checks).
5. Add a request/job correlation ID (see §7) as the first observability primitive, before other T1.x work, so every subsequent fix can be verified via logs.
6. Define a backup/rollback step: DB backup (`mysqldump`) before any migration or data-shape change in later tasks; git branch/tag before starting T1.1+.
7. **Acceptance criteria**: fresh clone + `.env` from `.env.example` + documented startup steps results in a bot that connects, sends, and receives a test message end-to-end, with correlation IDs visible in logs for that test message.

**Dependencies**: none — this is the foundation for T1.1-T1.3.
**Risks**: session rotation causes real downtime for every live bot; must be scheduled, not silent.
**Validation commands**: `git ls-files whatsapp-bot/sessions` (expect empty), `ps aux | grep -c "whatsapp:process-jobs"` (expect 1), manual end-to-end WhatsApp message test.
**Estimated complexity**: Medium (mostly process/ops work, low code risk, but real-world coordination for session rotation).

## T1.1 - Bugs and Reliability Fixes
**Status: 🟡 Partial** — subtask 1 half-done (duplicate killed + single-instance lock added and verified; OS supervisor with autorestart not added). Subtasks 2 (B3), 3 (B4), 4 (B6 persisted dedup) done. Subtask 5 (B7 Node supervisor), 6 (alerting hooks), 7 (tests for every fix) not started.

**Objective**: fix confirmed correctness/reliability bugs (B2, B3, B4, B6, B7) without changing AI behavior or prompts.

**Current evidence**: B2 (two live worker processes, no supervisor), B3 (confirmed signature mismatch, `\Throwable` swallowed), B4 (confirmed raw `env()` calls, breaks under `config:cache`), B6 (in-memory dedup set), B7 (no Node process supervision).

**Files likely affected**: `app/Console/Commands/ProcessWhatsappMessageJobs.php`, `app/Services/GeminiKeyManager.php`, `app/Services/GeminiClient.php`, `config/services.php` (new key), `app/Http/Controllers/Api/WhatsappBotController.php`, `app/Filament/Resources/WhatsappBotResource.php`, `whatsapp-bot/index.js`, new process-manager config files, `database/migrations/` (if a unique constraint is added for B6).

**Ordered subtasks**:
1. Kill the duplicate `whatsapp:process-jobs` process found running; wrap the command under a supervisor with `autorestart` + a startup PID-lock guard so a second manual invocation refuses to start (B2).
2. Fix `GeminiKeyManager::markRateLimited()` signature to accept (and use) `dailyLimit`/`cooldownSeconds`, matching the call in `GeminiClient` (B3); add the regression test described in §6.
3. Add `'bot_token' => env('BOT_TOKEN')` to `config/services.php`; replace all 7 raw `env('BOT_TOKEN')` call sites with `config('services.bot_token')` (B4); verify with `config:cache`.
4. Add persistence-backed dedup for inbound WhatsApp messages (unique index on the message's WhatsApp ID) as a second guard alongside the in-memory `handledMessages` set (B6).
5. Wrap `node whatsapp-bot/index.js` under a supervisor (pm2/systemd) with restart-on-crash (B7).
6. Add the alerting hooks described in §7 for "queue stalled" and "session conflict repeated" so future regressions of B1/B2 are caught automatically instead of by a customer complaint.
7. Tests for every fix per §6 (unit for B3/B4, integration for B2's row-locking guarantee, smoke test for B6/B7).

**Dependencies**: T1.0 (needs the correlation-ID/logging baseline to validate fixes, and the session rotation from T1.0 should happen before/alongside killing the duplicate worker to avoid re-triggering conflicts mid-fix).
**Risks**: changing `markRateLimited()`'s signature could affect any other untested caller — grep for all call sites first. Supervisor changes touch how the app is deployed/run — coordinate a deploy window.
**Validation commands**: `php artisan test --filter=GeminiKeyManager`, `php artisan config:cache && curl` webhook test, `ps aux | grep -c "whatsapp:process-jobs\|node index.js"` (expect 1 each).
**Acceptance criteria**: single supervised instance of each worker; a simulated 429 from Gemini results in `cooldown_until` being set and the key being skipped on the next call; webhook auth still works after `config:cache`.
**Estimated complexity**: Medium — individually small code changes, but each requires careful before/after verification given the "preserve current behavior" constraint and live-traffic risk.

## T1.2 - AI Token Usage and Refactoring
**Status: ⬜ Not started**

**Objective**: implement the six confirmed findings in `AI_TOKEN_USAGE_ISSUES.md` plus the four additional findings in §3, reducing token spend without changing observable bot behavior.

**Current evidence**: §3 table above (all six original findings re-verified; four new findings added).

**Files likely affected**: `app/Services/WhatsappIntentRouter.php`, `app/Services/AiIntentClassifier.php`, `app/Services/AiComplexReplyService.php`, `app/Services/AiPromptBuilder.php`, `app/Services/AiMemoryContextBuilder.php`, `app/Services/GeminiClient.php`, `app/Services/GeminiKeyManager.php` (for token persistence), new `ai_requests` migration/model.

**Ordered subtasks**:
1. **Remove duplicate AI calls**: restructure `WhatsappIntentRouter::handle()` so the existing local heuristics (`isPureFollowUp`, `isInstallmentSystemIntent`, `isApplicationIntent`) run *before* `AiIntentClassifier::classify()` is invoked, only calling AI when heuristics don't resolve the message — golden-file test against current classify() outputs first, to know what "heuristics resolve it" actually covers today.
2. **Local fast paths**: add a short-circuit for trivial inputs (pure greetings, single-word acks like "تمام"/"أيوه", pure numeric replies in a known clarification context) before both `classify()` and `AiComplexReplyService`.
3. **Reduce conversation history duplication**: stop embedding raw `payload` (OCR blobs) in `AiIntentClassifier`'s `$recent` messages; replace with a short flag (e.g. `"had_ocr_document": true, "ocr_summary": "..."`) capped at N characters.
4. **Filter/cap memory context**: add a length cap (e.g. `mb_substr`) to `AiMemoryContextBuilder::buildFullMemoryContext()` output, and/or add a relevance filter (keyword match against the current message) before falling back to "all memories."
5. **Prompt size limits**: add `mb_substr` caps on `$message` and any interpolated field in `AiPromptBuilder::buildChatReplyPrompt()` and `AiIntentClassifier::prompt()`.
6. **Improve token estimation**: replace `mb_strlen($prompt)` in `GeminiClient::generateText()` with a better estimate (word-count-based ratio appropriate for Arabic, or a real tokenizer library if one is available for PHP) — used only for the `GeminiKeyManager` reservation, not billing, so exactness is secondary to "closer than character count."
7. **Prevent unnecessary retries**: fixed by B3 in T1.1, but add the additional guard of not retrying against a model/key that just failed within the same request loop's `$excludedIds` (already done — confirm test coverage).
8. **Token/cost metrics**: add `ai_requests` table + write on every `GeminiClient::generateText()` call (model, key, prompt length, `usageMetadata.totalTokenCount`, latency, status) — this directly answers the original user question going forward.
9. **Preserve current AI behavior**: golden-file regression tests (per §6) run before/after each of subtasks 1-6 to catch any unintended intent/reply drift.

**Dependencies**: T1.0 (observability), T1.1 (B3 fix must land first, since retry/cooldown behavior affects how token-usage metrics in subtask 8 should be interpreted).
**Risks**: heuristic-before-AI reordering (subtask 1) is the highest-behavior-risk change in this whole plan — a heuristic that's slightly wrong now silently "loses" to the AI classify() call; once it runs first, its mistakes become visible to customers. Requires the golden-file test suite before merging.
**Validation commands**: `php artisan test --filter=AiIntentClassifier`, manual A/B message replay comparing token counts before/after via the new `ai_requests` table.
**Acceptance criteria**: measurable reduction in average `usage_total_tokens` per conversation in `ai_requests`, with zero regressions in the golden-file intent/reply test suite.
**Estimated complexity**: High — this is the largest behavioral-risk task in the plan; each subtask should land as its own reviewable change, not one large diff.

## T1.3 - Google Cloud Vision OCR
**Status: ⬜ Not started**

**Objective**: implement the provider abstraction and Google Cloud Vision integration designed in §5, with PaddleOCR preserved as fallback.

**Current evidence**: single call site (`MediaOcrHandler::handle()` → `app(PaddleOcrClient::class)->recognize()`), single well-defined return shape — see §5.

**Files likely affected**: new `app/Services/Ocr/OcrProviderInterface.php`, new `app/Services/Ocr/GoogleVisionOcrClient.php`, new `app/Services/Ocr/FallbackOcrClient.php`, `app/Services/Ocr/PaddleOcrClient.php` (add `implements`), `app/Services/Handlers/MediaOcrHandler.php` (inject interface instead of concrete class), `config/ocr.php`, a new service provider binding, `.env.example`.

**Ordered subtasks**:
1. Define `OcrProviderInterface` matching `PaddleOcrClient`'s current return shape exactly (zero behavior change for existing callers).
2. Make `PaddleOcrClient implements OcrProviderInterface`; update `MediaOcrHandler` to depend on the interface, bound to `PaddleOcrClient` by default — this subtask alone should be a no-op deploy (pure refactor, verified by existing OCR flow still working).
3. Implement `GoogleVisionOcrClient` for the sync image path (`images:annotate`, `DOCUMENT_TEXT_DETECTION`, `languageHints: ['ar']`).
4. Implement the PDF/TIFF batch path (`files:asyncBatchAnnotate` + GCS result polling) or explicitly document it as a follow-up if out of scope for v1 (PaddleOCR already handles `application/pdf` per `config/ocr.php:16`, so the fallback covers this gap during rollout).
5. Add config/env keys per §5; add credentials handling with an explicit `.gitignore` entry for any local credentials file (do not repeat B1's mistake).
6. Implement `FallbackOcrClient` and wire `config('ocr.provider') = 'fallback'` as the canary rollout mode.
7. Add unit/integration/contract tests per §6.
8. Canary rollout: enable `fallback` mode in production, monitor accuracy via the OCR metrics table from §7, compare against PaddleOCR-only baseline on the same documents where possible.
9. Full cutover to `google_vision` once validated; keep PaddleOCR service running for rollback per §5.

**Dependencies**: T1.0 (observability/metrics table pattern), independent of T1.1/T1.2 otherwise — can run in parallel.
**Risks**: Arabic ID-card extraction accuracy differences between engines could silently degrade `AiIntentClassifier::extractApplicationData()` results (wrong national ID/name parsed) — must be validated with real (anonymized) document samples before full cutover, not just synthetic tests. Data-residency/privacy consideration (documents leaving self-hosted infra to Google) needs explicit business sign-off.
**Validation commands**: `php artisan test --filter=Ocr`, manual side-by-side OCR comparison script per §6's regression bullet.
**Acceptance criteria**: `OCR_PROVIDER=google_vision` produces `extractApplicationData()` results matching or exceeding PaddleOCR accuracy on a sample set; rollback to `paddle` via config-only change verified to work.
**Estimated complexity**: Medium-High (mostly additive/new code, low risk to existing PaddleOCR path since it's preserved, but real-world Arabic-document accuracy validation takes real test data and time).

---

## Priority Matrix

| ID | Item | Impact | Effort | Priority | Status |
|---|---|---|---|---|---|
| B1 | Session creds in git → conflict loop + leaked secret | Critical | Medium | P0 | 🟡 Partial |
| B2 | Duplicate/unsupervised `whatsapp:process-jobs` | Critical | Low-Medium | P0 | ✅ Done (lock) / 🟡 supervisor open |
| B3 | `markRateLimited()` signature mismatch | Critical | Low | P0 | ✅ Done |
| B4 | `env('BOT_TOKEN')` breaks under `config:cache` | High | Low | P0 | ✅ Done |
| §3 #1 | Duplicate AI calls per message | High (cost) | Medium | P1 | ⬜ Not started |
| §3 #3 | OCR payload repeated in classify() history | High (cost) | Low-Medium | P1 | ⬜ Not started |
| §3 #2 | Unfiltered full memory dump | Medium-High (cost, grows over time) | Low | P1 | ⬜ Not started |
| B7 | No Node process supervision | High (reliability) | Low | P1 | ⬜ Not started |
| B6 | In-memory dedup lost on reconnect | Medium | Low-Medium | P1 | ✅ Done |
| §3 #8 | No token/cost metrics | High (visibility, enables everything else) | Medium | P1 | ⬜ Not started |
| §3 #4 | No fast-path before AI classify | Medium (cost) | Medium-High (behavior risk) | P2 | ⬜ Not started |
| B5 | Dead ChatGPT-era code in controller | Medium (maintainability) | Medium (needs call-graph audit) | P2 | ✅ Done |
| §3 #5 | Inaccurate token estimation | Low-Medium (indirect) | Low | P2 | ⬜ Not started |
| §3 #6 | No truncation caps on prompt fields | Low (defensive) | Low | P2 | ⬜ Not started |
| T1.3 | Google Cloud Vision OCR migration | Medium (quality/cost tradeoff, optional) | Medium-High | P2 | ⬜ Not started |
| B8 | Config/runtime model list drift | Low (suspected only) | Low | P3 | ⬜ Not started |
| B9 | Oversized `LARAVEL_TIMEOUT` | Low (suspected only) | Low | P3 | ⬜ Not started |
| B10 | 12h duplicate-order window | Low (business rule, not a bug) | N/A | P3 | N/A |

## Recommended Execution Order

1. **T1.0** — baseline, `.gitignore`/session rotation, correlation IDs. *(Must happen first — B1 makes every later fix unverifiable while sessions keep conflicting.)*
2. **T1.1** — B2, B3, B4, B6, B7. *(Stops active reliability bleeding; low behavioral risk; makes the bot trustworthy enough to measure T1.2 against.)*
3. **T1.2** — AI token/refactor work, in the internal order given (remove duplicate calls → fast paths → history/memory trimming → truncation caps → token estimation → metrics), with the highest-risk step (heuristics-before-AI reordering) last and gated on the golden-file regression suite.
4. **T1.3** — Google Cloud Vision, any time after T1.0 (can run in parallel with T1.2 since it's a separate code path; sequenced last here only because it's the least urgent — nothing is currently broken in OCR).

## Open Questions

These cannot be answered from the repository alone:

1. Is this exact repository (with the committed `whatsapp-bot/sessions/`) deployed anywhere else right now (a production server, another developer's machine, CI)? This determines how urgently B1's session rotation must happen and whether the GitHub repo (`monspace-2202/motocyklaty`) has ever been public.
2. Was `github.com/monspace-2202/motocyklaty` ever a public repository, or always private? This determines the real-world severity of the leaked WhatsApp session credentials (B1) and whether a broader credential-rotation/incident process is warranted beyond just this app.
3. Is there an existing process manager (systemd/pm2/supervisor) configured on the production server that simply isn't checked into this repo? The two duplicate worker processes were observed on what appears to be a local development Mac (`MacBook-Air-Kiro.local`) — it's unconfirmed whether production has the same unsupervised-process problem or already handles it differently.
4. What is the acceptable data-residency/privacy posture for sending customer ID documents to Google Cloud Vision (§5)? This is a business decision, not something inferable from code.
5. Is the legacy ChatGPT-era code in `WhatsappBotController` (B5) fully dead, or does `chatgpt-worker/` (present in the repo, not audited in this pass) still get invoked from some path not covered by this analysis? Needs an explicit confirmation before any deletion.
6. What is the intended/contracted Gemini model (`gemini-3.1-flash-lite`) — is it a real, currently-available Gemini model on the account being used, given it doesn't appear in `config/gemini.php`'s seed list (B8)?
