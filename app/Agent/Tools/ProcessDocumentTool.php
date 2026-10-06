<?php

namespace App\Agent\Tools;

use App\Domain\Applications\SnapshotService;
use App\Domain\Documents\DocumentPipeline;
use App\Models\Application;
use App\Models\MessageMedia;

/** WRITE — plan §6.12 */
class ProcessDocumentTool implements WriteTool
{
    public function __construct(
        private readonly DocumentPipeline $pipeline,
        private readonly SnapshotService $snapshots,
    ) {
    }

    public function name(): string
    {
        return 'process_document';
    }

    public function description(): string
    {
        return 'Run the full document pipeline on media the customer sent. Use when the customer sends an image or PDF '
            .'that looks like a document - pass every media_id shown as "[صورة مرفقة - media_id: N]" in one call. '
            .'Images from earlier messages that were never processed are listed in the state as unprocessed_media - '
            .'process those instead of asking the customer to send them again. Set replaces_previous=true only when '
            .'the customer says a document they sent before was wrong / not theirs and this one replaces it. '
            .'Do not use for motorcycle photos. A rejected result carries reason_for_customer: tell him that reason (in your words) - '
            .'never say the photo is unclear unless that is the reason.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['media_ids'],
            'properties' => [
                'media_ids' => [
                    'type' => 'array', 'minItems' => 1, 'maxItems' => 4,
                    'items' => ['type' => 'integer'],
                ],
                'expected_document_type' => ['type' => 'string', 'description' => 'A document type key from the snapshot (documents.required / missing), or omit.'],
                'replaces_previous' => ['type' => 'boolean', 'description' => 'true only when the customer said an earlier document of this kind was wrong and this one replaces it.'],
            ],
        ];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    /** The first issue, in the words he is told. Never "unclear" unless the photo really is. */
    private function reason(array $issues): ?string
    {
        $issue = $issues[0] ?? null;
        $label = fn (?string $key) => $key ? (\App\Models\RequirementField::where('key', $key)->value('label') ?? $key) : 'البيانات';

        return match ($issue['code'] ?? null) {
            'BLURRY_DOCUMENT', 'UNREADABLE' => 'الصورة مش واضحة كفاية - محتاجين صورة في نور كويس ومن غير انعكاس.',
            'INVALID_FORMAT' => 'مقدرتش أقرا '.$label($issue['field'] ?? null).' كامل من الصورة - صوّرها تاني من قريب والكارت كله في الصورة.',
            'MISSING_DATA' => $label($issue['field'] ?? null).' مش ظاهر في الصورة - محتاجين صورة للمستند كله.',
            'WRONG_DOCUMENT' => 'دي مش الورقة المطلوبة دلوقتي.',
            'NAME_MISMATCH', 'ID_MISMATCH' => 'البيانات اللي في الورقة دي مش زي البطاقة اللي اتبعتت قبل كده - الورقة دي بتاعة مين؟',
            default => null,
        };
    }

    /**
     * QA 2026-10-04: "الاسكرينات اتقبلت... ابعت سكرين أرباح ٣ شهور" - he could
     * not tell which month was missing. The months in, and the ones left.
     */
    private function earningsMonths(array $snapshot): array
    {
        $partial = $snapshot['documents']['partial']['delivery_app_earnings'] ?? null;

        if (! $partial || ($partial['satisfied'] ?? false) || empty($partial['periods'])) {
            return [];
        }

        $names = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        $have = [];

        foreach ($partial['periods'] as $period) {
            [$from, $to] = array_map('trim', explode('→', (string) $period) + [1 => '']);

            for ($m = \Illuminate\Support\Carbon::parse($from)->startOfMonth(); $to !== '' && $m->lte(\Illuminate\Support\Carbon::parse($to)); $m->addMonth()) {
                $have[$m->format('Y-m')] = $names[(int) $m->format('n')];
            }
        }

        $latest = \Illuminate\Support\Carbon::parse($partial['latest_end'] ?? now())->startOfMonth();
        $missing = [];

        for ($i = 2; $i >= 0; $i--) {
            $month = $latest->copy()->subMonths($i);
            $have[$month->format('Y-m')] ?? $missing[] = $names[(int) $month->format('n')];
        }

        return ['earnings_months' => ['arrived' => array_values($have), 'missing' => $missing]];
    }


    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $application = $ctx->activeApplicationId ? Application::find($ctx->activeApplicationId) : null;

        if (! $application) {
            // Replay of conversation 206: "ماقدرش أراجع الصور... مافيش طلب مفتوح"
            // three times - our mechanics are not his problem.
            return ToolResult::error('NO_ACTIVE_APPLICATION', 'The photos arrived; they are read once an application is open. Never tell him about an open application or reviewing photos. '
                .'If the person applying is clear and allowed, call start_application and then process_document; otherwise just answer where the conversation stands (who applies), without repeating a refusal you already gave.');
        }

        $expected = $args['expected_document_type'] ?? null;

        // "national_id" (not a real key) was accepted and stored as the
        // expected type; a wrong key is an error the model can fix.
        if ($expected !== null && ! \App\Models\DocumentType::where('key', $expected)->where('is_active', true)->exists()) {
            return ToolResult::error('UNKNOWN_DOCUMENT_TYPE', 'expected_document_type must be one of: '
                .\App\Models\DocumentType::where('is_active', true)->pluck('key')->implode(', ').' - or omit it.');
        }

        // A text-only "كده تمام؟" led the model to invent media_id 1, get
        // MEDIA_NOT_FOUND, and tell the customer their photo never arrived.
        $known = MessageMedia::whereIn('id', $args['media_ids'])
            ->whereHas('message', fn ($q) => $q->where('whatsapp_conversation_id', $ctx->conversationId)->where('direction', 'incoming'))
            ->exists();

        if (! $known) {
            return ToolResult::error('NO_SUCH_MEDIA', 'None of these media_ids exist in this conversation. If this message has no '
                .'attachment, answer from the snapshot (documents.accepted / partial) - do not tell the customer an image failed.');
        }

        // QA 2026-10-04: he sent the back of his ID and a work letter; the
        // model passed the id of the front sent earlier, and the back was
        // never read - yet he was told "الضهر اتقبل". This turn's own photos
        // are always read, whatever ids came in.
        $mediaIds = array_values(array_unique(array_merge(
            array_map('intval', $args['media_ids']),
            MessageMedia::query()
                ->whereHas('message', fn ($q) => $q->where('whatsapp_conversation_id', $ctx->conversationId)
                    ->where('direction', 'incoming')->where('turn_id', $ctx->turnId))
                ->whereIn('media_type', ['image', 'document'])
                ->whereNotIn('id', \App\Models\ApplicationDocument::where('application_id', $application->id)->whereNotNull('media_id')->select('media_id'))
                ->get()
                ->reject(fn (MessageMedia $m) => isset(($m->analysis ?? [])['band']))
                ->pluck('id')
                ->all()
        )));

        $results = [];

        $requested = array_map('intval', $args['media_ids']);

        $readable = [];
        $expectedByMedia = [];

        foreach ($mediaIds as $mediaId) {
            $media = MessageMedia::with('message')->find($mediaId);

            if (! $media || $media->message?->whatsapp_conversation_id !== $ctx->conversationId || $media->message?->direction !== 'incoming') {
                $results[$mediaId] = ['media_id' => $mediaId, 'document_id' => null, 'detected_type' => null, 'accepted' => false, 'status' => 'failed', 'issues' => [['code' => 'MEDIA_NOT_FOUND']], 'applied_fields' => []];

                continue;
            }

            $results[$mediaId] = null;
            $readable[] = $media;
            // the model's expected type is for the ids it passed, not this turn's other photos
            $expectedByMedia[$media->id] = in_array((int) $mediaId, $requested, true) ? $expected : null;
        }

        // DOC-004: the photos of one burst are read in one model call.
        foreach ($this->pipeline->processMany($readable, $application, $expectedByMedia, ($args['replaces_previous'] ?? false) === true) as $result) {
            $results[$result['media_id']] = $result;
        }

        $results = array_values($results);

        // QA 2026-10-04: front and back in one message, the back read first
        // with one digit misread (٣ as ٤) - rejected, because the front that
        // settles such a reading was not accepted yet. Read it again after.
        if (collect($results)->contains(fn ($r) => ($r['accepted'] ?? false) && ($r['detected_type'] ?? null) === 'national_id_front')) {
            foreach ($results as $i => $r) {
                $misread = collect($r['issues'] ?? [])->contains(fn ($issue) => ($issue['code'] ?? null) === 'INVALID_FORMAT' && ($issue['field'] ?? null) === 'national_id');

                if (! ($r['accepted'] ?? false) && ($r['detected_type'] ?? null) === 'national_id_back' && $misread) {
                    // the same reading, checked again now the front is on file - no new model call
                    $results[$i] = $this->pipeline->process(MessageMedia::find($r['media_id']), $application->refresh(), $expected, false, $this->pipeline->readingOf((int) $r['media_id']));
                }
            }
        }

        // Simulation 676: a number read wrong became "الصورة مش واضحة" - the
        // reason he is given is the real one, worded here, not guessed.
        $results = array_map(fn (array $r) => ($r['accepted'] ?? false) ? $r : $r + ['rejection_reason' => $this->reason($r['issues'] ?? [])], $results);

        // QA 2026-10-04: "دي بطاقة ابويا، دي بطاقتي انا" - his own ID was
        // rejected against his father's, and he kept being told he is 64.
        $results = array_map(function (array $r) {
            $codes = array_column($r['issues'] ?? [], 'code');

            return ($r['detected_type'] ?? null) === 'national_id_front' && in_array('NAME_MISMATCH', $codes, true) && in_array('ID_MISMATCH', $codes, true)
                ? $r + ['different_person' => true]
                : $r;
        }, $results);

        // The job printed on his ID is one the finance companies refuse.
        $results = array_map(function (array $r) {
            $say = app(\App\Domain\Applications\OccupationPolicy::class)->rejection($r['occupation_on_id'] ?? null);

            return $say ? $r + ['occupation_not_accepted' => 'refusal: "'.$say.'"'] : $r;
        }, $results);

        // Read before the model's call: a photo that is no document at all
        // (a motorcycle, a selfie) is not recorded as a rejected paper - it
        // is handed back to the model to look at.
        $notDocuments = [];

        if (($args['_preread'] ?? false) === true) {
            foreach ($results as $i => $r) {
                if (($r['detected_type'] ?? null) === null && in_array('DOCUMENT_NOT_SUPPORTED', array_column($r['issues'] ?? [], 'code'), true)) {
                    \App\Models\ApplicationDocument::whereKey($r['document_id'] ?? 0)->delete();
                    $media = MessageMedia::find($r['media_id']);
                    $media?->update(['analysis' => array_merge($media->analysis ?? [], ['not_a_document' => true])]);
                    $notDocuments[] = $r['media_id'];
                    unset($results[$i]);
                }
            }

            $results = array_values($results);
        }

        // QA 2026-10-04: after the swap the reply still asked "the name differs,
        // confirm it's yours" and spoke of the father's age.
        $replacedIdentity = ($args['replaces_previous'] ?? false) === true;

        if ($replacedIdentity) {
            $results = array_map(fn ($r) => array_diff_key($r, ['differs_from_file' => 1, 'different_person' => 1]), $results);
        }

        return ToolResult::ok([
            'results' => $results,
        ] + ($notDocuments !== [] ? ['not_documents' => $notDocuments] : [])
          + $this->earningsMonths($snapshot = $this->snapshots->for($application->refresh()))
          + ($replacedIdentity ? ['identity_replaced' => true] : []) + [
            // TOOL-006: the compact status, not the whole snapshot again
            'application_now' => SnapshotService::compact($snapshot),
        ]);
    }
}
