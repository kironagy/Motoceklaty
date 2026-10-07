<?php

namespace App\Domain\Memory;

use App\Domain\Conversations\CustomerStatements;
use App\Models\Customer;
use App\Models\EligibilityRule;
use App\Models\Machine;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Log;

/**
 * Persistent structured memory of one customer (ENHANCE-Ai §7-11), kept in
 * customers.memory. It is context for the model, never a workflow: nothing
 * here decides what to say.
 *
 * - facts: what the customer told us about himself. Every fact keeps its
 *   source. "customer_statement" only when the words the model relied on
 *   are really in one of his messages; anything else is "ai_inference" and
 *   never replaces something he said. A newer statement replaces the old
 *   one, which moves to the fact's history.
 * - motorcycles: every model he talked about with how far he went with it.
 *   Asking a price is not choosing it: the tools raise a model to
 *   asked_about at most; selected comes from his own words or from an
 *   application, applied from a submission.
 * - conversation: the model's own short note of where the talk stands.
 *
 * Isolation: every call is keyed by the customer id the runtime injects
 * (ToolContext) and quotes are only looked up in that conversation, so one
 * customer's words can never become another customer's memory.
 */
class CustomerMemory
{
    public const FACT_KEYS = [
        'name', 'age', 'job', 'workplace', 'insured', 'monthly_income', 'governorate', 'area',
        'monthly_budget', 'cash_budget', 'down_payment', 'preferred_duration', 'usage',
        'has_driving_license', 'gender', 'nationality',
    ];

    /** Arabic labels for the prompt - the keys never reach the model's reply. */
    private const FACT_LABELS = [
        'name' => 'الاسم', 'age' => 'السن', 'job' => 'الشغل', 'workplace' => 'مكان الشغل', 'insured' => 'التأمين',
        'monthly_income' => 'الدخل الشهري', 'governorate' => 'المحافظة', 'area' => 'المنطقة',
        'monthly_budget' => 'القسط اللي يقدر عليه', 'cash_budget' => 'الميزانية', 'down_payment' => 'المقدم اللي يقدر عليه',
        'preferred_duration' => 'المدة اللي عايزها', 'usage' => 'هيستخدمه في', 'has_driving_license' => 'الرخصة',
        'applicant' => 'مين هيقدّم', 'gender' => 'النوع', 'nationality' => 'الجنسية',
    ];

    public const STAGES = ['mentioned', 'asked_about', 'compared', 'interested', 'preferred', 'selected', 'applied', 'rejected'];

    private const STAGE_RANK = ['mentioned' => 1, 'asked_about' => 2, 'compared' => 3, 'interested' => 4, 'preferred' => 5, 'selected' => 6, 'applied' => 7];

    public const STAGE_LABELS = [
        'mentioned' => 'اتذكر في الكلام بس', 'asked_about' => 'سأل عنه', 'compared' => 'بيقارن بيه', 'interested' => 'مهتم بيه',
        'preferred' => 'مفضّله', 'selected' => 'اختاره', 'applied' => 'قدّم عليه', 'rejected' => 'مش عايزه',
    ];

    /** Stages the model may set only with words he really wrote. */
    private const NEEDS_QUOTE = ['preferred', 'selected', 'rejected'];

    private const MAX_MOTORCYCLES = 8;

    private const MAX_HISTORY = 3;

    /**
     * MEM-003: a topic or open question from yesterday ("مستني صورة
     * البطاقة" after it was accepted) was shown as current. These expire;
     * expired items are not rendered and are dropped on the next write.
     */
    private const TOPIC_TTL_HOURS = 24;

    private const OBJECTION_TTL_DAYS = 7;

    /** Interest in a motorcycle a month ago is not interest now - unless he applied for it. */
    private const MOTORCYCLE_TTL_DAYS = 30;

    public function __construct(private readonly CustomerStatements $statements)
    {
    }

    /** @return array{facts: array, motorcycles: array, conversation: array} */
    public function get(int $customerId): array
    {
        $memory = Customer::whereKey($customerId)->value('memory');
        $memory = is_string($memory) ? (json_decode($memory, true) ?: []) : (array) ($memory ?? []);

        return [
            'facts' => (array) ($memory['facts'] ?? []),
            'motorcycles' => (array) ($memory['motorcycles'] ?? []),
            'conversation' => (array) ($memory['conversation'] ?? []),
            // facts about the person applying instead of him: {for: relation, facts: {...}}
            'applicant_facts' => (array) ($memory['applicant_facts'] ?? []),
        ];
    }

    /**
     * The update the model sent with its reply. Invalid parts are dropped,
     * never the reply.
     *
     * @return array{applied: string[], rejected: array<int, array{item: string, code: string}>}
     */
    public function applyModelUpdate(int $customerId, int $conversationId, array $update): array
    {
        $memory = $this->get($customerId);
        $applied = [];
        $rejected = [];
        $now = now()->toIso8601String();

        foreach (array_slice((array) ($update['facts'] ?? []), 0, 10) as $fact) {
            $key = (string) ($fact['key'] ?? '');
            $value = trim((string) ($fact['value'] ?? ''));
            $quote = trim((string) ($fact['quote'] ?? ''));

            if (! in_array($key, self::FACT_KEYS, true) || $value === '' || mb_strlen($value) > 120) {
                $rejected[] = ['item' => $key ?: '?', 'code' => 'INVALID_FACT'];

                continue;
            }

            if ($key === 'age' && ! $this->plausibleAge($value)) {
                $rejected[] = ['item' => 'age', 'code' => 'INVALID_AGE'];

                continue;
            }

            $verified = $quote !== '' && $this->quoteIsHis($conversationId, $quote)
                && ($key !== 'age' || $this->ageInQuote($value, $quote));

            // Conversation 206: "عندها ٤٥" (his mother) replaced HIS age 20 and
            // "عنده ٢١ سنه" (his brother) replaced it again. Another person's
            // facts are kept apart, for the person applying now only.
            if (($fact['about'] ?? 'customer') === 'other_applicant') {
                $result = $this->putApplicantFact($memory, $conversationId, $key, $value, $verified ? 'customer_statement' : 'ai_inference', $verified ? $quote : null, $now);
                $result === null ? $rejected[] = ['item' => $key, 'code' => 'WEAKER_THAN_STATED'] : $applied[] = "applicant_fact:{$key}";

                continue;
            }

            $result = $this->putFact($memory, $key, $value, $verified ? 'customer_statement' : 'ai_inference', $verified ? $quote : null, $conversationId, $now);

            $result === null ? $rejected[] = ['item' => $key, 'code' => 'WEAKER_THAN_STATED'] : $applied[] = "fact:{$key}";
        }

        foreach (array_slice((array) ($update['motorcycles'] ?? []), 0, 6) as $item) {
            $code = $this->putModelStage($memory, $item, $conversationId, $now);
            $code === null ? $applied[] = 'motorcycle:'.($item['id'] ?? $item['name'] ?? '?') : $rejected[] = ['item' => (string) ($item['id'] ?? $item['name'] ?? '?'), 'code' => $code];
        }

        $conversation = $memory['conversation'];

        foreach (['topic' => 80, 'open_question' => 140] as $field => $limit) {
            if (array_key_exists($field, $update)) {
                $text = trim((string) $update[$field]);
                $conversation[$field] = $text === '' ? null : mb_substr($text, 0, $limit);
                $conversation[$field.'_at'] = $text === '' ? null : $now;
                // written in this stage; a new stage (application opened, submitted) ends it
                $conversation['stage'] = $this->stage($customerId);
                $applied[] = $field;
            }
        }

        if (isset($update['objections']) && is_array($update['objections'])) {
            $objections = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 60), $update['objections'])));
            $conversation['objections'] = array_slice($objections, 0, 4);
            $conversation['objections_at'] = $now;
            $applied[] = 'objections';
        }

        if ($conversation !== $memory['conversation']) {
            $conversation['updated_at'] = $now;
            $memory['conversation'] = array_filter($conversation, fn ($v) => $v !== null && $v !== []);
        }

        $this->save($customerId, $memory);

        return ['applied' => $applied, 'rejected' => $rejected];
    }

    /**
     * What a tool call says for sure, without reading any text: a price,
     * details or photos looked up = he asked about it; an application with
     * a motorcycle = selected; a submission = applied.
     */
    public function recordToolEvent(int $customerId, string $tool, array $args, array $result): void
    {
        if (! ($result['ok'] ?? false)) {
            return;
        }

        $data = (array) ($result['data'] ?? []);

        try {
            $ids = match ($tool) {
                'get_installment_offer', 'calculate_installment', 'send_motorcycle_images' => [(int) ($args['motorcycle_id'] ?? 0)],
                // the tool may fill the motorcycle from the last quote: the application says which
                'start_application', 'update_application_selection' => [(int) ($args['motorcycle_id'] ?? 0)
                    ?: (int) \App\Models\Application::whereKey((int) ($data['application_id'] ?? $data['snapshot']['application_id'] ?? $data['application_now']['application_id'] ?? 0))->value('machine_id')],
                'get_motorcycle_details' => array_map('intval', (array) ($args['motorcycle_ids'] ?? [])),
                'submit_application' => ($data['submitted'] ?? false) === true
                    ? [(int) \App\Models\Application::where('customer_id', $customerId)->whereNotNull('submitted_at')->latest('id')->value('machine_id')]
                    : [],
                default => [],
            };

            $ids = array_values(array_unique(array_filter($ids)));

            if ($ids === []) {
                return;
            }

            $stage = match ($tool) {
                'start_application', 'update_application_selection' => 'selected',
                'submit_application' => 'applied',
                default => 'asked_about',
            };

            $memory = $this->get($customerId);
            $now = now()->toIso8601String();

            foreach (Machine::whereIn('id', $ids)->get(['id', 'name']) as $machine) {
                $this->raiseStage($memory, (int) $machine->id, trim((string) $machine->name), $stage, $now, exact: $stage !== 'asked_about');
            }

            $this->save($customerId, $memory);
        } catch (\Throwable $e) {
            Log::warning('Customer memory tool event failed', ['tool' => $tool, 'error' => $e->getMessage()]);
        }
    }

    /** The age he said himself, if any - never an inference. */
    /**
     * The memory as data for the facts view: expired items already dropped,
     * nothing rendered. Read-only.
     *
     * @return array{facts: array, motorcycles: array, conversation: array, applicant_facts: array}
     */
    public function current(int $customerId): array
    {
        return $this->withoutExpired($this->get($customerId), $customerId);
    }

    public function statedAge(int $customerId): ?int
    {
        $fact = $this->get($customerId)['facts']['age'] ?? null;

        if (! $fact || ($fact['source'] ?? null) !== 'customer_statement') {
            return null;
        }

        return preg_match('/(?<!\d)\d{2}(?!\d)/', $this->westernDigits((string) $fact['value']), $m) ? (int) $m[0] : null;
    }

    public static function minimumAge(): ?int
    {
        try {
            $min = EligibilityRule::where('rule_type', 'age_range')->where('is_active', true)->whereNull('customer_type_id')->get()
                ->map(fn ($r) => $r->params['min'] ?? null)->filter()->max();

            return $min === null ? null : (int) $min;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The memory as a short block for the system prompt - Arabic labels,
     * provenance spelled out, nothing a customer could be shown verbatim.
     */
    /** Memory facts the application also holds, by application field key. */
    private const APPLICATION_KEYS = ['name' => 'full_name', 'monthly_income' => 'monthly_income'];

    public function forPrompt(int $customerId, ?int $conversationId = null, ?int $applicationId = null): ?string
    {
        $memory = $this->withoutExpired($this->get($customerId), $customerId);
        $lines = [];

        $stated = [];
        $guessed = [];
        $changed = [];
        $conflicts = [];

        // One value per fact: what the application holds is shown with the
        // application; a document/staff value beats his words (rebuild).
        $held = $applicationId === null ? collect() : \App\Models\ApplicationData::where('application_id', $applicationId)
            ->where('party', 'applicant')->where('status', 'valid')->whereIn('field_key', array_values(self::APPLICATION_KEYS))
            ->get(['field_key', 'value', 'source'])->keyBy('field_key');

        foreach (self::FACT_KEYS as $key) {
            $fact = $memory['facts'][$key] ?? null;

            if (! $fact || blank($fact['value'] ?? null)) {
                continue;
            }

            $label = self::FACT_LABELS[$key];
            $entry = "{$label}: {$fact['value']}";

            if (($row = $held->get(self::APPLICATION_KEYS[$key] ?? '')) !== null && filled($row->value)) {
                if (in_array($row->source, ['document', 'staff'], true)
                    && \App\Support\ArabicTextNormalizer::normalize((string) $row->value) !== \App\Support\ArabicTextNormalizer::normalize((string) $fact['value'])) {
                    $conflicts[] = "{$label}: قال \"{$fact['value']}\" والمستند فيه \"{$row->value}\" - المستند هو الصح";
                }

                continue;
            }

            if (($fact['source'] ?? null) === 'ai_inference') {
                $guessed[] = $entry;

                continue;
            }

            $stated[] = $entry;

            $previous = $fact['history'][0] ?? null;

            if ($previous && ($previous['source'] ?? null) === 'customer_statement' && $previous['value'] !== $fact['value']) {
                $changed[] = "{$label} كان \"{$previous['value']}\" وبقى \"{$fact['value']}\"";
            }
        }

        if ($stated !== []) {
            $lines[] = 'قاله بنفسه: '.implode(' · ', $stated);
        }

        $applicantFacts = $memory['applicant_facts'] ?? [];
        $nowFor = $conversationId !== null ? \App\Domain\Applications\Applicant::label(
            \App\Domain\Applications\Applicant::fromProfile(app(\App\Domain\Applications\WorkProfiles::class)->get($conversationId))
        ) : null;

        if (! empty($applicantFacts['facts']) && ($applicantFacts['for'] ?? null) === $nowFor) {
            $about = [];

            foreach ($applicantFacts['facts'] as $key => $fact) {
                $about[] = (self::FACT_LABELS[$key] ?? $key).': '.$fact['value'].(($fact['source'] ?? null) === 'ai_inference' ? ' (مش مؤكد)' : '');
            }

            $lines[] = "عن اللي هيقدّم بدله ({$nowFor}) - مش عنه هو: ".implode(' · ', $about);
        }

        if ($conflicts !== []) {
            $lines[] = 'كلامه عكس المستند (اسأله بلطف لو فرقت): '.implode(' · ', $conflicts);
        }

        if ($changed !== []) {
            $lines[] = 'غيّر كلامه (الجديد هو الصح): '.implode(' · ', $changed);
        }

        if ($guessed !== []) {
            $lines[] = 'استنتاج مش مؤكد (ما تبنيش عليه، ولو محتاجه اسأله): '.implode(' · ', $guessed);
        }

        $byStage = [];

        foreach ($this->sortedMotorcycles($memory['motorcycles']) as $bike) {
            $byStage[$bike['stage']][] = trim(($bike['name'] ?? '?').(isset($bike['id']) ? " (#{$bike['id']})" : ''));
        }

        $bikeParts = [];

        foreach (['applied', 'selected', 'preferred', 'interested', 'compared', 'asked_about', 'mentioned', 'rejected'] as $stage) {
            if (! empty($byStage[$stage])) {
                $bikeParts[] = self::STAGE_LABELS[$stage].': '.implode('، ', array_slice($byStage[$stage], 0, 4));
            }
        }

        if ($bikeParts !== []) {
            $lines[] = 'الموتوسيكلات: '.implode(' · ', $bikeParts).' - سؤال عن سعر مش معناه إنه اختار';
        }

        $conversation = $memory['conversation'];

        if (filled($conversation['topic'] ?? null)) {
            $lines[] = 'آخر كلامكم كان عن: '.$conversation['topic'];
        }

        if (filled($conversation['open_question'] ?? null)) {
            $lines[] = 'مستني/فاضل: '.$conversation['open_question'];
        }

        if (! empty($conversation['objections'])) {
            $lines[] = 'اعتراضاته: '.implode('، ', $conversation['objections']);
        }

        $age = $this->statedAge($customerId);
        $min = self::minimumAge();

        if ($age !== null && $min !== null && $age < $min) {
            $lines[] = "قال إن سنه {$age} وده أقل من {$min}: ما ينفعش يقدّم باسمه - ما تجمعش بيانات ولا ورق لطلب باسمه؛ قوله بلطف من أول مرة ووضّح البديل (حد شغال يقدّم باسمه)";
        }

        if ($conversationId !== null && ($gap = $this->silenceGap($conversationId)) !== null) {
            $lines[] = "راجع بعد {$gap} من آخر رسالة: كمّل من اللي فات من غير ترحيب جديد ولا إعادة أسئلة";
        }

        if ($lines === []) {
            return null;
        }

        $known = array_values(array_filter(array_map(
            fn ($key) => ($memory['facts'][$key]['source'] ?? null) === 'customer_statement' ? self::FACT_LABELS[$key] : null,
            ['name', 'age', 'job', 'insured', 'preferred_duration', 'governorate']
        )));

        if ($known !== []) {
            $lines[] = 'ما تسألوش تاني عن: '.implode('، ', $known);
        }

        return "## ذاكرة العميل (من كل كلامه معانا - للفهم بس، ما تتقالش للعميل)\n- ".implode("\n- ", $lines);
    }

    // ---------------------------------------------------------------- internals

    /** Facts about the person applying instead of him - dropped when that person changes. */
    private function putApplicantFact(array &$memory, int $conversationId, string $key, string $value, string $source, ?string $quote, string $now): ?string
    {
        $for = \App\Domain\Applications\Applicant::label(
            \App\Domain\Applications\Applicant::fromProfile(app(\App\Domain\Applications\WorkProfiles::class)->get($conversationId))
        );
        $sub = ($memory['applicant_facts']['for'] ?? null) === $for ? ['facts' => (array) ($memory['applicant_facts']['facts'] ?? [])] : ['facts' => []];
        $result = $this->putFact($sub, $key, $value, $source, $quote, $conversationId, $now);
        $memory['applicant_facts'] = ['for' => $for, 'facts' => $sub['facts']];

        return $result;
    }

    /** @return ?string the stored source, or null when refused */
    private function putFact(array &$memory, string $key, string $value, string $source, ?string $quote, int $conversationId, string $now): ?string
    {
        $current = $memory['facts'][$key] ?? null;

        // A guess never replaces what he said, or a document / staff value.
        if ($current && $source === 'ai_inference' && ($current['source'] ?? null) !== 'ai_inference') {
            return null;
        }

        if ($current && $this->same((string) $current['value'], $value)) {
            $memory['facts'][$key]['at'] = $now;

            if ($source === 'customer_statement' && ($current['source'] ?? null) === 'ai_inference') {
                $memory['facts'][$key]['source'] = $source;
                $memory['facts'][$key]['quote'] = $quote;
            }

            return $memory['facts'][$key]['source'];
        }

        $history = (array) ($current['history'] ?? []);

        if ($current) {
            array_unshift($history, ['value' => $current['value'], 'source' => $current['source'] ?? null, 'at' => $current['at'] ?? null]);
        }

        $memory['facts'][$key] = array_filter([
            'value' => $value,
            'source' => $source,
            'quote' => $quote !== null ? mb_substr($quote, 0, 160) : null,
            'conversation_id' => $conversationId,
            'at' => $now,
            'history' => array_slice($history, 0, self::MAX_HISTORY),
        ], fn ($v) => $v !== null && $v !== []);

        return $source;
    }

    private function putModelStage(array &$memory, array $item, int $conversationId, string $now): ?string
    {
        $stage = (string) ($item['stage'] ?? '');

        if (! in_array($stage, self::STAGES, true)) {
            return 'INVALID_STAGE';
        }

        $machine = isset($item['id']) && (int) $item['id'] > 0 ? Machine::find((int) $item['id']) : null;

        if (isset($item['id']) && (int) $item['id'] > 0 && ! $machine) {
            return 'UNKNOWN_MOTORCYCLE';
        }

        $name = $machine ? trim((string) $machine->name) : mb_substr(trim((string) ($item['name'] ?? '')), 0, 60);

        if ($name === '') {
            return 'INVALID_MOTORCYCLE';
        }

        $quote = trim((string) ($item['quote'] ?? ''));
        $verified = $quote !== '' && $this->quoteIsHis($conversationId, $quote);

        if (in_array($stage, self::NEEDS_QUOTE, true) && ! $verified) {
            // "VLR 150 بكام؟" is not a choice: without his words it is at most interest.
            if ($stage === 'rejected') {
                return 'QUOTE_REQUIRED';
            }

            $stage = 'interested';
        }

        $this->raiseStage($memory, $machine?->id, $name, $stage, $now, exact: $verified);

        return null;
    }

    /**
     * exact = the stage is known for sure (his own words, an application):
     * it replaces the current one, down as well as up - he changed his
     * mind. Otherwise it only ever raises, never past interested.
     */
    private function raiseStage(array &$memory, ?int $id, string $name, string $stage, string $now, bool $exact): void
    {
        $key = $id !== null ? "m{$id}" : 'n'.md5(mb_strtolower($name));
        $current = $memory['motorcycles'][$key] ?? null;
        $currentStage = $current['stage'] ?? null;

        // Even in his own words a lower stage does not undo a choice: "بكام
        // الـZ250؟" after choosing it is still the chosen one. Only "مش عايزه"
        // (rejected) or stepping back to plain interest lowers it.
        if ($exact && $currentStage !== null && $currentStage !== 'rejected' && $stage !== 'rejected'
            && (self::STAGE_RANK[$stage] ?? 0) < (self::STAGE_RANK[$currentStage] ?? 0)
            && ! ($stage === 'interested' && in_array($currentStage, ['preferred', 'selected'], true))) {
            $memory['motorcycles'][$key]['last_at'] = $now;

            return;
        }

        if (! $exact) {
            $ceiling = self::STAGE_RANK['interested'];
            $rank = min(self::STAGE_RANK[$stage] ?? 1, $ceiling);
            $stage = array_search($rank, self::STAGE_RANK, true);

            if ($currentStage !== null && ($currentStage === 'rejected' || (self::STAGE_RANK[$currentStage] ?? 0) >= $rank)) {
                $memory['motorcycles'][$key]['last_at'] = $now;

                return;
            }
        }

        // Only one motorcycle is the chosen one; the previous choice stays as interest.
        if (in_array($stage, ['selected', 'applied'], true)) {
            foreach ($memory['motorcycles'] as $otherKey => $other) {
                if ($otherKey !== $key && ($other['stage'] ?? null) === 'selected') {
                    $memory['motorcycles'][$otherKey]['stage'] = 'interested';
                }
            }
        }

        $memory['motorcycles'][$key] = array_filter([
            'id' => $id,
            'name' => $name,
            'stage' => $stage,
            'first_at' => $current['first_at'] ?? $now,
            'last_at' => $now,
        ], fn ($v) => $v !== null);

        if (count($memory['motorcycles']) > self::MAX_MOTORCYCLES) {
            $sorted = $this->sortedMotorcycles($memory['motorcycles']);
            $memory['motorcycles'] = array_slice($sorted, 0, self::MAX_MOTORCYCLES, true);
        }
    }

    private function sortedMotorcycles(array $motorcycles): array
    {
        uasort($motorcycles, fn ($a, $b) => strcmp((string) ($b['last_at'] ?? ''), (string) ($a['last_at'] ?? '')));

        return $motorcycles;
    }

    /**
     * MEM-003: topic / open question after 24 h or a stage change,
     * objections after 7 days, a motorcycle's stage after 30 days unless he
     * applied for it. Items written before the per-item time existed use the
     * block's updated_at.
     */
    private function withoutExpired(array $memory, int $customerId): array
    {
        $conversation = (array) ($memory['conversation'] ?? []);
        $fallback = $conversation['updated_at'] ?? null;
        $older = fn (?string $at, \Illuminate\Support\Carbon $limit) => $at === null || \Illuminate\Support\Carbon::parse($at)->lt($limit);
        $stageChanged = isset($conversation['stage']) && $conversation['stage'] !== $this->stage($customerId);

        foreach (['topic', 'open_question'] as $field) {
            if (isset($conversation[$field]) && ($stageChanged || $older($conversation[$field.'_at'] ?? $fallback, now()->subHours(self::TOPIC_TTL_HOURS)))) {
                unset($conversation[$field], $conversation[$field.'_at']);
            }
        }

        if (! empty($conversation['objections']) && $older($conversation['objections_at'] ?? $fallback, now()->subDays(self::OBJECTION_TTL_DAYS))) {
            unset($conversation['objections'], $conversation['objections_at']);
        }

        if (! isset($conversation['topic']) && ! isset($conversation['open_question'])) {
            unset($conversation['stage']);
        }

        $memory['conversation'] = array_diff_key($conversation, ['updated_at' => 1]) === [] ? [] : $conversation;
        $memory['motorcycles'] = array_filter((array) ($memory['motorcycles'] ?? []), fn ($bike) => ($bike['stage'] ?? null) === 'applied'
            || ! $older($bike['last_at'] ?? null, now()->subDays(self::MOTORCYCLE_TTL_DAYS)));

        return $memory;
    }

    /** The structured stage of this customer: his latest application's status, or browsing. */
    private function stage(int $customerId): string
    {
        return (string) (\App\Models\Application::where('customer_id', $customerId)->latest('id')->value('status') ?? 'browsing');
    }

    private function save(int $customerId, array $memory): void
    {
        $memory = $this->withoutExpired($memory, $customerId);
        $memory['v'] = 1;
        Customer::whereKey($customerId)->update(['memory' => json_encode($memory, JSON_UNESCAPED_UNICODE)]);
    }

    private function quoteIsHis(int $conversationId, string $quote): bool
    {
        return $this->statements->messageContainingQuote($conversationId, $quote) !== null;
    }

    private function plausibleAge(string $value): bool
    {
        return preg_match('/(?<!\d)\d{1,3}(?!\d)/', $this->westernDigits($value), $m) === 1 && (int) $m[0] >= 10 && (int) $m[0] <= 99;
    }

    private function ageInQuote(string $value, string $quote): bool
    {
        preg_match('/(?<!\d)\d{2}(?!\d)/', $this->westernDigits($value), $m);

        return isset($m[0]) && preg_match('/(?<!\d)'.$m[0].'(?!\d)/', $this->westernDigits($quote)) === 1;
    }

    private function same(string $a, string $b): bool
    {
        $normalize = fn ($s) => preg_replace('/\s+/u', ' ', \App\Support\ArabicTextNormalizer::normalize($this->westernDigits($s)));

        return $normalize($a) === $normalize($b);
    }

    private function westernDigits(string $text): string
    {
        return strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }

    /** "يومين" when the current burst comes 6+ hours after his previous message. */
    public function silenceGap(int $conversationId): ?string
    {
        $times = WhatsappMessage::where('whatsapp_conversation_id', $conversationId)->where('direction', 'incoming')
            ->latest('id')->limit(40)->pluck('created_at')->filter()->values();

        if ($times->count() < 2 || abs($times[0]->diffInMinutes(now())) > 30) {
            return null;
        }

        // walk back through the current burst (messages minutes apart) to the first real pause
        for ($i = 0; $i < $times->count() - 1; $i++) {
            $minutes = abs($times[$i + 1]->diffInMinutes($times[$i]));

            if ($minutes < 15) {
                continue;
            }

            $hours = $minutes / 60;

            if ($hours < 6) {
                return null;
            }

            return match (true) {
                $hours >= 48 => ((int) floor($hours / 24)).' أيام',
                $hours >= 24 => 'يوم',
                default => ((int) floor($hours)).' ساعات',
            };
        }

        return null;
    }
}
