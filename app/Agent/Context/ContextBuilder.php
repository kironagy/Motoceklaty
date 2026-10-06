<?php

namespace App\Agent\Context;

use App\Agent\Providers\AiRequest;
use App\Agent\Tracing\Redactor;
use App\Domain\Applications\ApplicationService;
use App\Domain\Applications\SnapshotService;
use App\Domain\Catalog\CatalogService;
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
        private readonly CatalogService $catalog,
        private readonly ApplicationService $applications,
        private readonly SnapshotService $snapshots,
        private readonly \App\Domain\Settings\AgentInstructions $instructions,
    ) {
    }

    public function build(object $turn): AiRequest
    {
        $conversation = WhatsappConversation::with('customer')->findOrFail($turn->whatsapp_conversation_id);
        $customer = $conversation->customer;
        $application = $customer ? $this->applications->activeFor($customer) : null;
        $snapshot = $application ? $this->snapshots->for($application) : null;

        // Rebuild: ONE instruction source (L0). Pinned/scoped knowledge and
        // lessons are folded into it; what follows is data only - catalog
        // names, customer-type keys, then what we know about this customer.
        $l0 = $this->buildL0();
        $l3 = $this->catalogIsRelevant($conversation, $application) ? $this->buildL3() : ['text' => null, 'count' => 0];
        $l3b = $this->buildL3b();
        $l4 = $this->buildL4($conversation, $customer, $snapshot, $turn->id);
        $l4m = $customer ? app(\App\Domain\Memory\CustomerMemory::class)->forPrompt($customer->id, $conversation->id, $application?->id) : null;
        $l6 = $this->buildL6($conversation);
        $l7 = $this->buildL7($conversation, $turn);
        $l8 = $this->buildL8($conversation, $turn);

        $system = implode("\n\n", array_values(array_filter([
            // The same text for every customer goes first, so the provider's
            // cache covers it on every call.
            $l0['text'], $l3['text'], $l3b['text'], $l4['text'], $l4m, $l6['text'], $this->ownStyleNote($conversation),
        ], fn ($block) => $block !== null && trim($block) !== '')));

        $request = new AiRequest(
            system: $system,
            contents: array_merge($l7['contents'], $l8['contents']),
        );

        $manifest = [
            'prompt_version' => $l0['version'],
            'l0_tokens' => TokenEstimator::estimate($l0['text']),
            'l3_count' => $l3['count'],
            'l3b_count' => $l3b['count'],
            'l4_present' => true,
            'memory_tokens' => $l4m === null ? 0 : TokenEstimator::estimate($l4m),
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

    /**
     * Conversation 206: 14 of 16 replies opened "تمام يا باشا" and most ran
     * 3-4 lines repeating the same refusal - the model copies its own
     * earlier replies from the history. What its last replies looked like
     * is told as a fact, so this one breaks the pattern.
     */
    private function ownStyleNote(WhatsappConversation $conversation): ?string
    {
        $last = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')->where('sender_type', 'bot')
            ->latest('id')->limit(4)->pluck('text')->filter()->values();

        if ($last->count() < 2) {
            return null;
        }

        $notes = [];
        $nicknames = $last->filter(fn ($t) => preg_match('/يا\s+(?:باشا|غالي|صاحبي|معلم|ريس|برنس)/u', (string) $t))->count();

        // any nickname in the last two replies: this one has none
        if ($last->take(2)->contains(fn ($t) => preg_match('/يا\s+(?:باشا|غالي|صاحبي|معلم|ريس|برنس)/u', (string) $t))) {
            $notes[] = "{$nicknames} من آخر {$last->count()} ردود فيهم نداء (يا باشا...): الرد ده من غير نداء خالص، وابدأ بالإجابة نفسها مش بـ\"تمام\"";
        }

        // Replay of conversation 206: "جهات التمويل بتطلب اللي يقدم يكون شغال
        // أو على المعاش وسنه من 21 لـ 62" in four replies running, a word
        // changed each time - so runs of 5+ words, not whole sentences.
        $repeated = [];
        $words = $last->map(fn ($t) => preg_split('/\s+/u', trim(preg_replace('/[.،,؟?!:\n]+/u', ' ', (string) $t))))->all();
        $texts = array_map(fn ($ws) => array_map(fn ($w) => \App\Support\ArabicTextNormalizer::normalize($w), $ws), $words);

        for ($i = 0; $i < count($texts); $i++) {
            for ($j = $i + 1; $j < count($texts); $j++) {
                if (($run = $this->longestSharedRun($texts[$i], $texts[$j])) !== null && $run[1] >= 5) {
                    $phrase = implode(' ', array_slice($words[$i], $run[0], $run[1]));
                    $repeated[implode(' ', array_slice($texts[$i], $run[0], $run[1]))] = $phrase;
                }
            }
        }

        if ($repeated !== []) {
            $notes[] = 'قلت ده في أكتر من رد قبل كده، ما تعيدوش (هو عارفه): "'.implode('" · "', array_slice(array_values($repeated), 0, 2)).'"';
        }

        $average = (int) round($last->avg(fn ($t) => mb_strlen((string) $t)));

        if ($average > 160) {
            $notes[] = "ردودك الأخيرة طويلة (حوالي {$average} حرف): الرد ده سطر أو اتنين، ورد على آخر رسالة بس من غير ما تعيد شرح قلته قبل كده";
        }

        return $notes === [] ? null : "## ردودك الأخيرة\n- ".implode("\n- ", $notes);
    }

    /** @return array{0: int, 1: int}|null [start in $a, length] of the longest run of words both share */
    private function longestSharedRun(array $a, array $b): ?array
    {
        $best = null;

        for ($i = 0; $i < count($a); $i++) {
            for ($j = 0; $j < count($b); $j++) {
                $k = 0;

                while (isset($a[$i + $k], $b[$j + $k]) && $a[$i + $k] === $b[$j + $k]) {
                    $k++;
                }

                if ($k > 0 && $k > ($best[1] ?? 0)) {
                    $best = [$i, $k];
                }
            }
        }

        return $best;
    }

    /** @return array{version: string, text: string} */
    private function buildL0(): array
    {
        $current = $this->instructions->current();

        return ['version' => $current['version'], 'text' => $current['text']];
    }



    /**
     * CTX-004: the 58-line index (~2,000 chars) rode along every call, also
     * while he was only sending papers for a motorcycle already chosen.
     * Structured state only: no application, none chosen on it, or an offer
     * given in the last 24 h = motorcycles are the talk. Otherwise a name he
     * brings up is found by search_motorcycles (names + aliases, CAT-002).
     */
    private function catalogIsRelevant(WhatsappConversation $conversation, ?Application $application): bool
    {
        return $application === null
            || $application->machine_id === null
            || \App\Domain\Conversations\QuotedOffer::ledger($conversation) !== [];
    }

    /** @return array{text: ?string, count: int} */
    private function buildL3(): array
    {
        $lines = $this->catalog->indexLines();

        if ($lines === []) {
            return ['text' => null, 'count' => 0];
        }

        return [
            'text' => "## فهرس الكتالوج (id · ماركة · اسم · سي سي؟) - للتعرف على الاسم والـ id بس. الأسعار من الأدوات\n".implode("\n", $lines),
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

        // With an open application its snapshot holds his values; the
        // profile from earlier applications would only repeat them.
        $profile = $customer && $snapshot === null ? $this->customerProfileFacts($customer) : [];

        $handoff = ['status' => $conversation->status === 'awaiting_agent' ? 'awaiting_agent' : 'none'];

        if ($handoff['status'] === 'awaiting_agent') {
            $handoff['reason'] = Handoff::where('conversation_id', $conversation->id)
                ->whereNull('closed_at')->latest('opened_at')->value('reason');

            if (\App\Domain\Handoff\HandoffService::botKeepsAnswering($conversation)) {
                // what to do meanwhile: the instructions (§٩)
                $handoff = ['status' => 'call_requested'];
            }
        } else {
            // Returned by the timeout with no staff reply: the agent should
            // pick the conversation up itself, not promise a colleague again.
            $last = Handoff::where('conversation_id', $conversation->id)->latest('opened_at')->first();

            if ($last && $last->closed_at && $last->closed_by === null && $last->reason !== 'call_request' && $last->closed_at->gt(now()->subDay())) {
                $handoff['last_handoff'] = 'returned_to_you_without_staff_reply';
            }
        }

        // Whitelisted state only - no counters, timestamps or internal ids.
        $payload = array_filter([
            'customer_profile' => $profile,
            'customer_gender' => $state['customer_gender'] ?? null,
            'waiting_for' => array_values(array_map(fn ($a) => ['kind' => $a['kind'] ?? null, 'key' => $a['key'] ?? null], (array) ($state['awaiting'] ?? []))),
            'conversation_ended' => isset($state['ended_at']) ? true : null,
            // offers a tool really gave him, still valid (24 h, price unchanged): a number source
            'quotes_given_to_him' => \App\Domain\Conversations\QuotedOffer::ledger($conversation),
            // cash prices a tool showed him in the last 24 h (catalog price now): a number source
            'cash_prices_shown' => \App\Domain\Conversations\QuotedOffer::cashPricesShown($conversation),
            'application' => $this->afterFullList($conversation, $snapshot),
            'handoff' => $handoff,
        ], fn ($v) => $v !== null && $v !== []);

        // "ايه المتاح؟" after an SRK 250 photo was answered with 150cc bikes.
        if (($interest = $state['interest'] ?? null) && ($interest['cc'] ?? null)) {
            $payload['customer_is_after'] = ['label' => $interest['label'] ?? null, 'cc' => (int) $interest['cc']];
        }

        // Simulator 2026-10-05: a Didi rider's work was recorded on his first
        // message, but the reading was nowhere in the next turns - he was asked
        // "بتشتغل إيه؟" eight times and no application could open.
        if (($work = app(\App\Domain\Applications\WorkProfiles::class)->get($conversation->id)) && ($work['work_stated'] ?? false)) {
            // whose work this is: "someone_else" alone let the bot say "أبوك" for a brother
            $payload['his_work'] = array_filter([
                'whose_work' => \App\Domain\Applications\Applicant::label(\App\Domain\Applications\Applicant::fromProfile($work)),
                'his_words' => $work['evidence'] ?? null,
                'occupation' => $work['occupation'] ?? null,
                'customer_type' => $work['customer_type'] ?? null,
                'work_type' => ($work['work_type'] ?? 'none') === 'none' ? null : $work['work_type'],
                'insured' => ($work['insured'] ?? 'unknown') === 'unknown' ? null : $work['insured'],
                'still_to_ask' => ($work['question'] ?? 'none') === 'none' ? null : $work['question'],
            ], fn ($v) => $v !== null && $v !== '');
        }

        // "طلبي وصل لفين؟" - his submitted requests, customer-safe fields only.
        $requests = app(\App\Domain\Applications\CustomerRequestStatus::class)->for($customer, $conversation);

        if ($requests !== []) {
            $payload['my_requests'] = $requests;
        }

        $unprocessed = $this->unprocessedMedia($conversation, $turnId);

        if ($unprocessed !== []) {
            $payload['unprocessed_media'] = $unprocessed;
        }

        return ['text' => "## اللي نعرفه عن العميل والطلب (بيانات حقيقية من الداتابيز)\n```json\n"
            .json_encode($payload, JSON_UNESCAPED_UNICODE)."\n```"];
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
            ->filter(fn (MessageMedia $m) => ! isset(($m->analysis ?? [])['band']) && ! isset(($m->analysis ?? [])['not_a_document']))
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


    /** @return array{text: ?string} */
    private function buildL6(WhatsappConversation $conversation): array
    {
        if (blank($conversation->summary)) {
            return ['text' => null];
        }

        // CTX-010: facts / decisions / still open; a summary written before is prose.
        $structured = SummarizeConversation::decode($conversation->summary);

        if ($structured === null) {
            return ['text' => "## ملخص المحادثة السابقة\n{$conversation->summary}"];
        }

        $sections = [];

        foreach (['facts' => 'حقايق عنه', 'decisions' => 'اتفقنا على', 'still_open' => 'لسه مفتوح'] as $key => $label) {
            if ($structured[$key] !== []) {
                $sections[] = "{$label}:\n- ".implode("\n- ", $structured[$key]);
            }
        }

        return ['text' => $sections === [] ? null : "## ملخص المحادثة السابقة (الأقدم من الرسايل اللي تحت)\n".implode("\n", $sections)];
    }

    /**
     * @return array{contents: array, tokens: int, trimmed: bool, oldest_included_id: ?int}
     */
    private function buildL7(WhatsappConversation $conversation, object $turn): array
    {
        // No cut at session_started_at: a customer back after a day got a
        // bot that had forgotten his job and the bike, and asked it all
        // again. The token budget and the summary bound the window instead.
        $query = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where(fn ($q) => $q->whereNull('turn_id')->orWhere('turn_id', '!=', $turn->id))
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
                $note = ($message->type === 'document' ? '[ملف سابق' : '[صورة سابقة').' - media_id: '.implode(', ', $ids).']';
                $text = $message->text ? "{$note} {$message->text}" : $note;
            }
        }

        // CTX-008: a location or a video read as "[media]" in history.
        if ($isCustomer && ($note = $this->locationNote($message) ?? $this->videoNote($message)) !== null) {
            $text = $message->text ? "{$note} {$message->text}" : $note;
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
        // CTX-005: the bot's own reply shares the turn_id; a turn retried
        // after it was sent showed the model that reply as customer text.
        $messages = WhatsappMessage::with(['quotedMessage', 'media'])
            ->where('whatsapp_conversation_id', $conversation->id)
            ->where('turn_id', $turn->id)
            ->where('direction', 'incoming')
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

            // CTX-008: a location has no text and no media - it was skipped.
            if (($note = $this->locationNote($message) ?? $this->videoNote($message)) !== null) {
                $parts[] = ['type' => 'text', 'text' => $note];
                $tokens += TokenEstimator::estimate($note);
            }

            // CTX-008: a PDF in this turn showed only its caption, so the
            // model had no media_id to call process_document with.
            if ($message->type === 'document') {
                foreach ($message->media as $media) {
                    if (str_starts_with((string) $media->mime, 'image/')) {
                        continue; // a photo sent "as a file" is read like a photo below
                    }

                    $read = \App\Models\ApplicationDocument::where('media_id', $media->id)->latest('id')->first(['detected_type_key', 'status']);
                    $idNote = $read !== null
                        ? "[ملف مستند اتقرا - media_id: {$media->id} - ".($read->detected_type_key ?? 'غير معروف')." - {$read->status}]"
                        : "[ملف مرفق ({$this->fileLabel($media)}) - media_id: {$media->id} - لو مستند للطلب اقراه بـ process_document]";
                    $parts[] = ['type' => 'text', 'text' => $idNote];
                    $tokens += TokenEstimator::estimate($idNote);
                }
            }

            if (in_array($message->type, ['image', 'sticker', 'document'], true)) {
                foreach ($message->media as $media) {
                    if ($message->type === 'document' && ! str_starts_with((string) $media->mime, 'image/')) {
                        continue;
                    }

                    // process_document/identify_motorcycle_from_image both require a
                    // media_id argument, but the model only ever sees the raw image
                    // bytes below - with no id anywhere in its context it has no way
                    // to reference this image in a tool call. Surface it as text.
                    // QA 2026-10-04: every ID card and screenshot was sent to
                    // the model again on every later turn (image tokens each
                    // time). A photo already read is its result, not its pixels.
                    $read = \App\Models\ApplicationDocument::where('media_id', $media->id)->latest('id')->first(['detected_type_key', 'status']);

                    if ($read !== null) {
                        $idNote = "[صورة مستند اتقرت - media_id: {$media->id} - ".($read->detected_type_key ?? 'غير معروف')." - {$read->status}]";
                        $parts[] = ['type' => 'text', 'text' => $idNote];
                        $tokens += TokenEstimator::estimate($idNote);

                        continue;
                    }

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

        // Replay of conversation 206: "اخويا عنده ٢٥ وشغال نجار" then "لا هو مش
        // شغال دلوقتي" - the correction was never recorded and the bot kept
        // collecting the brother's name and phone. What is recorded about the
        // person applying sits next to his new message, to be checked against it.
        if ($contents !== [] && ($recorded = $this->recordedApplicantNote($conversation)) !== null) {
            $contents[count($contents) - 1]['parts'][] = ['type' => 'text', 'text' => $recorded];
            $tokens += TokenEstimator::estimate($recorded);
        }

        return ['contents' => $contents, 'tokens' => $tokens];
    }

    /**
     * Owner 2026-10-05: the whole list once, when the application opens;
     * then "تمام ابعتلي" + the next missing thing. With every list in front
     * of it on every turn the model kept re-sending them all. Once a reply
     * went after the application opened, only next_step stays.
     */
    private function afterFullList(WhatsappConversation $conversation, ?array $snapshot): ?array
    {
        if ($snapshot === null || ! isset($snapshot['application_id'])) {
            return $snapshot;
        }

        $openedAt = Application::whereKey($snapshot['application_id'])->value('created_at');
        $sent = $openedAt && WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')->where('sender_type', 'bot')->where('created_at', '>=', $openedAt)->exists();

        if (! $sent) {
            return $snapshot + ['full_list_sent' => false];
        }

        $next = $snapshot['next_step']['key'] ?? null;
        unset($snapshot['full_list'], $snapshot['documents']['list']);

        foreach (['fields', 'documents'] as $part) {
            if (isset($snapshot[$part]['missing_hints'])) {
                $snapshot[$part]['missing_hints'] = array_intersect_key($snapshot[$part]['missing_hints'], array_flip(array_filter([$next])));
            }
        }

        return $snapshot + ['full_list_sent' => true];
    }

    private function recordedApplicantNote(WhatsappConversation $conversation): ?string
    {
        $work = app(\App\Domain\Applications\WorkProfiles::class)->get($conversation->id);

        if ($work === null) {
            return null;
        }

        $working = match ($work['working_now'] ?? 'unknown') {
            'yes' => 'شغال', 'no' => 'مش شغال', 'not_yet' => 'لسه هيشتغل', default => 'مش معروف',
        };

        // "أنا مسجل كلامك عن والدتك" was said to him from this note: it is marked as not for him
        return '[ملاحظة داخلية، ما تتقالش له ولا تقول إنك مسجل حاجة - اللي هيقدّم = '.\App\Domain\Applications\Applicant::label(\App\Domain\Applications\Applicant::fromProfile($work))
            ." · شغله: {$working}".(filled($work['evidence'] ?? null) ? ' (من كلامه: "'.$work['evidence'].'")' : '')
            .'. لو رسالته دي بتغيّر مين اللي هيقدّم أو شغله، نادي record_work_profile بكلامه الجديد قبل الرد.]';
    }

    /** "[العميل بعت لوكيشن: 30.04, 31.23 - اسم - عنوان]" or null when it is not a location. */
    private function locationNote(WhatsappMessage $message): ?string
    {
        $location = $message->metadata['location'] ?? null;

        if ($message->type !== 'location' && ! is_array($location)) {
            return null;
        }

        $location = (array) $location;
        $coordinates = isset($location['latitude'], $location['longitude'])
            ? $location['latitude'].', '.$location['longitude']
            : null;
        $details = array_values(array_filter([$coordinates, $location['name'] ?? null, $location['address'] ?? null], fn ($v) => filled($v)));

        return '[العميل بعت لوكيشن'.($details === [] ? '' : ': '.implode(' - ', $details)).']';
    }

    private function videoNote(WhatsappMessage $message): ?string
    {
        if ($message->type !== 'video') {
            return null;
        }

        return '[العميل بعت فيديو - مش بنقدر نشوف الفيديو. لو محتاج منه حاجة اطلب صورة أو يكتبها، من غير ما تفترض محتواه]';
    }

    private function fileLabel(MessageMedia $media): string
    {
        $kind = $media->mime === 'application/pdf' ? 'PDF' : ($media->mime ?: 'ملف');

        return $media->original_filename ? "{$kind} {$media->original_filename}" : $kind;
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
        $byTokens = config('agent.summary.trigger_tokens');
        $byCount = config('agent.summary.trigger_messages');

        if (($byTokens === null && $byCount === null) || $oldestIncludedId === null) {
            return;
        }

        $since = $conversation->summary_until_message_id ?? 0;

        // CTX-010: what fell out of the window, measured like the window itself.
        $backlog = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('id', '>', $since)
            ->where('id', '<', $oldestIncludedId)
            ->get(['id', 'text', 'transcript']);

        $tokens = $backlog->sum(fn ($m) => TokenEstimator::estimate((string) ($m->text ?? $m->transcript ?? '')));

        if (($byTokens !== null && $backlog->isNotEmpty() && $tokens >= (int) $byTokens)
            || ($byCount !== null && $backlog->count() >= (int) $byCount)) {
            SummarizeConversation::dispatch($conversation->id, $oldestIncludedId);
        }
    }
}
