<?php

namespace App\Agent\Context;

use App\Agent\Providers\AiRequest;
use App\Agent\Tracing\Redactor;
use App\Domain\Applications\ApplicationService;
use App\Domain\Applications\SnapshotService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Knowledge\KnowledgeService;
use App\Jobs\SummarizeConversation;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\Customer;
use App\Models\CustomerAttribute;
use App\Models\CustomerType;
use App\Models\Handoff;
use App\Models\MessageMedia;
use App\Models\RequirementField;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Storage;

/**
 * T16 §2: assembles the §5 context layers (L0-L8) for one turn into a
 * provider-neutral AiRequest, writes the manifest to the turn's AiTrace,
 * and checks whether the rolling summary needs to catch up.
 *
 * Layer selection uses only structured state (application status, customer
 * type, session, snapshot) - never message text (plan constraint).
 */
class ContextBuilder
{
    public function __construct(
        private readonly KnowledgeService $knowledge,
        private readonly CatalogService $catalog,
        private readonly ApplicationService $applications,
        private readonly SnapshotService $snapshots,
        private readonly \App\Domain\Settings\AgentInstructions $instructions,
        private readonly \App\Domain\Teaching\LessonBook $lessons,
    ) {
    }

    public function build(object $turn): AiRequest
    {
        $conversation = WhatsappConversation::with('customer')->findOrFail($turn->whatsapp_conversation_id);
        $customer = $conversation->customer;
        $application = $customer ? $this->applications->activeFor($customer) : null;
        $snapshot = $application ? $this->snapshots->for($application) : null;

        $l0 = $this->buildL0();
        $l0b = $this->lessons->forPrompt($snapshot['customer_type'] ?? null);
        $l1 = $this->buildL1();
        $l2 = $this->buildL2();
        $l3 = $this->buildL3();
        $l3b = $this->buildL3b();
        $l4 = $this->buildL4($conversation, $customer, $snapshot, $turn->id);
        $l5 = $this->buildL5($application);
        $l6 = $this->buildL6($conversation);
        $l7 = $this->buildL7($conversation, $turn);
        $l8 = $this->buildL8($conversation, $turn);

        $system = implode("\n\n", array_values(array_filter([
            $l0['text'], $l0b['text'], $l1['text'], $l2['text'], $l3['text'], $l3b['text'], $l4['text'], $l5['text'], $l6['text'],
        ], fn ($block) => $block !== null && trim($block) !== '')));

        $request = new AiRequest(
            system: $system,
            contents: array_merge($l7['contents'], $l8['contents']),
        );

        $manifest = [
            'prompt_version' => $l0['version'],
            'l0_tokens' => TokenEstimator::estimate($l0['text']),
            'l0b_count' => $l0b['count'],
            'l1_count' => $l1['count'],
            'l2_count' => $l2['count'],
            'l3_count' => $l3['count'],
            'l3b_count' => $l3b['count'],
            'l4_present' => true,
            'l5_count' => $l5['count'],
            'l6_present' => $l6['text'] !== null,
            'l7_count' => count($l7['contents']),
            'l7_trimmed' => $l7['trimmed'],
            'l8_count' => count($l8['contents']),
            'total_input_tokens_estimate' => TokenEstimator::estimate($system) + $l7['tokens'] + $l8['tokens'],
        ];

        $this->recordManifest($conversation, $turn, $l0['version'], $manifest);
        $this->maybeTriggerSummary($conversation, $l7['oldest_included_id']);

        return $request;
    }

    /** @return array{version: string, text: string} */
    private function buildL0(): array
    {
        $current = $this->instructions->current();

        return ['version' => $current['version'], 'text' => $current['text']];
    }

    /** @return array{text: ?string, count: int} */
    private function buildL1(): array
    {
        $memories = $this->knowledge->pinned();
        $budget = config('agent.context.pinned_memory_tokens');
        $lines = [];
        $used = 0;

        foreach ($memories as $memory) {
            $tokens = $memory->estimatedTokens();

            if ($budget !== null && $used + $tokens > (int) $budget) {
                break;
            }

            $lines[] = "### {$memory->title}\n{$memory->content}";
            $used += $tokens;
        }

        if ($lines === []) {
            return ['text' => null, 'count' => 0];
        }

        return ['text' => "## إرشادات ثابتة من لوحة التحكم\n".implode("\n\n", $lines), 'count' => count($lines)];
    }

    /** @return array{text: ?string, count: int} */
    private function buildL2(): array
    {
        $index = $this->knowledge->index();

        if ($index->isEmpty()) {
            return ['text' => null, 'count' => 0];
        }

        $lines = $index->map(fn ($title, $key) => "{$key}: {$title}")->values()->all();

        return ['text' => "## فهرس المعرفة (اطلب get_business_knowledge بالمفتاح لو محتاج التفاصيل)\n".implode("\n", $lines), 'count' => count($lines)];
    }

    /** @return array{text: ?string, count: int} */
    private function buildL3(): array
    {
        $lines = $this->catalog->indexLines();

        if ($lines === []) {
            return ['text' => null, 'count' => 0];
        }

        return [
            'text' => "## فهرس الكتالوج (id · ماركة · اسم · سي سي؟ · كاش · قسط · عرض؟) - للتعرف على الاسم فقط، مش مصدر للسعر النهائي\n".implode("\n", $lines),
            'count' => count($lines),
        ];
    }

    /**
     * Regression fix: start_application/get_application_requirements/etc.
     * all take an exact customer_type KEY (e.g. "employee"), but nothing
     * told the model what the valid keys are - it was guessing Arabic
     * phrases and English words ("موظف", "موظف قطاع خاص متأمن عليه",
     * "freelance"), all rejected with UNKNOWN_CUSTOMER_TYPE, which is what
     * drove the repeated-question loops (the model kept fishing for more
     * detail hoping to land on the right string, having never seen the
     * failures explained). Same small-dashboard-list pattern as L3's
     * catalog index / agent.catalog.categories.
     *
     * @return array{text: ?string, count: int}
     */
    private function buildL3b(): array
    {
        $types = CustomerType::where('is_active', true)->orderBy('sort')->get(['key', 'label']);

        if ($types->isEmpty()) {
            return ['text' => null, 'count' => 0];
        }

        $lines = $types->map(fn ($t) => "{$t->key}: {$t->label}")->values()->all();

        return [
            'text' => "## أنواع العملاء (key: اسم) - استخدم الـ key بالظبط في أي أداة محتاجة customer_type\n".implode("\n", $lines),
            'count' => count($lines),
        ];
    }

    /** @return array{text: string} */
    private function buildL4(WhatsappConversation $conversation, ?Customer $customer, ?array $snapshot, ?int $turnId = null): array
    {
        $state = $this->cleanedState($conversation, $snapshot);

        $profile = $customer ? $this->customerProfileFacts($customer) : [];

        $handoff = ['status' => $conversation->status === 'awaiting_agent' ? 'awaiting_agent' : 'none'];

        if ($handoff['status'] === 'awaiting_agent') {
            $handoff['reason'] = Handoff::where('conversation_id', $conversation->id)
                ->whereNull('closed_at')->latest('opened_at')->value('reason');
        } else {
            // Returned by the timeout with no staff reply: the agent should
            // pick the conversation up itself, not promise a colleague again.
            $last = Handoff::where('conversation_id', $conversation->id)->latest('opened_at')->first();

            if ($last && $last->closed_at && $last->closed_by === null && $last->closed_at->gt(now()->subDay())) {
                $handoff['last_handoff'] = 'returned_to_you_without_staff_reply';
            }
        }

        $payload = [
            'customer_profile' => $profile,
            'conversation_state' => $state,
            'application' => $snapshot,
            'handoff' => $handoff,
        ];

        $unprocessed = $this->unprocessedMedia($conversation, $turnId);

        if ($unprocessed !== []) {
            $payload['unprocessed_media'] = $unprocessed;
        }

        return ['text' => "## الحالة الحالية (بيانات حقيقية - لا تخترع غيرها)\n```json\n"
            .json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n```"];
    }

    /**
     * Customer images from earlier messages that no tool ever looked at -
     * a license sent in a burst, a pension statement sent while a colleague
     * had the chat. The model could not reach them (it invented media ids),
     * so the customer was asked to send them again.
     *
     * @return array<int, array{media_id: int, sent_at: string, caption: ?string}>
     */
    private function unprocessedMedia(WhatsappConversation $conversation, ?int $currentTurnId): array
    {
        return MessageMedia::query()
            ->whereHas('message', fn ($q) => $q->where('whatsapp_conversation_id', $conversation->id)
                ->where('direction', 'incoming')
                ->where('created_at', '>=', now()->subDays(7))
                // This turn's own images are already in front of the model.
                ->where(fn ($q) => $q->whereNull('turn_id')->orWhere('turn_id', '!=', (int) $currentTurnId)))
            ->whereIn('media_type', ['image', 'document'])
            ->whereNotIn('id', \App\Models\ApplicationDocument::whereNotNull('media_id')->select('media_id'))
            ->with('message:id,text,created_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->filter(fn (MessageMedia $m) => ! isset(($m->analysis ?? [])['band']))
            ->map(fn (MessageMedia $m) => [
                'media_id' => $m->id,
                'sent_at' => $m->message?->created_at?->toIso8601String(),
                'caption' => $m->message?->text,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array{key: string, value: mixed, source: string, verified: bool}> */
    private function customerProfileFacts(Customer $customer): array
    {
        return CustomerAttribute::where('customer_id', $customer->id)
            ->get()
            ->map(function (CustomerAttribute $attribute) {
                $isSensitive = RequirementField::where('key', $attribute->field_key)->value('is_sensitive') ?? true;

                return [
                    'key' => $attribute->field_key,
                    'value' => $isSensitive ? null : $attribute->value,
                    'source' => $attribute->source,
                    'verified' => $attribute->verified_at !== null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * §3.2's deterministic clean-up: an `awaiting` entry drops out once its
     * field is collected or its document is accepted in the snapshot -
     * purely structural, never a read of message text.
     */
    private function cleanedState(WhatsappConversation $conversation, ?array $snapshot): array
    {
        $state = $conversation->state ?? [];
        $awaiting = $state['awaiting'] ?? [];

        if ($awaiting !== [] && $snapshot) {
            $collected = $snapshot['fields']['collected'] ?? [];
            $accepted = $snapshot['documents']['accepted'] ?? [];

            $awaiting = array_values(array_filter($awaiting, function ($entry) use ($collected, $accepted) {
                return match ($entry['kind']) {
                    'field' => ! in_array($entry['key'], $collected, true),
                    'document' => ! in_array($entry['key'], $accepted, true),
                    default => true,
                };
            }));

            if ($awaiting !== ($state['awaiting'] ?? [])) {
                $state['awaiting'] = $awaiting;
                $conversation->update(['state' => $state]);
            }
        }

        return $state;
    }

    /** @return array{text: ?string, count: int} */
    private function buildL5(?Application $application): array
    {
        if (! $application) {
            return ['text' => null, 'count' => 0];
        }

        $memories = $this->knowledge->scoped($application->customerType?->key, $application->status);

        if ($memories->isEmpty()) {
            return ['text' => null, 'count' => 0];
        }

        $lines = $memories->map(fn ($m) => "### {$m->title}\n{$m->content}")->all();

        return ['text' => "## إرشادات خاصة بمرحلة الطلب الحالية\n".implode("\n\n", $lines), 'count' => count($lines)];
    }

    /** @return array{text: ?string} */
    private function buildL6(WhatsappConversation $conversation): array
    {
        if (blank($conversation->summary)) {
            return ['text' => null];
        }

        return ['text' => "## ملخص المحادثة السابقة\n{$conversation->summary}"];
    }

    /**
     * @return array{contents: array, tokens: int, trimmed: bool, oldest_included_id: ?int}
     */
    private function buildL7(WhatsappConversation $conversation, object $turn): array
    {
        $sessionStartedAt = $conversation->state['session_started_at'] ?? null;

        $query = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where(fn ($q) => $q->whereNull('turn_id')->orWhere('turn_id', '!=', $turn->id))
            ->when($sessionStartedAt, fn ($q) => $q->where('created_at', '>=', $sessionStartedAt))
            ->when($conversation->summary_until_message_id, fn ($q) => $q->where('id', '>', $conversation->summary_until_message_id))
            ->orderByDesc('id');

        $budget = config('agent.context.recent_messages_tokens');
        $messages = [];
        $tokens = 0;
        $trimmed = false;

        foreach ($query->cursor() as $message) {
            $content = $this->messageToContent($message);
            $messageTokens = TokenEstimator::estimate($content['parts'][0]['text'] ?? '');

            if ($budget !== null && $messages !== [] && $tokens + $messageTokens > (int) $budget) {
                $trimmed = true;

                break;
            }

            $messages[] = ['message' => $message, 'content' => $content];
            $tokens += $messageTokens;
        }

        $messages = array_reverse($messages);

        return [
            'contents' => array_column($messages, 'content'),
            'tokens' => $tokens,
            'trimmed' => $trimmed,
            'oldest_included_id' => $messages === [] ? null : $messages[0]['message']->id,
        ];
    }

    private function messageToContent(WhatsappMessage $message): array
    {
        $isCustomer = $message->sender_type === 'customer';
        $role = $isCustomer ? 'user' : 'model';
        $text = $message->text ?? $message->transcript ?? '[media]';

        // Earlier customer images keep their ids, so a document sent a few
        // messages ago can still be processed instead of asked for again.
        if ($isCustomer && in_array($message->type, ['image', 'document'], true)) {
            $ids = $message->media()->pluck('id')->all();

            if ($ids !== []) {
                $note = '[صورة سابقة - media_id: '.implode(', ', $ids).']';
                $text = $message->text ? "{$note} {$message->text}" : $note;
            }
        }

        // A bare "[media]" on the model's own turn read as something it had
        // typed, so it started writing "[media]" into replies instead of
        // calling send_motorcycle_images. Say what actually happened.
        if (! $isCustomer && blank($message->text) && $message->type !== 'text') {
            $note = $this->sentPhotoNote($message) ?? 'صورة/ملف';

            // A photo that failed to send read as sent, so "فين الصور؟" got
            // "بعتلك الصور" instead of a resend.
            $record = $message->delivery_status === 'failed'
                ? "({$note} ماوصلتش للعميل - الإرسال فشل. لو سأل عليها ابعتها تاني بـ resend: true - ده سجل مش نص تكتبه)"
                : "(اتبعت للعميل {$note} - ده سجل مش نص تكتبه)";

            return ['role' => $role, 'parts' => [['type' => 'text', 'text' => $record]]];
        }

        if (! $isCustomer) {
            $text = Redactor::redact($text);

            if (in_array($message->sender_type, ['human_phone', 'agent'], true)) {
                $text = "[staff] {$text}";
            }
        }

        return ['role' => $role, 'parts' => [['type' => 'text', 'text' => $text]]];
    }

    /** "صورة Demora 2000w (رقم 52) - لونها أبيض" for a catalog photo we sent, else null. */
    private function sentPhotoNote(WhatsappMessage $message): ?string
    {
        $machineId = $message->metadata['motorcycle_id'] ?? null;

        if ($message->direction !== 'outgoing' || ! $machineId) {
            return null;
        }

        $name = \App\Models\Machine::whereKey($machineId)->value('name') ?? '';
        $color = $message->metadata['image_color'] ?? null;

        return "صورة {$name} (رقم {$machineId})".($color ? " - لونها {$color}" : '');
    }

    /** @return array{contents: array, tokens: int} */
    private function buildL8(WhatsappConversation $conversation, object $turn): array
    {
        $messages = WhatsappMessage::with(['quotedMessage', 'media'])
            ->where('whatsapp_conversation_id', $conversation->id)
            ->where('turn_id', $turn->id)
            ->orderBy('id')
            ->get();

        $contents = [];
        $tokens = 0;

        foreach ($messages as $message) {
            $parts = [];

            if ($message->quotedMessage) {
                $quoted = $message->quotedMessage;
                $quotedText = $this->sentPhotoNote($quoted) ?? $quoted->text ?? $quoted->transcript ?? '[media]';
                $focus = $quoted->metadata['focus_motorcycle_ids'] ?? [];
                $focusNote = $focus !== [] ? ' (كانت بتتكلم عن الموتوسيكل رقم: '.implode(', ', $focus).')' : '';
                $quoteText = "[العميل بيرد على رسالة قديمة: \"{$quotedText}\"{$focusNote}]";
                $parts[] = ['type' => 'text', 'text' => $quoteText];
                $tokens += TokenEstimator::estimate($quoteText);
            }

            if ($message->type === 'image' || $message->type === 'sticker') {
                foreach ($message->media as $media) {
                    // process_document/identify_motorcycle_from_image both require a
                    // media_id argument, but the model only ever sees the raw image
                    // bytes below - with no id anywhere in its context it has no way
                    // to reference this image in a tool call. Surface it as text.
                    $idNote = "[صورة مرفقة - media_id: {$media->id}]";
                    $parts[] = ['type' => 'text', 'text' => $idNote];
                    $tokens += TokenEstimator::estimate($idNote);
                    $parts[] = $this->imagePart($media);
                }
            }

            $text = $message->text ?? $message->transcript;

            // A voice note whose transcription failed or never finished
            // used to vanish from the context entirely.
            if (($text === null || $text === '') && $message->type === 'audio') {
                $text = '[رسالة صوتية ماقدرناش نسمعها - اطلب من العميل يعيدها أو يكتبها، من غير ما تفترض محتواها]';
            }

            if ($text !== null && $text !== '') {
                $parts[] = ['type' => 'text', 'text' => $text];
                $tokens += TokenEstimator::estimate($text);
            }

            if ($parts === []) {
                continue;
            }

            $contents[] = ['role' => 'user', 'parts' => $parts];
        }

        return ['contents' => $contents, 'tokens' => $tokens];
    }

    private function imagePart(MessageMedia $media): array
    {
        return [
            'type' => 'inline_media',
            'mime' => $media->mime,
            'base64' => base64_encode(Storage::disk($media->disk)->get($media->path)),
            // The agent only needs to tell a document from a bike photo:
            // process_document and identify_motorcycle_from_image re-read
            // the image at full resolution. Measured 1,101 -> 265 tokens
            // per image, paid again on every model call of the turn.
            'resolution' => 'low',
        ];
    }

    private function recordManifest(WhatsappConversation $conversation, object $turn, string $promptVersion, array $manifest): void
    {
        AiTrace::firstOrCreate(
            ['conversation_id' => $conversation->id, 'turn_id' => $turn->id],
            ['status' => 'running']
        )->update([
            'prompt_version' => $promptVersion,
            'context_manifest' => $manifest,
        ]);
    }

    /**
     * T16 §4: the trigger check itself. Dispatched here (at context-build
     * time, i.e. the start of the next turn) rather than strictly "after a
     * turn completes" as §4 describes, because T17's turn-completion hook
     * doesn't exist yet - this is idempotent and self-correcting either
     * way, so it will simply also be called by T17 once wired.
     */
    private function maybeTriggerSummary(WhatsappConversation $conversation, ?int $oldestIncludedId): void
    {
        $triggerThreshold = config('agent.summary.trigger_messages');

        if ($triggerThreshold === null || $oldestIncludedId === null) {
            return;
        }

        $since = $conversation->summary_until_message_id ?? 0;

        $backlogCount = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('id', '>', $since)
            ->where('id', '<', $oldestIncludedId)
            ->count();

        if ($backlogCount >= (int) $triggerThreshold) {
            SummarizeConversation::dispatch($conversation->id, $oldestIncludedId);
        }
    }
}
