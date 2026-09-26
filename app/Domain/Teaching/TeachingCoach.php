<?php

namespace App\Domain\Teaching;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiRequest;
use App\Domain\Applications\ApplicationService;
use App\Domain\Documents\DocumentPipeline;
use App\Jobs\RunTeachingRegression;
use App\Models\MessageMedia;
use App\Models\TeachingCase;
use App\Models\TeachingChange;
use App\Models\TeachingSession;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;

class TeachingCoach
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly CoachContext $context,
        private readonly ChangeApplier $applier,
        private readonly RegressionRunner $regression,
    ) {
    }

    public function teach(WhatsappConversation $conversation, ?int $targetMessageId, string $ownerText, ?int $staffId): TeachingSession
    {
        $session = TeachingSession::create([
            'conversation_id' => $conversation->id,
            'target_message_id' => $targetMessageId,
            'after_message_id' => $targetMessageId ?? WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)->max('id'),
            'owner_text' => $ownerText,
            'status' => 'thinking',
            'created_by' => $staffId,
        ]);

        try {
            $plan = $this->ask($conversation, $targetMessageId, $ownerText);
            $this->process($session, $plan, $staffId, retryOnFail: true);
        } catch (\Throwable $e) {
            report($e);
            $session->update(['status' => 'error', 'understanding' => 'حصلت مشكلة وأنا بفهم التصحيح: '.$e->getMessage()]);
        }

        return $session->refresh();
    }

    public function approve(TeachingChange $change, ?int $staffId): void
    {
        $this->applier->apply($change, $staffId);
        $this->afterApproval($change->session, $change);
    }

    public function reject(TeachingChange $change): void
    {
        $change->update(['status' => 'rejected']);
    }

    public function revert(TeachingChange $change): void
    {
        $this->applier->revert($change);
    }

    private function process(TeachingSession $session, array $plan, ?int $staffId, bool $retryOnFail): void
    {
        $session->update([
            'understanding' => $plan['understanding'] ?? null,
            'question' => ($plan['question'] ?? '') ?: null,
        ]);

        $operations = (array) ($plan['operations'] ?? []);

        if ($operations === []) {
            $session->update(['status' => $session->question ? 'question' : 'done']);

            return;
        }

        $changes = collect($operations)->map(fn ($op) => $this->makeChange($session, $op))->filter();
        $invalid = $changes->where('status', 'invalid');

        if ($retryOnFail && $invalid->isNotEmpty()) {
            $invalid->each->delete();
            $changes->where('status', 'proposed')->each->delete();

            $this->process($session, $this->ask(
                WhatsappConversation::find($session->conversation_id),
                $session->target_message_id,
                $session->owner_text,
                $invalid->map(fn ($c) => "العملية \"{$c->summary}\" اترفضت: {$c->error}")->implode("\n")
            ), $staffId, retryOnFail: false);

            return;
        }

        $case = $this->makeCase($session, $plan);

        $lessons = $changes->where('kind', 'lesson')->where('status', 'proposed');

        foreach ($lessons as $change) {
            try {
                $this->applier->apply($change, $staffId);
            } catch (\Throwable $e) {
                $change->update(['status' => 'invalid', 'error' => $e->getMessage()]);
            }
        }

        $applied = $lessons->where('status', 'applied');

        if ($case && $applied->isNotEmpty() && ! $case->bot_lesson_id) {
            $case->update(['bot_lesson_id' => (int) $applied->first()->target_id]);
        }

        $pending = $changes->where('status', 'proposed')->isNotEmpty();
        $result = (array) $session->result;

        if ($case && $applied->isNotEmpty()) {
            $checks = $this->quickCheck($case, includeCase: ! $pending);
            $failed = collect($checks)->where('pass', false);

            if ($failed->isNotEmpty()) {
                $applied->each(fn ($c) => $this->applier->revert($c));

                if ($retryOnFail) {
                    $case->delete();
                    $session->update(['result' => $result + ['first_attempt' => $checks]]);
                    $applied->each(fn ($c) => $c->update(['status' => 'failed_check']));

                    $this->process($session, $this->ask(
                        WhatsappConversation::find($session->conversation_id),
                        $session->target_message_id,
                        $session->owner_text,
                        $failed->map(fn ($f) => "الحالة: {$f['label']}\nرد البوت بعد التعديل: {$f['reply']}\nالسبب: {$f['reason']}")->implode("\n\n")
                    ), $staffId, retryOnFail: false);

                    return;
                }

                $applied->each(fn ($c) => $c->update(['status' => 'failed_check', 'error' => $failed->first()['reason']]));
            }

            $result['checks'] = $checks;
        }

        $session->update(['result' => $result, 'status' => $pending ? 'awaiting_approval' : 'done']);

        if (! $pending) {
            RunTeachingRegression::dispatch();
        }
    }

    private function afterApproval(TeachingSession $session, TeachingChange $change): void
    {
        $result = (array) $session->result;

        if ($change->target_type === 'document_type' && ($preview = $this->documentPreview($session))) {
            $result['document'] = $preview;
        }

        if (! $session->changes()->where('status', 'proposed')->exists()) {
            $case = $session->cases()->first();

            if ($case) {
                $result['checks'] = [['label' => 'نفس الموقف بعد التعديل'] + $this->regression->run($case)];
            }

            $session->status = 'done';
            RunTeachingRegression::dispatch();
        }

        $session->result = $result;
        $session->save();
    }

    /** @return array<int, array{label: string, pass: bool, reply: string, reason: string}> */
    private function quickCheck(TeachingCase $case, bool $includeCase): array
    {
        $cases = $this->regression->related($case, (int) (config('agent.teaching.quick_check_cases') ?? 3));

        if ($includeCase) {
            $cases->prepend($case);
        }

        return $cases->map(fn (TeachingCase $c) => [
            'label' => $c->id === $case->id ? 'نفس الموقف بعد التعليم' : 'درس قديم: '.\Illuminate\Support\Str::limit($c->expectation, 70),
        ] + $this->regression->run($c))->values()->all();
    }

    private function documentPreview(TeachingSession $session): ?array
    {
        $turnId = WhatsappMessage::whereKey($session->target_message_id)->value('turn_id');
        $media = MessageMedia::whereIn('message_id', WhatsappMessage::where('whatsapp_conversation_id', $session->conversation_id)
            ->where('direction', 'incoming')
            ->when($turnId, fn ($q) => $q->where('turn_id', $turnId))
            ->pluck('id'))->latest('id')->first();

        if (! $media) {
            return null;
        }

        $customer = WhatsappConversation::find($session->conversation_id)?->customer;
        $application = $customer ? app(ApplicationService::class)->activeFor($customer) : null;

        return app(DocumentPipeline::class)->preview($media, $application);
    }

    private function makeCase(TeachingSession $session, array $plan): ?TeachingCase
    {
        $expectation = trim((string) ($plan['expectation'] ?? ''));

        if ($expectation === '') {
            return null;
        }

        $history = [];
        $badReply = null;

        if ($session->target_message_id) {
            $target = WhatsappMessage::find($session->target_message_id);
            $messages = WhatsappMessage::where('whatsapp_conversation_id', $session->conversation_id)
                ->where('id', '<=', $target->id)->with('media')->orderBy('id')->get();

            $badReply = $messages->where('direction', 'outgoing')->where('turn_id', $target->turn_id)->pluck('text')->implode("\n");
            $messages = $messages->reject(fn ($m) => $m->direction === 'outgoing' && $m->turn_id === $target->turn_id);

            foreach ($messages->values()->slice(-20) as $m) {
                $history[] = [
                    'role' => $m->direction === 'incoming' ? 'customer' : 'bot',
                    'text' => (string) $m->text,
                    'media' => $m->media->map(fn ($x) => ['disk' => $x->disk, 'path' => $x->path])->all(),
                ];
            }
        } elseif (trim((string) ($plan['test_message'] ?? '')) !== '') {
            $history[] = ['role' => 'customer', 'text' => trim($plan['test_message']), 'media' => []];
        }

        if ($history === [] || end($history)['role'] !== 'customer') {
            return null;
        }

        return TeachingCase::create([
            'teaching_session_id' => $session->id,
            'history' => $history,
            'bad_reply' => $badReply,
            'expectation' => $expectation,
            'must_contain' => array_values(array_filter((array) ($plan['must_contain'] ?? []))),
            'scope_stage' => ($plan['stage'] ?? '') ?: null,
        ]);
    }

    private function makeChange(TeachingSession $session, array $op): ?TeachingChange
    {
        $kind = (string) ($op['kind'] ?? '');

        [$targetType, $targetId, $after] = match ($kind) {
            'lesson' => ['bot_lesson', ($op['lesson_id'] ?? 0) ?: null, array_filter([
                'lesson_id' => ($op['lesson_id'] ?? 0) ?: null,
                'fields' => [
                    'title' => $op['title'] ?? 'درس',
                    'rule' => $op['rule'] ?? '',
                    'fixed_facts' => array_values(array_filter((array) ($op['fixed_facts'] ?? []))) ?: null,
                    'example_context' => ($op['example_context'] ?? '') ?: null,
                    'example_reply' => ($op['example_reply'] ?? '') ?: null,
                    'scope_customer_types' => array_values(array_filter((array) ($op['scope_customer_types'] ?? []))) ?: null,
                    'scope_stage' => ($op['scope_stage'] ?? '') ?: null,
                ],
            ])],
            'instruction' => ['instructions', null, ['find' => (string) ($op['find'] ?? ''), 'replace' => (string) ($op['replace'] ?? '')]],
            'setting' => ['agent_setting', (string) ($op['record_id'] ?? ''), ['value' => $this->decode($op['changes'][0]['value'] ?? null)]],
            'data_update', 'data_create' => [(string) ($op['entity'] ?? ''), $kind === 'data_update' ? (string) ($op['record_id'] ?? '') : null,
                collect((array) ($op['changes'] ?? []))->mapWithKeys(fn ($c) => [(string) ($c['field'] ?? '') => $this->decode($c['value'] ?? null)])->all()],
            default => [null, null, null],
        };

        if ($targetType === null) {
            return null;
        }

        $change = TeachingChange::create([
            'teaching_session_id' => $session->id,
            'kind' => $kind,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'summary' => $op['summary'] ?? null,
            'after' => $after,
            'status' => 'proposed',
        ]);

        if ($kind !== 'lesson' && $kind !== 'instruction' && ! EditableEntities::has($targetType)) {
            $change->update(['status' => 'invalid', 'error' => "مينفعش أعدّل {$targetType}"]);

            return $change;
        }

        try {
            $this->applier->validate($change);

            // validate() pins `find` to the exact paragraph it matched.
            if ($change->isDirty('after')) {
                $change->save();
            }
        } catch (\Throwable $e) {
            $change->update(['status' => 'invalid', 'error' => $e->getMessage()]);

            return $change;
        }

        if ($lost = $this->lostListItems($change)) {
            $change->update(['status' => 'invalid', 'error' => 'التعديل ده بيشيل أو بيغيّر حاجات قديمة: '.$lost]);
        }

        return $change;
    }

    /** Items of an existing JSON list that the proposed value drops or alters. */
    private function lostListItems(TeachingChange $change): ?string
    {
        if ($change->kind !== 'data_update') {
            return null;
        }

        $record = EditableEntities::model($change->target_type)::find($change->target_id);
        $lost = [];

        foreach ((array) $change->after as $field => $value) {
            $old = $record?->getAttribute($field);

            if (! is_array($old) || ! array_is_list($old) || ! is_array($value)) {
                continue;
            }

            $new = array_map(fn ($item) => json_encode($this->sorted($item)), $value);

            foreach ($old as $item) {
                if (! in_array(json_encode($this->sorted($item)), $new, true)) {
                    $lost[] = "{$field}: ".json_encode($item, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        return $lost === [] ? null : implode(' | ', $lost);
    }

    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => $this->sorted($v), $value);
    }

    private function decode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function ask(WhatsappConversation $conversation, ?int $targetMessageId, string $ownerText, ?string $failedCheck = null): array
    {
        $transcript = $this->context->transcript($conversation->id, $targetMessageId);
        $prompt = "# المحادثة\n{$transcript}\n\n# كلام صاحب الشغل\n{$ownerText}";

        if ($failedCheck) {
            $prompt .= "\n\n# محاولتك الأولى ما نجحتش\nصلّح العمليات (ابعت كل القيم كاملة) أو صياغة الدرس عشان يبقى أوضح للبوت، أو اسأل صاحب الشغل لو المشكلة مش في الصياغة.\n{$failedCheck}";
        }

        $system = trim((string) file_get_contents(resource_path('agent/instructions/coach.md')))
            ."\n\n".$this->context->knowledge($ownerText."\n".$transcript);

        $request = new AiRequest(
            system: $system,
            contents: [['role' => 'user', 'parts' => [['type' => 'text', 'text' => $prompt]]]],
            toolMode: 'none',
            temperature: 0.2,
            maxOutputTokens: 16384,
            timeoutSeconds: 120,
            responseSchema: $this->schema(),
            thinkingLevel: config('agent.teaching.coach_thinking') ?: 'low',
        );

        $response = $this->withCoachModel(fn () => $this->ai->chat($request));
        $raw = trim(implode('', $response->textParts));
        $plan = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', $raw), true);

        if (! is_array($plan)) {
            throw new \RuntimeException("رد المدرّب مش مفهوم ({$response->finishReason}): ".mb_substr($raw, 0, 300));
        }

        return $plan;
    }

    private function withCoachModel(callable $call): mixed
    {
        $model = config('agent.model');
        $budget = config('agent.provider_budget_seconds');

        config([
            'agent.model' => config('agent.teaching.coach_model') ?: $model,
            'agent.provider_budget_seconds' => max(120, (int) $budget),
        ]);

        try {
            return $call();
        } finally {
            config(['agent.model' => $model, 'agent.provider_budget_seconds' => $budget]);
        }
    }

    private function schema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => [
                'understanding' => ['type' => 'string'],
                'question' => ['type' => 'string'],
                'expectation' => ['type' => 'string'],
                'must_contain' => $strings,
                'test_message' => ['type' => 'string'],
                'stage' => ['type' => 'string'],
                'operations' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'kind' => ['type' => 'string', 'enum' => ['lesson', 'instruction', 'data_update', 'data_create', 'setting']],
                        'summary' => ['type' => 'string'],
                        'lesson_id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'rule' => ['type' => 'string'],
                        'fixed_facts' => $strings,
                        'example_context' => ['type' => 'string'],
                        'example_reply' => ['type' => 'string'],
                        'scope_customer_types' => $strings,
                        'scope_stage' => ['type' => 'string'],
                        'find' => ['type' => 'string'],
                        'replace' => ['type' => 'string'],
                        'entity' => ['type' => 'string'],
                        'record_id' => ['type' => 'string'],
                        'changes' => ['type' => 'array', 'description' => 'data_update/data_create/setting: every field being set, value as a string (JSON for arrays/objects). Empty for lesson/instruction.', 'items' => [
                            'type' => 'object',
                            'properties' => ['field' => ['type' => 'string'], 'value' => ['type' => 'string']],
                            'required' => ['field', 'value'],
                        ]],
                    ],
                    'required' => ['kind', 'summary', 'changes'],
                ]],
            ],
            'required' => ['understanding', 'question', 'expectation', 'operations'],
        ];
    }
}
