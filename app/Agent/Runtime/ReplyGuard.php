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
    /** What exactly the last refusal found (which model, which missing item) - added to its hint. */
    private ?string $detail = null;

    public function lastDetail(): ?string
    {
        return $this->detail;
    }

    /**
     * Rebuild: only what the AI may not decide - claims of actions that did not
     * happen, numbers / branches / companies / models no tool returned, promises
     * nothing will keep, eligibility said without the tool, and broken text.
     * Wording, tone and repetition are the instructions' job, never a refusal.
     */
    public function check(array $args, WhatsappConversation $conversation, string $system, array $contents, array $outcomes): ?string
    {
        $this->detail = null;
        // Matching only: "سِجلت" (a kasra) slipped past the "سجلت" claim check
        // and he was told his answer was saved when nothing was (simulator 2026-10-05).
        $replyText = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', implode(' ', $args['messages'] ?? []));
        $succeeded = array_column(array_filter($outcomes, fn ($o) => $o['ok'] && $this->changedSomething($o)), 'name');
        $toolResultsBlob = json_encode(array_column($outcomes, 'data'), JSON_UNESCAPED_UNICODE) ?: '';

        // Staying silent on a closing "تمام"/emoji is checked by send_reply
        // itself (SendReplyTool::mayStaySilent) - there is no text to judge.
        if (trim($replyText) === '' && ($args['no_reply'] ?? false) === true) {
            return null;
        }

        // send_reply(messages: [""]) was accepted and the customer who had
        // just sent their details by voice got nothing back.
        // Simulator 2026-10-05: the model sent the text "[]", then "(no reply)" to
        // his address - nothing said to him in Arabic at all.
        if (trim($replyText) === '' || ! preg_match('/\p{Arabic}/u', $replyText)) {
            return 'EMPTY_REPLY';
        }

        // QA 2026-10-04: he sent the back of his ID and the reply asked him
        // for "a clearer back" - the photo was never read.
        if ($this->ignoresNewDocumentPhoto($conversation, $outcomes)) {
            return 'DOCUMENT_PHOTO_NOT_PROCESSED';
        }

        // Replay of conversation 206 on Gemini: the same four-line refusal,
        // word for word, five replies running while he kept insisting.
        if ($this->repeatsAnEarlierReply($replyText, $conversation)) {
            return 'REPEATED_REPLY';
        }

        // Conversation 206: "اخ اخويا حبيب صاحب" got "أخوك سنه كام؟" and "اخ" +
        // "اه" got "التقديم باسم أبوك" - a person nobody recorded from his words.
        if ($this->speaksOfUnrecordedPerson($replyText, $conversation)) {
            return 'PERSON_NOT_RECORDED';
        }

        // Claims are judged on assertions only: "لو غيرت رأيك أنا موجود"
        // (if YOU change your mind) is not "I changed it". A goodbye was
        // blocked on that word four times until the real customer got the
        // provider-outage notice instead.
        $assertions = $this->withoutConditionalClauses($replyText);

        if ($this->claimsSubmission($assertions) && ! $this->submissionHappened($conversation, $outcomes)) {
            return 'SUBMISSION_CLAIMED_NOT_DONE';
        }

        // Simulator 2026-10-05: "ده ملخص طلبك، راجعه" with no summary behind it -
        // the summary is sent by submit_application, which was not called.
        if (preg_match('/ملخص\s+(?:طلبك|الطلب|بياناتك)|(?:ده|دا|هو ده)\s+الملخص/u', $assertions)
            && ! collect($outcomes)->contains(fn ($o) => $o['name'] === 'submit_application')) {
            return 'SUMMARY_CLAIMED_NOT_SENT';
        }

        // Simulator 2026-10-05: submit_application succeeded (#3672) and he was
        // told "الطلب ما اتبعتش" - the opposite of what happened.
        if ($this->submittedThisTurn($outcomes)
            && preg_match('/(?:الطلب|طلبك)\s+(?:\S+\s+){0,2}?(?:ما\s*|م)(?:اتبعت|اتقدم|اتقدّم|اترفع|وصل)(?:ش)|(?:ما\s*|م)(?:اتبعتش|اتقدمش|اترفعش|وصلش)/u', $assertions)) {
            return 'SUBMISSION_DENIED_BUT_DONE';
        }

        // Request 4272, rejected by the finance company: "الطلب اتبعت على
        // أمان" four times - nothing was sent anywhere. An earlier
        // submission does not cover a claim that it went again / elsewhere.
        if ($this->claimsSubmission($assertions) && ! $this->submittedThisTurn($outcomes)
            && ($this->mentionsResubmission($assertions) || $this->latestApplicationClosed($conversation))) {
            return 'RESUBMISSION_CLAIMED';
        }

        // "ممكن نقدم تاني على نظام تاني" - only staff can move a submitted
        // request to another finance company.
        if (preg_match('/(?:نقد[ّ]?م|أقد[ّ]?م|اقد[ّ]?م|هقد[ّ]?م|هنقد[ّ]?م|نجرب|أجرب|اجرب|هجرب|نحول|أحول|احول)(?:لك|هولك)?\s+(?:\S+\s+){0,3}?(?:على|علي|ع)\s+(?:نظام|جه[ةه]|شرك[ةه])\s+(?:\S+\s+)?(?:تاني|تانية|تانيه|تانى|مختلف[ةه]?|أخرى|اخرى)/u', $replyText)) {
            return 'RESUBMISSION_PROMISED';
        }

        // "سجلت البيانات" with no write this turn: the customer's phone and
        // address were lost while they were told they had been saved.
        // "أنا عدلتها عندي في الطلب" - the company name was never changed.
        if ((preg_match('/(?:^|\s)و?(?:سجلت|سجّلت|اتسجل|اتسجلت|تم تسجيل|حفظت|ثبت|ثبّت|غيرت|غيّرت|عدلت|عدّلت|حدثت|حدّثت|اتغيرت|اتعدلت|صححت|صحّحت)(?:ها|هم|ه|هولك|هالك|لك|ت)?(?:\s|$|[،.!])/u', $assertions)
                || preg_match('/(?:الرقم|رقمك|العنوان|عنوانك|بياناتك|البيانات|الاسم|اسمك)\s+(?:\S+\s+)?(?:وصل|وصلت|وصلني|وصلتني|اتسجل|اتسجلت|اتعدل|اتعدلت|اتحفظ)(?:\s|$|[،.!])/u', $assertions))
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
        // "يا باشa." - one Latin letter stuck to an Arabic word (conversation 206)
        if (preg_match('/\p{Arabic}[A-Za-z]+\p{Arabic}|[\x{0621}-\x{064A}][A-Za-z]{1,2}(?![A-Za-z0-9])|[\p{Hebrew}\p{Cyrillic}\p{Han}\p{Hangul}\p{Thai}\p{Devanagari}]/u', $replyText)
            || $this->latinGluedToArabic($replyText)) {
            return 'GARBLED_TEXT';
        }

        if ($this->containsInternalKey($replyText)) {
            return 'INTERNAL_KEY_IN_REPLY';
        }

        // Placeholders from the history rendering, never customer-facing text.
        // "[سيظهر ملخص الطلب هنا]" went to two customers as it was.
        if (preg_match('/\[(media|staff)\]|\(اتبعت للعميل|\(تم إرسال الصور\)|\[[^\]]*\p{Arabic}[^\]]*\]/u', $replyText)) {
            return 'PLACEHOLDER_IN_REPLY';
        }

        // "البطاقة وصلت واتسجلت" while the photo had been rejected or never
        // processed: the customer stopped sending and the file stalled.
        if ($this->claimsDocumentReceived($assertions) && ! $this->documentAccepted($outcomes)
            && ! ($this->photosHeldWithoutApplication($outcomes) && ! $this->claimsDocumentApproved($assertions))) {
            return 'DOCUMENT_CLAIMED_NOT_ACCEPTED';
        }

        // "اقفل الطلب" got "قفلتلك الطلب" with nothing withdrawn, then a
        // "لسه معايا؟ طلبك ماشي" reminder.
        if ($this->claimsWithdrawal($assertions) && ! in_array('withdraw_application', $succeeded, true)) {
            return 'WITHDRAWAL_CLAIMED_NOT_DONE';
        }

        // "فتحتلك الطلب" / "بدأتلك الطلب" is only true in the turn that opened
        // it. Said to a customer whose application was open since yesterday
        // (3 times in the 2026-10-04 runs) the right words are "طلبك مفتوح".
        if ($this->claimsOpenedApplication($assertions) && ! $this->openedThisTurn($outcomes)) {
            return $this->activeApplication($conversation) !== null ? 'APPLICATION_ALREADY_OPEN_CLAIMED' : 'APPLICATION_CLAIMED_NOT_OPENED';
        }

        // "فتحتلك الطلب على LIFAN على سنة" - the application was on Keeway.
        if (($set = $this->selectionClaimedNotSet($assertions, $conversation)) !== null) {
            $this->detail = $set;

            return 'SELECTION_CLAIMED_NOT_SET';
        }

        // "تمام كده كملنا التسجيل" with the work landmark still missing: he
        // stopped sending and the application stalled.
        if (($left = $this->completionOverclaimed($assertions, $replyText, $conversation)) !== null) {
            $this->detail = $left;

            return 'COMPLETION_OVERCLAIMED';
        }

        // "المكنة محجوزة باسمك", "بتخلص في نفس اليوم", "بضمان المعرض":
        // nothing reserves a motorcycle, and no time or warranty is recorded.
        if ($this->makesUnrecordedPromise($replyText, $toolResultsBlob)) {
            return 'UNRECORDED_PROMISE';
        }

        // Conversation 852: "هشوفلك حالا المتاح في الفرعين... ثواني وهرد
        // عليكي" then "اتأكدتلك، متاح في عين شمس وجسر السويس" - nothing
        // holds stock per branch and nobody checked. Nothing the bot can do
        // later is "I'll check and come back".
        // offering to check ("أشيّك لك؟") is as empty as saying it was checked
        if ($this->claimsStockOrCheck($this->withoutConditionalClausesOnly($replyText))) {
            return 'STOCK_OR_CHECK_CLAIMED';
        }

        if ($this->inventsTimeFrame($assertions, $toolResultsBlob.' '.$system)) {
            return 'UNRECORDED_PROMISE';
        }

        // "مفيش فوايد" - the customer did the sum and found 30-50% more.
        if ($this->deniesInterest($replyText)) {
            return 'INTEREST_DENIED';
        }

        // "دي صورها" with no successful send_motorcycle_images this turn left
        // customers waiting for photos that never came.
        if (! in_array('send_motorcycle_images', $succeeded, true) && $this->claimsImagesSent($assertions)) {
            return 'IMAGES_CLAIMED_NOT_SENT';
        }

        // "بعتلك صور كيواي وليفان" when only the Lifan went out (the Keeway
        // call came back MOTORCYCLE_DIFFERS_FROM_LAST_QUOTED) - 4 times in
        // the 2026-10-04 runs. Every model named with the photos was sent.
        if (($unsent = $this->imagesClaimedForUnsentModel($assertions, $outcomes)) !== null) {
            $this->detail = $unsent;

            return 'IMAGES_CLAIMED_FOR_UNSENT_MODEL';
        }

        // "هحولك لزميل" / "زميلي هيتابع معاك" with no handoff: nobody was
        // notified and the customer waited on a promise nothing would keep.
        if ($conversation->status !== 'awaiting_agent'
            && ! in_array('handoff_to_human', $succeeded, true)
            && $this->promisesHandoff($replyText)) {
            return 'HANDOFF_CLAIMED_NOT_DONE';
        }

        // "انا مدرس" got "جهات التمويل مش بتقبل شغل المدرسين" - a private
        // school is accepted. Only the owner's rule (a tool) refuses work.
        if (preg_match('/(?:بتتحفظ|تتحفظ|بيتحفظوا)|القرار (?:النهائي )?(?:بيكون |هيكون )?(?:عند|ليهم)|نقد(?:ّ)?م\s+ونشوف|(?:مش\s+(?:بتقبل|بيقبلوا|هتقبل|هيقبلوا)|مبتقبلش|مبيقبلوش|هيترفض|بيترفض|هيرفضوا|بيرفضوا)[^.؟?\n]{0,40}(?:شغل|وظيف|مهن|حكوم)|(?:شغل|وظيف|مهن|المدرسين|حكوم)[^.؟?\n]{0,60}(?:مش\s+(?:بتقبل|بيقبلوا|هتقبل)|مبتقبلش|هيترفض|بيترفض)/u', $replyText)
            && ! preg_match('/OCCUPATION_NOT_ACCEPTED|occupation_not_accepted|APPLICANT_HAS_NO_WORK|FEMALE_FREE_INCOME_NOT_ACCEPTED|GENDER_NOT_ACCEPTED_FOR_TYPE|FOREIGNER_NO_INSTALLMENTS|STATED_INCOME_BELOW_MINIMUM/', $toolResultsBlob)) {
            return 'WORK_REFUSAL_NOT_SOURCED';
        }

        // Owner 2026-09-29: every document on his job's list is asked. A
        // workshop owner was told the required tax card was "مش شرط، لو مش
        // معاك مفيش مشكلة"; a rider was told "بالبطاقة بس".
        if ($this->waivesRequiredDocument($replyText, $conversation)) {
            return 'REQUIRED_DOCUMENT_WAIVED';
        }

        // "ثواني ويكون معاك زميل" said four times over ninety minutes while
        // nobody answered.
        if ($this->promisesColleagueSoon($replyText)) {
            return 'HANDOFF_TIME_PROMISED';
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

        // "مع مين التقسيط؟" got "أمان وفاليو وكونتكت" - two companies we do
        // not work with, named from general knowledge.
        if ($this->namesUnsourcedFinanceCompany($replyText, $toolResultsBlob)) {
            return 'UNSOURCED_FINANCE_COMPANY';
        }

        // Conversation 855 (owner 2026-10-04): "30% بدون مصاريف على سنتين
        // ممكن" - no plan has that; each duration has its own rate (سنة 20،
        // سنة ونص 30، سنتين 40، 3 سنين 60). Percentages are small numbers
        // the number check lets through, so they are checked against the
        // real plans here.
        if ($this->percentWithWrongDuration($replyText)) {
            return 'PERCENT_DURATION_MISMATCH';
        }

        if ($this->hasUnverifiedNumber($replyText, $system, $toolResultsBlob, $contents, true)) {
            return 'UNVERIFIED_NUMBER';
        }

        // after the numbers: a price with the name gets the lookup hint first.
        // "باجاج بوكسر 150" and "دايو 2" offered with no lookup - the model
        // named them from memory. A model is named from this turn's tools,
        // his state, or his own words.
        if (($model = $this->modelNotLookedUp($replyText, $system, $toolResultsBlob, $contents, $conversation)) !== null) {
            $this->detail = $model;

            return 'MODEL_NOT_LOOKED_UP';
        }

        return null;
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
        // a time of day ("10 الصبح") is an opening hour
        .'[0-9٠-٩۰-۹]+\\s*(?:الصبح|صباحا|صباحًا|صباحاً|الضهر|الظهر|العصر|المغرب|بالليل|مساء|مساءً|مساءا)|'
        // the customer's own address ("سجلت العنوان") is not a branch fact
        // "لو عايز عنوان فرع أقرب ليك" is an offer, not an address - one was refused
        // and the repair told him his submitted request had not gone (simulator 2026-10-05)
        .'(?:عنوان (?:ال)?(?:فرع|معرض)\s*(?:[:،,-]|هو\s|في\s)|عنوانّا|عنوانا|عنوان فرعنا|مكاننا|مكان (?:ال)?(?:فرع|معرض)|لوكيشن (?:ال)?(?:فرع|معرض)|maps\.app)|(?:مواعيد\S*|بنفتح|بنقفل|فاتحين|شغالين)\s+(?:\S+\s+){0,4}?(?:من|لـ?|ل|لحد)\s*(?:ال)?(?:ساع[ةه]\s*)?\d/u';

    /** Words after "فرع" that name a place rather than being a place. */
    private const BRANCH_FILLER = ['في', 'ف', 'فى', 'الفرع', 'فرع', 'اقرب', 'أقرب', 'الأقرب', 'الاقرب', 'لينا', 'عندنا', 'احنا', 'إحنا', 'تقدر', 'ليك', 'ليكي',
        'تانية', 'تاني', 'تانيين', 'المتاحة', 'متاحة', 'المتاح', 'متاح', 'قريب', 'قريبة', 'القريب', 'جديد', 'جديدة', 'كتير', 'واحد', 'واحدة', 'كذا',
        'بتاعنا', 'بتاعتنا', 'كلها', 'كلهم', 'موجود', 'موجودة', 'الموجودة', 'الرئيسي', 'التانية', 'التاني', 'دي', 'ده', 'اللي', 'مفتوح', 'مفتوحة',
        'محافظة', 'منطقة', 'المنطقة', 'المحافظة', 'بتاعك', 'عندك', 'قريبه', 'تانيه', 'متاحه', 'واحده', 'موجوده',
        'هناك', 'هنا', 'مواعيده', 'مواعيدها', 'مواعيدهم', 'مواعيدنا', 'عنوانه', 'عنوانها', 'بتاعه', 'بتاعها', 'بتاعهم', 'برضه', 'كمان',
        'بتاعكم', 'ليكم', 'جنبك', 'منك', 'دلوقتي', 'النهارده', 'بكره', 'يفتح', 'بيفتح', 'بتفتح', 'وده', 'ودي', 'وعنوانه', 'ومواعيده',
        'شغالة', 'شغاله', 'شغالين', 'شغال', 'مفتوحين', 'بتشتغل', 'مواعيد', 'عناوين', 'عنوان'];

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
        // Simulator 688: the hours in the table were changed and the bot
        // repeated the old ones from earlier in the chat. Every time of day
        // it states is in the branches it looked up this turn.
        // (normalized: digits are Latin and the article is gone - "الصبح" is "صبح")
        preg_match_all('/(\d+)\s*(صبح|صباحا|ضهر|ظهر|عصر|مغرب|بالليل|ليل|مساء|مساءا)/u', \App\Support\ArabicTextNormalizer::normalize($replyText), $times, PREG_SET_ORDER);

        foreach ($times as [, $hour, $period]) {
            if (! preg_match('/(?<!\d)'.$hour.'\s*'.preg_quote($period, '/').'/u', $source)) {
                return true;
            }
        }

        // "مفيش فرع في المنصورة" is the truthful answer, not a claim.
        $affirmed = preg_replace('/(?:مفيش|مافيش|مفيهاش|معندناش|ماعندناش|ما عندناش|ملناش|مالناش|مش عندنا|للأسف مفيش)\s+[^.،,!؟?\n]*/u', ' ', $replyText);
        // Only a bare "فرع X": in "كل الفروع شغالة" the word after "الفروع"
        // is no place, and three correct branch lists were blocked on it.
        preg_match_all('/(?<![\p{L}])(?:فرع|فروع|فرعنا)\s+(?:(?:في|ف|فى|بـ?)\s+)?(?!أي|اي|وقت|الوقت)((?:ال)?[\p{Arabic}]{3,})/u', $affirmed, $named);

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
            if (isset($outcome['data']['eligibility']) || isset($outcome['data']['snapshot']['eligibility']) || isset($outcome['data']['application_now']['eligibility'])) {
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

    /** A photo arrived this turn, an application is open, and no tool looked at the photo. */
    private function ignoresNewDocumentPhoto(WhatsappConversation $conversation, array $outcomes): bool
    {
        $looked = array_intersect(array_column($outcomes, 'name'), ['process_document', 'identify_motorcycle_from_image', 'handoff_to_human']);

        if ($looked !== [] || ! $conversation->customer_id) {
            return false;
        }

        $turnId = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'incoming')->latest('id')->value('turn_id');

        $applicationIds = \App\Models\Application::where('customer_id', $conversation->customer_id)
            ->whereIn('status', \App\Models\Application::ACTIVE_STATUSES)->pluck('id');

        if (! $turnId || $applicationIds->isEmpty()) {
            return false;
        }

        return \App\Models\MessageMedia::query()
            ->whereHas('message', fn ($q) => $q->where('whatsapp_conversation_id', $conversation->id)
                ->where('direction', 'incoming')->where('turn_id', $turnId))
            ->whereIn('media_type', ['image', 'document'])
            ->whereNotIn('id', \App\Models\ApplicationDocument::whereIn('application_id', $applicationIds)->whereNotNull('media_id')->select('media_id'))
            ->get()
            ->contains(fn (\App\Models\MessageMedia $m) => ! isset(($m->analysis ?? [])['band']) && ! isset(($m->analysis ?? [])['not_a_document']));
    }

    private function namesUnsourcedFinanceCompany(string $replyText, string $toolResultsBlob): bool
    {
        $reply = \App\Support\ArabicTextNormalizer::normalize($replyText);
        // QA 2026-10-04: a customer who picked مايلو (one of our own
        // systems, named by a tool a turn earlier) had every reply naming it
        // blocked, got the fallback twice and was handed off. The systems we
        // work with are sourced by definition.
        $sources = \App\Support\ArabicTextNormalizer::normalize($toolResultsBlob.' '
            .\App\Models\InstallmentSystem::where('is_active', true)->pluck('name')->implode(' '));

        foreach (self::KNOWN_FINANCE_BRANDS as $brand) {
            $brand = \App\Support\ArabicTextNormalizer::normalize($brand);

            if (preg_match('/(?<![\p{L}])'.preg_quote($brand, '/').'(?![\p{L}])/u', $reply) && ! str_contains($sources, $brand)) {
                return true;
            }
        }

        return false;
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
    private function withoutConditionalClausesOnly(string $text): string
    {
        return preg_replace('/(?:^|(?<=[\s،,.!؟?]))(?:ولو|لو|إذا|اذا|وإذا|وإن|إن|في حالة|فى حالة)\s+[^،,.!؟?\n]*/u', ' ', $text) ?? $text;
    }

    private function withoutConditionalClauses(string $text): string
    {
        $text = preg_replace('/(?:^|(?<=[\s،,.!؟?]))(?:ولو|لو|إذا|اذا|وإذا|وإن|إن|لما|في حالة|فى حالة|أول ما|اول ما|لحد ما|عشان)\s+[^،,.!؟?\n]*/u', ' ', $text) ?? $text; // null on broken UTF-8 crashed a turn

        // A question is not a claim: "هل قدّمت طلب تقسيط في معرض تاني قبل
        // كده؟" was refused 212 times in four days as "the application was
        // submitted" - each refusal one more full model call.
        // Only the asking clause goes: "سجلت رقمك، تحب نكمل؟" still claims.
        $text = preg_replace('/(?:^|(?<=[.!؟?\n]))\s*هل\s[^.!؟?\n]*[؟?]/u', ' ', $text) ?? $text;

        return preg_replace('/(?:^|(?<=[.!؟?\n،,]))[^.!؟?\n،,]*[؟?]/u', ' ', $text) ?? $text;
    }

    /**
     * "الطلب اتبعت للمراجعة" / "أكدت إرسال الطلب" while the application was
     * still collecting: two customers were told they had applied.
     */
    private function submittedThisTurn(array $outcomes): bool
    {
        foreach ($outcomes as $outcome) {
            if ($outcome['name'] === 'submit_application' && $outcome['ok'] && ($outcome['data']['submitted'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function mentionsResubmission(string $text): bool
    {
        return (bool) preg_match('/تاني|تانى|من جديد|الجديد|التاني|نظام|جه[ةه] تمويل|أمان|امان|مايلو|فاليو|كونتكت|(?:اتقدم|اتبعت)\s+فعل/u', $text);
    }

    /** His latest application was cancelled or refused - it is not "submitted". */
    private function latestApplicationClosed(WhatsappConversation $conversation): bool
    {
        $status = $conversation->customer_id
            ? Application::where('customer_id', $conversation->customer_id)->latest('id')->value('status')
            : null;

        return in_array($status, ['withdrawn', 'rejected', 'expired'], true);
    }

    /** "هبعتلusd الطلب": Latin letters stuck to an Arabic word that is not an article/prefix ("الـHLX", "بالVLR"). */
    private function latinGluedToArabic(string $text): bool
    {
        preg_match_all('/([\x{0621}-\x{064A}\x{0640}]+)[A-Za-z]{2,}/u', $text, $matches);

        foreach ($matches[1] as $prefix) {
            if (! in_array(str_replace('ـ', '', $prefix), ['ال', 'بال', 'وال', 'فال', 'كال', 'لل', 'ل', 'ب', 'و', 'ف', 'ك'], true)) {
                return true;
            }
        }

        return false;
    }

    private const DOCUMENT_WORDS = [
        'salary_slip' => '/مفردات/u',
        'pension_statement' => '/كشف\s+(?:ال)?معاش/u',
        'delivery_app_earnings' => '/(?:ا?سكرين|screen)\S*\s+(?:\S+\s+){0,3}?(?:أرباح|ارباح|الأرباح|الارباح)|(?:أرباح|ارباح)\S*\s+(?:من\s+)?(?:ال)?تطبيق/u',
        'driving_license' => '/رخص[ةه]\s*(?:ال)?(?:قياد[ةه]|سواق[ةه])|(?:صور[ةه]|وش|ضهر)\s+(?:ال)?رخص[ةه]/u',
        'business_place_photo' => '/صور[ةه]?\s+(?:\S+\s+)?(?:ال|ل|لل)?(?:مكان|ورش[ةه]|ورشت|محل|نشاط|يافط[ةه])/u',
        'tax_card' => '/بطاق[ةه]\s+ضريبي[ةه]|سجل\s+تجاري/u',
        'delivery_app_profile' => '/(?:ا?سكرين|صور[ةه])\S*\s+(?:\S+\s+){0,2}?(?:ال)?بروفايل|البروفايل/u',
        // owner 2026-10-04: the employee's paper when his company gives no salary slip
        'insurance_print' => '/برنت\s+(?:ال)?(?:تأمينات|تامينات|تأمين|تامين)|بيان\s+تأميني/u',
        // never required of anyone
        // A warehouse worker with no salary slip was offered a bank
        // statement, then "صورة العقد" - none exist.
        '' => '/كشف\s+حساب|صور[ةه]\s+(?:ال)?عقد|عقد\s+(?:ال)?(?:ورش[ةه]|محل|إيجار|ايجار|شغل)|إيصال\s+(?:ال)?(?:مرافق|كهرب|ميا[هه]|غاز)|ايصال\s+(?:ال)?(?:مرافق|كهرب|ميا[هه]|غاز)|فاتور[ةه]\s+(?:ال)?(?:كهرب|ميا[هه]|غاز)|فواتير|إيصالات|ايصالات|تقارير\s+ضريبي|إشعارات\s+(?:ال)?ضريب|اشعارات\s+(?:ال)?ضريب|معاش\s+تقاعدي/u',
    ];


    private function namesAnyDocument(string $sentence, array $keys): bool
    {
        foreach ($keys as $key) {
            if (isset(self::DOCUMENT_WORDS[$key]) && preg_match(self::DOCUMENT_WORDS[$key], $sentence)) {
                return true;
            }
        }

        return false;
    }


    private function waivesRequiredDocument(string $replyText, WhatsappConversation $conversation): bool
    {
        $application = $conversation->customer_id
            ? Application::where('customer_id', $conversation->customer_id)->whereIn('status', Application::ACTIVE_STATUSES)->latest('id')->first()
            : null;

        if (! $application) {
            return false;
        }

        $required = (array) (app(\App\Domain\Applications\SnapshotService::class)->for($application)['documents']['required'] ?? []);
        $beyondId = array_diff($required, ['national_id_front', 'national_id_back']);

        // Owner 2026-10-04: "تقديم بالبطاقة فقط" is a real last resort - offering
        // it is not a waiver once the application is on that route. Structural
        // only: a guard never asks a model (rebuild GUARD-005).
        $cardOnlyAllowed = fn (): bool => \App\Domain\Applications\CardOnlyRoute::on($application);

        foreach (preg_split('/(?<=[.!؟?\n])/u', $replyText) as $sentence) {
            if (preg_match('/مش\s+(?:هينفع|ينفع|هنقدر|نقدر|كفاي[ةه])|لازم/u', $sentence)) {
                continue;
            }

            // QA 2026-10-04: "تحب أصفلك المحل بالكلام عشان نكمل؟" - a
            // description offered in place of the shop photo.
            if (preg_match('/(?:[اأتن]?وصف|اوصفلك|أوصفلك|تصفلي|اصفلك|أصفلك)\S*.{0,25}بالكلام|بالكلام\s+بدل/u', $sentence)) {
                return true;
            }

            if ($beyondId !== [] && preg_match('/(?<!مش\s)(?:بال)?بطاق[ةه]\s+(?:بس|فقط)/u', $sentence) && ! $cardOnlyAllowed()) {
                return true;
            }

            // "مفيش مشكلة، نكمّل بالبطاقة وعنوان شغلك" said while his
            // application still needs the salary slip: the switch
            // (documents.if_unavailable) was never made, so it would stall.
            if ($beyondId !== [] && preg_match('/(?:نكم[ّ]?ل|هنكم[ّ]?ل|نقد[ّ]?م|هنقد[ّ]?م|نمشي|هنمشي)\S*\s+(?:\S+\s+){0,2}?بالبطاق[ةه]/u', $sentence)
                && ! $this->namesAnyDocument($sentence, $beyondId) && ! $cardOnlyAllowed()) {
                return true;
            }

            if (! preg_match('/مش\s+(?:شرط|ضروري[ةه]?|مطلوب[ةه]?|مهم[ةه]?)|لو\s+(?:مش\s+)?(?:معاك|موجود[ةه]?|متاح[ةه]?|عندك|متوفر[ةه]?)|اختياري|مفيش\s+مشكل[ةه]|ينفع\s+من\s+غير/u', $sentence)) {
                continue;
            }

            foreach ($required as $key) {
                // "لو الشركة مش بتطلع مفردات، ابعت برنت التأمينات" offers the
                // accepted equivalent, it does not waive the paper
                if (isset(self::DOCUMENT_WORDS[$key]) && preg_match(self::DOCUMENT_WORDS[$key], $sentence)
                    && ! $this->namesAnyDocument($sentence, \App\Domain\Documents\DocumentEquivalents::FOR[$key] ?? [])) {
                    return true;
                }
            }
        }

        return false;
    }


    private function promisesColleagueSoon(string $replyText): bool
    {
        foreach (preg_split('/(?<=[.!؟?\n])/u', $replyText) as $sentence) {
            if (preg_match('/زميل|الزملا|حد من (?:ال)?فريق/u', $sentence)
                && preg_match('/ثواني|ثانية|حالا|حالاً|فورا|فوراً|فورًا|دقيق[ةه]|دقايق|للمر[ةه] الأخير[ةه]|للمره الاخيره/u', $sentence)) {
                return true;
            }
        }

        return false;
    }



    private function claimsSubmission(string $assertions): bool
    {
        return (bool) preg_match(
            '/(?:الطلب|طلبك|الملف)\s+(?:\S+\s+){0,2}?و?(?:اتبعت|اتقدم|اتقدّم|اترفع|اتأكد|وصل|راح|اتحول)'
            .'|(?:تم|اتم)\s+(?:إرسال|ارسال|تقديم|رفع|تأكيد|تاكيد)\s+(?:ال)?(?:طلب|ملف)'
            // (?<!\p{L}): "نبعت الطلب" / "هبعت الطلب" / "قبل ما نبعت" are promises,
            // not claims - one turn on 2026-10-01 was refused ten times on them.
            .'|(?<!\p{L})(?:أكدت|اكدت|بعت|بعتت|قدمت|قدّمت|رفعت)\s+(?:\S+\s+)?(?:ال)?(?:طلب|ملف)'
            .'|(?<!\p{L})(?:أكدت|اكدت)\s+(?:إرسال|ارسال|تقديم)'
            // "الطلب في مرحلة المراجعة" said to a customer whose file was never sent
            .'|(?:الطلب|طلبك|الملف)\s+(?:\S+\s+){0,2}?(?:في|فى|ف|تحت)\s+(?:مرحل[ةه]\s+)?(?:ال)?مراجع[ةه]|(?:الطلب|طلبك)\s+(?:\S+\s+)?بيتراجع/u',
            $assertions
        );
    }

    private function claimsDocumentReceived(string $assertions): bool
    {
        return (bool) preg_match('/(?:البطاق[ةه]|الصور[ةه]?|صورة البطاق[ةه]|المستند|الورق[ةه]?|الإيصال|الايصال|الفاتور[ةه]|الرخص[ةه]|وش البطاق[ةه]|ضهر البطاق[ةه]|ظهر البطاق[ةه])\s+(?:\S+\s+){0,2}?و?(?:وصلت|وصلتني|وصلني|اتسجلت|اتقبلت|اتحفظت|اتقرت)'
            .'|(?:^|\s)و?(?:وصلتني|وصلني|استلمت|اتقبلت)\s+(?:\S+\s+)?(?:ال)?(?:بطاق[ةه]|صور|ورق|مستند)'
            // "تمام وصلت الصور" / "البطاقة تمام" while process_document had failed
            .'|(?:^|\s)و?(?:وصلت|وصلوا|وصلو|اتقبلوا|اتقبلو)\s+(?:\S+\s+)?(?:ال)?(?:بطاق[ةه]|صور|ورق|مستند)'
            .'|(?:البطاق[ةه]|الصور[ةه]?)\s+(?:وصلت\s+)?(?:تمام|سليم[ةه]|مقبول[ةه]|اتقبلت)(?!\p{L})/u', $assertions);
    }

    /**
     * Replay of conversation 206: the brother's ID came with no application
     * open (he cannot apply); "البطاقة وصلت" is true - it did arrive - and was
     * blocked twice into "مش متأكد إني فهمتك". Only "accepted/saved" is a claim then.
     */
    private function photosHeldWithoutApplication(array $outcomes): bool
    {
        return collect($outcomes)->contains(fn ($o) => $o['name'] === 'process_document' && ! $o['ok'] && ($o['data']['code'] ?? null) === 'NO_ACTIVE_APPLICATION');
    }

    private function claimsDocumentApproved(string $assertions): bool
    {
        return (bool) preg_match('/(?:البطاق[ةه]|الصور[ةه]?|المستند|الورق[ةه]?|الرخص[ةه])\s+(?:\S+\s+){0,2}?و?(?:اتسجلت|اتقبلت|اتقبلوا|اتحفظت|اتقرت|تمام|سليم[ةه]|مقبول[ةه])(?!\p{L})'
            .'|(?:^|\s)و?(?:اتقبلت|اتقبلوا|اتسجلت)\s+(?:\S+\s+)?(?:ال)?(?:بطاق[ةه]|صور|ورق|مستند)/u', $assertions);
    }

    private function documentAccepted(array $outcomes): bool
    {
        foreach ($outcomes as $outcome) {
            if ($outcome['name'] === 'process_document' && $outcome['ok'] && $this->changedSomething($outcome)) {
                return true;
            }
        }

        return false;
    }

    private function claimsWithdrawal(string $assertions): bool
    {
        // "وقفتلك الطلب" / "هقفل الطلب دلوقتي" were said with no tool call.
        return (bool) preg_match('/(?:^|\s)و?(?:قفلت|قفلتلك|قفلتهولك|لغيت|لغيتلك|ألغيت|الغيت|الغيتلك|سحبت|سحبتلك|كنسلت|كنسلتلك|وقفت|وقفتلك|وقفتهولك)(?:\s|$|[،.!])'
            .'|(?:^|\s)(?:ه|ح)(?:قفل|لغي|ألغي|الغي|وقف|سحب|كنسل)(?:لك)?\s+(?:ال)?طلب'
            .'|(?:الطلب|طلبك)\s+(?:\S+\s+)?(?:اتقفل|اتلغى|اتلغي|اتسحب|اتكنسل)|(?:تم|اتم)\s+(?:إلغاء|الغاء|قفل|سحب)\s+(?:ال)?طلب/u', $assertions);
    }

    private function claimsOpenedApplication(string $assertions): bool
    {
        // "بدأتلك الطلب" / "هابدأ معاكي طلب" said the same and were not caught
        return (bool) preg_match('/(?:^|\s)و?(?:فتحت|فتحتلك|فتحنا|فتحنالك|عملتلك|بدأت|بدات|بدأتلك|بداتلك|بدأنا|بدانا|هبدألك|هبدالك|هابدألك|هابدالك|هبدأ|هبدا|هابدأ|هابدا)(?:\s+(?:معاك|معاكي|معاكى|مع\s+حضرتك))?\s+(?:\S+\s+)?(?:ال)?طلب'
            .'|(?:الطلب|طلبك)\s+(?:\S+\s+)?اتفتح|(?:تم|اتم)\s+فتح\s+(?:ال)?طلب/u', $assertions);
    }

    /** start_application opened (or brought back) the application in this turn. */
    private function openedThisTurn(array $outcomes): bool
    {
        foreach ($outcomes as $outcome) {
            if ($outcome['name'] === 'start_application' && $outcome['ok']
                && (($outcome['data']['created'] ?? false) === true || isset($outcome['data']['reopened']))) {
                return true;
            }
        }

        return false;
    }

    private function activeApplication(WhatsappConversation $conversation): ?Application
    {
        return $conversation->customer_id
            ? Application::where('customer_id', $conversation->customer_id)->whereIn('status', Application::ACTIVE_STATUSES)->latest('id')->first()
            : null;
    }

    /** The model and duration the reply ties the application to, when the application (after this turn's tools) is not on them. */
    private function selectionClaimedNotSet(string $assertions, WhatsappConversation $conversation): ?string
    {
        // only "الطلب/طلبك ... على X": a model or duration said next to the application
        if (! preg_match_all('/(?:الطلب|طلبك|طلب)\s+(?:\S+\s+){0,3}?(?:على|علي|ع|بـ|ب)\s+([^،,.!؟?\n]+)/u', $assertions, $m)) {
            return null;
        }

        $application = $this->activeApplication($conversation);

        if (! $application) {
            return null;
        }

        $application->loadMissing('installmentPlan', 'machine.brand');
        $months = $application->installmentPlan?->months !== null ? (int) $application->installmentPlan->months : null;
        $wrong = false;

        foreach ($m[1] as $said) {
            $said = implode(' ', array_slice(preg_split('/\s+/u', trim($said)), 0, 5));

            foreach (app(CatalogMentions::class)->words($said) as $machineIds) {
                $wrong = $wrong || ! in_array((int) $application->machine_id, $machineIds, true);
            }

            $durations = $this->durationsIn($said);
            $wrong = $wrong || ($durations !== [] && ! in_array($months, $durations, true));
        }

        if (! $wrong) {
            return null;
        }

        $machine = $application->machine ? trim($application->machine->brand?->name.' '.$application->machine->name) : null;

        return 'The application is set to: '.($machine ?? 'no motorcycle yet').', '.($months ? $months.' months' : 'no duration yet').'.';
    }

    /**
     * "كملنا التسجيل" / "كل حاجة جاهزة" while the application still has
     * blockers. "البيانات كملت" is judged on the data alone, and a reply that
     * names what is left ("فاضل البطاقة") is not claiming everything is in.
     */
    private function completionOverclaimed(string $assertions, string $replyText, WhatsappConversation $conversation): ?string
    {
        $data = preg_match('/(?:كملنا|كمّلنا|خلصنا|خلّصنا)\s+(?:ال)?(?:تسجيل|بيانات)|(?:البيانات|بياناتك)\s+(?:(?!مش\s)\S+\s+)?(?:كملت|كمّلت|اكتملت|خلصت|جاهز[ةه]|كامل[ةه])/u', $assertions);
        $all = ! $data && preg_match('/(?:كده|كدا)\s+(?:كملنا|كمّلنا|خلصنا|خلّصنا)(?!\p{L})|(?:كملنا|خلصنا)\s+(?:ال)?(?:طلب|ورق|كل\s+حاج[ةه])|(?:طلبك|الطلب|ورقك|الورق)\s+(?:(?!مش\s)\S+\s+)?(?:جاهز|كمل|اكتمل|خلص|كامل)|كل\s+حاج[ةه]\s+(?:(?!مش\s)\S+\s+)?(?:تمام|جاهز[ةه]|كملت|خلصت|كامل[ةه])/u', $assertions);

        if (! $data && ! $all) {
            return null;
        }

        // a reply that names what is left or asks for it is not claiming everything is in
        if ($all && preg_match('/(?:^|\s)(?:فاضل|ناقص|باقي|لسه|محتاج\S*|ابعت\S*|هات)(?!\p{L})/u', $replyText)) {
            return null;
        }

        $application = $this->activeApplication($conversation);

        if (! $application) {
            return null;
        }

        try {
            $snapshot = app(\App\Domain\Applications\SnapshotService::class)->for($application);
        } catch (\Throwable) {
            return null;
        }

        $blockers = array_values(array_filter((array) ($snapshot['blockers'] ?? []), fn ($b) => ! $data || ($b['type'] ?? null) === 'field'));

        if ($blockers === []) {
            return null;
        }

        $first = $blockers[0];
        $label = match ($first['type'] ?? null) {
            'field' => RequirementField::where('key', $first['key'])->value('label'),
            'document' => DocumentType::where('key', $first['key'])->value('label'),
            default => null,
        } ?? ($snapshot['next_step']['label'] ?? $first['key']);

        return 'Still missing: '.$label.' (and '.(count($blockers) - 1).' more).';
    }

    /** The models a photos claim names that no send_motorcycle_images sent this turn. */
    private function imagesClaimedForUnsentModel(string $assertions, array $outcomes): ?string
    {
        $sent = [];
        $sentNames = [];

        foreach ($outcomes as $outcome) {
            if ($outcome['name'] === 'send_motorcycle_images' && $outcome['ok']) {
                $sent[] = (int) ($outcome['data']['motorcycle_id'] ?? 0);
                $sentNames[] = (string) ($outcome['data']['motorcycle'] ?? '');
            }
        }

        if ($sent === []) {
            return null;
        }

        $claim = '/(?:^|\s)(?:دي|ودي|اهي|أهي|هتلاقي|هتوصلك)\s+(?:ال)?صور|(?<![\p{L}])(?:بعت|بعتت)(?:ل|لك|لحضرتك)\s+(?:ال)?صور/u';

        foreach (preg_split('/(?<=[.!؟?\n])/u', $assertions) as $sentence) {
            if (! preg_match($claim, $sentence) || preg_match($this->offerPattern(), $sentence)) {
                continue;
            }

            foreach (app(CatalogMentions::class)->words($sentence) as $word => $machineIds) {
                if (array_intersect($machineIds, $sent) === []) {
                    return 'You sent photos of '.implode(' and ', array_filter($sentNames))." only - \"{$word}\" was not sent.";
                }
            }
        }

        return null;
    }

    /** A catalog model the reply names that no tool this turn, his state or his own words mention. */
    private function modelNotLookedUp(string $replyText, string $system, string $toolResultsBlob, array $contents, WhatsappConversation $conversation): ?string
    {
        $catalog = app(CatalogMentions::class);
        $named = $catalog->fullNames($replyText);

        if ($named === []) {
            return null;
        }

        $customerText = implode(' ', array_map(
            fn ($c) => implode(' ', array_column(array_filter($c['parts'], fn ($p) => ($p['type'] ?? null) === 'text'), 'text')),
            // a model our earlier reply named passed this same check when it went out
            array_filter($contents, fn ($c) => in_array($c['role'] ?? null, ['user', 'model'], true))
        ));

        $application = $this->activeApplication($conversation);
        $known = array_map('intval', array_filter([$application?->machine_id, \App\Domain\Conversations\QuotedMotorcycle::last($conversation->id)]));

        // the catalog index is for resolving names, not a lookup
        $sources = $catalog->normalize($toolResultsBlob.' '.$this->removeBlock($system, '## فهرس الكتالوج').' '.$customerText.' '.$this->customerTextSinceLastReply($conversation));

        foreach ($named as $machineId) {
            if (in_array($machineId, $known, true)) {
                continue;
            }

            $sourced = false;

            foreach ($catalog->namesOf($machineId) as $name) {
                $sourced = $sourced || preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $sources);
            }

            if (! $sourced) {
                return 'Not looked up this turn: '.($catalog->namesOf($machineId)[0] ?? $machineId).'.';
            }
        }

        return null;
    }



    /**
     * The draft without the sentences he already got (8+ words in a row from
     * one of the last three replies); the shortest one stays when every
     * sentence was said before. Null when nothing is left at all.
     *
     * @param string[] $messages
     * @return string[]|null
     */
    public function withoutRepeatedSentences(array $messages, WhatsappConversation $conversation): ?array
    {
        $words = fn (string $t) => preg_split('/\s+/u', trim(preg_replace('/[.،,؟?!:\n]+/u', ' ', \App\Support\ArabicTextNormalizer::normalize($t))), -1, PREG_SPLIT_NO_EMPTY);
        $said = [];

        foreach (WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)->where('direction', 'outgoing')
            ->where('sender_type', 'bot')->latest('id')->limit(3)->pluck('text') as $text) {
            $before = $words((string) $text);

            for ($i = 0; $i + 8 <= count($before); $i++) {
                $said[implode(' ', array_slice($before, $i, 8))] = true;
            }
        }

        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[.؟?!])\s+|\n+/u', implode("\n", $messages))), fn ($x) => $x !== ''));
        $kept = array_values(array_filter($sentences, function ($sentence) use ($words, $said) {
            $w = $words($sentence);

            for ($i = 0; $i + 8 <= count($w); $i++) {
                if (isset($said[implode(' ', array_slice($w, $i, 8))])) {
                    return false;
                }
            }

            return true;
        }));

        if ($kept === []) {
            usort($sentences, fn ($a, $b) => mb_strlen($a) <=> mb_strlen($b));
            $kept = array_slice($sentences, 0, 1);
        }

        return $kept === [] ? null : [implode(' ', $kept)];
    }

    /** 12+ words in a row already sent in one of the last three replies. */
    private function repeatsAnEarlierReply(string $replyText, WhatsappConversation $conversation): bool
    {
        $words = fn (string $t) => preg_split('/\s+/u', trim(preg_replace('/[.،,؟?!:\n]+/u', ' ', \App\Support\ArabicTextNormalizer::normalize($t))), -1, PREG_SPLIT_NO_EMPTY);
        $reply = $words($replyText);

        if (count($reply) < 12) {
            return false;
        }

        $grams = [];

        for ($i = 0; $i + 12 <= count($reply); $i++) {
            $grams[implode(' ', array_slice($reply, $i, 12))] = true;
        }

        $earlier = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)->where('direction', 'outgoing')
            ->where('sender_type', 'bot')->latest('id')->limit(3)->pluck('text');

        foreach ($earlier as $text) {
            $before = $words((string) $text);

            for ($i = 0; $i + 12 <= count($before); $i++) {
                if (isset($grams[implode(' ', array_slice($before, $i, 12))])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The reply talks about ONE person of his family/friends as the one
     * applying, and that is not who record_work_profile holds (his words).
     * Two people named = a question ("أخوك ولا أبوك؟"), which is fine.
     */
    private function speaksOfUnrecordedPerson(string $replyText, WhatsappConversation $conversation): bool
    {
        $named = [];

        foreach ([
            'brother' => 'أخوك|اخوك', 'sister' => 'أختك|اختك', 'father' => 'أبوك|ابوك|والدك', 'mother' => 'والدتك|أمك|امك|مامتك',
            'son' => 'ابنك', 'daughter' => 'بنتك', 'spouse' => 'مراتك|جوزك|زوجتك', 'friend' => 'صاحبك', 'relative' => 'قريبك',
        ] as $relation => $words) {
            // "حد تاني من أهلك أو صاحبك" offers options - it names nobody
            if (preg_match('/(?<!أو\s|او\s|ولا\s|زي\s|مثلا\s)(?<!\p{L})(?:'.$words.')(?!\p{L})(?!\s+(?:أو|او|ولا)\s)/u', $replyText)) {
                $named[] = $relation;
            }
        }

        // "نبدأ التقديم باسم أخويا؟" - the bot has no brother: his relatives are "أخوك/أبوك"
        if (preg_match('/(?<!\p{L})(?:أخويا|اخويا|أبويا|ابويا|أختي|اختي|أمي|امي|مراتي|جوزي|ابني|بنتي)(?!\p{L})/u', $replyText)
            && ! preg_match('/["«“][^"»”]*(?:أخويا|اخويا|أبويا|ابويا|أمي|امي)[^"»”]*["»”]/u', $replyText)) {
            return true;
        }

        if (count($named) !== 1) {
            return false;
        }

        $work = app(\App\Domain\Applications\WorkProfiles::class)->get($conversation->id);

        return ($work['applicant'] ?? null) !== 'someone_else' || ($work['applicant_relation'] ?? 'unclear') !== $named[0];
    }

    /** A promise no record backs: a reservation, a time frame, a warranty or a guarantor. */
    private function makesUnrecordedPromise(string $replyText, string $sources): bool
    {
        if (preg_match('/(?<!مش )محجوز[ةه]?|(?:[نهأا]|ن|هن)?حجز(?:ت)?(?:لك|هالك|هولك|ها لك)|نلحق\s+(?:\S+\s+)?نحجز|في نفس اليوم|فى نفس اليوم|نفس اليوم|خلال\s+(?:\S+\s+){0,2}?(?:يوم|يومين|ايام|أيام|ساع[ةه]|ساعات|اسبوع|أسبوع|اسبوعين|أسبوعين|أسابيع|اسابيع)|(?:يوم|يومين|\d+\s+(?:يوم|أيام|ايام))\s+(?:عمل|شغل)|بالرقمين|كأولوي[ةه]|هيجربوا\s+(?:ال)?رقم|من\s+(?:يوم|يومين|\d+)\s+(?:لـ?|ل|إلى|الى)\s*\S+\s+(?:يوم|ايام|أيام)|أول ما يجيلي رد|اول ما يجيلي رد|المصنع بيدينا/u', $replyText)) {
            return true;
        }

        $normalizedSources = \App\Support\ArabicTextNormalizer::normalize($sources);

        // "الضمان بيوضحهولك الزميل في الفرع" is the right answer; "بضمان
        // المعرض" / "محتاج ضامن" are claims that need a source.
        // Conversation 206: "مش بتقبلها بضمان دخل حر" (answering his "ينفع
        // بضمان الشقه؟") is no warranty promise - only "بضمان المعرض/سنة..." is.
        foreach (['ضمان' => '/بضمان\s+(?:ال)?(?:معرض|وكيل|شرك[ةه]|مصنع|شامل|سن[ةه]|سنتين|\d)|(?:عليها|عليه|فيها|ليها|معاها|و)\s*ضمان|ضمان\s+(?:سن[ةه]|سنتين|\d|المعرض|الوكيل|شامل|لمد[ةه])/u',
            'ضامن' => '/(?:محتاج|لازم|يجيب|تجيب|هتحتاج|بيحتاج|محتاجين|نحتاج)\s+(?:\S+\s+)?ضامن/u'] as $word => $pattern) {
            if (preg_match($pattern, $replyText) && ! str_contains($normalizedSources, $word)) {
                return true;
            }
        }

        // "الضمان مكتوب على الورقة 6 شهور": a warranty period in the same
        // sentence is a fact - a source has to give that warranty and that number.
        foreach (preg_split('/(?<=[.!؟?\n])/u', $replyText) as $sentence) {
            if (preg_match('/ضمان/u', $sentence)
                && preg_match_all('/(\d+)\s*(?:يوم|أيام|ايام|شهر|شهور|أشهر|اشهر|سن[ةه]|سنين|سنوات|ساع[ةه]|ساعات)(?!\p{L})/u', $this->westernDigits($sentence), $periods)
                && (! str_contains($normalizedSources, 'ضمان') || array_diff(array_map('floatval', $periods[1]), $this->extractNumbers($sources)) !== [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replayed 2026-10-04: "مدة التقديم عادةً من يومين لغاية أسبوع" - no
     * time is recorded anywhere; the truthful answer is that there is none.
     */
    private function inventsTimeFrame(string $assertions, string $sources): bool
    {
        $span = '(?:يوم|يومين|ايام|أيام|اسبوع|أسبوع|اسبوعين|أسبوعين|اسابيع|أسابيع|ساع[ةه]|ساعتين|ساعات|شهر|شهرين)';

        // "الموافقة 24-72 ساعة": a range with no lead word
        if (preg_match('/\d+\s*[-–]\s*\d+\s*'.$span.'(?!\p{L})/u', $this->westernDigits($assertions), $range)
            && ! str_contains($this->westernDigits($sources), $range[0])) {
            return true;
        }

        if (! preg_match('/(?:عاد[ةه]ً?|بياخد|هياخد|بتاخد|هتاخد|بيستغرق|المد[ةه]|مد[ةه]|في\s+خلال|خلال|من|لحد|لغاي[ةه])\s+(?:\S+\s+){0,4}?(?:\d+\s*)?'.$span.'(?!\p{L})/u', $assertions, $m)) {
            return false;
        }

        // A duration a tool gave is fine. "مفيش مدة ثابتة" has no span in it,
        // and saying it first does not excuse "عادةً بين يومين لحد أسبوع" after.
        preg_match('/(?:\d+\s*)?'.$span.'(?!\p{L})/u', $m[0], $duration);

        return ! str_contains($sources, $duration[0] ?? $m[0]);
    }


    private function claimsStockOrCheck(string $assertions): bool
    {
        return (bool) preg_match(
            '/(?:^|\s)و?(?:ه|ح|هن|ن)(?:شوف|تأكد|تاكد|سأل|سال|راجع|كلم|تابع|تحقق|اتحقق)(?:لك|لِك|ك|ي|لكم)?\s+(?:\S+\s+){0,5}?(?:و(?:ه|أ|ا|ن)?(?:رد|رجع|بلغ|قول|رتب))'
            .'|(?:^|\s)و?(?:ه|ح|هن|ن)(?:تحقق|اتحقق|تأكد|تاكد|شوف)\s+(?:\S+\s+){0,2}?(?:من\s+)?(?:ال)?(?:توافر|توفر|متاح|موجود)'
            .'|(?:ن|ه|هن|أ|ا)(?:رتب|حجز|جهز)(?:لك|لِك|ك)?\s+(?:\S+\s+){0,2}?(?:معاين[ةه]|ميعاد|موعد)'
            .'|ثواني\s+و(?:ه|ح)?(?:رد|رجع|بلغ|قول)'
            .'|(?:اتأكدت|اتاكدت|اتأكدتلك|اتاكدتلك|سألت|سالت)\s+(?:\S+\s+){0,3}?(?:متاح|موجود|في\s+(?:ال)?فرع)'
            .'|(?:متاح|متوفر|موجود)(?:[ةه]|ين)?\s+(?:\S+\s+){0,2}?(?:في|فى|ف)\s+(?:ال)?(?:فرع|فروع|فرعين|كل\s+(?:ال)?فروع)'
            .'|(?:في|فى|ف)\s+(?:ال)?(?:فرع|فروع|فرعين)\s+(?:\S+\s+){0,1}?(?:متاح|متوفر|موجود)'
            .'|(?:^|\s)و?(?:أ|ا|ه|ن|هن)?شي[ّ]?ك(?:لك|لِك|ك|هالك|هولك)?(?!\p{L})/u',
            $assertions
        );
    }

    private function deniesInterest(string $replyText): bool
    {
        return (bool) preg_match('/(?:مفيش|مافيش|من غير|بدون|بلا|مش بنحسب\S*|ملهاش|مالهاش|معندناش|مفيهاش)\s+(?:\S+\s+){0,2}?(?:فوايد|فوائد|فايد[ةه]|فائد[ةه])/u', $replyText);
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

        // Replayed 2026-10-04: "وتطلع لك نتيجته مع القسط في الأداة"; history:
        // "السيستم مش قادر يقرأ اسم التطبيق". The customer talks to a salesman.
        // "اتغير وضعك وبقيت سواق اوبر في النظام" (server, gpt-5-nano) - "نظام
        // التقسيط" is fine, "the system" on its own is our machinery
        // ("أعدتلك شغله في النظام وكمان سجلت المعاش" - replay of conversation 206)
        if (preg_match('/(?<!\p{L})(?:في|ف|على|ع)\s+(?:ال)?(?:نظام|سيستم)(?=\s*(?:[.،,!؟?\n]|$)|\s+و)/u', $replyText)) {
            return true;
        }

        if (preg_match('/(?<!\p{L})(?:ال)?(?:أداة|اداة|أدوات|ادوات|سيستم|ذاكر[ةه] العميل|برومبت|التعليمات اللي (?:ماشي|ماشيين|ماشيه|بمشي|بنمشي|بشتغل|بنشتغل)\S*)(?!\p{L})/u', $replyText)) {
            return true;
        }

        // QA 2026-10-04: "ده اختيار تقسيط لـ H250 (ID 10)" and "upfront 16,500".
        if (preg_match('/\b(?:id|plan_id|media_id)\s*[:#=]?\s*\d+|\b(?:snapshot|customer_type|media_id|plan_id)\b|(?<![A-Za-z])ID(?![A-Za-z])/i', $replyText)) {
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







    /**
     * Principle 7: a price shown to the customer comes from a tool result in
     * the same turn. The catalog index (## فهرس الكتالوج) is for resolving
     * names only, so it is deliberately NOT a source here - "Lifan 150 بـ
     * 53,000" was quoted straight from it with no lookup at all. The rest
     * of the system prompt (structured state, approved business memory),
     * and what the customer wrote are sources - our own earlier replies are not.
     */
    /** A percentage said with a duration no active plan has it for. */
    private function percentWithWrongDuration(string $replyText): bool
    {
        $text = strtr($replyText, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٪' => '%', '٫' => '.']);

        if (! preg_match('/\d\s*(?:%|في\s?المي[ةه]|بالمي[ةه])/u', $text)) {
            return false;
        }

        $plans = \App\Models\InstallmentPlan::query()->where('is_active', true)
            ->whereHas('installmentSystem', fn ($q) => $q->where('is_active', true))
            ->get(['months', 'interest_percent']);
        $fees = \App\Models\InstallmentSystem::where('is_active', true)->pluck('administrative_fees')->map(fn ($f) => (float) $f)->all();
        $any = array_merge($plans->pluck('interest_percent')->map(fn ($p) => (float) $p)->all(), $fees);

        foreach (preg_split('/(?<=[.!؟?\n،,:])\s*|\s+(?:أو|او|ولا|و)\s+(?=\S*\s*\d)/u', $text) as $part) {
            preg_match_all('/(\d+(?:\.\d+)?)\s*(?:%|في\s?المي[ةه]|بالمي[ةه])/u', $part, $m);

            if ($m[1] === []) {
                continue;
            }

            $months = $this->durationsIn($part);

            foreach ($m[1] as $percent) {
                $percent = (float) $percent;
                $allowed = count($months) === 1
                    ? array_merge($plans->where('months', $months[0])->pluck('interest_percent')->map(fn ($p) => (float) $p)->all(), $fees)
                    : $any;

                if (! in_array($percent, $allowed, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return int[] the plan durations a sentence names, in months */
    private function durationsIn(string $text): array
    {
        $found = [];
        $text = preg_replace_callback('/(\d+)\s*(?:شهر|شهور|أشهر|اشهر)/u', function ($m) use (&$found) {
            $found[] = (int) $m[1];

            return ' ';
        }, $text);
        $patterns = [
            18 => '/(?:ال)?سن[ةه]\s+ونص/u',
            36 => '/(?:تلت|ثلاث|تلات|3)\s*(?:سنين|سنوات)/u',
            24 => '/(?:ال)?سنتين/u',
            12 => '/(?<!\p{L})(?:ال)?سن[ةه](?!\p{L})/u',
        ];

        foreach ($patterns as $months => $pattern) {
            if (preg_match($pattern, $text)) {
                $found[] = $months;
                $text = preg_replace($pattern, ' ', $text);
            }
        }

        return array_values(array_unique($found));
    }

    private function hasUnverifiedNumber(string $replyText, string $system, string $toolResultsBlob, array $contents, bool $earlierRepliesCount = true): bool
    {
        return $this->unverifiedNumbers($replyText, $system, $toolResultsBlob, $contents, $earlierRepliesCount) !== [];
    }



    /** @return float[] */
    private function unverifiedNumbers(string $replyText, string $system, string $toolResultsBlob, array $contents, bool $earlierRepliesCount = true): array
    {
        $minValue = config('agent.guard.number_min_value');
        $sourcedSystem = $this->removeBlock($system, '## فهرس الكتالوج');
        // What the customer wrote. Our own earlier replies used to count too
        // (to save re-lookups), and that is how a stale 50,000 and fees of
        // 3,220 / 2,730 were repeated in the 2026-10-04 runs: a figure is
        // sourced by a tool of this turn, the state, or the customer only.
        $customerText = implode(' ', array_map(
            fn ($c) => implode(' ', array_column(array_filter($c['parts'], fn ($p) => ($p['type'] ?? null) === 'text'), 'text')),
            // When he brings a price his figure is no source either -
            // agreeing with "بقت 35 ألف" is invented. Only a lookup now answers it.
            array_filter($contents, fn ($c) => $earlierRepliesCount && ($c['role'] ?? null) === 'user')
        ));

        $haystackNumbers = $this->extractNumbers($toolResultsBlob.' '.$sourcedSystem.' '.$customerText);

        // "في حدود 60 او 65" is 60,000-65,000: an Egyptian budget is said in
        // thousands, and repeating it back is not an invented number.
        foreach ($this->extractNumbers($customerText) as $n) {
            if ($n >= 1 && $n < 1000) {
                $haystackNumbers[] = $n * 1000;
            }
        }
        $withUnits = $this->numbersWithUnits($replyText);

        foreach ($this->extractNumbers($replyText) as $number) {
            $measured = in_array($number, $withUnits, true);

            if (! $measured && $minValue !== null && $number < (float) $minValue) {
                continue;
            }

            if (! in_array($number, $haystackNumbers, true)) {
                $unverified[] = $number;
            }
        }

        return $unverified ?? [];
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


    /**
     * Wording the owner bans that needs no new model call to fix: emoji,
     * **bold** and stray markdown headers are removed in place - a retry
     * resends the whole context for a character the code can drop.
     */
    public function tidy(array $args): array
    {
        // Simulator 2026-10-05: the model wrote its own arguments as the text
        // ('{"messages":[],"no_reply":true}') - unwrapped, nothing reworded.
        $only = (array) ($args['messages'] ?? []);
        $decoded = count($only) === 1 ? json_decode(trim((string) $only[0]), true) : null;

        if (is_array($decoded) && array_key_exists('messages', $decoded) && is_array($decoded['messages'])) {
            $args = array_merge($args, array_intersect_key($decoded, array_flip(['messages', 'no_reply', 'ends_conversation'])));
        }

        $args['messages'] = array_values(array_filter(array_map(function ($m) {
            $m = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', (string) $m);
            $m = preg_replace('/\*\*(.+?)\*\*/u', '$1', $m);
            // Simulator 2026-10-05: "وش وبُطِّين" - a WhatsApp salesman writes no tashkeel.
            $m = preg_replace('/[\x{064B}-\x{0652}]/u', '', $m);
            $m = preg_replace('/^#{1,6}\s+/mu', '', $m);
            // "تمام — بدأتلك الطلب": a dash reads like a document, not a salesman.
            $m = preg_replace('/\s*[—–]\s*/u', '، ', $m);
            return trim(preg_replace('/[ \t]{2,}/u', ' ', $m));
        }, (array) ($args['messages'] ?? [])), fn ($m) => $m !== ''));

        return $args;
    }








    /** What the customer wrote since our last reply. */
    public function customerTextSinceLastReply(WhatsappConversation $conversation): string
    {
        $lastOut = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')->whereIn('sender_type', ['bot', 'human', 'human_phone', 'staff'])->max('id');

        return WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'incoming')
            ->when($lastOut, fn ($q) => $q->where('id', '>', $lastOut))
            ->orderBy('id')->get(['text', 'transcript'])
            ->map(fn ($m) => trim((string) $m->text.' '.(string) $m->transcript))
            ->implode(' ');
    }



}
