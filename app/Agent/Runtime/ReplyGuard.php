<?php

namespace App\Agent\Runtime;

use App\Models\Application;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\RequirementField;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;

/**
 * T17 §2: deterministic checks on the AI's *output* (never on customer
 * text). Each check compares what the reply asserts against what Laravel
 * actually recorded this turn - a claim of an action is only allowed when
 * the matching tool really succeeded, and a number is only allowed when a
 * source (tool result, structured state, the customer) contains it.
 */
class ReplyGuard
{
    /** Tools whose success means "something was saved/changed". */
    public const WRITE_TOOLS = ['record_customer_data', 'process_document', 'start_application', 'update_application_selection', 'submit_application', 'withdraw_application'];

    /**
     * A measured quantity ("40-45 كيلو في اللتر", "ضمان سنتين", "11 حصان")
     * must be sourced whatever its size - the min-value threshold only
     * exists to let small everyday numbers through, not invented specs.
     */
    private const UNIT_PATTERN = '(?:كيلو|كم|km|لتر|حصان|hp|سي\s?سي|cc|٪|%|في\s?المي[ةه]|بالمي[ةه]|شهر|شهور|أشهر|اشهر|سنة|سنه|سنين|سنوات'
        .'|غيار|سرعات|يوم|أيام|ايام|ساع[ةه]|ساعات|الصبح|صباح|صباحا|صباحًا|صباحاً|الضهر|الظهر|العصر|المغرب|مساء|مساءً|مساءا|بالليل|الليل)';

    /**
     * @param  array<int, array{name: string, ok: bool, data: array}>  $outcomes  every tool call of this turn, in order
     */
    public function check(array $args, WhatsappConversation $conversation, string $system, array $contents, array $outcomes): ?string
    {
        $replyText = implode(' ', $args['messages'] ?? []);
        $succeeded = array_column(array_filter($outcomes, fn ($o) => $o['ok'] && $this->changedSomething($o)), 'name');
        $toolResultsBlob = json_encode(array_column($outcomes, 'data'), JSON_UNESCAPED_UNICODE) ?: '';

        // send_reply(messages: [""]) was accepted and the customer who had
        // just sent their details by voice got nothing back.
        if (trim($replyText) === '') {
            return 'EMPTY_REPLY';
        }

        // Claims are judged on assertions only: "لو غيرت رأيك أنا موجود"
        // (if YOU change your mind) is not "I changed it". A goodbye was
        // blocked on that word four times until the real customer got the
        // provider-outage notice instead.
        $assertions = $this->withoutConditionalClauses($replyText);

        if ($this->claimsSubmission($assertions) && ! $this->submissionHappened($conversation, $outcomes)) {
            return 'SUBMISSION_CLAIMED_NOT_DONE';
        }

        // "سجلت البيانات" with no write this turn: the customer's phone and
        // address were lost while they were told they had been saved.
        if (preg_match('/(?:^|\s)(?:سجلت|سجّلت|سجلتها|سجلتهم|اتسجل|اتسجلت|تم تسجيل|حفظت|ثبت|ثبّت|غيرت|غيّرت|عدلت|عدّلت|حدثت|حدّثت|اتغيرت|اتعدلت)(?:\s|$|[،.!])/u', $assertions)
            && array_intersect($succeeded, self::WRITE_TOOLS) === []) {
            return 'DATA_CLAIMED_NOT_SAVED';
        }

        // "سجلت بياناتك" after only the work type was saved: the customer
        // thought he was done and stopped sending the rest.
        if (preg_match('/(?:سجلت|سجّلت|اتسجلت|حفظت)\s+(?:كل\s+)?(?:بياناتك|البيانات|بيانات\s+حضرتك)|(?:بياناتك|البيانات)\s+(?:اتسجلت|اتحفظت)/u', $assertions)
            && $this->savedFieldCount($outcomes) < 2) {
            return 'DATA_OVERCLAIMED';
        }

        // "وlـحظة" / "تشפّي": a Latin letter inside an Arabic word, or a
        // letter from a script no customer here writes, is a model glitch
        // (seen from the fallback model under load), never real wording -
        // model names sit next to Arabic ("الـHLX"), not inside a word.
        if (preg_match('/\p{Arabic}[A-Za-z]+\p{Arabic}|[\p{Hebrew}\p{Cyrillic}\p{Han}\p{Hangul}\p{Thai}\p{Devanagari}]/u', $replyText)) {
            return 'GARBLED_TEXT';
        }

        if ($this->containsInternalKey($replyText)) {
            return 'INTERNAL_KEY_IN_REPLY';
        }

        // Placeholders from the history rendering, never customer-facing text.
        if (preg_match('/\[(media|staff)\]|\(اتبعت للعميل|\(تم إرسال الصور\)/u', $replyText)) {
            return 'PLACEHOLDER_IN_REPLY';
        }

        // "دي صورها" with no successful send_motorcycle_images this turn left
        // customers waiting for photos that never came.
        if (! in_array('send_motorcycle_images', $succeeded, true) && $this->claimsImagesSent($assertions)) {
            return 'IMAGES_CLAIMED_NOT_SENT';
        }

        // "هحولك لزميل" / "زميلي هيتابع معاك" with no handoff: nobody was
        // notified and the customer waited on a promise nothing would keep.
        if ($conversation->status !== 'awaiting_agent'
            && ! in_array('handoff_to_human', $succeeded, true)
            && $this->promisesHandoff($replyText)) {
            return 'HANDOFF_CLAIMED_NOT_DONE';
        }

        // "شغال أوبر" got a recited list (رخصة + سكرين أرباح) while work_type
        // was never saved, so the application kept asking for it and the list
        // came from memory, not from the requirements for his work.
        if (! in_array('record_customer_data', $succeeded, true)
            && $this->listsWorkDocuments($assertions)
            && $this->workTypeStillMissing($conversation)) {
            return 'WORK_TYPE_NOT_RECORDED';
        }

        // "لينا فرع في المنصورة، شارع الجيش، من 10 الصبح" with no lookup:
        // there is no Mansoura branch. Branch facts come from the branch
        // table only, looked up this turn.
        if ($this->claimsUnsourcedBranch($replyText, $outcomes)) {
            return 'BRANCH_NOT_SOURCED';
        }

        // "عندي ٢٠ سنة" got "السن ده تمام جداً" - the minimum is 21. Whether
        // an age qualifies is only said after the system checked it.
        if ($this->judgesAgeUnchecked($assertions, $outcomes)) {
            return 'AGE_NOT_CHECKED';
        }

        // "هتتوفر امتى؟" got "هي متاحة حالياً" for a model we do not carry,
        // then "أول ما توصل هبلغك" - nothing records or sends such a notice.
        if ($this->promisesAvailabilityNotice($replyText)) {
            return 'AVAILABILITY_PROMISE';
        }

        // "الـ46 ألف ده إجمالي اللي هتدفعه" - the customer's own number
        // relabelled as the total; the real total was 67,612. A total is
        // only stated from a tool result of this turn.
        if ($this->statesUnsourcedTotal($replyText, $toolResultsBlob)) {
            return 'TOTAL_NOT_SOURCED';
        }

        // "المصاريف دي مقابل إجراءات التقسيط والورق" / "تمويل خارجي":
        // reasons nobody recorded. Judged on the whole reply: the reason
        // usually sits in an "عشان ..." clause that $assertions drops.
        if (! $this->hasPriceDifferencePolicy($outcomes) && $this->explainsPriceDifferenceOrFees($replyText)) {
            return 'UNSOURCED_REASON';
        }

        // "مع مين التقسيط؟" got "أمان وفاليو وكونتكت" - two companies we do
        // not work with, named from general knowledge.
        if ($this->namesUnsourcedFinanceCompany($replyText, $toolResultsBlob)) {
            return 'UNSOURCED_FINANCE_COMPANY';
        }

        // The same "تحب أقولك سعرها؟" closed three replies in a row.
        if ($this->repeatsLastClosingQuestion($conversation, $replyText)) {
            return 'REPEATED_QUESTION';
        }

        if ($this->isDuplicateOfRecentReply($conversation, $replyText)) {
            return 'DUPLICATE_REPLY';
        }

        if ($this->hasUnverifiedNumber($replyText, $system, $toolResultsBlob, $contents)) {
            return 'UNVERIFIED_NUMBER';
        }

        return null;
    }

    /**
     * The owner's explanation of the cash/installment difference is data
     * (get_installment_offer.price_difference_policy): with it in hand the
     * reason is sourced, without it it is invented.
     */
    /** "بيغطي تكاليف التمويل والتشغيل" / "مقابل الورق": a reason for the price gap or the fees. */
    private function explainsPriceDifferenceOrFees(string $text): bool
    {
        $reasonWords = '/مقابل\s+(?:ال)?(?:إجراءات|اجراءات|ورق|تمويل|خدمات|تكلف[ةه]|تسهيلات|فتح ملف|دراس[ةه])|تمويل خارجي|جه[ةه] تمويل|تكلف[ةه]|تكاليف|التشغيل|(?:ال)?خدمات|ضريب[ةه]|ضرايب|ضرائب|فوايد|فوائد|تأمين|التأمين/u';
        $aboutGap = '/الفرق|فرق|أغلى|اغلى|أزيد|ازيد|أكتر من الكاش|اكتر من الكاش|بيغطي|مقابل|المصاريف|مصاريف/u';

        foreach (preg_split('/(?<=[.!؟?\n])/u', $text) as $sentence) {
            if (preg_match($reasonWords, $sentence) && preg_match($aboutGap, $sentence)) {
                return true;
            }
        }

        return false;
    }

    private function hasPriceDifferencePolicy(array $outcomes): bool
    {
        foreach ($outcomes as $outcome) {
            if ($outcome['name'] === 'get_installment_offer' && $outcome['ok'] && filled($outcome['data']['price_difference_policy'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function statesUnsourcedTotal(string $replyText, string $toolResultsBlob): bool
    {
        if (! preg_match('/إجمالي|اجمالي|الإجمالي|مجموع|هتدفعه كله|هتدفعه في الآخر|في الآخر هتدفع|في الاخر هتدفع/u', $replyText)) {
            return false;
        }

        $sourced = $this->extractNumbers($toolResultsBlob);
        $minValue = (float) (config('agent.guard.number_min_value') ?? 0);

        foreach ($this->extractNumbers($replyText) as $number) {
            if ($number >= $minValue && ! in_array($number, $sourced, true)) {
                return true;
            }
        }

        return false;
    }

    /** A branch, its address or its opening hours, stated as fact. */
    private const BRANCH_FACT = '/(?:فرع|فروع|فرعنا|فروعنا)\s+(?:في|ف|فى|بـ?)\s+(?!أي|اي|أى|اى|وقت|الوقت|أقرب|اقرب)\S+|(?:فرع|فروع|فرعنا)\s+(?!الـ?معرض)(?:ال)?[\p{Arabic}]{3,}\s*[:،,-]|'
        // the customer's own address ("سجلت العنوان") is not a branch fact
        .'(?:عنوان (?:ال)?(?:فرع|معرض)|عنوانّا|عنوانا|عنوان فرعنا|مكاننا|مكان (?:ال)?(?:فرع|معرض)|لوكيشن (?:ال)?(?:فرع|معرض)|maps\.app)|(?:مواعيد\S*|بنفتح|بنقفل|فاتحين|شغالين)\s+(?:\S+\s+){0,4}?(?:من|لـ?|ل|لحد)\s*(?:ال)?(?:ساع[ةه]\s*)?\d/u';

    /** Words after "فرع" that name a place rather than being a place. */
    private const BRANCH_FILLER = ['في', 'ف', 'فى', 'الفرع', 'فرع', 'اقرب', 'أقرب', 'الأقرب', 'الاقرب', 'لينا', 'عندنا', 'احنا', 'إحنا', 'تقدر', 'ليك', 'ليكي',
        'تانية', 'تاني', 'تانيين', 'المتاحة', 'متاحة', 'المتاح', 'متاح', 'قريب', 'قريبة', 'القريب', 'جديد', 'جديدة', 'كتير', 'واحد', 'واحدة', 'كذا',
        'بتاعنا', 'بتاعتنا', 'كلها', 'كلهم', 'موجود', 'موجودة', 'الموجودة', 'الرئيسي', 'التانية', 'التاني', 'دي', 'ده', 'اللي', 'مفتوح', 'مفتوحة',
        'محافظة', 'منطقة', 'المنطقة', 'المحافظة', 'بتاعك', 'عندك', 'قريبه', 'تانيه', 'متاحه', 'واحده', 'موجوده',
        'هناك', 'هنا', 'مواعيده', 'مواعيدها', 'مواعيدهم', 'مواعيدنا', 'عنوانه', 'عنوانها', 'بتاعه', 'بتاعها', 'بتاعهم', 'برضه', 'كمان',
        'بتاعكم', 'ليكم', 'جنبك', 'منك', 'دلوقتي', 'النهارده', 'بكره', 'يفتح', 'بيفتح', 'بتفتح', 'وده', 'ودي', 'وعنوانه', 'ومواعيده'];

    /**
     * Stating a branch, its address or hours needs a successful
     * get_branch_information this turn, and every place named right after
     * "فرع" must be in what it returned.
     */
    private function claimsUnsourcedBranch(string $replyText, array $outcomes): bool
    {
        if (! preg_match(self::BRANCH_FACT, $replyText)) {
            return false;
        }

        $lookups = array_filter($outcomes, fn ($o) => $o['name'] === 'get_branch_information' && $o['ok']);

        if ($lookups === []) {
            return true;
        }

        // Only the branch rows count - the tool's own note ("We have NO
        // branch in المنصورة") named the very place a branch was then
        // invented in, and let it through.
        $branches = array_merge(...array_map(fn ($o) => $o['data']['branches'] ?? [], array_values($lookups)));
        $source = \App\Support\ArabicTextNormalizer::normalize(json_encode($branches, JSON_UNESCAPED_UNICODE) ?: '');

        // A map link is only ever one of the branches' own links.
        preg_match_all('#https?://(?:maps\.app\.goo\.gl|goo\.gl/maps|(?:www\.)?google\.[a-z.]+/maps)\S*#i', $replyText, $links);
        $knownLinks = array_filter(array_column($branches, 'map_url'));

        foreach ($links[0] as $link) {
            $link = rtrim($link, '.,،)');

            if (! in_array($link, $knownLinks, true)) {
                return true;
            }
        }
        // "مفيش فرع في المنصورة" is the truthful answer, not a claim.
        $affirmed = preg_replace('/(?:مفيش|مافيش|مفيهاش|معندناش|ماعندناش|ما عندناش|ملناش|مالناش|مش عندنا|للأسف مفيش)\s+[^.،,!؟?\n]*/u', ' ', $replyText);
        preg_match_all('/(?:فرع|فروع|فرعنا)\s+(?:(?:في|ف|فى|بـ?)\s+)?(?!أي|اي|وقت|الوقت)((?:ال)?[\p{Arabic}]{3,})/u', $affirmed, $named);

        foreach ($named[1] as $place) {
            $place = preg_replace('/[^\p{L}]/u', '', $place);
            $bare = \App\Support\ArabicTextNormalizer::normalize(preg_replace('/^ب?ال/u', '', $place));

            if (in_array(\App\Support\ArabicTextNormalizer::normalize($place), array_map([\App\Support\ArabicTextNormalizer::class, 'normalize'], self::BRANCH_FILLER), true)) {
                continue;
            }

            $place = $bare;

            if (mb_strlen($place) >= 3 && ! in_array($place, array_map([\App\Support\ArabicTextNormalizer::class, 'normalize'], self::BRANCH_FILLER), true)
                && ! str_contains($source, $place)) {
                return true;
            }
        }

        return false;
    }

    private function judgesAgeUnchecked(string $assertions, array $outcomes): bool
    {
        if (! preg_match('/(?:السن|سنك|عمرك|سن حضرتك)\s+(?:\S+\s+){0,3}?(?:تمام|مناسب|كويس|ينفع|مفيهوش مشكل|مافيهوش مشكل|مش مشكل|يسمح)/u', $assertions)) {
            return false;
        }

        foreach ($outcomes as $outcome) {
            if (! $outcome['ok']) {
                continue;
            }

            if ($outcome['name'] === 'check_eligibility') {
                return false;
            }

            // An open application's snapshot carries its own eligibility.
            if (isset($outcome['data']['eligibility']) || isset($outcome['data']['snapshot']['eligibility'])) {
                return false;
            }
        }

        return true;
    }

    private function promisesAvailabilityNotice(string $replyText): bool
    {
        return (bool) preg_match('/(?:[أاه]بلغك|هبلغك|أعرفك|هعرفك|هكلمك|ابعتلك|هبعتلك|هقولك)\s+(?:\S+\s+){0,3}?(?:أول ما|اول ما|لما|بمجرد ما)\s+(?:\S+\s+){0,2}?(?:توصل|تتوفر|يتوفر|تنزل|تيجي|يوصل|يجي)'
            .'|(?:أول ما|اول ما|لما|بمجرد ما)\s+(?:\S+\s+){0,2}?(?:توصل|تتوفر|يتوفر|تنزل|يوصل)\S*\s+(?:\S+\s+){0,4}?(?:[أاه]بلغك|هبلغك|هعرفك|هكلمك|هبعتلك)/u', $replyText);
    }

    /** Egyptian consumer-finance brands the model knows from outside our data. */
    // Only names that are not everyday words: "أمان" (safety), "حالا" (now) and
    // "سهولة" (ease) would block ordinary sentences.
    private const KNOWN_FINANCE_BRANDS = ['فاليو', 'ڤاليو', 'valu', 'كونتكت', 'contact', 'souhoola', 'تساهيل', 'tasaheel',
        'مايلو', 'mylo', 'aman', 'halan', 'سوبر كاش'];

    private function namesUnsourcedFinanceCompany(string $replyText, string $toolResultsBlob): bool
    {
        $reply = \App\Support\ArabicTextNormalizer::normalize($replyText);
        $sources = \App\Support\ArabicTextNormalizer::normalize($toolResultsBlob);

        foreach (self::KNOWN_FINANCE_BRANDS as $brand) {
            $brand = \App\Support\ArabicTextNormalizer::normalize($brand);

            if (preg_match('/(?<![\p{L}])'.preg_quote($brand, '/').'(?![\p{L}])/u', $reply) && ! str_contains($sources, $brand)) {
                return true;
            }
        }

        return false;
    }

    private function repeatsLastClosingQuestion(WhatsappConversation $conversation, string $replyText): bool
    {
        $question = $this->closingQuestion($replyText);

        if ($question === null) {
            return false;
        }

        $previous = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')
            ->whereNotNull('text')
            ->latest('id')
            ->value('text');

        return $previous !== null && $this->closingQuestion($previous) === $question;
    }

    /** The last sentence when it is a question, normalized. */
    private function closingQuestion(string $text): ?string
    {
        $sentences = preg_split('/(?<=[.!؟?\n])\s*/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $last = trim((string) end($sentences));

        if ($last === '' || ! preg_match('/[؟?]$/u', $last)) {
            return null;
        }

        return \App\Support\ArabicTextNormalizer::normalize(preg_replace('/[^\p{L}\p{N}\s]/u', '', $last));
    }

    /** Fields saved this turn, counting an accepted document as the fields it filled. */
    private function savedFieldCount(array $outcomes): int
    {
        $count = 0;

        foreach ($outcomes as $outcome) {
            if (! $outcome['ok']) {
                continue;
            }

            if ($outcome['name'] === 'record_customer_data') {
                $count += count($outcome['data']['saved'] ?? []);
            }

            if ($outcome['name'] === 'process_document') {
                foreach ($outcome['data']['results'] ?? [] as $result) {
                    $count += ($result['accepted'] ?? false) ? max(2, count($result['applied_fields'] ?? [])) : 0;
                }
            }
        }

        return $count;
    }

    /** Two or more work-dependent documents named = a requirements list. */
    private function listsWorkDocuments(string $text): bool
    {
        preg_match_all('/رخص[ةه]|سكري?ن|ارباح|أرباح|سجل تجاري|بطاق[ةه] ضريبي[ةه]|عنوان الشغل|صور (?:ال)?نشاط/u', $text, $m);

        return count(array_unique($m[0])) >= 2;
    }

    private function workTypeStillMissing(WhatsappConversation $conversation): bool
    {
        $application = Application::where('customer_id', $conversation->customer_id)
            ->whereIn('status', Application::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if (! $application) {
            return false;
        }

        $workType = RequirementField::where('key', 'work_type')->value('id');
        $needsWorkType = $workType !== null && \App\Models\ApplicationRequirement::where('customer_type_id', $application->customer_type_id)
            ->where('requirement_field_id', $workType)
            ->exists();

        return $needsWorkType && ! \App\Models\ApplicationData::where('application_id', $application->id)
            ->where('field_key', 'work_type')
            ->where('status', 'valid')
            ->exists();
    }

    /**
     * A processed batch where every document was rejected saved nothing -
     * "البطاقة اتسجلت" must not pass on it.
     */
    private function changedSomething(array $outcome): bool
    {
        if ($outcome['name'] !== 'process_document') {
            return true;
        }

        return collect($outcome['data']['results'] ?? [])->contains(fn ($r) => ($r['accepted'] ?? false) === true);
    }

    /**
     * Drops conditional/hypothetical clauses ("لو ...", "إذا ...", "في حالة
     * ..."), up to the next clause break. What is left is what the reply
     * asserts as having happened.
     */
    private function withoutConditionalClauses(string $text): string
    {
        return preg_replace('/(?:^|(?<=[\s،,.!؟?]))(?:ولو|لو|إذا|اذا|وإذا|وإن|إن|لما|في حالة|فى حالة|أول ما|اول ما|لحد ما|عشان)\s+[^،,.!؟?\n]*/u', ' ', $text);
    }

    /**
     * "الطلب اتبعت للمراجعة" / "أكدت إرسال الطلب" while the application was
     * still collecting: two customers were told they had applied.
     */
    private function claimsSubmission(string $assertions): bool
    {
        return (bool) preg_match(
            '/(?:الطلب|طلبك|الملف)\s+(?:\S+\s+){0,2}?(?:اتبعت|اتقدم|اتقدّم|اترفع|اتأكد|وصل|راح|اتحول)'
            .'|(?:تم|اتم)\s+(?:إرسال|ارسال|تقديم|رفع|تأكيد|تاكيد)\s+(?:ال)?(?:طلب|ملف)'
            .'|(?:أكدت|اكدت|بعت|بعتت|قدمت|قدّمت|رفعت)\s+(?:\S+\s+)?(?:ال)?(?:طلب|ملف)'
            .'|(?:أكدت|اكدت)\s+(?:إرسال|ارسال|تقديم)/u',
            $assertions
        );
    }

    private function submissionHappened(WhatsappConversation $conversation, array $outcomes): bool
    {
        foreach ($outcomes as $outcome) {
            if ($outcome['name'] === 'submit_application' && $outcome['ok'] && ($outcome['data']['submitted'] ?? false) === true) {
                return true;
            }
        }

        return $conversation->customer_id !== null
            && Application::where('customer_id', $conversation->customer_id)->whereNotNull('submitted_at')->exists();
    }

    /**
     * Internal identifiers leaked into replies: "(delivery_app)" was caught
     * by a snake_case pattern but "(craftsman)" was not. Checked against the
     * real vocabulary the system uses - customer type keys, field keys and
     * enum options, document type keys - plus any snake_case token.
     */
    private function containsInternalKey(string $replyText): bool
    {
        if (preg_match('/\b[a-z]+(?:_[a-z0-9]+)+\b/', $replyText)) {
            return true;
        }

        preg_match_all('/\b[a-z]{3,}\b/i', $replyText, $matches);

        if ($matches[0] === []) {
            return false;
        }

        $vocabulary = $this->internalVocabulary();

        foreach ($matches[0] as $word) {
            if (isset($vocabulary[mb_strtolower($word)])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private function internalVocabulary(): array
    {
        $keys = array_merge(
            CustomerType::pluck('key')->all(),
            RequirementField::pluck('key')->all(),
            DocumentType::pluck('key')->all(),
            RequirementField::whereNotNull('enum_options')->pluck('enum_options')->flatten()->all(),
        );

        $vocabulary = [];

        foreach ($keys as $key) {
            // Only single-word keys matter here; multi-word ones are snake_case.
            if (is_string($key) && preg_match('/^[a-z]+$/', $key)) {
                $vocabulary[$key] = true;
            }
        }

        return $vocabulary;
    }

    /**
     * "دي صورها" / "بعتلك الصور" state that photos are attached. "تحب
     * أبعتلك صورها؟" is an offer - it was blocked twice as a false claim.
     * Offers/questions and the future form (أبعتلك / هبعتلك) are not claims.
     */
    private function claimsImagesSent(string $text): bool
    {
        $claim = '/(?:^|\s)(?:دي|ودي|اهي|أهي|هتلاقي|هتوصلك)\s+(?:ال)?صور|(?<![\p{L}])(?:بعت|بعتت)(?:ل|لك|لحضرتك)\s+(?:ال)?صور/u';

        foreach (preg_split('/(?<=[.!؟?\n])/u', $text) as $sentence) {
            if (preg_match($claim, $sentence) && ! preg_match($this->offerPattern(), $sentence)) {
                return true;
            }
        }

        return false;
    }

    private function offerPattern(): string
    {
        return '/ممكن|لو (?:حابب|حابة|عايز|عايزة|تحب)|تحب|تحبي|عايزني|تفضل|\?|؟/u';
    }

    /**
     * A committed "هحولك لزميل", not an offer: "ممكن أحولك... تحب؟" was
     * read as a promise, the handoff was forced before the customer said
     * yes, and a customer who was only joking went to staff.
     */
    private function promisesHandoff(string $replyText): bool
    {
        $promise = '/[هأا]حو[ّ]?ل|حو[ّ]?لت|زميل[يى]?\s+(?:\S+\s+){0,4}?(?:هيتابع|هيكلمك|هيتواصل|هيرد|هيكون معاك|هيرجعلك)|هيتواصل معاك|(?:ه|و)?يكون معاك (?:حد|زميل)|حد من (?:ال)?زملا(?:ء|ئي)|(?:ا|أ|ه)تأكد\s*(?:لك|لكم)?\s+من\s+(?:ال)?زميل|[أاه]رجع\s+[أا]?قولك|ارجعلك|أرجعلك|هرجعلك|[أاه]رد عليك (?:فور|بعد|حالا|في أقرب)/u';
        foreach (preg_split('/(?<=[.!؟?\n])/u', $replyText) as $sentence) {
            if (! preg_match($promise, $sentence, $match, PREG_OFFSET_CAPTURE) || preg_match($this->offerPattern(), $sentence)) {
                continue;
            }

            // "لو حابب تكلم زميل، قولي وأنا أحولك": the transfer is the
            // consequence of a condition - an offer. Judged on the whole
            // sentence, because stripping the condition first left
            // "قولي وأنا أحولك", and a customer who only asked "إنت بوت؟"
            // was handed to staff.
            if (preg_match('/(?:^|\s)(?:لو|ولو|إذا|اذا|وإذا)\s/u', substr($sentence, 0, $match[0][1]))) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function isDuplicateOfRecentReply(WhatsappConversation $conversation, string $replyText): bool
    {
        $normalized = $this->normalizeWhitespace($replyText);

        if ($normalized === '') {
            return false;
        }

        $recent = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('sender_type', 'bot')
            ->where('direction', 'outgoing')
            ->latest('id')
            ->limit(3)
            ->pluck('text');

        foreach ($recent as $text) {
            if ($this->normalizeWhitespace((string) $text) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * Principle 7: a price shown to the customer comes from a tool result in
     * the same turn. The catalog index (## فهرس الكتالوج) is for resolving
     * names only, so it is deliberately NOT a source here - "Lifan 150 بـ
     * 53,000" was quoted straight from it with no lookup at all. The rest
     * of the system prompt (structured state, approved business memory),
     * what the customer wrote and earlier replies in the history are sources.
     */
    private function hasUnverifiedNumber(string $replyText, string $system, string $toolResultsBlob, array $contents): bool
    {
        $minValue = config('agent.guard.number_min_value');
        $sourcedSystem = $this->removeBlock($system, '## فهرس الكتالوج');
        // What the customer wrote, and what was already said to them: a
        // price in an earlier reply passed this same check when it went out,
        // so repeating it ("تقصد الفرز التاني بـ 40,000؟") is not a new
        // claim. Blocking it burned the turn's budget on re-lookups.
        $customerText = implode(' ', array_map(
            fn ($c) => implode(' ', array_column(array_filter($c['parts'], fn ($p) => ($p['type'] ?? null) === 'text'), 'text')),
            array_filter($contents, fn ($c) => in_array($c['role'] ?? null, ['user', 'model'], true))
        ));

        $haystackNumbers = $this->extractNumbers($toolResultsBlob.' '.$sourcedSystem.' '.$customerText);
        $withUnits = $this->numbersWithUnits($replyText);

        foreach ($this->extractNumbers($replyText) as $number) {
            $measured = in_array($number, $withUnits, true);

            if (! $measured && $minValue !== null && $number < (float) $minValue) {
                continue;
            }

            if (! in_array($number, $haystackNumbers, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return float[] numbers directly attached to a measurement unit */
    private function numbersWithUnits(string $text): array
    {
        $normalized = $this->westernDigits($text);
        preg_match_all('/(\d[\d,]*(?:\.\d+)?)(?:\s*[-–]\s*(\d[\d,]*(?:\.\d+)?))?\s*'.self::UNIT_PATTERN.'/u', $normalized, $matches);

        $numbers = array_merge($matches[1], array_filter($matches[2]));

        return array_map(fn ($n) => (float) str_replace(',', '', $n), $numbers);
    }

    /** @return float[] */
    private function extractNumbers(string $text): array
    {
        // "46 ألف" is 46,000: read as 46 it slipped under the minimum and a
        // wrong total went out.
        $text = preg_replace_callback('/(\d[\d,]*(?:\.\d+)?)\s*(?:ألف|الف|آلاف|الاف)/u',
            fn ($m) => (string) ((float) str_replace(',', '', $m[1]) * 1000), $this->westernDigits($text));
        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $matches);

        return array_map(fn ($n) => (float) str_replace(',', '', $n), $matches[0]);
    }

    private function westernDigits(string $text): string
    {
        return strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٬' => ',', '٫' => '.']);
    }

    private function removeBlock(string $system, string $header): string
    {
        $start = mb_strpos($system, $header);

        if ($start === false) {
            return $system;
        }

        $next = mb_strpos($system, "\n\n## ", $start + 1);

        return mb_substr($system, 0, $start).($next === false ? '' : mb_substr($system, $next));
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
