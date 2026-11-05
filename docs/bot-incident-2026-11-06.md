# Bot incident: repeated selection and stale rates (2026-11-06)

Production traces showed that customer model shorthand such as X ROAD, KTX and دايون4استيراد did not resolve through the strict selection guard. A direct response after recording work then returned MODEL_SELECTION_REQUIRED, asking for the model again despite customer context. Single-duration recovery also missed short replies and multi-duration voice transcripts.

The active dashboard instruction and lesson 35 still prescribed 20%/30%, while active standard plans already stored 21% annually (42% for 24 months) and the no-admin-fee Aمان plan stored 31%. The runtime guard was advisory: PERCENT_DURATION_MISMATCH and UNVERIFIED_NUMBER could be observed without preventing transmission. No interest-plan values were changed for this repair.

Fixes:
- Normalize digit/letter boundaries and the دايون spelling; derive catalog shorthand for scooter names and alphabetic model prefixes without choosing the first search result. Ambiguous version qualifiers require a specific clarification.
- Recover customer durations, including multiple durations and voice transcripts, without treating age as duration. Reuse known selection after recording work.
- Format financial responses directly from calculator results, retaining an already computed offer rather than estimating. Include the recorded financing-cap explanation when a down payment is due.
- Read current rates from active systems/plans. Prioritize financial validation above advisory style checks; financial failures no longer become advisory-only.
- Detect requests for private application data/documents in chat and use eligibility plus send_application_link instead. Form-link mode was already enabled. The actual link continues through the existing outbound-link wrapper.
- Publish corrected active instructions and replace the stale lesson after saving a private JSON backup. Active revision after the lesson observer was v2.4.35.

Validation: PHP syntax checks for the changed files; 3 isolated regression tests/13 assertions; read-only production checks on affected conversations verified exact single- and multi-duration offers, selection recovery, current rates and private-data redirection. No test messages were sent to customers. The owner explicitly approved restarting only whatsapp-queue after the earlier restart restriction; stopped queue workers remain stopped.

Backups on production: /root/bot-before-continuity-20261106.tar.gz and /root/bot-policy-before-20261106-005046.json (private permissions). Live chat behavior still depends on supplied customer facts, current catalog/financing eligibility, AI provider availability and transport delivery. This repair does not claim that every possible conversation error is eliminated. No customers, applications, files or historical messages were deleted or requeued.
