<?php

return [
    // Master switch. While false, nothing in the new agent runtime may claim
    // turns or deliver messages. See ai-agent-implementation-plan.md T01/T05.
    'enabled' => env('AGENT_ENABLED', false),

    // DEC-06: no default on purpose. Tests set this explicitly; production
    // must set AGENT_MODEL before the agent can be enabled.
    'model' => env('AGENT_MODEL'),

    // Tried in order only when AGENT_MODEL is unavailable (all keys limited
    // or Google 5xx). Comma-separated; empty = no fallback.
    'fallback_models' => env('AGENT_FALLBACK_MODELS'),
    'fallback_thinking_level' => env('AGENT_FALLBACK_THINKING_LEVEL', 'minimal'),

    // Model that reads document photos (IDs, slips, screenshots, shop signs).
    // QA 2026-10-04: gpt-5-nano missed a neon shop sign, read a manager's name
    // off a letterhead as the employee's and did not know an Uber profile;
    // a document is a few calls per customer, so a stronger reader is cheap.
    // Empty = the conversation model.
    'document_model' => env('AGENT_DOCUMENT_MODEL'),
    'document_reasoning_effort' => env('AGENT_DOCUMENT_REASONING_EFFORT', 'low'),

    // Voice notes: OpenAI transcription model (gpt-4o-mini-transcribe), with
    // Gemini as the fallback when no OpenAI key works.
    'voice_model' => env('AGENT_VOICE_MODEL', 'gpt-4o-mini-transcribe'),

    // reasoning_effort for a GPT model (OpenAiProvider): minimal|low|medium|high
    'openai_reasoning_effort' => env('AGENT_OPENAI_REASONING_EFFORT', 'minimal'),

    // Wall-clock cap for one provider call across keys and fallback models.
    'provider_budget_seconds' => env('AGENT_PROVIDER_BUDGET_SECONDS', 45),
    // per model while a fallback model is still left to try (GeminiProvider)
    'primary_timeout_seconds' => env('AGENT_PRIMARY_TIMEOUT_SECONDS', 15),

    // DEC-14: session gap length is still OPEN. No default - until decided,
    // IngestionService treats every message as belonging to the existing
    // session (never marks a new session_started_at except on the very
    // first message of a conversation).
    'session_gap_hours' => env('AGENT_SESSION_GAP_HOURS'),

    'media' => [
        // Not decision-gated (an engineering limit, not a business rule):
        // safe to default. Media larger than this is rejected and recorded
        // as metadata.media_rejected on the message, never silently dropped.
        'max_bytes' => env('AGENT_MEDIA_MAX_BYTES', 20 * 1024 * 1024),
    ],

    // DEC-07: burst debounce. Values are the owner's decision, not
    // engineering defaults - see the Decision Register. 3s split 93 bursts
    // in three days (a second message 3-10s after the first got its own
    // reply); the owner asked for one reply per burst, so 8s.
    // Owner 2026-10-04: start on the message at once and let the wait sit
    // before the "seen" instead (whatsapp-bot: the read receipt goes out
    // only with the reply). A message that lands while a reply is being
    // written still supersedes it (TurnScheduler), and the customer sees
    // no "seen" meanwhile - so a short window only catches a quick burst.
    // Rebuild (AI_SALES_AGENT_REBUILD_PLAN.md). OBS-003: redacted prompt and
    // answer on each ai_calls row - off, sampled, phones and IDs masked.
    'observability' => [
        'capture' => [
            'enabled' => (bool) env('AGENT_CAPTURE_ENABLED', false),
            'sample_rate' => (float) env('AGENT_CAPTURE_SAMPLE_RATE', 0.1),
        ],
    ],

    // OBS-005: chars per token until ai_calls has enough real calls to calibrate it
    'tokens' => ['default_chars_per_token' => (float) env('AGENT_DEFAULT_CHARS_PER_TOKEN', 4.0)],

    'turns' => [
        'debounce_seconds' => env('AGENT_TURNS_DEBOUNCE_SECONDS', 3),
        'media_debounce_seconds' => env('AGENT_TURNS_MEDIA_DEBOUNCE_SECONDS', 5),
        'max_wait_seconds' => env('AGENT_TURNS_MAX_WAIT_SECONDS', 15),
        // Not decision-gated: how long a turn waits for a pending voice
        // transcription (DEC-08) before claiming anyway.
        'transcript_wait_seconds' => env('AGENT_TURNS_TRANSCRIPT_WAIT_SECONDS', 10),
        // AI down (quota, rate limit): how long a turn keeps retrying, once
        // a minute, before giving up. No handoff - staff cannot fix it.
        'outage_retry_minutes' => env('AGENT_TURNS_OUTAGE_RETRY_MINUTES', 720),
    ],

    'delivery' => [
        // Not decision-gated (an engineering limit).
        'max_attempts' => env('AGENT_DELIVERY_MAX_ATTEMPTS', 3),
    ],

    'reply' => [
        // Not decision-gated: a WhatsApp-readability limit, not a business rule.
        'max_chars' => env('AGENT_REPLY_MAX_CHARS', 700),
    ],

    // T06: tool classes the ToolRegistry loads. Each task that adds a tool
    // appends its class here - no central switch over tool names.
    // Seconds a customer-reply model call may take before it is dropped for the next key/model.
    'reply_timeout_seconds' => env('AGENT_REPLY_TIMEOUT_SECONDS', 12),

    'tools' => [
        \App\Agent\Tools\GetEarlierMessagesTool::class,
        \App\Agent\Tools\SendReplyTool::class,
        \App\Agent\Tools\HandoffToHumanTool::class,
        \App\Agent\Tools\SearchMotorcyclesTool::class,
        \App\Agent\Tools\GetMotorcycleDetailsTool::class,
        \App\Agent\Tools\LookupMotorcycleSpecsOnlineTool::class,
        \App\Agent\Tools\SendMotorcycleImagesTool::class,
        \App\Agent\Tools\IdentifyMotorcycleFromImageTool::class,
        \App\Agent\Tools\GetBranchInformationTool::class,
        \App\Agent\Tools\GetApplicationRequirementsTool::class,
        \App\Agent\Tools\GetInstallmentOfferTool::class,
        \App\Agent\Tools\GetInstallmentOptionsTool::class,
        \App\Agent\Tools\RecordWorkProfileTool::class,
        \App\Agent\Tools\CheckEligibilityTool::class,
        \App\Agent\Tools\RecordCustomerDataTool::class,
        \App\Agent\Tools\StartApplicationTool::class,
        \App\Agent\Tools\UpdateApplicationSelectionTool::class,
        \App\Agent\Tools\WithdrawApplicationTool::class,
        \App\Agent\Tools\SubmitApplicationTool::class,
        \App\Agent\Tools\ProcessDocumentTool::class,
    ],

    // T11: field validator registry, keyed by requirement_fields.data_type.
    'field_validators' => [
        'national_id' => \App\Domain\Applications\Validators\NationalIdValidator::class,
        'phone' => \App\Domain\Applications\Validators\PhoneValidator::class,
        'date' => \App\Domain\Applications\Validators\DateValidator::class,
        'money' => \App\Domain\Applications\Validators\MoneyValidator::class,
        'integer' => \App\Domain\Applications\Validators\IntegerValidator::class,
        'enum' => \App\Domain\Applications\Validators\EnumValidator::class,
        'person_name' => \App\Domain\Applications\Validators\NonEmptyStringValidator::class,
        'address' => \App\Domain\Applications\Validators\NonEmptyStringValidator::class,
        'string' => \App\Domain\Applications\Validators\NonEmptyStringValidator::class,
    ],

    // Rough centre of each governorate (lat, lng) - only to order our
    // branches nearest-first for a customer where we have none.
    'governorate_coordinates' => [
        'cairo' => [30.04, 31.24], 'giza' => [30.01, 31.21], 'alexandria' => [31.20, 29.92], 'qalyubia' => [30.46, 31.18],
        'port_said' => [31.26, 32.30], 'suez' => [29.97, 32.53], 'dakahlia' => [31.04, 31.38], 'sharqia' => [30.59, 31.50],
        'gharbia' => [30.79, 31.00], 'monufia' => [30.55, 31.01], 'beheira' => [31.03, 30.47], 'kafr_el_sheikh' => [31.11, 30.94],
        'damietta' => [31.42, 31.81], 'north_sinai' => [31.13, 33.80], 'south_sinai' => [28.24, 33.62], 'ismailia' => [30.60, 32.27],
        'beni_suef' => [29.07, 31.10], 'faiyum' => [29.31, 30.84], 'minya' => [28.10, 30.75], 'asyut' => [27.18, 31.18],
        'sohag' => [26.56, 31.69], 'qena' => [26.16, 32.72], 'aswan' => [24.09, 32.90], 'luxor' => [25.69, 32.64],
        'red_sea' => [27.26, 33.81], 'new_valley' => [25.45, 30.55], 'matrouh' => [31.35, 27.24],
    ],

    // T11: eligibility rule evaluator registry, keyed by eligibility_rules.rule_type.
    'eligibility_rules' => [
        'age_range' => \App\Domain\Applications\EligibilityRules\AgeRangeEvaluator::class,
        'financing_cap' => \App\Domain\Applications\EligibilityRules\FinancingCapEvaluator::class,
        'minimum_value' => \App\Domain\Applications\EligibilityRules\MinimumValueEvaluator::class,
        'excluded_occupation' => \App\Domain\Applications\EligibilityRules\ExcludedOccupationEvaluator::class,
        'gender' => \App\Domain\Applications\EligibilityRules\GenderEvaluator::class,
    ],

    // DEC-19: factual catalog data, not a business rule - implementer
    // lists it, owner reviews. Free text elsewhere is validated against
    // this list, never invented by the AI or accepted as arbitrary text.
    'installments' => [
        // The first installment falls due this many days after pickup.
        'first_payment_after_days' => (int) env('AGENT_FIRST_INSTALLMENT_AFTER_DAYS', 45),

        // Owner's policy (2026-09-26) for "why does it cost more on
        // installments". The agent explains it in its own words; editable
        // from the dashboard (إعدادات البوت).
        'price_difference_explanation' => 'التقسيط بيبقى عن طريق جهة تمويل خارجية. الفرق بين سعر الكاش وسعر التقسيط هو تكلفة التمويل والخدمات اللي بتتقدم في نظام التقسيط على مدار المدة اللي العميل اختارها، مش سعر المكنة لوحدها، والسعر بالتقسيط بيبقى شامل الضريبة والخدمات. لو العميل عايز يشتري كاش، سعر الكاش متاح في كل فروعنا.',
    ],

    'catalog' => [
        'categories' => [
            'sport' => 'رياضية',
            'naked' => 'نيكد',
            'cruiser' => 'كروزر',
            'touring' => 'توورينج',
            'scooter' => 'سكوتر',
            'off_road' => 'أوف رود',
            'commuter' => 'اقتصادية',
        ],
    ],

    'recognition' => [
        // DEC-05: no defaults. Until set, identify_motorcycle_from_image
        // always reports band=unknown rather than overclaiming.
        'match_threshold' => env('AGENT_RECOGNITION_MATCH_THRESHOLD'),
        'similar_threshold' => env('AGENT_RECOGNITION_SIMILAR_THRESHOLD'),
    ],

    'images' => [
        // Not decided/registered; until set, the recent-duplicate skip
        // (§6.3) never triggers - images can always be resent.
        'resend_window_minutes' => env('AGENT_IMAGES_RESEND_WINDOW_MINUTES'),
    ],

    // T10: the 27 Egyptian governorates. Factual data, not a business
    // rule - implementer lists it, owner reviews (same as agent.catalog.categories).
    'governorates' => [
        'cairo' => 'القاهرة',
        'giza' => 'الجيزة',
        'alexandria' => 'الإسكندرية',
        'qalyubia' => 'القليوبية',
        'port_said' => 'بورسعيد',
        'suez' => 'السويس',
        'dakahlia' => 'الدقهلية',
        'sharqia' => 'الشرقية',
        'gharbia' => 'الغربية',
        'monufia' => 'المنوفية',
        'beheira' => 'البحيرة',
        'kafr_el_sheikh' => 'كفر الشيخ',
        'damietta' => 'دمياط',
        'north_sinai' => 'شمال سيناء',
        'south_sinai' => 'جنوب سيناء',
        'ismailia' => 'الإسماعيلية',
        'beni_suef' => 'بني سويف',
        'faiyum' => 'الفيوم',
        'minya' => 'المنيا',
        'asyut' => 'أسيوط',
        'sohag' => 'سوهاج',
        'qena' => 'قنا',
        'aswan' => 'أسوان',
        'luxor' => 'الأقصر',
        'red_sea' => 'البحر الأحمر',
        'new_valley' => 'الوادي الجديد',
        'matrouh' => 'مطروح',
    ],

    'applications' => [
        'concurrent_active_policy' => env('AGENT_APPLICATIONS_CONCURRENT_ACTIVE_POLICY'),

        // A customer silent this long after our last message in an open
        // application gets one short reminder of the single thing left (a
        // second one a day later). Unset = no reminders.
        'nudge_after_minutes' => env('AGENT_APPLICATION_NUDGE_AFTER_MINUTES'),
        'nudge_quiet_from' => env('AGENT_APPLICATION_NUDGE_QUIET_FROM', 23),
        'nudge_quiet_to' => env('AGENT_APPLICATION_NUDGE_QUIET_TO', 9),
    ],

    'handoff' => [
        // DEC-13: automatic handoff threshold is still OPEN. No default -
        // T17 must not trigger handOffForFailures until this is set.
        'max_failed_turns' => env('AGENT_HANDOFF_MAX_FAILED_TURNS'),

        // While a conversation waits for staff the bot says nothing, so a
        // customer writing "فين الراجل ده؟" got silence. This line (sent at
        // most once per interval) tells them a person is coming. Unset =
        // no acknowledgement.
        'waiting_message' => env('AGENT_HANDOFF_WAITING_MESSAGE'),
        'waiting_ack_interval_minutes' => env('AGENT_HANDOFF_WAITING_ACK_INTERVAL_MINUTES', 30),

        // No staff reply this long after the handoff opened -> the
        // conversation goes back to the bot (and any waiting messages are
        // answered). Unset = handoffs stay open until staff close them.
        'return_to_agent_after_minutes' => env('AGENT_HANDOFF_RETURN_TO_AGENT_AFTER_MINUTES'),

        // A staff message from the phone silences the bot in that chat for
        // this long (0 = never). The bot and a colleague used to answer the
        // same customer in the same minute.
        'staff_pause_minutes' => env('AGENT_STAFF_PAUSE_MINUTES', 10),
    ],

    // T17: agent loop limits, all DEC-23 (still OPEN) - no defaults.
    // AgentRunner refuses to run() until every one of these is set.
    'runtime' => [
        'max_model_calls' => env('AGENT_RUNTIME_MAX_MODEL_CALLS'),
        'max_tool_calls' => env('AGENT_RUNTIME_MAX_TOOL_CALLS'),
        'wall_clock_seconds' => env('AGENT_RUNTIME_WALL_CLOCK_SECONDS'),
    ],

    'guard' => [
        // DEC-23: the number guard's minimum value is still OPEN. No
        // default - until set, the guard checks every numeric token.
        'number_min_value' => env('AGENT_GUARD_NUMBER_MIN_VALUE'),
    ],

    'instructions' => [
        // T19 readiness gate: the owner records the L0 wording version
        // they reviewed here. No default - cutover is refused until it
        // matches the live file's first line (resources/agent/instructions/agent.md).
        'approved_version' => env('AGENT_INSTRUCTIONS_APPROVED_VERSION'),
    ],

    'fallback' => [
        // DEC-13: fallback wording is still OPEN. No default - this is the
        // only string PHP itself is allowed to send to a customer, and
        // only after it is explicitly set.
        'message' => env('AGENT_FALLBACK_MESSAGE'),
    ],

    'redaction' => [
        // T06 baseline until T11's field registry supplies is_sensitive
        // flags. Keys named here are masked wherever they appear in traces.
        'keys' => ['national_id', 'document_text'],
    ],

    'context' => [
        // DEC-23: agent runtime limits are still OPEN. No default - the
        // pinned-memory cap is only enforced once this is set (T08).
        'pinned_memory_tokens' => env('AGENT_CONTEXT_PINNED_MEMORY_TOKENS'),

        // CTX-006 (L7): recent-messages budget, in tokens as the provider
        // bills them (TokenEstimator, calibrated from ai_calls). Filled
        // newest first; the oldest messages that do not fit fall to the
        // summary (L6). The current turn (L8) is never cut. 4,000 tokens
        // = about 60-80 short WhatsApp lines.
        'recent_messages_tokens' => env('AGENT_CONTEXT_RECENT_MESSAGES_TOKENS', 4000),

        // Phase 1: raw messages that stay raw once summarized - conversation
        // for tone and continuity, never a source of facts. Older talk is
        // the summary (context only); what is true is in the facts. A
        // message the summary has not absorbed yet is never hidden: the
        // summarizer is triggered (agent.summary) by messages older than
        // these N, so a lower AGENT_SUMMARY_TRIGGER_MESSAGES shrinks the window.
        'recent_messages_count' => env('AGENT_CONTEXT_RECENT_MESSAGES_COUNT', 8),
    ],

    'summary' => [
        // CTX-010: the summarizer runs once this many tokens of messages
        // fell out of the L7 window since the last summary.
        'trigger_tokens' => env('AGENT_SUMMARY_TRIGGER_TOKENS', 1500),
        // Messages older than the last agent.context.recent_messages_count raw ones that the summary has not absorbed yet (either trigger is enough).
        'trigger_messages' => env('AGENT_SUMMARY_TRIGGER_MESSAGES', 6),
        // Items kept per section of the structured summary.
        'max_items' => 8,
    ],

    // T15: document rule evaluator registry, keyed by document_types.validation_rules[].rule_type.
    'document_rules' => [
        'matches_application_field' => \App\Domain\Documents\DocumentRules\MatchesApplicationFieldEvaluator::class,
        'not_expired' => \App\Domain\Documents\DocumentRules\NotExpiredEvaluator::class,
        'not_past' => \App\Domain\Documents\DocumentRules\NotPastEvaluator::class,
        'min_days_since' => \App\Domain\Documents\DocumentRules\MinDaysSinceEvaluator::class,
        'period_coverage' => \App\Domain\Documents\DocumentRules\PeriodCoverageEvaluator::class,
    ],

    'ocr' => [
        // DEC-22 (agreed): Vision OCR only for document processing, never
        // logged. No default timeout beyond what's already in .env.
        'google_vision_api_key' => env('GOOGLE_VISION_API_KEY'),
        'timeout' => env('GOOGLE_VISION_TIMEOUT', 60),
    ],

    'documents' => [
        // DEC-04: name-match threshold is still OPEN. No default -
        // MatchesApplicationFieldEvaluator requires an exact normalized
        // match until the owner sets a fuzzy-match tolerance here.
        'name_match_threshold' => env('AGENT_DOCUMENTS_NAME_MATCH_THRESHOLD'),
    ],

    'teaching' => [
        'coach_model' => env('AGENT_TEACHING_COACH_MODEL', 'gemini-3.5-flash'),
        'coach_thinking' => env('AGENT_TEACHING_COACH_THINKING', 'low'),
        'lessons_tokens' => env('AGENT_TEACHING_LESSONS_TOKENS', 3000),
    ],
];
