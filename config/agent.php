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

    // Wall-clock cap for one provider call across keys and fallback models.
    'provider_budget_seconds' => env('AGENT_PROVIDER_BUDGET_SECONDS', 45),

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
    'turns' => [
        'debounce_seconds' => env('AGENT_TURNS_DEBOUNCE_SECONDS', 8),
        'media_debounce_seconds' => env('AGENT_TURNS_MEDIA_DEBOUNCE_SECONDS', 10),
        'max_wait_seconds' => env('AGENT_TURNS_MAX_WAIT_SECONDS', 25),
        // Not decision-gated: how long a turn waits for a pending voice
        // transcription (DEC-08) before claiming anyway.
        'transcript_wait_seconds' => env('AGENT_TURNS_TRANSCRIPT_WAIT_SECONDS', 10),
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
    'tools' => [
        \App\Agent\Tools\GetEarlierMessagesTool::class,
        \App\Agent\Tools\SendReplyTool::class,
        \App\Agent\Tools\HandoffToHumanTool::class,
        \App\Agent\Tools\GetBusinessKnowledgeTool::class,
        \App\Agent\Tools\SearchMotorcyclesTool::class,
        \App\Agent\Tools\GetMotorcycleDetailsTool::class,
        \App\Agent\Tools\LookupMotorcycleSpecsOnlineTool::class,
        \App\Agent\Tools\SendMotorcycleImagesTool::class,
        \App\Agent\Tools\IdentifyMotorcycleFromImageTool::class,
        \App\Agent\Tools\GetBranchInformationTool::class,
        \App\Agent\Tools\GetApplicationRequirementsTool::class,
        \App\Agent\Tools\GetInstallmentOfferTool::class,
        \App\Agent\Tools\GetInstallmentOptionsTool::class,
        \App\Agent\Tools\CalculateInstallmentTool::class,
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

    // T11: eligibility rule evaluator registry, keyed by eligibility_rules.rule_type.
    'eligibility_rules' => [
        'age_range' => \App\Domain\Applications\EligibilityRules\AgeRangeEvaluator::class,
        'financing_cap' => \App\Domain\Applications\EligibilityRules\FinancingCapEvaluator::class,
        'minimum_value' => \App\Domain\Applications\EligibilityRules\MinimumValueEvaluator::class,
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

        // T16 (L7): recent-messages budget. No default - until set, L7
        // includes the full current session with no trimming.
        'recent_messages_tokens' => env('AGENT_CONTEXT_RECENT_MESSAGES_TOKENS'),
    ],

    'summary' => [
        // T16: how many messages must fall out of the L7 window (since the
        // last summary) before the summarizer job is dispatched. No
        // default - until set, the summary is never triggered.
        'trigger_messages' => env('AGENT_SUMMARY_TRIGGER_MESSAGES'),
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
        'lessons_tokens' => env('AGENT_TEACHING_LESSONS_TOKENS', 1500),
        'quick_check_cases' => env('AGENT_TEACHING_QUICK_CHECK_CASES', 3),
    ],
];
