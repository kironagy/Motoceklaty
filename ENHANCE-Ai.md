# MOTOCYKLATY WHATSAPP AI AGENT

# FULL HUMAN-LEVEL REBUILD, HISTORICAL LEARNING, MEMORY & PERFORMANCE OVERHAUL

You are working on the existing Motocyklaty WhatsApp AI Sales Agent.

This is NOT a simple prompt-improvement task.

The objective is to take the existing production-connected AI agent and bring it as close as realistically possible to:

> A genuinely capable Egyptian motorcycle salesperson who understands customers, remembers them, learns from previous mistakes, uses real business data, never invents facts, and communicates naturally enough that the customer does not feel they are talking to a bot.

The current behavior is significantly below this standard.

You must investigate the actual system, historical customer conversations, applications, database state, existing implementation, and real failures before making decisions.

---

# 0. CRITICAL EXECUTION RULE — DO NOT WASTE PAID API USAGE

This is extremely important.

The AI API is paid.

DO NOT repeatedly call the production AI API during investigation, exploration, code inspection, database inspection, or speculative testing.

Do NOT spend paid API requests just to "see what happens" after every small change.

Your workflow must be:

```text
INSPECT
↓
READ
↓
ANALYZE
↓
COLLECT EVIDENCE
↓
IDENTIFY ROOT CAUSES
↓
DESIGN
↓
IMPLEMENT
↓
STATIC / LOCAL / DATABASE TESTING
↓
VERIFY CODE
↓
ONLY THEN USE REAL PAID AI API
↓
RUN FINAL REAL-WORLD QA
↓
FIX REMAINING ISSUES
↓
FINAL VERIFICATION
```

Use the paid AI API only when there is a real need to validate the final integrated behavior.

If something fails during final testing:

1. Diagnose the failure.
2. Fix the code/configuration/prompt/tool architecture.
3. Avoid repeatedly replaying the same expensive scenario.
4. Re-run only the minimum required API tests.
5. Continue until the behavior is correct.

Do not burn API requests through trial-and-error.

---

# 1. YOU WILL RECEIVE SERVER ACCESS

I will provide you with access/details for the server where the actual system is running.

Treat this as a real engineering investigation.

You should inspect the actual environment.

You need to investigate:

- Application code.
- Laravel backend.
- WhatsApp worker.
- Database.
- Existing AI implementation.
- Existing tools.
- Existing customer/application records.
- Existing conversation history.
- Existing messages.
- Existing bot responses.
- Existing failures.
- Existing logs.
- Existing configuration.
- Existing memory/state mechanisms.
- Existing installment data.
- Existing motorcycle catalog.
- Existing providers.
- Existing application statuses.
- Existing customer profiles.
- Existing media/document handling.
- Existing performance behavior.

Do not make assumptions about the system from this prompt.

Inspect the real system.

---

# 2. IMPORTANT — INVESTIGATE HISTORICAL DATA

I do NOT want you to inspect only the latest conversation.

I want you to inspect the actual history of how this bot has behaved with real customers.

Find and analyze:

- Previous WhatsApp conversations.
- Customer messages.
- Bot messages.
- Conversation histories.
- Submitted applications.
- Application status changes.
- Customer profiles.
- Motorcycle selections.
- Installment requests.
- Documents/media where accessible.
- Failed applications.
- Successful applications.
- Abandoned conversations.
- Handoffs.
- Fallback responses.
- Tool calls.
- Errors.
- Any available logs related to AI responses.

The purpose is to discover patterns.

We want the new agent to behave as if it has learned from the mistakes of the old agent.

---

# 3. CONVERSATION 302 IS A CRITICAL REGRESSION CASE

Find the latest customer conversation whose identifier ends with:

**302**

Read the entire conversation.

Do NOT only inspect the last messages.

Analyze it deeply.

The conversation contains examples of the exact behavior we do NOT want.

Identify every important failure, including:

- Bad greeting.
- Unnatural Egyptian Arabic.
- Robotic wording.
- Irrelevant responses.
- Answering questions the customer never asked.
- Introducing motorcycle prices when the customer did not ask about a motorcycle.
- Inventing prices.
- Inventing installment values.
- Making assumptions about customer intent.
- Repeating itself.
- Forgetting context.
- Asking for information already provided.
- Changing topics incorrectly.
- Over-selling.
- Asking unnecessary questions.
- Failing to understand the customer.
- Treating a casual mention as buying intent.
- Treating a question as a purchase decision.
- Losing track of the actual motorcycle of interest.
- Giving technically valid but conversationally stupid responses.
- Any other behavior that makes the bot obviously look like a bot.

Do not simply patch these individual responses.

Find the underlying causes.

---

# 4. DO NOT ONLY LEARN FROM CONVERSATION 302

Conversation 302 is a starting point, not the complete training set.

Analyze a representative sample of historical conversations.

Look for recurring patterns such as:

### Communication failures

- Bad greetings.
- Robotic responses.
- Excessive messages.
- Long irrelevant explanations.
- Repetitive wording.
- Poor Egyptian Arabic.
- Failure to understand slang.
- Failure to understand short messages.
- Failure to understand corrections.
- Failure to understand customer hesitation.

### Sales failures

- Selling too early.
- Pushing motorcycles unnecessarily.
- Giving irrelevant prices.
- Failing to identify genuine buying intent.
- Missing buying signals.
- Asking unnecessary qualification questions.
- Losing customers through bad conversation flow.

### Memory failures

- Forgetting names.
- Forgetting profession.
- Forgetting previously discussed motorcycles.
- Asking repeated questions.
- Losing previous customer preferences.
- Losing application context.

### Business-data failures

- Wrong prices.
- Wrong installment calculations.
- Wrong providers.
- Wrong eligibility.
- Wrong application state.
- Invented information.

### Technical failures

- Slow response.
- Excessive API calls.
- Excessive context.
- Unnecessary DB queries.
- Unnecessary model calls.
- Tool latency.
- Queue delays.
- WhatsApp delays.

---

# 5. LEARN FROM THE OLD SYSTEM — BUT DO NOT COPY ITS MISTAKES

The objective is NOT:

> "Put old conversations into the prompt."

That would be a bad solution.

Instead:

Extract reusable behavioral and architectural lessons.

For example:

If historical conversations repeatedly show:

```text
Customer asks about price
→ bot invents a price
```

The solution is not:

```text
"Remember not to invent this particular price."
```

The solution is:

```text
Business price must come from authoritative backend/tool data.
```

If historical conversations repeatedly show:

```text
Customer gives name
→ bot asks name again 10 messages later
```

The solution is:

```text
Persistent structured customer memory + context retrieval.
```

If historical conversations repeatedly show:

```text
Customer mentions VLR
→ bot assumes they want to buy VLR
```

The solution is:

```text
Separate mention / question / interest / selection / application intent.
```

Turn recurring historical failures into general system improvements.

---

# 6. THE FINAL SYSTEM MUST HAVE A REAL AI SUPPORT LAYER

The AI remains the conversational brain.

The backend becomes:

- Persistent memory.
- Authoritative business reality.
- Structured customer context.
- Data validation.
- Tool execution.
- Application state.
- Performance optimization.
- Security/integrity.

Do NOT turn Laravel into a giant conversational decision tree.

Do NOT replace AI reasoning with:

```text
if greeting
if motorcycle
if installment
if name
if profession
if application
```

The model should understand conversations.

The backend should enforce facts.

---

# 7. CUSTOMER MEMORY

Build a persistent structured customer memory layer.

Do NOT create physical JSON files per customer.

Use the existing database architecture.

A JSON/JSON-column representation is acceptable if appropriate, but do not add unnecessary infrastructure.

The conceptual memory may contain things such as:

```json
{
    "identity": {
        "name": {},
        "phone": {}
    },

    "profile": {
        "profession": {},
        "employment_type": {}
    },

    "motorcycle_interest": {
        "mentioned": [],
        "asked_about": [],
        "interested": [],
        "preferred": [],
        "selected": []
    },

    "conversation": {
        "current_topic": {},
        "customer_goal": {},
        "open_questions": []
    }
}
```

This is conceptual only.

Design the correct implementation for the actual existing system.

---

# 8. MEMORY MUST HAVE PROVENANCE

Do not blindly store AI assumptions as facts.

For important fields preserve where the information came from.

Conceptually:

```text
value
source
confidence
last_seen_at
```

Example:

Customer:

"أنا مدرس"

Store:

```text
profession = مدرس
source = explicit_customer_statement
```

But if the AI merely guesses:

"شكلك مدرس"

Do NOT automatically turn that into a customer fact.

Explicit customer information is stronger than AI inference.

---

# 9. MEMORY MUST EVOLVE

Example:

Customer:

"أنا مدرس"

Later:

"لا أنا سبت التدريس وبشتغل سواق دلوقتي"

The new information should supersede the old current profile.

Do not keep stale information as the current truth.

Historical information may remain available when useful.

---

# 10. MOTORCYCLE MEMORY MUST NOT CREATE FALSE INTENT

This is critical.

Do NOT store only:

```text
last_motorcycle = VLR 150
```

because this can cause bad future behavior.

Distinguish between:

```text
mentioned
asked_about
compared
interested
preferred
selected
application_intent
purchased
```

Example:

"VLR 150 بكام؟"

means:

```text
asked_about
```

NOT:

```text
selected
```

Example:

"أنا عايز VLR 150"

means:

```text
interested
```

Example:

"خلاص هقدم على VLR 150"

means:

```text
selected/application_intent
```

The AI must understand the difference.

---

# 11. MEMORY MUST HELP THE AI — NEVER CONTROL IT

Memory is context.

It is not a rigid workflow.

The customer can:

- Change their mind.
- Change motorcycles.
- Change topic.
- Correct old information.
- Ask something unrelated.
- Return days later.

The AI must remain conversationally intelligent.

---

# 12. CONTEXT MANAGEMENT

Do NOT blindly send the entire conversation history to the model on every message.

Build an efficient context strategy.

The AI should have access to:

1. Relevant recent messages.
2. Structured customer memory.
3. Current conversation context.
4. Compact conversation summary when needed.
5. Relevant authoritative business data.
6. Relevant tool results.

Do not destroy intelligence just to save tokens.

Do not send huge irrelevant histories either.

Find the correct balance.

---

# 13. PERFORMANCE IS A FIRST-CLASS REQUIREMENT

The current bot is too slow.

Investigate the complete latency chain:

```text
WhatsApp
→ Node/Baileys
→ Laravel
→ job
→ database
→ context
→ memory
→ tools
→ AI API
→ response
→ WhatsApp
```

Measure where possible:

- Queue delay.
- DB queries.
- DB duration.
- Context construction.
- Memory retrieval.
- Tool latency.
- AI API latency.
- Response processing.
- WhatsApp send latency.

Then optimize the real bottlenecks.

---

# 14. DO NOT MAKE THE BOT FASTER BY MAKING IT DUMBER

Do NOT solve latency by:

- Removing memory.
- Removing useful context.
- Removing business validation.
- Using fewer tools when tools are necessary.
- Truncating important conversation history.
- Making the AI blindly guess.

The target is:

> Better architecture + fewer unnecessary operations + intelligent context.

Not:

> Less intelligence.

---

# 15. MINIMIZE PAID API CALLS

This is a strict requirement.

Avoid architecture such as:

```text
LLM #1 = understand
LLM #2 = extract memory
LLM #3 = detect intent
LLM #4 = generate answer
LLM #5 = validate answer
```

That is expensive and slow.

Prefer:

```text
One primary conversational AI call
+
Deterministic backend tools
+
Structured memory
+
Efficient context
```

Only use additional model calls when there is a demonstrated reason.

Do not add an LLM call simply because it seems convenient.

---

# 16. DO NOT RUN PAID API TESTS AFTER EVERY CHANGE

During development:

Use:

- Static code analysis.
- Unit tests.
- Feature tests.
- Database inspection.
- Existing logs.
- Tool-level testing.
- Deterministic test cases.
- Local mocks/stubs where appropriate.
- Context construction tests.
- Memory update tests.
- Tool validation tests.

Reserve real paid API calls for final integrated validation.

---

# 17. FINAL API TESTING SHOULD BE INTENTIONAL

After the architecture and code are ready:

Run a small, carefully selected real-world test set.

Do not randomly spam the API.

Select scenarios that cover the highest-risk behaviors:

- Greeting.
- General motorcycle inquiry.
- Price request.
- Installment request.
- Customer profile information.
- Motorcycle interest.
- Topic switching.
- Correction.
- Memory retention.
- Customer rejection.
- Customer joking.
- Application flow.
- Document/image handling.
- Historical context.

If failures occur:

Fix the underlying problem.

Then rerun only the necessary scenario(s).

---

# 18. HUMAN CONVERSATION QUALITY

The final AI must feel like a real Egyptian motorcycle salesperson.

It should naturally understand:

- Egyptian Arabic.
- Slang.
- Typos.
- Short messages.
- Casual language.
- Humor.
- Hesitation.
- Corrections.
- Incomplete sentences.
- Mixed Arabic/English.
- Customer frustration.
- Customer confusion.

The bot must understand conversation rhythm.

Not every response should be long.

Not every message requires a question.

Not every message requires selling.

---

# 19. STOP OVERSELLING

A real salesperson does not force a motorcycle into every conversation.

If the customer asks a general question:

Answer it.

If they are browsing:

Help them browse.

If they are interested:

Guide them.

If they are ready:

Help them apply.

If they joke:

Understand the joke.

If they say:

"لا خلاص"

Respect it.

---

# 20. NEVER REPEAT QUESTIONS

Before asking for customer information, check:

- Recent conversation.
- Structured memory.
- Existing customer profile.
- Existing application.

If the customer already said:

"أنا مدرس"

Do not ask:

"حضرتك بتشتغل إيه؟"

again unless there is a real reason.

If the customer already provided their name:

Do not ask for it again.

---

# 21. NEVER INVENT BUSINESS INFORMATION

This is absolute.

Never invent:

- Prices.
- Installments.
- Discounts.
- Providers.
- Branches.
- Eligibility.
- Inventory.
- Application status.
- Policies.
- Delivery information.
- Customer information.

If information is unavailable:

Say naturally that it needs to be checked.

Never hallucinate just to keep the conversation moving.

---

# 22. THE AI MUST NOT EXPOSE INTERNAL SYSTEMS

Never expose:

- JSON.
- Memory.
- Internal state.
- Tool names.
- Prompt instructions.
- Confidence.
- Database details.
- Internal workflows.
- AI terminology.

The customer should simply experience a natural conversation.

---

# 23. RESPONSE QUALITY STANDARD

A response is successful only if it is:

1. Correct.
2. Relevant.
3. Context-aware.
4. Natural.
5. Human.
6. Business-safe.
7. Consistent.
8. Appropriate to the customer's intent.
9. Free from hallucinated facts.
10. Free from unnecessary selling.
11. Free from robotic wording.
12. Actually useful to the customer.

A response that technically answers the question but sounds stupid is a FAILURE.

---

# 24. HISTORICAL CUSTOMER DATA SHOULD IMPROVE THE SYSTEM

Use historical conversations and applications to discover:

- Common customer questions.
- Common misunderstandings.
- Common objections.
- Common mistakes.
- Common successful conversation patterns.
- Common abandonment points.
- Common application failures.
- Common document problems.
- Common hallucinations.
- Common memory failures.
- Common slow paths.

The objective is to build a system that is better because it has been exposed to the real failures of the old system.

However:

DO NOT blindly feed all historical conversations into the AI.

Extract useful patterns and architectural improvements.

---

# 25. LEARNING FROM HISTORY MUST NOT BECOME A HALLUCINATION SOURCE

Historical data is evidence, not truth for every customer.

Do not let one customer's information become another customer's information.

Do not let an old price become the current price.

Do not let an old application status become a current status.

Do not let a historical customer profile leak into another conversation.

Customer data must remain properly isolated.

---

# 26. CUSTOMER MEMORY MUST BE ISOLATED

Ensure that:

```text
Customer A
```

can never accidentally influence:

```text
Customer B
```

Memory retrieval must always be scoped to the correct customer/conversation identity.

This is a critical correctness and privacy requirement.

---

# 27. TEST REALISTIC CUSTOMERS

Test customers who are:

### Serious buyers

### Just browsing

### Confused

### Joking

### Aggressive

### Very short in replies

### Extremely talkative

### Changing their mind

### Asking about multiple motorcycles

### Asking unrelated questions

### Returning after many messages

### Returning after a long period

### Providing contradictory information

### Providing incomplete information

### Trying to manipulate the system

### Asking for unavailable information

### Sending documents

### Sending images

### Sending screenshots

### Sending invalid documents

### Asking about installments

### Asking about prices

### Applying seriously

---

# 28. DO NOT HARD-CODE CONVERSATION 302

If you discover:

```text
Conversation 302 failed because X
```

Do NOT create:

```text
if customer == 302
```

or any equivalent special-case behavior.

The solution must generalize.

---

# 29. DO NOT CREATE A GIANT IF/ELSE CHATBOT

This is explicitly prohibited.

Do not transform the project into:

```text
if greeting
if motorcycle
if price
if installment
if name
if profession
if document
if application
...
```

The AI should remain responsible for natural conversation understanding.

Backend code should enforce deterministic reality.

---

# 30. EXISTING ARCHITECTURE MUST BE RESPECTED

Before implementation:

Read:

- project.md.
- Existing implementation.
- Existing database schema.
- Existing worker.
- Existing tools.
- Existing model integration.
- Existing WhatsApp integration.
- Existing memory/state mechanisms.

Do not introduce:

- Redis.
- Vector DB.
- Microservices.
- Extra queues.
- Extra agents.
- Unnecessary infrastructure.

Reuse the current architecture wherever possible.

---

# 31. INSPECT BEFORE MODIFYING

Do not start coding immediately.

First understand:

```text
What exists?
What works?
What fails?
Why does it fail?
What data already exists?
What information can already be reused?
Where is latency coming from?
Where does hallucination originate?
Where does context get lost?
```

Then make changes.

---

# 32. DO NOT ASK ME TO EXPLAIN THE SYSTEM IF YOU CAN INSPECT IT

You have server access.

Use it.

Inspect the actual:

- Database.
- Code.
- Logs.
- Conversations.
- Applications.
- Customer records.
- AI integration.
- Tool behavior.

Do not make me manually describe things that can be discovered directly from the system.

Act as the engineer sitting in front of the server.

---

# 33. ACT LIKE YOU ARE TAKING OWNERSHIP OF THE AGENT

I do not want a superficial code review.

I want you to approach this as:

> "I am responsible for making this AI agent actually good."

That means:

- Find problems yourself.
- Investigate historical evidence yourself.
- Discover hidden failure modes yourself.
- Trace bad responses to their root causes.
- Find inconsistencies.
- Find unnecessary API usage.
- Find slow operations.
- Find memory gaps.
- Find hallucination paths.
- Fix them intelligently.
- Verify the fixes.

Do not wait for me to identify every bug.

---

# 34. THE TARGET IS NOT "PASSING TESTS"

Passing tests is not enough.

A bot can technically pass:

```text
"What's the price?"
```

while still being terrible conversationally.

The standard is real customer experience.

Ask yourself for every test:

> If I were a real Egyptian customer, would this response feel natural and intelligent?

If the answer is no:

It is not finished.

---

# 35. FINAL REAL-WORLD TEST

After all implementation and deterministic verification is complete:

Use the paid AI API for the smallest meaningful final validation set.

Run realistic conversations.

Evaluate:

- Human feel.
- Accuracy.
- Memory.
- Context.
- Business correctness.
- Speed.
- Tool correctness.
- Egyptian Arabic.
- Conversation flow.
- Customer intent understanding.

If something fails:

DO NOT simply tweak the response.

Find the root cause.

Fix it.

Then retest only what is necessary.

---

# 36. REQUIRED FINAL REPORT

At the end, provide a complete engineering + behavioral QA report.

Do NOT simply say:

"Done."

The report must contain:

---

## A. CONVERSATION 302 COMPLETE ANALYSIS

Table:

| #   | Customer context/message | Bad bot response | Why it is bad | Root cause | Fix |
| --- | ------------------------ | ---------------- | ------------- | ---------- | --- |

Include all meaningful problems.

---

## B. HISTORICAL FAILURE PATTERNS

Table:

| Pattern | Evidence from historical data | Root cause | General fix |
| ------- | ----------------------------- | ---------- | ----------- |

---

## C. BEFORE vs AFTER

| Scenario | Before | After | Improvement |
| -------- | ------ | ----- | ----------- |

---

## D. MEMORY ARCHITECTURE

Explain:

- Where memory lives.
- What is stored.
- How it is updated.
- How contradictions work.
- How provenance works.
- How motorcycle intent is represented.
- How memory is injected.
- How customer isolation is guaranteed.
- How token usage is controlled.

---

## E. API COST / REQUEST OPTIMIZATION

Explain:

- Number of model calls before.
- Number after.
- Which calls were removed.
- Which calls are necessary.
- Which operations became deterministic.
- How unnecessary paid API usage was reduced.

---

## F. PERFORMANCE

Provide measurements where possible:

- Average response latency.
- Queue latency.
- DB latency.
- Tool latency.
- AI latency.
- WhatsApp send latency.
- Context size.
- Token usage.
- Number of model calls.

Identify the remaining bottleneck.

---

## G. HALLUCINATION PROTECTION

Explain how the new system prevents:

- Fake prices.
- Fake installments.
- Fake discounts.
- Fake providers.
- Fake inventory.
- Fake customer data.
- Unsupported assumptions.

---

## H. HUMAN BEHAVIOR EVALUATION

Evaluate:

- Egyptian Arabic.
- Naturalness.
- Context retention.
- Memory.
- Topic switching.
- Corrections.
- Humor.
- Objections.
- Sales behavior.
- Repetition.
- Relevance.
- Customer intent understanding.

---

## I. SECURITY / DATA ISOLATION

Verify:

- Customer A cannot leak into Customer B.
- Historical data cannot accidentally become current business truth.
- Internal memory cannot leak to customers.
- Internal tools cannot be exposed.
- Customer information remains correctly scoped.

---

## J. REGRESSION TEST RESULTS

Provide:

- Tests run.
- Tests passed.
- Tests failed.
- Tests blocked.
- Remaining known issues.
- Severity of each remaining issue.

Do not hide failures.

---

# 37. FINAL QUALITY BAR

The finished system should achieve this:

### Old behavior:

```text
Customer says something
↓
Bot finds something vaguely related
↓
Bot answers mechanically
↓
May invent information
↓
May forget previous context
↓
May repeat questions
↓
May push an irrelevant motorcycle
↓
Customer realizes it is a bot
```

### Desired behavior:

```text
Customer message
↓
AI understands the actual meaning
↓
Relevant customer memory is available
↓
Relevant recent context is available
↓
Authoritative business tools provide real facts
↓
AI decides the natural conversational response
↓
Backend validates deterministic constraints
↓
Natural Egyptian response
↓
Memory is updated
↓
Conversation continues naturally
```

The final experience should feel like:

> A smart, experienced human salesperson who remembers the customer and understands what they actually mean.

NOT:

> A chatbot that happens to know motorcycle prices.

---

# 38. MOST IMPORTANT PRINCIPLE

Do not optimize for:

> "The AI answered."

Optimize for:

> "The customer genuinely feels that they are talking to a smart, experienced human motorcycle salesperson who remembers them, understands them, does not make things up, does not waste their time, and gets better because the system continuously learns from real historical failures."

The AI remains the conversational brain.

The backend becomes:

**Memory + Reality + Validation + Tools + Performance + Data Integrity**

The historical database becomes:

**Evidence for improving the system — not a source of hallucinated customer facts.**

The paid AI API becomes:

**A resource to use intentionally — not something to spam during development.**

---

# 39. EXECUTION ORDER — FOLLOW THIS

Follow this order strictly:

### PHASE 1 — DISCOVERY

Inspect the server and existing system.

### PHASE 2 — HISTORICAL ANALYSIS

Inspect conversations, applications, customer records, failures, and logs.

### PHASE 3 — CONVERSATION 302

Perform a deep forensic analysis.

### PHASE 4 — ROOT CAUSES

Identify architectural causes.

### PHASE 5 — DESIGN

Design the memory/context/performance improvements.

### PHASE 6 — IMPLEMENTATION

Implement the smallest correct architectural changes.

### PHASE 7 — DETERMINISTIC TESTING

Test without wasting paid API requests.

### PHASE 8 — FINAL INTEGRATION

Connect the complete system.

### PHASE 9 — LIMITED REAL API QA

Use paid API requests only for carefully selected final tests.

### PHASE 10 — FIX

If anything fails, fix the root cause.

### PHASE 11 — FINAL QA

Run the final regression suite.

### PHASE 12 — REPORT

Provide the complete report described above.

---

# FINAL INSTRUCTION

Do not rush.

Do not guess.

Do not blindly rewrite prompts.

Do not waste paid API requests.

Do not build a giant hardcoded chatbot.

Do not hide failures.

Do not declare success because the code compiles.

Inspect the real system.

Learn from the real customer history.

Understand why the old agent failed.

Build the support architecture that prevents those failures from recurring.

Use the AI where AI is actually valuable.

Use deterministic code where deterministic guarantees are required.

Use historical data as evidence.

Use persistent customer memory intelligently.

Keep the system fast.

Keep customer data isolated.

Keep business facts authoritative.

And push the final agent as close as realistically possible to a genuinely human-quality Egyptian motorcycle salesperson.
