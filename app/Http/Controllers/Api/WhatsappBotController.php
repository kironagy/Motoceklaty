<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiMemory;
use App\Models\Machine;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\InstallmentRequest;
use App\Models\Brand;
use App\Services\WhatsappIntentRouter;





class WhatsappBotController extends Controller
{
   public function incomingMessage(Request $request)
{
    if ($request->header('X-BOT-TOKEN') !== config('services.whatsapp.bot_token')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $botId = $request->input('bot_id');
    $from = $request->input('from');
    $message = $this->cleanIncomingMessage(trim($request->input('message', '')));
    $direction = $request->input('direction', 'incoming');
    $waMessageId = $request->input('wa_message_id') ?: null;

    $isFromMe = (bool) (
        $request->input('from_me', false)
        || $request->input('fromMe', false)
        || data_get($request->all(), 'key.fromMe', false)
        || data_get($request->all(), 'message.key.fromMe', false)
    );

    $hasSingleMedia = $request->filled('media_base64');
    $hasMultiMedia = is_array($request->input('media_items')) && count($request->input('media_items')) > 0;
    $hasMedia = $hasSingleMedia || $hasMultiMedia;

    if (!$from || (!$message && !$hasMedia)) {
        return $this->emptyResponse();
    }

    $phone = $this->cleanPhoneFromJid($from);
    $bot = WhatsappBot::find($botId);

    if (!$bot) {
        return $this->emptyResponse();
    }

    $conversation = WhatsappConversation::firstOrCreate(
        ['whatsapp_bot_id' => $bot->id, 'phone' => $phone],
        ['status' => 'open']
    );

    /*
     * بعض العملاء بيبان الشات معاهم بمعرّف @lid (خصوصية واتساب) بدل رقم
     * الموبايل الحقيقي في remoteJid، فـ $phone بيبقى رقم مش حقيقي. الـ
     * worker بيحاول يحل الرقم الحقيقي (remoteJidAlt أو lid-mapping) ولو
     * لقاه بيبعته هنا كـ customer_jid - بنسجله في عمود منفصل real_phone
     * للعرض بس، من غير ما نغيّر $phone اللي بيتستخدم كمفتاح للمحادثة.
     */
    $customerJid = $request->input('customer_jid');

    if ($customerJid) {
        $realPhone = $this->cleanPhoneFromJid((string) $customerJid);

        if ($realPhone !== '' && $realPhone !== $conversation->real_phone) {
            $conversation->forceFill(['real_phone' => $realPhone])->save();
        }
    }

    if ($waMessageId && $direction !== 'outgoing' && !$isFromMe) {
        $alreadyProcessed = $conversation->messages()
            ->where('wa_message_id', $waMessageId)
            ->exists();

        if ($alreadyProcessed) {
            return response()->json([
                'ok' => true,
                'queued' => false,
                'duplicate' => true,
                'reply' => null,
                'image' => null,
                'images' => [],
            ]);
        }
    }

    if ($direction === 'outgoing' || $isFromMe) {
        $conversation->messages()->create([
            'direction' => 'outgoing',
            'message' => $message,
            'payload' => $request->except(['media_base64', 'media_items']),
        ]);

        return $this->emptyResponse();
    }

    $mediaItems = [];

    if ($hasMultiMedia) {
        $mediaItems = $this->saveIncomingMediaItems($request, $conversation);
    } elseif ($hasSingleMedia) {
        $single = $this->saveIncomingMedia($request, $conversation);

        if ($single) {
            $mediaItems[] = $single;
        }
    }

    /*
     * "لو بعت نفس الرسالة مرتين رد على واحدة بس" - نفس نص آخر رسالة واردة
     * فعلًا (بدون ميديا) وجاية خلال دقيقتين، اعتبرها العميل بيكرر نفسه
     * (رسالة اترسلت مرتين بالغلط، أو نفس التحية تاني) - نسجّلها في
     * التاريخ زي أي رسالة عادي، بس من غير ما نعمل لها Job/رد جديد.
     * الفاصل بدقيقتين عشان ميضربش نفس الرسالة لو العميل بعتها تاني بعد
     * ساعات مستني رد حقيقي جديد.
     */
    $trimmedMessage = trim($message);
    $isDuplicateOfLast = false;

    if ($trimmedMessage !== '' && !$hasMedia) {
        $lastIncoming = $conversation->messages()
            ->where('direction', 'incoming')
            ->latest('id')
            ->first();

        $isDuplicateOfLast = $lastIncoming
            && trim((string) $lastIncoming->message) === $trimmedMessage
            && $lastIncoming->created_at
            && $lastIncoming->created_at->gt(now()->subMinutes(2));
    }

    /*
     * "لو بعت رسالتين أو تلاتة ورا بعض رد عليه بـ reply مش رسالة عادية" -
     * لو لسه فيه Job من نفس المحادثة قاعد ينتظر أو بيتعالج، يبقى الرسالة
     * دي جت قبل ما نرد على اللي قبلها - الرد عليها لازم يبقى quoted
     * (reply) عشان يوضح إنه بيرد على السؤال ده بالذات، مش على آخر حاجة
     * في الشات.
     */
    $hasUnansweredJob = DB::table('whatsapp_message_jobs')
        ->where('whatsapp_conversation_id', $conversation->id)
        ->whereIn('status', ['pending', 'processing'])
        ->exists();

    /*
     * أكتر من فويس/صورة ورا بعض بيتجمعوا في job واحد (الـ collector في
     * Node بيستنى 3 ثواني)، فمفيش job سابق ينتظر - ومع ذلك العميل بعت
     * أكتر من رسالة والرد لازم يبان بيرد على أنهي واحدة. والفويس تحديدًا
     * العميل مش شايف نصه قدامه، فالـ reply بيوضّح أكتر من رسالة عادية.
     */
    $quoteReply = $hasUnansweredJob || count($mediaItems) > 1;

    $conversation->messages()->create([
        'direction' => 'incoming',
        'wa_message_id' => $waMessageId,
        'message' => $message ?: (count($mediaItems) ? '[media]' : ''),
        'payload' => array_merge($request->except(['media_base64', 'media_items']), [
            'saved_media_items' => $mediaItems,
        ]),
    ]);

    if ($isDuplicateOfLast) {
        return response()->json([
            'ok' => true,
            'queued' => false,
            'duplicate' => true,
            'reply' => null,
            'image' => null,
            'images' => [],
        ]);
    }

    $this->queueWhatsappMessageJob(
        $bot,
        $conversation,
        $from,
        $request->input('reply_jid') ?: $from,
        $message,
        $mediaItems,
        $request,
        $quoteReply
    );

    return response()->json([
        'ok' => true,
        'queued' => true,
        'reply' => null,
        'image' => null,
        'images' => [],
    ]);
}

    /**
     * بيرجع id آخر بوت شغّال (is_active) عشان الـ worker يعرف يشغّل
     * البوت الصح لوحده وقت ما يبدأ، من غير ما نحتاج نعدّل AUTO_START_BOT_ID
     * في الـ .env يدويًا كل ما نمسح بوت وننشئ واحد جديد.
     */
    public function latestActiveBotId(Request $request)
    {
        if ($request->header('X-BOT-TOKEN') !== config('services.whatsapp.bot_token')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $bot = WhatsappBot::where('is_active', true)->latest('id')->first()
            ?? WhatsappBot::latest('id')->first();

        return response()->json([
            'ok' => (bool) $bot,
            'bot_id' => $bot?->id,
        ]);
    }

private function queueWhatsappMessageJob(
    WhatsappBot $bot,
    WhatsappConversation $conversation,
    string $from,
    string $replyJid,
    string $message,
    array $mediaItems,
    Request $request,
    bool $quoteReply = false
): void {
    DB::table('whatsapp_message_jobs')->insert([
        'whatsapp_bot_id' => $bot->id,
        'whatsapp_conversation_id' => $conversation->id,
        'from' => $from,
        'reply_jid' => $replyJid,
        'message' => $message ?: (count($mediaItems) ? '[media]' : ''),
        'payload' => json_encode(array_merge(
            $request->except(['media_base64', 'media_items']),
            [
                'saved_media_items' => $mediaItems,
                'quote_reply' => $quoteReply,
            ]
        ), JSON_UNESCAPED_UNICODE),
        'status' => 'pending',
        'attempts' => 0,
        'locked_at' => null,
        'processed_at' => null,
        'error' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}


public function processQueuedWhatsappJob(object $job): array
{
    $bot = WhatsappBot::find($job->whatsapp_bot_id);

    if (!$bot) {
        throw new \RuntimeException('Bot not found');
    }

    $conversation = WhatsappConversation::find($job->whatsapp_conversation_id);

    if (!$conversation) {
        throw new \RuntimeException('Conversation not found');
    }

    $payload = is_string($job->payload ?? null)
        ? json_decode($job->payload, true)
        : ($job->payload ?? []);

    if (!is_array($payload)) {
        $payload = [];
    }

    $message = $this->cleanIncomingMessage((string) ($job->message ?? ''));
    $mediaItems = data_get($payload, 'saved_media_items', []);

    if (!is_array($mediaItems)) {
        $mediaItems = [];
    }

    /*
     * لو العميل عمل reply/quote على رسالة سابقة (مننا أو منه) - زي "انت
     * قولتلي ٥٨٥٠٠ عايز اعرف ده سعر ايه" - من غير السياق ده الراوتر ماكانش
     * بيفهم "ده" بتشاور على إيه. بنلزق نص الرسالة المقتبسة كسياق قبل
     * الرسالة نفسها عشان الـ intent classifier والـ AI يقدروا يحلوا المرجع.
     */
    $quotedText = trim((string) data_get($payload, 'quoted_text', ''));

    if ($quotedText !== '' && $message !== '') {
        $message = "(العميل بيرد على رسالة سابقة نصها: \"{$quotedText}\") {$message}";
    }

    /*
     * مهم:
     * هنسيب تأكيد الطلب القديم زي ما هو مؤقتًا
     * عشان لو فيه pending_order_data محفوظ قبل كده ميتكسرش.
     * بعد ما نعمل Gemini Order Extractor هنظبط الجزء ده.
     */
    $forcedData = (!count($mediaItems) && $this->isOrderConfirmationMessage($message))
        ? $this->latestPendingOrderData($conversation)
        : null;

    /*
     * لو مفيش pending_order_data قديمة أصلاً (زي أي عميل بيستخدم
     * التدفق الجديد عبر ApplicationHandler)، منردش برسالة تايهة -
     * نسيب الراوتر الحديث يتصرف بدل ما نوقف المحادثة هنا.
     */
    if ($forcedData) {
        if (!$this->orderDataIsComplete($forcedData)) {
            return [
                'reply' => $this->missingOrderDataReply($forcedData),
                'image' => null,
                'images' => [],
                'image_items' => [],
                'image_groups' => [],
            ];
        }

        $created = $this->createInstallmentRequestFromBot($bot, $conversation, $forcedData);

        return [
            'reply' => $created['reply'] ?? '',
            'image' => null,
            'images' => [],
            'image_items' => [],
            'image_groups' => [],
        ];
    }

    /*
     * من هنا خلاص مفيش ChatGPT.
     * كل الردود من Gemini Intent Router + Database.
     */
    $intentHandled = app(WhatsappIntentRouter::class)->handle(
        conversation: $conversation,
        message: $message,
        mediaItems: $mediaItems
    );

    return [
        'reply' => $intentHandled['reply'] ?? '',
        /*
         * Multi-request messages ("سعرها وابعتلي مكانكم فين") come back with
         * one entry per answer so the worker sends them as separate WhatsApp
         * messages instead of one wall of text. Absent for a normal single
         * answer, where 'reply' alone is used.
         */
        'replies' => $intentHandled['replies'] ?? [],
        'image' => $intentHandled['image'] ?? null,
        'images' => $intentHandled['images'] ?? [],
        'image_items' => $intentHandled['image_items'] ?? [],
        'image_groups' => $intentHandled['image_groups'] ?? [],
        'intent' => $intentHandled['intent'] ?? null,
        'source' => $intentHandled['source'] ?? 'gemini_intent_router',
    ];
}    private function mediaReviewPrompt(): string
    {
        return trim("
دي صورة أو مستند من العميل.

دقيقة يا فندم جاري مراجعة البيانات.

اقرأ كل الصور/المستندات المرفقة بنفسك واستخرج البيانات المهمة منها.
لو صورة بطاقة مصرية:
- استخرج الاسم.
- استخرج الرقم القومي.
- استخرج العنوان.
- اقرأ الاسم كامل من أول كلمة في سطر الاسم، ممنوع حذف أول اسم.
- مثال: لو الاسم ظاهر جلال حسين صالح عبدالله اكتبه كامل زي ما هو.
- لا تعتمد الاسم الناقص من محادثة قديمة.
- استخرج تاريخ الميلاد من الرقم القومي.
- احسب السن من تاريخ الميلاد الموجود داخل الرقم القومي.
- لو العميل كاتب سن مختلف عن الرقم القومي، اعتمد السن المحسوب من الرقم القومي فقط.
- لو الصورة غير واضحة، قول للعميل يصورها بشكل أوضح من غير ما تخترع بيانات.
لو المستند سجل تجاري أو بطاقة ضريبية:
- صنّف الحالة self_employed / صاحب نشاط.
- استخرج اسم النشاط وعنوان العمل.
- لا تعتبره موظف حتى لو اسم النشاط فيه كلمة شركة.
- ما تقولش بيانات من صورة قديمة أو محادثة سابقة إلا لو نفس البيانات ظاهرة في الصور الحالية.
- رد كسيلز طبيعي وباللهجة المصرية.
        ");
    }

private function findMachinesStrict(string $message): Collection
{
    $query = $this->normalizeSearchText($message);
    $queryCode = $this->normalizeModelCode($message);
    $queryTokens = $this->importantSearchTokens($query);

    if (!$query && !$queryCode) {
        return collect();
    }

    $machines = Machine::query()->get();

    $scored = $machines->map(function ($machine) use ($query, $queryCode, $queryTokens) {
        $bestScore = 0;

        foreach ($this->machineNames($machine) as $rawName) {
            $name = $this->normalizeSearchText($rawName);
            $nameCode = $this->normalizeModelCode($rawName);
            $nameTokens = $this->importantSearchTokens($name);

            $score = 0;

            if ($name && $query && $name === $query) {
                $score += 1000;
            }

            if ($name && $query && str_contains($name, $query)) {
                $score += 700;
            }

            if ($name && $query && str_contains($query, $name)) {
                $score += 650;
            }

            if ($nameCode && $queryCode) {
                if ($nameCode === $queryCode) {
                    $score += 1000;
                } elseif (str_contains($nameCode, $queryCode)) {
                    $score += 650;
                } elseif (str_contains($queryCode, $nameCode)) {
                    $score += 600;
                }
            }

            foreach ($queryTokens as $token) {
                if (in_array($token, $nameTokens, true)) {
                    $score += $this->isNumericToken($token) ? 250 : 180;
                }
            }

            similar_text($query, $name, $percent);
     if ($percent >= 85) {
    $score += (int) $percent;
}
if (
    !$this->containsAnyStrongTokenMatch($queryTokens, $nameTokens)
) {
    $score = 0;
}

            $bestScore = max($bestScore, $score);
        }

        return [
            'machine' => $machine,
            'score' => $bestScore,
        ];
    })
    ->filter(fn ($row) => $row['score'] >= 350)
    ->sortByDesc('score')
    ->values();

    if ($scored->isEmpty()) {
        return collect();
    }

    $topScore = $scored->first()['score'];

    return $scored
        ->filter(fn ($row) => $row['score'] >= max(350, $topScore - 220))
        ->pluck('machine')
        ->values();
}
private function containsAnyStrongTokenMatch(array $queryTokens, array $nameTokens): bool
{
    foreach ($queryTokens as $token) {

        if (mb_strlen($token) < 3 && !is_numeric($token)) {
            continue;
        }

        if (in_array($token, $nameTokens, true)) {
            return true;
        }
    }

    return false;
}
    private function machineNames(Machine $machine): array
    {
        $names = [];

        if (!empty($machine->name)) {
            $names[] = $machine->name;
        }

        if (!empty($machine->aliases)) {
            $aliases = is_array($machine->aliases)
                ? $machine->aliases
                : json_decode($machine->aliases, true);

            if (is_array($aliases)) {
                foreach ($aliases as $alias) {
                    if ($alias) {
                        $names[] = $alias;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    private function cleanIncomingMessage(string $message): string
    {
        $message = preg_replace('/Pasted\s*text(\.txt)?/iu', '', $message);
        $message = preg_replace('/Attached\s*file\s*:\s*/iu', '', $message);
        $message = preg_replace('/\s+/', ' ', $message);

        return trim($message);
    }

    private function saveIncomingMediaItems(Request $request, WhatsappConversation $conversation): array
    {
        $saved = [];

        foreach ($request->input('media_items', []) as $index => $item) {
            $fakeRequest = new Request([
                'media_base64' => $item['media_base64'] ?? null,
                'media_mime' => $item['media_mime'] ?? 'application/octet-stream',
                'media_type' => $item['media_type'] ?? 'file',
                'media_filename' => $item['media_filename'] ?? null,
            ]);

            $media = $this->saveIncomingMedia($fakeRequest, $conversation, $index + 1);

            if ($media) {
                $saved[] = $media;
            }
        }

        return $saved;
    }

    private function saveIncomingMedia(Request $request, WhatsappConversation $conversation, ?int $index = null): ?array
    {
        try {
            $base64 = $request->input('media_base64');

            if (!$base64) return null;

            $binary = base64_decode($base64, true);

            if ($binary === false) return null;

            $mime = $request->input('media_mime', 'application/octet-stream');
            $type = $request->input('media_type', 'file');
            $extension = $this->extensionFromMime($mime, $type);

            $directory = 'whatsapp-documents/conversation-' . $conversation->id;
            $prefix = $index ? $index . '_' : '';
            $filename = now()->format('Ymd_His') . '_' . $prefix . Str::random(12) . '.' . $extension;
            $path = $directory . '/' . $filename;

            Storage::disk('public')->put($path, $binary);

            return [
                'type' => $type,
                'mime' => $mime,
                'filename' => $request->input('media_filename') ?: $filename,
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'size' => strlen($binary),
            ];
        } catch (\Throwable $e) {
            Log::error('save incoming media error', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extensionFromMime(string $mime, string $type = 'file'): string
    {
        $mime = strtolower($mime);

        return match (true) {
            str_contains($mime, 'jpeg') => 'jpg',
            str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'mp4') => 'mp4',
            str_contains($mime, 'quicktime') => 'mov',
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'opus') => 'ogg',
            str_contains($mime, 'mpeg') && $type === 'audio' => 'mp3',
            str_contains($mime, 'mp3') => 'mp3',
            default => $type === 'image' ? 'jpg' : ($type === 'video' ? 'mp4' : ($type === 'audio' ? 'ogg' : 'bin')),
        };
    }

    private function saveConversationState(WhatsappConversation $conversation, array $data): void
    {
        $allowed = [];

        foreach ($data as $key => $value) {
            if (Schema::hasColumn('whatsapp_conversations', $key)) {
                $allowed[$key] = $value;
            }
        }

        if (!empty($allowed)) {
            $conversation->forceFill($allowed)->save();
        }
    }

    private function cleanPhoneFromJid(string $jid): string
    {
        return str_replace(['@s.whatsapp.net', '@lid', '@c.us'], '', $jid);
    }

private function normalizeModelCode(string $text): string
{
    $text = $this->arabicDigitsToEnglish($text);
    $text = $this->normalizeSearchText($text);

    return preg_replace('/[^a-z0-9\p{Arabic}]/iu', '', mb_strtolower($text));
}

    private function normalizeSearchText(string $text): string
{
    $text = $this->arabicDigitsToEnglish($text);
    $text = mb_strtolower($text);

    $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
    $text = str_replace(['ة'], 'ه', $text);
    $text = str_replace(['ى'], 'ي', $text);
    $text = str_replace(['ؤ'], 'و', $text);
    $text = str_replace(['ئ'], 'ي', $text);
    $text = preg_replace('/\bال(?=[\p{Arabic}]{2,})/u', '', $text);
    $replace = [
        'hojon' => 'هوجن',
        'hogan' => 'هوجن',
        'hogon' => 'هوجن',
        'haojiang' => 'هوجن',
        'haojang' => 'هوجن',
        'هوجان' => 'هوجن',
        'هوجين' => 'هوجن',
        'هوجن' => 'هوجن',
        'الهوجن' => 'هوجن',
        'الهوجن' => 'هوجن',
        'الدايون' => 'دايون',
        'dayun' => 'دايون',
        'daion' => 'دايون',
        'ديوان' => 'دايون',
        'ار كيه' => 'rk',
'اركيه' => 'rk',
'ار كى' => 'rk',
'اركي' => 'rk',
'ركيه' => 'rk',
'r k' => 'rk',
        'استراد' => 'استيراد',
        'استيراد' => 'استيراد',
        'وارد' => 'استيراد',

        'فرز ثاني' => 'فرز تاني',
        'فرز 2' => 'فرز تاني',
        'فرز تانى' => 'فرز تاني',
        'تاني' => 'تاني',
        'تانى' => 'تاني',

        'اصلى' => 'اصلي',
        'original' => 'اصلي',
        'اوريجينال' => 'اصلي',
    ];

    foreach ($replace as $from => $to) {
        $text = str_replace($from, $to, $text);
    }

    $text = preg_replace('/[^\p{Arabic}a-zA-Z0-9\s]/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);

    return trim($text);
}

private function importantSearchTokens(string $text): array
{
    $text = $this->normalizeSearchText($text);

    $stopWords = [
        'عايز', 'عاوزه', 'عاوز', 'محتاج', 'هات', 'ابعت', 'وريني','ال',
        'صوره', 'صورة', 'صور', 'صورها', 'صورتها', 'شكلها',
        'المكنه', 'مكنه', 'موتوسيكل', 'موتسكل', 'سكوتر',
        'دي', 'ده', 'دا', 'من', 'في', 'على', 'عن', 'لو', 'يا', 'فندم',
    ];

    $tokens = preg_split('/\s+/u', $text);

    $tokens = array_filter($tokens, function ($token) use ($stopWords) {
        $token = trim($token);

        if ($token === '') return false;
        if (in_array($token, $stopWords, true)) return false;
        if (mb_strlen($token) < 2 && !is_numeric($token)) return false;

        return true;
    });

    return array_values(array_unique($tokens));
}

private function arabicDigitsToEnglish(string $text): string
{
    return str_replace(
        ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
        ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
        $text
    );
}

private function isNumericToken(string $token): bool
{
    return preg_match('/^\d+$/', $token);
}
private function createInstallmentRequestFromBot(
    WhatsappBot $bot,
    WhatsappConversation $conversation,
    array $data
): ?array {
    try {
        $nationalIdForOrder = $this->normalizeNationalIdForBot($data['applicant_national_id'] ?? null);
        $phoneForOrder = $this->normalizePhoneForBot($data['applicant_phone'] ?? null);

        if ($this->phoneLooksEmbeddedInNationalId($phoneForOrder, $nationalIdForOrder)) {
            $phoneForOrder = null;
        }

        $machine = null;

        if (!empty($data['machine_name'])) {
            $machine = $this->findMachinesStrict($data['machine_name'])->first();
        }

        if (!$machine) {
            Log::warning('CREATE ORDER FAILED - MACHINE NOT FOUND', [
                'conversation_id' => $conversation->id,
                'machine_name' => $data['machine_name'] ?? null,
                'data' => $data,
            ]);

            return [
                'reply' => 'تمام يا فندم، بس محتاج أعرف اسم المكنة بالظبط عشان أسجل الطلب.',
            ];
        }

        $staffId = $bot->staff_id ?? null;

        if (!$staffId) {
            Log::warning('Bot has no linked staff_id', [
                'bot_id' => $bot->id,
            ]);

            return [
                'reply' => 'تمام يا فندم، البيانات وصلت، وهحولها للموظف المختص يكملها مع حضرتك.',
            ];
        }

        $existingIdentityRequest = InstallmentRequest::query()
            ->where(function ($query) use ($phoneForOrder, $nationalIdForOrder) {
                if ($phoneForOrder && Schema::hasColumn('installment_requests', 'applicant_phone')) {
                    $query->orWhere('applicant_phone', $phoneForOrder);
                }

                if ($nationalIdForOrder && Schema::hasColumn('installment_requests', 'applicant_national_id')) {
                    $query->orWhere('applicant_national_id', $nationalIdForOrder);
                }
            })
            ->latest('id')
            ->first();

        if ($existingIdentityRequest) {
            Log::info('DUPLICATE INSTALLMENT REQUEST BLOCKED BY IDENTITY', [
                'existing_request_id' => $existingIdentityRequest->id,
                'phone' => $phoneForOrder,
                'national_id' => $nationalIdForOrder,
            ]);

            return [
                'reply' => "الطلب ده مرفوع قبل كده يا فندم ورقم الطلب هو #{$existingIdentityRequest->id}. هنراجع البيانات ونتواصل معاك.",
            ];
        }

        $existingRequest = InstallmentRequest::where('applicant_phone', $phoneForOrder)
            ->where('machine_id', $machine->id)
            ->whereIn('status', ['new', 'new_request', 'pending', 'work_check'])
            ->where('created_at', '>=', now()->subHours(12))
            ->latest('id')
            ->first();

        if ($existingRequest) {
            Log::info('DUPLICATE INSTALLMENT REQUEST BLOCKED', [
                'existing_request_id' => $existingRequest->id,
                'phone' => $phoneForOrder,
                'machine_id' => $machine->id,
            ]);

            return [
                'reply' => "الطلب متسجل بالفعل يا فندم ورقم الطلب هو #{$existingRequest->id}. هنراجع البيانات ونتواصل معاك.",
            ];
        }

$allConversationText = implode(' ', array_filter($data));

        $workStatus = $this->resolveWorkStatusForOrder($data, $conversation, $allConversationText);

        $workAddress = $this->cleanAddressValue($data['work_address'] ?? null);

        if (!$workAddress) {
            $workAddress = $this->extractWorkAddressFromText($allConversationText);
        }

        $applicantAddress = $this->cleanAddressValue($data['applicant_address'] ?? null);

        if (!$applicantAddress) {
            $applicantAddress = $this->extractAddressFromText($allConversationText);
        }

$documents = array_merge(
    $this->extractConversationDocuments($conversation, $allConversationText, $workStatus),
    $this->documentsFromCurrentOrderData($data)
);
$idImages = [];

Log::info('ORDER CREATE FINAL DEBUG', [
    'phoneForOrder' => $phoneForOrder,
    'staffId' => $staffId,
    'machine_id' => $machine?->id,
    'machine_name' => $machine?->name,
    'workStatus' => $workStatus,
    'applicantAddress' => $applicantAddress,
    'workAddress' => $workAddress,
    'idImages' => $idImages,
    'create_payload' => [
        'applicant_name' => $this->limitText($this->cleanExtractedName($data['applicant_name'] ?? null), 190),
        'applicant_phone' => $phoneForOrder,
        'applicant_national_id' => $nationalIdForOrder,
        'work_status' => $workStatus,
        'free_work_name' => in_array($workStatus, ['no_income_proof', 'self_employed'], true)
                ? $this->limitText(
                    $this->cleanExtractedWorkName($data['free_work_name'] ?? null) ?: $this->extractWorkNameFromText($allConversationText),
                    190
                )
                : null,
    ],
]);
        $createPayload = [
            'applicant_id_image' => $documents['applicant_id_image'] ?? ($idImages[0] ?? null),
            'applicant_id_back_image' => $documents['applicant_id_back_image'] ?? ($idImages[1] ?? null),

            'salary_certificate_image' => $documents['salary_certificate_image'] ?? null,
            'commercial_register_image' => $documents['commercial_register_image'] ?? null,
            'tax_card_image' => $documents['tax_card_image'] ?? null,
            'work_place_image' => $documents['work_place_image'] ?? null,
            'work_place_images' => $documents['work_place_images'] ?? null,
            'work_place_video' => $documents['work_place_video'] ?? null,
            'pension_statement_image' => $documents['pension_statement_image'] ?? null,
            'driving_license_image' => $documents['driving_license_image'] ?? null,

            'staff_id' => $staffId,

            'installment_type' => $data['installment_type'] ?? null,
            'months' => $data['months'] ?? null,

            'brand_id' => $machine->brand_id ?? null,
            'machine_id' => $machine->id,
            'machine_installment_price' => $machine->installment_price ?? null,
            'machine_cash_price' => $machine->cash_price ?? null,

            'deposit' => $data['deposit'] ?? 0,

            'applicant_name' => $this->limitText(
                $this->cleanExtractedName($data['applicant_name'] ?? null),
                190
            ),

            'applicant_phone' => $phoneForOrder,
            'applicant_phone_2' => $this->normalizePhoneForBot($data['applicant_phone_2'] ?? null),
            'applicant_address' => $applicantAddress,
            'applicant_national_id' => $nationalIdForOrder,

            'work_status' => $workStatus,
            'job_title' => $this->limitText($data['job_title'] ?? $this->extractJobTitleFromText($allConversationText, $workStatus), 190),
            'work_address' => $workAddress,

            'free_work_name' => in_array($workStatus, ['no_income_proof', 'self_employed'], true)
                ? $this->limitText(
                    $this->cleanExtractedWorkName($data['free_work_name'] ?? null) ?: $this->extractWorkNameFromText($allConversationText),
                    190
                )
                : null,

            'free_work_address' => in_array($workStatus, ['no_income_proof', 'self_employed'], true)
                ? $workAddress
                : null,

            'guarantor_name' => $data['guarantor_name'] ?? null,
            'guarantor_phone' => $this->normalizePhoneForBot($data['guarantor_phone'] ?? null),

            'notes' => trim(($data['notes'] ?? '') . "\n\nتم إنشاء الطلب تلقائيًا من واتساب. رقم العميل: {$conversation->phone}"),
            'status' => 'new',
        ];

        $request = InstallmentRequest::create(
            $this->filterInstallmentRequestPayload($createPayload)
        );

        $this->saveConversationState($conversation, [
            'installment_request_id' => $request->id,
        ]);

        Log::info('INSTALLMENT REQUEST CREATED FROM WHATSAPP', [
            'request_id' => $request->id,
            'conversation_id' => $conversation->id,
            'phone' => $conversation->phone,
        ]);

        return [
            'reply' => "تمام يا فندم، كده سجلت الطلب لحضرتك ورقم الطلب هو #{$request->id}. هنراجع البيانات ونتواصل معاك.",
        ];
   } catch (\Throwable $e) {
    Log::error('create installment request from bot error', [
        'message' => $e->getMessage(),
        'sql_error' => method_exists($e, 'errorInfo') ? $e->errorInfo : null,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace_first' => collect($e->getTrace())->take(5)->toArray(),
        'data' => $data,
    ]);

    return [
        'reply' => 'البيانات وصلت يا فندم، بس حصلت مشكلة أثناء تسجيل الطلب وهنتابعها مع حضرتك.',
    ];
}
}
private function normalizeWorkStatus(?string $value): ?string
{
    $value = $this->normalizeSearchText((string) $value);

    if (!$value) {
        return null;
    }

    $directMap = [
        'employee' => 'employee',
        'موظف' => 'employee',
        'no_income_proof' => 'no_income_proof',
        'دخل حر' => 'no_income_proof',
        'عمل حر' => 'no_income_proof',
        'pension' => 'pension',
        'معاش' => 'pension',
        'self_employed' => 'self_employed',
        'صاحب نشاط' => 'self_employed',
    ];

    foreach ($directMap as $needle => $status) {
        if ($value === $this->normalizeSearchText($needle)) {
            return $status;
        }
    }

    $scores = [
        'self_employed' => 0,
        'pension' => 0,
        'no_income_proof' => 0,
        'employee' => 0,
    ];

    $keywordGroups = [
        'self_employed' => [
            'business owner', 'self_employed', 'صاحب نشاط', 'صاحب محل', 'صاحب شركه', 'صاحب شركة',
            'نشاط تجاري', 'سجل تجاري', 'السجل التجاري', 'بطاقه ضريبيه', 'بطاقة ضريبية',
            'ضريبيه', 'ضريبية', 'شركة خاصة', 'شركه خاصه',
        ],
        'pension' => [
            'صاحب معاش', 'معاش', 'بيان معاش', 'مفردات معاش', 'pension', 'متقاعد', 'متقاعده',
        ],
        'no_income_proof' => [
            'دخل حر', 'عمل حر', 'شغل حر', 'بدون مفردات', 'مفيش مفردات', 'من غير مفردات',
            'غير مومن', 'غير مؤمن', 'مش مومن', 'مش مؤمن', 'no_income_proof', 'دليفري',
            'سائق طلبات', 'طلبات', 'تاكسي', 'اوبر', 'كريم', 'حرفي', 'عامل يوميه', 'عامل يومية',
        ],
        'employee' => [
            'موظف', 'مفردات مرتب', 'شهادة مرتب', 'شهاده مرتب', 'مرتب ثابت', 'قطاع عام',
            'قطاع خاص', 'حكومي', 'employee', 'تأمينات', 'تامينات',
        ],
    ];

    foreach ($keywordGroups as $status => $keywords) {
        foreach ($keywords as $keyword) {
            if (Str::contains($value, $this->normalizeSearchText($keyword))) {
                $scores[$status] += 1;
            }
        }
    }

    if ($scores['self_employed'] > 0) {
        return 'self_employed';
    }

    if ($scores['pension'] > 0) {
        return 'pension';
    }

    if ($scores['no_income_proof'] > 0) {
        return 'no_income_proof';
    }

    if ($scores['employee'] > 0) {
        return 'employee';
    }

    return null;
}


private function normalizePhoneForBot(?string $phone): ?string
{
    if (!$phone) return null;

    $phone = $this->arabicDigitsToEnglish($phone);
    $phone = preg_replace('/\D+/', '', $phone);

    if (str_starts_with($phone, '20') && strlen($phone) === 12) {
        $phone = '0' . substr($phone, 2);
    }

    if (strlen($phone) !== 11 || !preg_match('/^01[0125][0-9]{8}$/', $phone)) {
        return null;
    }

    return $phone;
}

private function normalizeNationalIdForBot(?string $nationalId): ?string
{
    if (!$nationalId) return null;

    $nationalId = $this->arabicDigitsToEnglish($nationalId);
    $nationalId = preg_replace('/\D+/', '', $nationalId);

    return substr($nationalId, 0, 14);
}

private function normalizeApplicantNameForBot(?string $name): ?string
{
    if (!$name) return null;

    return str_replace(['أ', 'إ', 'آ'], 'ا', trim($name));
}

private function phoneLooksEmbeddedInNationalId(?string $phone, ?string $nationalId): bool
{
    $phone = $this->normalizePhoneForBot($phone);
    $nationalId = $this->normalizeNationalIdForBot($nationalId);

    return $phone && $nationalId && str_contains($nationalId, $phone);
}

private function isOrderConfirmationMessage(string $message): bool
{
    $m = $this->normalizeSearchText($message);

    return Str::contains($m, [
        'ارفع الطلب',
        'رفع الطلب',
        'سجل الطلب',
        'قدم الطلب',
        'اعمل طلب',
        'ثبت الطلب',
        'الطلب مش مرفوع',
'مش مرفوع',
'اتأكد من رفعه',
'ارفع الطلب تاني',
'ارفعه تاني',
'ارفع الطلب مرة تانية',
'ارفع الطلب مره تانيه',
        'ثبته',
        'ارفعه',
        'ارفعه علي السيستم',
        'سجله علي السيستم',
        'يلا ارفع',
        'اكد الطلب',
        'أكد الطلب',
        'مظبوط',
        'تمام مظبوط',
        'كده مظبوط',
        'كدا مظبوط',
        'البيانات مظبوطه',
        'البيانات مظبوطة',
        'تمام كده',
        'تمام كدا',
        'متوكلين علي الله',
        'توكلنا علي الله',
    ]);
}

private function extractAddressFromText(string $text): ?string
{
    $patterns = [

        '/(?:عنوان السكن|العنوان بالسكن|العنوان بالبطاقة|العنوان|السكن)[:：]\s*(.+?)(?=\s+(?:عنوان العمل|عنوان الشغل|مكان الشغل|مكان العمل|العمل|الشغل|المهنة|رقم الموبايل|رقم إضافي|المكنة|مدة التقسيط|تاريخ الميلاد|السن)[:：]|\n|$)/su',
    ];

    foreach ($patterns as $pattern) {

        if (preg_match($pattern, $text, $m)) {

            $value = $this->cleanAddressValue($m[1]);

            if ($value) {
                return $value;
            }
        }
    }

    return null;
}

private function extractWorkAddressFromText(string $text): ?string
{
    $patterns = [

        '/(?:عنوان العمل|عنوان الشغل|مكان الشغل|مكان العمل|عنوان الشركة)[:：]\s*(.+?)(?=\s+(?:عنوان السكن|العنوان|السكن|رقم الموبايل|رقم إضافي|المكنة|مدة التقسيط|هل فيه|فاضل)[:：]|\n|$)/su',
    ];

    foreach ($patterns as $pattern) {

        if (preg_match($pattern, $text, $m)) {

            $value = $this->cleanAddressValue($m[1]);

            if ($value) {
                return $value;
            }
        }
    }

    return null;
}
private function cleanAddressValue(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    $value = trim(preg_replace('/\s+/u', ' ', $value));

    $cutWords = [
        'تاريخ الميلاد',
        'السن',
        'وبما إن',
        'وبما ان',
        'محتاج منك',
        'صورة البطاقة',
        'رقم الموبايل',
        'هل فيه',
        'فيه رقم',
        'رقم إضافي',
        'رقم اضافي',
        'المكنة المطلوبة',
        'مدة التقسيط',
        'فاضل',
        'مظبوط',
        'عايز',
        'تقسيط',
        'كام سنة',
        'عشان',
        'نراجع',
        'نكمل',
    ];

    foreach ($cutWords as $word) {

        $pos = mb_stripos($value, $word);

        if ($pos !== false) {
            $value = mb_substr($value, 0, $pos);
        }
    }

    $value = trim($value, " \n\r\t-،:?؟");

    if (Str::startsWith($value, '?')) {
        $value = ltrim($value, '?');
    }

    return mb_strlen($value) >= 5
        ? mb_substr($value, 0, 500)
        : null;
}
private function limitText(?string $value, int $limit = 255): ?string
{
    if ($value === null) return null;

    $value = trim(preg_replace('/\s+/u', ' ', $value));

    if ($value === '') return null;

    return mb_substr($value, 0, $limit);
}

private function cleanExtractedName(?string $value): ?string
{
    if (!$value) return null;

    $value = trim($value);

    $cutWords = [
        'الرقم القومي',
        'تاريخ الميلاد',
        'السن',
        'العنوان',
        'عنوان',
        'فاضل',
        'المهنة',
        'الدخل',
        'السكن',
        'المكنة',
        'مدة التقسيط',
    ];

    foreach ($cutWords as $word) {
        $pos = mb_strpos($value, $word);
        if ($pos !== false) {
            $value = mb_substr($value, 0, $pos);
        }
    }

    return $this->normalizeApplicantNameForBot($value);
}

private function cleanExtractedWorkName(?string $value): ?string
{
    if (!$value) return null;

    $value = trim($value);

    $cutWords = [
        'الدخل',
        'السكن',
        'المكنة',
        'مدة التقسيط',
        'فاضل',
        'رقم',
        'عنوان',
        'مظبوط',
    ];

    foreach ($cutWords as $word) {
        $pos = mb_strpos($value, $word);
        if ($pos !== false) {
            $value = mb_substr($value, 0, $pos);
        }
    }

    return $this->limitText($value, 190);
}
private function extractWorkNameFromText(string $text): ?string
{
    $patterns = [
        '/المهنة[:：]\s*([^\n]+)/u',
        '/الشغل[:：]\s*([^\n]+)/u',
        '/الوظيفة[:：]\s*([^\n]+)/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {

            $value = trim($m[1]);

            $cutWords = [
                'الدخل',
                'السكن',
                'العنوان',
                'المكنة',
                'مدة التقسيط',
                'فاضل',
                'رقم',
                'عنوان',
                'مظبوط',
            ];

            foreach ($cutWords as $word) {
                $pos = mb_stripos($value, $word);

                if ($pos !== false) {
                    $value = mb_substr($value, 0, $pos);
                }
            }

            $value = trim($value, " \n\r\t-،:");

            if (mb_strlen($value) < 2) {
                return null;
            }

            return mb_substr($value, 0, 190);
        }
    }

    return null;
}

private function latestPendingOrderData(WhatsappConversation $conversation): ?array
{
    $message = $conversation->messages()
        ->where('direction', 'outgoing')
        ->where('created_at', '>=', now()->subMinutes(30))
        ->orderByDesc('id')
        ->limit(20)
        ->get()
        ->first(function ($message) {
            $payload = $message->payload;

            if (is_string($payload)) {
                $payload = json_decode($payload, true) ?: [];
            }

            return data_get($payload, 'source') === 'pending_order_review'
                && is_array(data_get($payload, 'pending_order_data'));
        });

    if (!$message) {
        return null;
    }

    $payload = $message->payload;

    if (is_string($payload)) {
        $payload = json_decode($payload, true) ?: [];
    }

    return data_get($payload, 'pending_order_data');
}

private function detectWorkStatusFromStructuredData(array $data): ?string
{
    $text = implode(' ', array_filter([
        $data['work_status'] ?? '',
        $data['job_type'] ?? '',
        $data['job_title'] ?? '',
        $data['free_work_name'] ?? '',
        $data['free_work_address'] ?? '',
        $data['work_address'] ?? '',
        $data['salary_certificate_image'] ?? '',
        $data['commercial_register_image'] ?? '',
        $data['tax_card_image'] ?? '',
        $data['pension_statement_image'] ?? '',
        $data['driving_license_image'] ?? '',
        $data['notes'] ?? '',
    ]));

    return $this->normalizeWorkStatus($text);
}





private function resolveWorkStatusForOrder(array $data, WhatsappConversation $conversation, string $text = ''): ?string
{
    $explicit = $this->normalizeWorkStatus($data['work_status'] ?? null);

    if ($explicit) {
        return $explicit;
    }

    $structured = $this->detectWorkStatusFromStructuredData($data);

    if ($structured) {
        return $structured;
    }

    return $this->detectWorkStatusFromText($text);
}

private function missingOrderFields(array $data): array
{
    $required = [
        'applicant_name' => 'اسم العميل',
        'applicant_phone' => 'رقم الموبايل',
        'applicant_national_id' => 'الرقم القومي',
        'applicant_address' => 'عنوان السكن',
        'machine_name' => 'اسم المكنة',
        'months' => 'مدة التقسيط',
        'work_status' => 'نوع الدخل',
    ];

    $missing = [];

    foreach ($required as $key => $label) {
        if (empty($data[$key])) {
            $missing[] = $label;
        }
    }

    $nationalId = $this->normalizeNationalIdForBot($data['applicant_national_id'] ?? null);
    $phone = $this->normalizePhoneForBot($data['applicant_phone'] ?? null);

    if (!empty($data['applicant_phone']) && (!$phone || $this->phoneLooksEmbeddedInNationalId($phone, $nationalId))) {
        $missing[] = 'رقم موبايل صحيح منفصل عن الرقم القومي';
    }

    if (!empty($data['applicant_national_id']) && (!$nationalId || strlen($nationalId) !== 14)) {
        $missing[] = 'رقم قومي صحيح 14 رقم';
    }

    return array_values(array_unique($missing));
}

private function missingOrderDataReply(array $data): string
{
    $missing = $this->missingOrderFields($data);

    if (!$missing) {
        return 'تمام يا فندم، محتاج أراجع بيانات الطلب قبل التسجيل.';
    }

    return 'تمام يا فندم، قبل ما أرفع الطلب محتاج البيانات دي: ' . implode('، ', $missing) . '.';
}

private function orderDataIsComplete(array $data): bool
{
    return count($this->missingOrderFields($data)) === 0;
}



private function detectWorkStatusFromText(string $text): ?string
{
    $m = $this->normalizeSearchText($text);

    return $this->normalizeWorkStatus($m);
}


private function extractConversationDocuments(
    WhatsappConversation $conversation,
    string $text = '',
    ?string $workStatus = null
): array {
    $documents = [];

    $mediaMessages = $conversation->messages()
        ->where('direction', 'incoming')
        ->orderBy('id')
        ->limit(200)
        ->get();

    $allMedia = [];

    foreach ($mediaMessages as $message) {
        $messageText = $this->normalizeSearchText(
            trim(
                (string) $message->message . ' ' .
                json_encode($message->payload ?? [], JSON_UNESCAPED_UNICODE)
            )
        );

        $items = data_get($message->payload, 'saved_media_items', []);

        foreach ($items as $item) {
            $path = $item['path'] ?? null;
            if (!$path) continue;

            $mime = strtolower((string) ($item['mime'] ?? ''));
            $filename = $this->normalizeSearchText((string) ($item['filename'] ?? ''));

            $labelText = trim($messageText . ' ' . $filename);

            $allMedia[] = [
                'path' => $path,
                'mime' => $mime,
                'filename' => $filename,
                'label' => $labelText,
                'is_image' => str_starts_with($mime, 'image/'),
                'is_video' => str_starts_with($mime, 'video/'),
            ];
        }
    }

    foreach ($allMedia as $media) {
        $label = $media['label'];
        $path = $media['path'];

        if (
            $media['is_image'] &&
            empty($documents['applicant_id_image']) &&
            Str::contains($label, [
                'وش البطاقه',
                'وجه البطاقه',
                'امام البطاقه',
                'صوره البطاقه وش',
                'صورة البطاقة وش',
                'بطاقه وش',
                'بطاقة وش',
                'الوش',
            ]) &&
            !$this->labelLooksLikeBusinessDocument($label)
        ) {
            $documents['applicant_id_image'] = $path;
            continue;
        }

        if (
            $media['is_image'] &&
            empty($documents['applicant_id_back_image']) &&
            Str::contains($label, [
                'ظهر البطاقه',
                'ضهر البطاقه',
                'خلف البطاقه',
                'صوره البطاقه ظهر',
                'صورة البطاقة ظهر',
                'بطاقه ظهر',
                'بطاقة ظهر',
                'الضهر',
            ]) &&
            !$this->labelLooksLikeBusinessDocument($label)
        ) {
            $documents['applicant_id_back_image'] = $path;
            continue;
        }

        if (
            $media['is_image'] &&
            empty($documents['salary_certificate_image']) &&
            Str::contains($label, ['مفردات', 'مفردات مرتب', 'شهاده مرتب', 'شهادة مرتب'])
        ) {
            $documents['salary_certificate_image'] = $path;
            continue;
        }

        if (
            $media['is_image'] &&
            empty($documents['commercial_register_image']) &&
            Str::contains($label, ['سجل تجاري', 'السجل التجاري', 'سجل'])
        ) {
            $documents['commercial_register_image'] = $path;
            continue;
        }

        if (
            $media['is_image'] &&
            empty($documents['tax_card_image']) &&
            Str::contains($label, ['بطاقه ضريبيه', 'بطاقة ضريبية', 'ضريبيه', 'ضريبية', 'tax'])
        ) {
            $documents['tax_card_image'] = $path;
            continue;
        }

        if (
            empty($documents['pension_statement_image']) &&
            $media['is_image'] &&
            Str::contains($label, ['بيان معاش', 'مفردات معاش', 'معاش'])
        ) {
            $documents['pension_statement_image'] = $path;
            continue;
        }

        if (
            empty($documents['driving_license_image']) &&
            $media['is_image'] &&
            Str::contains($label, ['رخصه', 'رخصة', 'رخصه قياده', 'رخصة قيادة'])
        ) {
            $documents['driving_license_image'] = $path;
            continue;
        }

        if (
            Str::contains($label, [
                'مكان النشاط',
                'صوره النشاط',
                'صورة النشاط',
                'فيديو النشاط',
                'مكان الشغل',
                'مكان العمل',
                'المحل',
                'الشركه',
                'الشركة',
            ])
        ) {
            if ($media['is_video'] && empty($documents['work_place_video'])) {
                $documents['work_place_video'] = $path;
                continue;
            }

            if ($media['is_image'] && empty($documents['work_place_image'])) {
                $documents['work_place_image'] = $path;
                continue;
            }
        }
    }

    // fallback ذكي:
    // لو الصور جات من غير كابشن، أول صورتين صور ومش متصنفين كمستندات نشاط نعتبرهم بطاقة وش وضهر.
    foreach ($allMedia as $media) {
        if (!$media['is_image']) continue;

        $path = $media['path'];
        $label = $media['label'];

        if (in_array($path, $documents, true)) continue;
        if ($this->labelLooksLikeBusinessDocument($label)) continue;

        if (empty($documents['applicant_id_image'])) {
            $documents['applicant_id_image'] = $path;
            continue;
        }

        if (empty($documents['applicant_id_back_image'])) {
            $documents['applicant_id_back_image'] = $path;
            continue;
        }
    }

    return $documents;
}

private function documentsFromCurrentOrderData(array $data): array
{
    $keys = [
        'applicant_id_image',
        'applicant_id_back_image',
        'salary_certificate_image',
        'commercial_register_image',
        'tax_card_image',
        'work_place_image',
        'work_place_images',
        'work_place_video',
        'pension_statement_image',
        'driving_license_image',
    ];

    $documents = [];

    foreach ($keys as $key) {
        if (!empty($data[$key])) {
            $documents[$key] = $data[$key];
        }
    }

    if (empty($documents['work_place_images']) && !empty($documents['work_place_image'])) {
        $documents['work_place_images'] = [$documents['work_place_image']];
    }

    return $documents;
}

private function labelLooksLikeBusinessDocument(string $label): bool
{
    return Str::contains($label, [
        'سجل تجاري',
        'السجل التجاري',
        'بطاقه ضريبيه',
        'بطاقة ضريبية',
        'ضريبيه',
        'ضريبية',
        'مكان النشاط',
        'فيديو النشاط',
    ]);
}

private function filterInstallmentRequestPayload(array $payload): array
{
    return collect($payload)
        ->filter(function ($value, $key) {
            return Schema::hasColumn('installment_requests', $key);
        })
        ->all();
}

private function extractJobTitleFromText(string $text, ?string $workStatus): ?string
{
    if ($workStatus === 'self_employed') return 'صاحب نشاط';
    if ($workStatus === 'pension') return 'صاحب معاش';
    if ($workStatus === 'no_income_proof') return 'دخل حر';

    $patterns = [
        '/الشغل[:：]\s*([^\n]+)/u',
        '/المهنة[:：]\s*([^\n]+)/u',
        '/الوظيفة[:：]\s*([^\n]+)/u',
        '/العمل[:：]\s*([^\n]+)/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            return $this->cleanExtractedWorkName($m[1]);
        }
    }

    return $workStatus === 'employee' ? 'موظف' : null;
}


    private function emptyResponse()
    {
        return response()->json([
            'reply' => null,
            'image' => null,
            'images' => [],
        ]);
    }
}