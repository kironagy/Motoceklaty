# Tool contract v2 (ARCH-003)

Status: in force for every tool in `config('agent.tools')`. Enforced by `tests/Feature/Agent/Rebuild/ToolContractTest.php`. The test freezes today's known violations; each cleanup task removes its rows, and the test fails if a row is fixed but still listed, or if a new violation appears.

## Two kinds of tool

| Kind | Interface | `permission()` | May write | May call a model | Result |
|---|---|---|---|---|---|
| READ | `App\Agent\Tools\ReadTool` | `READ` | nothing | no; vision/web tools are declared in `AI_CALLING_READ_TOOLS` | facts + codes |
| WRITE | `App\Agent\Tools\WriteTool` | `WRITE` / `DESTRUCTIVE` | only what its contract names | no | `{ok, data: {delta…, next_step?}, error}` |

The runner, not a tool, writes derived state (quote ledger, interest, last-quoted machine) from READ outcomes (TOOL-002, INS-004).

## Result envelope

`ToolResult` is the only return type:

```
{ "ok": true,  "data": { …facts… } }
{ "ok": false, "error": { "code": "UPPER_SNAKE_CASE", "detail": "short factual reason" } }
```

- `code` comes from one catalog (TOOL-012). `detail` states what is wrong, never how to word the reply.
- No prose-instruction keys in `data`: `say`, `how_to_present`, `note`, `hint`, `explain_to_customer`, `tell_customer`, `ask_next`, `price_difference_policy`, `reason_for_customer`, `instruction(s)`. Wording rules live in instruction packs (INST-012).
- Size: a result stays under the cap set by TOOL-005. A write returns a delta and `next_step`, not the whole snapshot (TOOL-006): what it changed (`saved`/`rejected`, `results`, `selected_plan`) plus `application_now` = `SnapshotService::compact()` (progress, missing keys, documents in, eligibility, `can_submit`, `next_step` and only its hint). The full snapshot is in the context once: L4, or the `start_application` result that opened/reopened the application.

## Tool sets by stage (TOOL-013)

`AgentRunner::toolsFor()` sends one of two stable sets — never a per-turn variant:

| Set | When | Tools, in this order |
|---|---|---|
| browsing | no open application | every stable tool (registry order) → `identify_motorcycle_from_image`, `process_document` |
| application | an open application (also from the moment `start_application` succeeds in the turn) | the browsing set, unchanged → `update_application_selection`, `submit_application`, `withdraw_application` |

Rules:
- The browsing set is an exact prefix of the application set, so the provider cache (it caches from the start of the request, tools first) keeps hitting when an application opens.
- Nothing is shown or hidden by a photo in the turn: the old four variants (application × photo) changed the list from turn to turn and broke the cache.
- A new tool is stable (anywhere before the photo tools) unless it can only act on an open application; then it goes to `AgentRunner::APPLICATION_TOOLS`, at the end.
- Sizes (2026-10-05): 20,271 chars browsing, 22,724 application. Before: 18,192 / 20,271 / 21,820 / 22,724 by turn (no app, no photo / no app, photo / app, no photo / app, photo).

## Descriptions

`description()` states what the tool does, when to call it, and what its arguments mean. Policy and wording rules do not belong there (INST-013).

## Known exceptions (2026-10-05, after the rebuild steps)

| Tool | Writes | Why it stays |
|---|---|---|
| identify_motorcycle_from_image | `message_media.analysis` | cache of the vision result for that photo |
| lookup_motorcycle_specs_online | specs cache | cache of the web lookup |

Removed: the state writes of `search_motorcycles`, `get_motorcycle_details`, `calculate_installment`, `get_installment_offer` (now `ToolOutcomeRecorder`, run by the runner after the result), `fillEmptySelection` (the application changes only through `update_application_selection`), and every `WorkClassifier` call (the agent records the work with `record_work_profile`; the rules read it).
