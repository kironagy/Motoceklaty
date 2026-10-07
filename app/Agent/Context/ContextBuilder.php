<?php

namespace App\Agent\Context;

use App\Agent\Context\Facts\ConversationFacts;
use App\Agent\Context\Facts\ToldSoFar;
use App\Agent\Providers\AiRequest;
use App\Agent\Tracing\Redactor;
use App\Domain\Catalog\CatalogService;
use App\Jobs\SummarizeConversation;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\CustomerType;
use App\Models\MessageMedia;
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
        private readonly ConversationFacts $facts,
        private readonly ToldSoFar $toldSoFar,
        private readonly \App\Domain\Settings\AgentInstructions $instructions,
    ) {
    }

    public function build(object $turn): AiRequest
    {
        $conversation = WhatsappConversation::with('customer')->findOrFail($turn->whatsapp_conversation_id);

        // Phase 1: ONE factual view. Everything the agent knows about this
        // customer, his application and his conversation comes from
        // ConversationFacts; what he was already told from ToldSoFar. The
        // stores are not read here.
        $result = $this->facts->for($conversation, $turn->id);
        $this->tidyAwaiting($conversation, $result->snapshot);
        $application = $result->application;

        // Rebuild: ONE instruction source (L0). Pinned/scoped knowledge and
        // lessons are folded into it; what follows is data only - catalog
        // names, customer-type keys, then the facts.
        $l0 = $this->buildL0();
        $l3 = $this->catalogIsRelevant($conversation, $application) ? $this->buildL3() : ['text' => null, 'count' => 0];
        $l3b = $this->buildL3b();
        $l4 = $this->renderFacts($result->facts);
        $told = $this->renderToldSoFar($this->toldSoFar->for($conversation, $application));
        $l6 = $this->buildL6($conversation);
        $l7 = $this->buildL7($conversation, $turn);
        $l8 = $this->buildL8($conversation, $turn, $result->facts);

        $system = implode("\n\n", array_values(array_filter([
            // The same text for every customer goes first, so the provider's
            // cache covers it on every call.
            $l0['text'], $l3['text'], $l3b['text'], $l4['text'], $told['text'], $l6['text'], $this->ownStyleNote($conversation),
        ], fn ($block) => $block !== null && trim($block) !== '')));

        $request = new AiRequest(
            system: $system,
            contents: array_merge($l7['contents'], $l8['contents']),
            // A customer reply healthy takes 2-4s. Real run 2026-10-07: Google stalled calls for 30s
            // (then 15-20s each) while another model answered in 1.3s - a stalled call is dropped early
            // and the next key/model takes over.
            timeoutSeconds: (int) config('agent.reply_timeout_seconds', 12),
        );

        $manifest = [
            'prompt_version' => $l0['version'],
            'l0_tokens' => TokenEstimator::estimate($l0['text']),
            'l3_count' => $l3['count'],
            'l3b_count' => $l3b['count'],
            'l4_present' => true,
            'facts_tokens' => TokenEstimator::estimate($l4['text']),
            'told_so_far_tokens' => $told['text'] === null ? 0 : TokenEstimator::estimate($told['text']),
            'l6_present' => $l6['text'] !== null,
            'l7_count' => count($l7['contents']),
            'l7_trimmed' => $l7['trimmed'],
            'l8_count' => count($l8['contents']),
            'total_input_tokens_estimate' => TokenEstimator::estimate($system) + $l7['tokens'] + $l8['tokens'],
        ];

        $this->recordManifest($conversation, $turn, $l0['version'], $manifest);
        $this->maybeTriggerSummary($conversation, $l7['summary_until_id']);

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

    /**
     * The facts as one block. The heading says what they are not: messages
     * and the summary are context, not facts. That the facts are the only
     * source of prices, installments, names and documents is in the instructions.
     *
     * @return array{text: string}
     */
    private function renderFacts(array $facts): array
    {
        return ['text' => "## اللي نعرفه عن العميل والطلب (حقايق الداتابيز؛ الرسايل والملخص للسياق بس)\n```json\n"
            .json_encode($facts === [] ? new \stdClass() : $facts, JSON_UNESCAPED_UNICODE)."\n```"];
    }

    /** @return array{text: ?string} */
    private function renderToldSoFar(array $told): array
    {
        if ($told === []) {
            return ['text' => null];
        }

        return ['text' => "## اللي اتقاله فعلاً في المحادثة دي (من الأدوات - لو قال \"تمام\" ما تعيدوش؛ لو سأل تاني جاوبه)\n```json\n"
            .json_encode($told, JSON_UNESCAPED_UNICODE)."\n```"];
    }

    /**
     * An `awaiting` entry drops out once its field is collected or its
     * document accepted in the snapshot. The facts filter it at read time;
     * this is the one explicit write that keeps the stored list tidy.
     */
    private function tidyAwaiting(WhatsappConversation $conversation, ?array $snapshot): void
    {
        $state = $conversation->state ?? [];
        $stored = (array) ($state['awaiting'] ?? []);

        if ($stored === [] || ! $snapshot) {
            return;
        }

        $open = $this->facts->openAwaiting($state, $snapshot);

        if ($open !== $stored) {
            $state['awaiting'] = $open;
            $conversation->update(['state' => $state]);
        }
    }

    /**
     * The older conversation, as context only. It is never a source of a
     * price, a name, a document or any other fact: those come from the
     * facts block, and a summary line never overrides one.
     *
     * @return array{text: ?string}
     */
    private function buildL6(WhatsappConversation $conversation): array
    {
        if (blank($conversation->summary)) {
            return ['text' => null];
        }

        $heading = '## الكلام الأقدم (للسياق بس - مش مصدر للأرقام ولا للبيانات؛ لو اختلف مع الحقايق اللي فوق الحقايق هي الصح)';

        // CTX-010: facts / decisions / still open; a summary written before is prose.
        $structured = SummarizeConversation::decode($conversation->summary);

        if ($structured === null) {
            return ['text' => "{$heading}\n{$conversation->summary}"];
        }

        $sections = [];

        foreach (['facts' => 'اتقال', 'decisions' => 'اتفقنا على', 'still_open' => 'لسه مفتوح'] as $key => $label) {
            if ($structured[$key] !== []) {
                $sections[] = "{$label}:\n- ".implode("\n- ", $structured[$key]);
            }
        }

        return ['text' => $sections === [] ? null : "{$heading}\n".implode("\n", $sections)];
    }

    /**
     * @return array{contents: array, tokens: int, trimmed: bool, oldest_included_id: ?int, summary_until_id: ?int}
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
        // Recent messages keep the talk natural; they are not a source of facts.
        // Nothing vanishes: what the summary has not absorbed yet stays in
        // view; the summary is asked to take everything before the last N.
        $keepRaw = max(1, (int) config('agent.context.recent_messages_count', 8));
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

        // Where the summary should reach: the last N raw messages stay raw,
        // and whatever the token budget cut is summarized too.
        $oldestIncluded = $messages === [] ? null : $messages[0]['message']->id;
        $lastRaw = count($messages) > $keepRaw ? $messages[count($messages) - $keepRaw]['message']->id : null;

        return [
            'contents' => array_column($messages, 'content'),
            'tokens' => $tokens,
            'trimmed' => $trimmed,
            'oldest_included_id' => $oldestIncluded,
            'summary_until_id' => $oldestIncluded === null ? null : max($oldestIncluded, $lastRaw ?? $oldestIncluded),
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
    private function buildL8(WhatsappConversation $conversation, object $turn, array $facts = []): array
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
        // شغال دلوقتي" - the correction was never recorded. What is recorded is
        // in the facts (`applicant`, `work`); this only reminds the agent, next to
        // his newest message, to check that message against it. Measured in
        // Phase 1: with the reminder only in the long instructions the agent
        // recorded his work less often and fewer applications opened.
        if ($contents !== []) {
            $note = isset($facts['work']) || isset($facts['applicant'])
                ? '[ملاحظة داخلية، ما تتقالش له ولا تقول إنك مسجل حاجة: لو رسالته دي بتغيّر مين اللي هيقدّم أو شغله (شغال/لأ، متأمن/لأ، نوع الشغل)، نادي record_work_profile بكلامه الجديد قبل الرد]'
                // nothing recorded yet: a real cafe owner said "انا صاحب قهوة" in his first message and was asked
                // "صاحبها ولا شغال فيها؟" because the first reply came before any record
                : '[ملاحظة داخلية، ما تتقالش له: لو رسالته فيها شغله أو إنه ست/راجل أو جنسيته أو مين هيقدّم، نادي record_work_profile بكلامه قبل الرد، ومتسألوش على حاجة قالها]';
            $contents[count($contents) - 1]['parts'][] = ['type' => 'text', 'text' => $note];
            $tokens += TokenEstimator::estimate($note);
        }

        // Owner 2026-10-06: "بكام الهوجن 3 وبتقسطوا على كام سنة وفين الفرع؟"
        // got the price only - the rest of his message was dropped.
        if ($contents !== [] && $this->asksSeveralThings($messages)) {
            $note = '[ملاحظة داخلية، ما تتقالش له: رسالته فيها أكتر من سؤال أو طلب - جاوبهم كلهم في نفس الرد بالترتيب، كل واحد في سطر قصير، ونادي كل الأدوات اللي محتاجها في نفس الخطوة]';
            $contents[count($contents) - 1]['parts'][] = ['type' => 'text', 'text' => $note];
            $tokens += TokenEstimator::estimate($note);
        }

        return ['contents' => $contents, 'tokens' => $tokens];
    }

    private const QUESTION_WORDS = '(?:بكام|بكم|كام|فين|امتى|إمتى|ازاي|إزاي|ايه|إيه|اية|هل|ينفع|ممكن|عندكم|عندك|في\s+تقسيط|المطلوب|محتاج)';

    /** Two or more questions/asks in this turn's text: several messages, "؟" twice, or "و" + a second question word. */
    private function asksSeveralThings(\Illuminate\Support\Collection $messages): bool
    {
        $text = $messages->map(fn ($m) => trim((string) ($m->text ?? $m->transcript)))->filter()->implode("\n");

        if ($text === '') {
            return false;
        }

        $pieces = preg_split('/[؟?\n]+|\s+و\s*(?='.self::QUESTION_WORDS.'(?:\s|$))/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $questions = array_filter($pieces, fn ($p) => preg_match('/(?:^|\s)'.self::QUESTION_WORDS.'(?:\s|$)/u', trim($p)));

        return count($questions) >= 2;
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
