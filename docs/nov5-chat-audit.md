# Conversation audit — 2026-11-05 (Africa/Cairo)

The initial server scan covered 380 traces created since local midnight.
Twelve had fallback status. Review combined an automated scan of outgoing
messages, inspection of flagged conversations and their tool results, and
simulator regressions. It was not an exhaustive semantic review of every
possible customer phrasing. Simulations did not send WhatsApp messages.

## Findings and changes

- A lookup/last quote could be mistaken for a customer-selected motorcycle.
  Financial tools now require customer naming evidence; an unknown selection
  returns a model question without financial numbers.
- No charge applies to the letter. Direct answers and outgoing corrections
  distinguish letter fees from installment administrative fees.
- Link detection only recognized the Arabic word for link, missing loanword
  and form wording. Both direct requests and reply wrapping now handle these;
  fresh link creation checks work classification and age restrictions.
- Government employment was incorrectly generalized as excluded. Outgoing
  corrections prevent that generalization; explicit excluded occupations remain.
- Student printed on an ID was treated as unemployment. A direct clarification
  separates the printed occupation from current work.
- Repeated work recording could overwrite known insurance/sector facts with
  unknown. Same-applicant records preserve known values when no replacement
  fact is supplied; hypothetical work declarations are rejected.
- Bulk 250cc photo requests exhausted the agent loop. A bounded catalog batch
  queues up to eight images with authoritative name/price captions.
- Catalog lookups at the model-call limit could become generic failures.
  Already retrieved catalog facts can now be formatted without another call.
- Failed human-handoff attempts now ask for explicit permission rather than
  claiming a transfer happened.
- Cyrillic text is rejected by the existing local language check.

## Verification

17 focused unit/provider tests passed (26 assertions).
The broader unit suite had 36 passing tests and nine database-dependent tests
blocked by the unavailable configured test database; no claim of a full suite pass.

Live server simulator checks:

| Scenario | Result | AI calls |
|---|---|---:|
| General installment request, no motorcycle | Model clarification, no figures | 0 |
| Duration and work supplied without model | Model clarification, no figures | 1–2 before the final work-result formatter |
| Letter fee question | Explicitly no letter charge | 0 |
| F250 installment request | Cash price and income question | 0 |
| Mechanic work declaration | Required difference and all four durations | 1 |
| Two-year calculation | Actual offer numbers | 0 |
| Start application / where is form | Same actual form URL | 0 |
| Employed person with student on ID | Current work clarification | 0 |
| All 250cc images and prices | Eight images, named/priced captions | 0 |
| Branch locations | All four branches and map links | 1 |
| Government teacher | Asked about insurance; not categorically refused | 2 |

Server readiness check passed. These checks cover the identified failures;
they are not a guarantee that future model-generated messages cannot be wrong.
