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

        // Staying silent on a closing "تمام"/emoji is checked by send_reply
        // itself (SendReplyTool::mayStaySilent) - there is no text to judge.
        if (trim($replyText) === '' && ($args['no_reply'] ?? false) === true) {
            return null;
        }

        // send_reply(messages: [""]) was accepted and the customer who had
        // just sent their details by voice got nothing back.
        if (trim($replyText) === '') {
            return 'EMPTY_REPLY';
        }

        // QA 2026-10-04: he sent the back of his ID and the reply asked him
        // for "a clearer back" - the photo was never read.
        if ($this->ignoresNewDocumentPhoto($conversation, $outcomes)) {
            return 'DOCUMENT_PHOTO_NOT_PROCESSED';
        }

        // Claims are judged on assertions only: "لو غيرت رأيك أنا موجود"
        // (if YOU change your mind) is not "I changed it". A goodbye was
        // blocked on that word four times until the real customer got the
        // provider-outage notice instead.
        $assertions = $this->withoutConditionalClauses($replyText);

        if ($this->claimsSubmission($assertions) && ! $this->submissionHappened($conversation, $outcomes)) {
            return 'SUBMISSION_CLAIMED_NOT_DONE';
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

        // Owner 2026-10-02 (lessons 37/38): anyone may apply in his place -
        // but only offered when he does not work at all, is about to start,
        // is under 21 or has a bad credit record. A warehouse worker short
        // of a salary slip was told "هات قريب أو صاحب يقدم باسمه" instead.
        // The text first: the work reading behind mayApplyThroughSomeoneElse
        // is a paid model call, and asked first it ran on every reply
        // (ENHANCE-Ai: a second model call in almost every turn).
        if (preg_match('/(?:حد|شخص|قريب|صاحب|قرايبك|أصحابك|اصحابك)\s+(?:\S+\s+){0,6}?و?(?:يقد[ّ]?م|يتقد[ّ]?م|تقد[ّ]?م)\s+(?:\S+\s+){0,3}?باسم|(?:نقد[ّ]?م|يقد[ّ]?م|تقد[ّ]?م)\s+(?:\S+\s+){0,2}?باسم\s+(?:حد|شخص)|باسم\s+(?:حد|شخص)\s+تاني/u', $replyText)
            && ! $this->mayApplyThroughSomeoneElse($conversation, $toolResultsBlob)) {
            return 'INVENTED_APPLICATION_ROUTE';
        }

        // "هعدلك الطلب لعامل حر بدل موظف": he said he is insured; the bot
        // must never suggest another work type so he gets accepted.
        if (preg_match('/(?:عامل حر|موظف|صاحب نشاط)["”]?\s*(?:\([^)]{0,40}\)\s*)?بدل\s+(?:ما\s+)?["“]?(?:موظف|عامل حر|صاحب نشاط)|(?:نقد[ّ]?م|نسجل|نكتب|نعدل|نحول)(?:ك|لك)?\s+(?:\S+\s+){0,2}?(?:على\s+)?(?:إنك|انك)\s+["“]?(?:عامل حر|موظف|صاحب نشاط)/u', $replyText)) {
            return 'WORK_TYPE_SWITCH_SUGGESTED';
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
        if (preg_match('/\p{Arabic}[A-Za-z]+\p{Arabic}|[\p{Hebrew}\p{Cyrillic}\p{Han}\p{Hangul}\p{Thai}\p{Devanagari}]/u', $replyText)
            || $this->latinGluedToArabic($replyText)) {
            return 'GARBLED_TEXT';
        }

        if (($code = $this->conversationFailure($replyText, $conversation, $outcomes)) !== null) {
            return $code;
        }
        if ($this->containsInternalKey($replyText)) {
            return 'INTERNAL_KEY_IN_REPLY';
        }

        // Owner 2026-10-02: "الطلب اتبعت للمراجعة" with no number - the
        // customer has nothing to quote when he calls or comes in.
        if (($number = $this->submittedRequestNumber($outcomes)) !== null
            && ! str_contains(\App\Support\ArabicTextNormalizer::normalize($replyText), $number)) {
            return 'REQUEST_NUMBER_MISSING';
        }

        // Placeholders from the history rendering, never customer-facing text.
        // "[سيظهر ملخص الطلب هنا]" went to two customers as it was.
        if (preg_match('/\[(media|staff)\]|\(اتبعت للعميل|\(تم إرسال الصور\)|\[[^\]]*\p{Arabic}[^\]]*\]/u', $replyText)) {
            return 'PLACEHOLDER_IN_REPLY';
        }

        // "البطاقة وصلت واتسجلت" while the photo had been rejected or never
        // processed: the customer stopped sending and the file stalled.
        if ($this->claimsDocumentReceived($assertions) && ! $this->documentAccepted($outcomes)) {
            return 'DOCUMENT_CLAIMED_NOT_ACCEPTED';
        }

        // "اقفل الطلب" got "قفلتلك الطلب" with nothing withdrawn, then a
        // "لسه معايا؟ طلبك ماشي" reminder.
        if ($this->claimsWithdrawal($assertions) && ! in_array('withdraw_application', $succeeded, true)) {
            return 'WITHDRAWAL_CLAIMED_NOT_DONE';
        }

        if ($this->claimsOpenedApplication($assertions) && ! in_array('start_application', $succeeded, true)
            && ! Application::where('customer_id', $conversation->customer_id)->whereIn('status', Application::ACTIVE_STATUSES)->exists()) {
            return 'APPLICATION_CLAIMED_NOT_OPENED';
        }

        // "المكنة محجوزة باسمك", "بتخلص في نفس اليوم", "بضمان المعرض":
        // nothing reserves a motorcycle, and no time or warranty is recorded.
        if ($this->makesUnrecordedPromise($replyText, $toolResultsBlob)) {
            return 'UNRECORDED_PROMISE';
        }

        // Replayed conversation 302: "الهوجن ٤ عندنا بنسختين" with no lookup
        // - there are three. How many versions we carry is catalog data.
        if (preg_match('/(?:بنسختين|نسختين|بتلات نسخ|تلات نسخ|كذا نسخ[ةه]|أكتر من نسخ[ةه]|اكتر من نسخ[ةه]|\d\s*نسخ|كذا نوع|نوعين)/u', $replyText)
            && array_intersect(array_column(array_filter($outcomes, fn ($o) => $o['ok']), 'name'), ['search_motorcycles', 'get_motorcycle_details']) === []) {
            return 'VERSIONS_NOT_LOOKED_UP';
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

        // "قولي \"أنا شغال دليفري\" عشان أقدر أسجلك": the customer is never
        // asked to repeat a sentence for the system.
        if ($this->asksForScriptedPhrase($replyText)) {
            return 'SCRIPTED_PHRASE_REQUEST';
        }

        // The owner: the bot never gives itself a name, and never "أهلاً بك",
        // "حقك عليا", "من غير مقدم" or "الكتالوج" (an internal word).
        if (preg_match('/(?:معاك|أنا|انا|اسمي)\s+(?:ال)?حاوي|(?:أهلاً|أهلا|اهلاً|اهلا)\s+(?:بك|بيك|بيكي|بكي)(?!\p{L})|حقك عليا|حقك عليّا|كتالوج|كاتالوج|كتلوج|catalog|(?:من غير|بدون|مفيش|مافيش)\s+(?:(?:أي|اي|عندنا|خالص|فيه|فيها)\s+){0,2}مقد[مّ]/u', $replyText)) {
            return 'BANNED_WORDING';
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

        // "انا مدرس" got "جهات التمويل مش بتقبل شغل المدرسين" - a private
        // school is accepted. Only the owner's rule (a tool) refuses work.
        if (preg_match('/(?:بتتحفظ|تتحفظ|بيتحفظوا)|القرار (?:النهائي )?(?:بيكون |هيكون )?(?:عند|ليهم)|نقد(?:ّ)?م\s+ونشوف|(?:مش\s+(?:بتقبل|بيقبلوا|هتقبل|هيقبلوا)|مبتقبلش|مبيقبلوش|هيترفض|بيترفض|هيرفضوا|بيرفضوا)[^.؟?\n]{0,40}(?:شغل|وظيف|مهن|حكوم)|(?:شغل|وظيف|مهن|المدرسين|حكوم)[^.؟?\n]{0,60}(?:مش\s+(?:بتقبل|بيقبلوا|هتقبل)|مبتقبلش|هيترفض|بيترفض)/u', $replyText)
            && ! preg_match('/OCCUPATION_NOT_ACCEPTED|occupation_not_accepted|APPLICANT_HAS_NO_WORK|FEMALE_FREE_INCOME_NOT_ACCEPTED|GENDER_NOT_ACCEPTED_FOR_TYPE|FOREIGNER_NO_INSTALLMENTS|STATED_INCOME_BELOW_MINIMUM/', $toolResultsBlob)) {
            return 'WORK_REFUSAL_NOT_SOURCED';
        }

        // "يعني حضرتك بتشتغل في الصيدلية كعامل حر": the type is for the tools only.
        if (preg_match('/(?:ك|بصفتك|بصفته|بصفتها|هنسجلك|هسجلك|هنسجلها|هسجلها)\s*(?:"|«)?(?:عامل حر|موظف متأمن|موظف متامن|صاحب نشاط)/u', $replyText)) {
            return 'WORK_TYPE_TOLD';
        }

        // Owner 2026-10-02: "انا محاسب" got "تمام يا أستاذ محاسب".
        if (preg_match('/يا\s+(?:(?:أ|ا)ستاذ(?:ة|ه)?\s+|باشمهندس\s+)?(?:ال)?(?:محاسب|مدرس|معلم|ممرض|صيدلي|سواق|نجار|سباك|كهربائي|ميكانيكي|طيار|مندوب|موظف|صنايعي|فران|حداد|نقاش|مبيض|ترزي|حلاق|بياع|كاشير|محامي|مهندس|دليفري)(?:ة|ه)?(?!\p{L})/u', $replyText)) {
            return 'JOB_AS_NAME';
        }

        // "معلمة ... ده بيسهل الأمور جداً" and "فرص القبول أعلى بكتير" before
        // anything was checked: only the tools say what his work means.
        if (preg_match('/(?:بيسهل|هيسهل|يسهل|بتسهل|هتسهل)\s+(?:ال)?(?:أمور|امور|إجراءات|اجراءات|موضوع|قبول|دنيا)|فرص(?:ة|ه)?\s+(?:ال)?قبول\s+(?:أعلى|اعلى|أكبر|اكبر)|(?:مقبول|مقبوله|مقبولة)\s+جدا/u', $replyText)) {
            return 'WORK_JUDGED';
        }

        // Lesson 33: "أمين الشرطة من المهن اللي جهات التمويل بتتحفظ عليها...
        // لو حابب نجرب" - the owner wants a plain "هيترفض".
        if ((str_contains($toolResultsBlob, 'OCCUPATION_NOT_ACCEPTED') || str_contains($toolResultsBlob, 'occupation_not_accepted'))
            && preg_match('/بتتحفظ|تتحفظ|نجرب|نقد[ّ]?م|اقد[ّ]?م|أقد[ّ]?م|القرار (?:النهائي )?(?:عند|بيكون)|مصدر دخل تاني|ممكن يتقبل|احتمال/u', $replyText)) {
            return 'OCCUPATION_SOFTENED';
        }

        // A workshop owner was asked for "عقد الورشة أو إيصال مرافق" and a
        // pharmacy courier for app earnings - neither is required of them.
        if ($this->asksForUnrequiredDocument($replyText, $conversation, $outcomes)) {
            return 'DOCUMENT_NOT_REQUIRED';
        }

        // Owner 2026-09-29: "بما إن المكنة سعرها تحت 60 ألف" is our own rule,
        // not something to tell a customer. Above the cap it is said - only
        // when this turn's offer shows it is above (explanation or ASK_WORK_FIRST).
        if ($this->mentionsCapThreshold($replyText, $toolResultsBlob)) {
            return 'CAP_THRESHOLD_MENTIONED';
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

        // "انا عايز اعرف اجمالي السعر" got "التقسيط عندنا مش بيتحسب
        // بإجمالي سعر ثابت" - the total is in every offer (breakdown).
        if (preg_match('/مش\s+(?:بيتحسب|بنحسب|بيبقى|هيبقى)\s+(?:ب)?(?:إجمالي|اجمالي|الإجمالي|الاجمالي)|(?:مفيش|مافيش|ملوش|مالوش)\s+(?:سعر\s+)?(?:إجمالي|اجمالي)|(?:مقدرش|ماقدرش|ما اقدرش|مش هقدر)\s+(?:\S+\s+){0,2}(?:الإجمالي|الاجمالي|إجمالي|اجمالي)/u', $replyText)) {
            return 'TOTAL_REFUSED';
        }

        // "حضرتك بتشتغل إيه؟ (موظف، صاحب نشاط، ولا عامل حر؟)": the owner
        // asks only "بتشتغل إيه؟" - a menu of types steers the answer.
        if (preg_match_all('/موظف|صاحب نشاط|عامل حر|شغال حر|على المعاش|ع المعاش/u', $replyText) >= 2
            && preg_match('/بتشتغل|شغلك|شغال إيه|شغال ايه/u', $replyText)) {
            return 'WORK_TYPES_LISTED';
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

        if ($this->hasUnverifiedNumber($replyText, $system, $toolResultsBlob, $contents, ! $this->customerBringsAPrice($conversation))) {
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
        // Conversation 302 replayed 2026-10-04: "المصاريف الإدارية دي الرسوم
        // اللي جهة التمويل بتفرضها عشان فتح الملف وتسجيل العقد... وتدفعها
        // ضمن أول قسط" - a reason nobody recorded and the wrong time to pay.
        $reasonWords = '/مقابل\s+(?:ال)?(?:إجراءات|اجراءات|ورق|تمويل|خدمات|تكلف[ةه]|تسهيلات|فتح ملف|دراس[ةه])|تمويل خارجي|جه[ةه] (?:ال)?تمويل\s+(?:\S+\s+)?(?:بتفرض|بتاخد|بتحط|بتطلب)|فتح (?:ال)?ملف|تسجيل (?:ال)?عقد|تقييم (?:ال)?طلب|رسوم|(?:ضمن|مع) (?:أول|اول) قسط|تكلف[ةه]|تكاليف|التشغيل|(?:ال)?خدمات|ضريب[ةه]|ضرايب|ضرائب|فوايد|فوائد|تأمين|التأمين/u';
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
        // a time of day ("10 الصبح") is an opening hour
        .'[0-9٠-٩۰-۹]+\\s*(?:الصبح|صباحا|صباحًا|صباحاً|الضهر|الظهر|العصر|المغرب|بالليل|مساء|مساءً|مساءا)|'
        // the customer's own address ("سجلت العنوان") is not a branch fact
        .'(?:عنوان (?:ال)?(?:فرع|معرض)|عنوانّا|عنوانا|عنوان فرعنا|مكاننا|مكان (?:ال)?(?:فرع|معرض)|لوكيشن (?:ال)?(?:فرع|معرض)|maps\.app)|(?:مواعيد\S*|بنفتح|بنقفل|فاتحين|شغالين)\s+(?:\S+\s+){0,4}?(?:من|لـ?|ل|لحد)\s*(?:ال)?(?:ساع[ةه]\s*)?\d/u';

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
        'business_place_photo' => '/صور[ةه]?\s+(?:\S+\s+)?(?:ال)?(?:مكان|ورش[ةه]|محل|نشاط|يافط[ةه])/u',
        'tax_card' => '/بطاق[ةه]\s+ضريبي[ةه]|سجل\s+تجاري/u',
        'delivery_app_profile' => '/(?:ا?سكرين|صور[ةه])\S*\s+(?:\S+\s+){0,2}?(?:ال)?بروفايل|البروفايل/u',
        // owner 2026-10-04: the employee's paper when his company gives no salary slip
        'insurance_print' => '/برنت\s+(?:ال)?(?:تأمينات|تامينات|تأمين|تامين)|بيان\s+تأميني/u',
        // never required of anyone
        // A warehouse worker with no salary slip was offered a bank
        // statement, then "صورة العقد" - none exist.
        '' => '/كشف\s+حساب|صور[ةه]\s+(?:ال)?عقد|عقد\s+(?:ال)?(?:ورش[ةه]|محل|إيجار|ايجار|شغل)|إيصال\s+(?:ال)?(?:مرافق|كهرب|ميا[هه]|غاز)|ايصال\s+(?:ال)?(?:مرافق|كهرب|ميا[هه]|غاز)|فاتور[ةه]\s+(?:ال)?(?:كهرب|ميا[هه]|غاز)/u',
    ];

    private function asksForUnrequiredDocument(string $replyText, WhatsappConversation $conversation, array $outcomes): bool
    {
        $allowed = $this->allowedDocuments($conversation, $outcomes);

        foreach (preg_split('/(?<=[.!؟?\n])/u', $replyText) as $sentence) {
            if (! preg_match('/ابعت|تبعت|تجيب|محتاج|محتاجين|مطلوب|المطلوب|هنحتاج|بنحتاج|نحتاج|لازم|هات|جهز|بديل/u', $sentence)
                || preg_match('/مش\s+(?:محتاج|مطلوب|لازم|شرط)|ممنوع|من غير\s+(?:\S+\s+)?(?:مفردات|سكرين|رخص)/u', $sentence)) {
                continue;
            }

            foreach (self::DOCUMENT_WORDS as $key => $pattern) {
                if (preg_match($pattern, $sentence) && ($key === '' || ($allowed !== null && ! in_array($key, $allowed, true)))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function namesAnyDocument(string $sentence, array $keys): bool
    {
        foreach ($keys as $key) {
            if (isset(self::DOCUMENT_WORDS[$key]) && preg_match(self::DOCUMENT_WORDS[$key], $sentence)) {
                return true;
            }
        }

        return false;
    }

    private function mentionsCapThreshold(string $replyText, string $toolResultsBlob): bool
    {
        $caps = \App\Models\EligibilityRule::where('is_active', true)->where('rule_type', 'financing_cap')->get()
            ->map(fn ($r) => (int) ($r->params['max_amount'] ?? 0))->filter(fn ($c) => $c >= 1000)->unique();

        foreach ($caps as $cap) {
            $western = [(string) intdiv($cap, 1000), number_format($cap), (string) $cap];
            $forms = [];

            foreach ($western as $form) {
                $forms[] = preg_quote($form, '/');
                $forms[] = preg_quote(strtr($form, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩', ',' => '٬']), '/');
            }

            $number = '(?:'.implode('|', $forms).')(?!\d|[٠-٩])';
            $below = '/(?:تحت|أقل|اقل|أقل من|مش معدي[ةه]?)\s+(?:من\s+)?(?:ال)?'.$number.'/u';
            $above = '/(?:فوق|أعلى|اعلى|أكتر|اكتر|أكثر|اكثر|معدي[ةه]?|بتعدي|بيعدي|تعدي|يعدي)\s+(?:من\s+)?(?:ال)?'.$number.'/u';

            if (preg_match($below, $replyText)) {
                return true;
            }

            if (preg_match($above, $replyText) && ! str_contains($toolResultsBlob, 'explain_to_customer') && ! str_contains($toolResultsBlob, 'ASK_WORK_FIRST')) {
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

        // Owner 2026-10-04: "تقديم بالبطاقة فقط" is a real last resort for a
        // working man who said he cannot bring his work's papers - offering
        // it then is not a waiver. Read lazily: only a sentence about the ID
        // alone needs it.
        $lastResort = null;
        $cardOnlyAllowed = function () use (&$lastResort, $conversation, $application): bool {
            if ($lastResort === null) {
                $r = app(\App\Domain\Applications\WorkClassification::class)->reading($conversation->id);
                $lastResort = \App\Domain\Applications\CardOnlyRoute::on($application)
                    || ($r !== null && $r['cannot_bring_work_papers'] && ($r['applicant_gender'] ?? null) !== 'female');
            }

            return $lastResort;
        };

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

    /** @return string[]|null document keys this customer may be asked for; null = not known yet (no application, no lookup) */
    private function allowedDocuments(WhatsappConversation $conversation, array $outcomes): ?array
    {
        $blob = json_encode(array_column($outcomes, 'data'), JSON_UNESCAPED_UNICODE) ?: '';
        $fromTools = array_values(array_filter(array_keys(self::DOCUMENT_WORDS), fn ($k) => $k !== '' && str_contains($blob, '"'.$k.'"')));

        $application = $conversation->customer_id
            ? Application::where('customer_id', $conversation->customer_id)->whereIn('status', Application::ACTIVE_STATUSES)->latest('id')->first()
            : null;

        if (! $application) {
            return $fromTools === [] ? null : $fromTools;
        }

        $required = (array) (app(\App\Domain\Applications\SnapshotService::class)->for($application)['documents']['required'] ?? []);
        $optional = \App\Models\ApplicationRequirement::where('customer_type_id', $application->customer_type_id)
            ->where('requirement_type', 'document')->where('is_required', false)
            ->with('documentType')->get()->pluck('documentType.key')->filter()->all();

        return \App\Domain\Documents\DocumentEquivalents::acceptable(array_values(array_unique(array_merge($required, $optional, $fromTools))));
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

    /** The request number when submit_application succeeded this turn. */
    private function submittedRequestNumber(array $outcomes): ?string
    {
        foreach ($outcomes as $outcome) {
            if ($outcome['name'] === 'submit_application' && $outcome['ok'] && ($outcome['data']['submitted'] ?? false) === true) {
                $id = $outcome['data']['reference']['installment_request_id'] ?? null;

                return $id ? (string) $id : null;
            }
        }

        return null;
    }

    private function mayApplyThroughSomeoneElse(WhatsappConversation $conversation, string $toolResultsBlob): bool
    {
        if (str_contains($toolResultsBlob, 'not_eligible') || str_contains($toolResultsBlob, 'AGE_OUT_OF_RANGE')
            || str_contains($toolResultsBlob, 'APPLICANT_HAS_NO_WORK')) {
            return true;
        }

        // QA 2026-10-04: "ما انا مش شغال اصلا عشان كده صاحبي هيقدم" was
        // refused three times - the reading of his work knows he is not working.
        $r = app(\App\Domain\Applications\WorkClassification::class)->reading($conversation->id);

        if ($r !== null && (in_array($r['working_now'], ['no', 'not_yet'], true) || $r['applicant'] === 'someone_else')) {
            return true;
        }

        return app(\App\Domain\Conversations\CustomerStatements::class)->anyMessageMatches($conversation->id,
            '/(?<!\p{L})(?:مش شغال|مبشتغلش|ما بشتغلش|مش بشتغل|مش بيشتغل|مبيشتغلش|عاطل|بدون عمل|مش لاقي شغل|لسه هشتغل|هشتغل|هيشتغل|هبدا|ناوي اشتغل|طالب|سكور|ايسكور|متعثر|متعثره|قضيه|قضايا|اقساط متاخره|قسط متاخر|بلاك ?ليست|عليا مديونيه|عليه مديونيه|(?:عندي|سني|سنه|عمري)\s*(?:1[0-9]|20)(?!\d))(?!\p{L})/u');
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
            .'|(?:^|\s)و?(?:وصلتني|وصلني|استلمت|اتقبلت)\s+(?:\S+\s+)?(?:ال)?(?:بطاق[ةه]|صور|ورق|مستند)/u', $assertions);
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
        return (bool) preg_match('/(?:^|\s)و?(?:فتحت|فتحتلك|فتحنالك|عملتلك)\s+(?:\S+\s+)?(?:ال)?طلب|(?:الطلب|طلبك)\s+(?:\S+\s+)?اتفتح|(?:تم|اتم)\s+فتح\s+(?:ال)?طلب/u', $assertions);
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
        foreach (['ضمان' => '/بضمان|(?:عليها|عليه|فيها|ليها|معاها|و)\s*ضمان|ضمان\s+(?:سن[ةه]|سنتين|\d|المعرض|الوكيل|شامل|لمد[ةه])/u',
            'ضامن' => '/(?:محتاج|لازم|يجيب|تجيب|هتحتاج|بيحتاج|محتاجين|نحتاج)\s+(?:\S+\s+)?ضامن/u'] as $word => $pattern) {
            if (preg_match($pattern, $replyText) && ! str_contains($normalizedSources, $word)) {
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

    private function asksForScriptedPhrase(string $replyText): bool
    {
        return (bool) preg_match('/(?:قولي|قولّي|قول لي|اكتبلي|اكتب لي|ابعتلي|رد علي[اّ]?|ردلي)\s*(?:بس\s*)?(?:كلم[ةه]\s*)?[«"“\']|(?:قولي|اكتبلي)\s+(?:بس\s+)?(?:أنا|انا)\s+\S+\s+(?:\S+\s+)?(?:عشان|علشان)\s+(?:أقدر|اقدر)/u', $replyText);
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
        if (preg_match('/(?<!\p{L})(?:في|ف|على|ع)\s+(?:ال)?(?:نظام|سيستم)(?=\s*(?:[.،,!؟?\n]|$))/u', $replyText)) {
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
    private function hasUnverifiedNumber(string $replyText, string $system, string $toolResultsBlob, array $contents, bool $earlierRepliesCount = true): bool
    {
        return $this->unverifiedNumbers($replyText, $system, $toolResultsBlob, $contents, $earlierRepliesCount) !== [];
    }

    /**
     * Conversation 302: he wrote "الكاش بقى ٤١٠٠٠" and was told "عندي 39,000"
     * - the figure came from our own reply ten minutes earlier, not from the
     * catalog (which said 41,000). When he brings a price, an earlier reply
     * is no source: the answer needs a lookup now.
     */
    private function customerBringsAPrice(WhatsappConversation $conversation): bool
    {
        $minValue = (float) (config('agent.guard.number_min_value') ?? 1000);
        $text = preg_replace('/(?<!\d)0?1[0125]\d{8}(?!\d)/', ' ', $this->westernDigits($this->customerTextSinceLastReply($conversation))) ?? '';

        foreach ($this->extractNumbers($text) as $number) {
            if ($number >= $minValue && $number < 10000000) {
                return true;
            }
        }

        return false;
    }

    /**
     * The reply's sentences that carry a number no tool result, instruction or
     * earlier message contains - what the runner drops to rescue a reply the
     * model kept sending with one invented figure.
     *
     * @param  array<int, array{name: string, ok: bool, data: array}>  $outcomes
     * @return string[]
     */
    public function unverifiedSentences(string $replyText, string $system, array $contents, array $outcomes): array
    {
        $blob = json_encode(array_column($outcomes, 'data'), JSON_UNESCAPED_UNICODE) ?: '';
        $bad = $this->unverifiedNumbers($replyText, $system, $blob, $contents);

        if ($bad === []) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/(?<=[.!؟?\n])/u', $replyText),
            fn ($sentence) => array_intersect($this->extractNumbers($sentence), $bad) !== []
        ));
    }

    /** @return float[] */
    private function unverifiedNumbers(string $replyText, string $system, string $toolResultsBlob, array $contents, bool $earlierRepliesCount = true): array
    {
        $minValue = config('agent.guard.number_min_value');
        $sourcedSystem = $this->removeBlock($system, '## فهرس الكتالوج');
        // What the customer wrote, and what was already said to them: a
        // price in an earlier reply passed this same check when it went out,
        // so repeating it ("تقصد الفرز التاني بـ 40,000؟") is not a new
        // claim. Blocking it burned the turn's budget on re-lookups.
        $customerText = implode(' ', array_map(
            fn ($c) => implode(' ', array_column(array_filter($c['parts'], fn ($p) => ($p['type'] ?? null) === 'text'), 'text')),
            // When he brings a price, neither his figure nor our earlier one
            // is a source - agreeing with "بقت 35 ألف" is as invented as
            // repeating a stale price. Only a lookup now answers it.
            array_filter($contents, fn ($c) => $earlierRepliesCount && in_array($c['role'] ?? null, ['user', 'model'], true))
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
     * 2026-10-02 (conversations 713, 755): "وعليكم السلام ورحمة الله وبركاته"
     * opened replies to "متاح تقسيط مكن" and "وهدفع مقدم ولا لا" - nobody had
     * said السلام عليكم. The salam is answered only in the turn he said it.
     */
    /** pattern => replacement, applied to every reply before the guards */
    private const WORDING_FIXES = [
        '/تقرّ?ط/u' => 'تقسّط',
        '/(?<!\p{L})عايب(?!\p{L})/u' => 'عايز',
        '/(?<!\p{L})تشري(?!\p{L})/u' => 'تشتري',
        '/(?<!\p{L})(?:ال)?تطبيق\s+(?:ال)?تقسيط/u' => 'طلب التقسيط',
        '/(?:نفتح|افتح|أفتح|هفتح)(?:لك)?\s+(?:ال)?تطبيق(?!\p{L})/u' => 'نبدأ الطلب',
        '/\s*(?:لل|ل)مسنين/u' => '',
        '/(?:ال)?(?:بطاق[ةه]|هوي[ةه])\s+(?:ال)?وطني[ةه]/u' => 'البطاقة',
        '/\bdriving\s+licen[cs]e\b/iu' => 'الرخصة',
        '/\bupfront\b/iu' => 'وقت الاستلام',
        '/\bfront\b/iu' => 'الوش',
        '/\bback\b/iu' => 'الضهر',
        '/\bmismatch\b/iu' => 'مش مطابق',
        '/(مفردات(?:\s+(?:ال)?مرتب)?)\s+(?:(?:حديث[ةه]|جديد[ةه])\s+)?و?(?:مختوم[ةه]|حديث[ةه])(?:\s+(?:من\s+جه[ةه]\s+(?:ال)?عمل|و?مختوم[ةه]))?/u' => '$1',
        '/طريه\s+البطاق/u' => 'طريقة البطاق',
        // a salesman does not call a customer "my daughter / my son"
        '/(?<!\p{L})يا\s+(?:بنتي|بنيتي|ابني|إبني|ابنى)(?!\p{L})/u' => 'يا فندم',
    ];

    /**
     * Wording the owner bans that needs no new model call to fix: emoji,
     * **bold** and stray markdown headers are removed in place - a retry
     * resends the whole context for a character the code can drop.
     */
    public function tidy(array $args): array
    {
        $args['messages'] = array_values(array_filter(array_map(function ($m) {
            $m = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', (string) $m);
            $m = preg_replace('/\*\*(.+?)\*\*/u', '$1', $m);
            $m = preg_replace('/^#{1,6}\s+/mu', '', $m);
            // QA 2026-10-04 (table 2): misspellings, English terms and a hurtful
            // word the model kept writing - corrected here, no new model call.
            $m = preg_replace(array_keys(self::WORDING_FIXES), array_values(self::WORDING_FIXES), $m);
            // "يا صاحب المحل" / "يا أستاذ محاسب": his job is not his name (JOB_AS_NAME) -
            // said as "يا باشا" here instead of a whole new model call.
            $m = preg_replace('/يا\s+(?:(?:أ|ا)ستاذ(?:ة|ه)?\s+|باشمهندس\s+)?(?:(?:ال)?(?:محاسب|مدرس|معلم|ممرض|صيدلي|سواق|نجار|سباك|كهربائي|ميكانيكي|طيار|مندوب|موظف|صنايعي|فران|حداد|نقاش|مبيض|ترزي|حلاق|بياع|كاشير|محامي|مهندس|دليفري)(?:ة|ه)?|صاحب\s+(?:ال)?(?:محل|ورش[ةه]|قهو[ةه]|مطعم|نشاط|مكان))(?!\p{L})/u', 'يا باشا', $m);

            return trim(preg_replace('/[ \t]{2,}/u', ' ', $m));
        }, (array) ($args['messages'] ?? [])), fn ($m) => $m !== ''));

        return $args;
    }

    public function withoutUnpromptedSalam(array $args, WhatsappConversation $conversation, int $turnId): array
    {
        $messages = array_values((array) ($args['messages'] ?? []));

        // Owner 2026-10-02: every greeting came out as the same template with
        // "معاك فريق المبيعات في معرض موتوجيت". Said only when he asks who we are.
        if ($messages !== [] && preg_match('/معاك\s+فريق\s+المبيعات/u', (string) $messages[0])) {
            $asked = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
                ->where('turn_id', $turnId)->where('direction', 'incoming')->pluck('text')
                ->contains(fn ($t) => preg_match('/مين|انت بوت|انت روبوت|بني ?ادم|ai/iu', (string) $t));
            if (! $asked) {
                $messages[0] = trim((string) preg_replace('/[،,.]?\s*(?:أنا\s+|انا\s+)?معاك\s+فريق\s+المبيعات(?:\s+في\s+معرض\s+موتوجيت)?\s*[،,.]?\s*/u', '، ', (string) $messages[0]), " ،,\n");
                $args['messages'] = $messages;
            }
        }

        if ($messages === [] || ! preg_match('/^\s*وعليكم\s+السلام/u', (string) $messages[0])) {
            return $args;
        }

        $said = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('turn_id', $turnId)->where('direction', 'incoming')
            ->get(['text', 'transcript'])
            ->contains(fn ($m) => preg_match('/سلام|salam/iu', (string) $m->text.' '.(string) $m->transcript));

        if ($said) {
            return $args;
        }

        $first = preg_replace('/^\s*وعليكم\s+السلام(?:\s+ورحم[ةه]\s+الله)?(?:\s+وبركاته)?\s*[،,.!]*\s*/u', '', (string) $messages[0]);
        $first = ltrim((string) $first, " \n،,.");

        if ($first === '') {
            array_shift($messages);
        } else {
            $messages[0] = $first;
        }

        if ($messages !== []) {
            $args['messages'] = array_values($messages);
        }

        return $args;
    }

    /**
     * Replayed 2026-10-04: "عايز اقدم" got "دايو 4 على سنتين: أول قسط بعد 45
     * يوم، القسط 2,625..." for the third time in a row before the papers.
     * A sentence with a price that our previous reply already said is
     * dropped in place (no model call) - unless he is asking about a
     * number now, or too little would be left.
     */
    public function withoutRepeatedOffer(array $args, WhatsappConversation $conversation): array
    {
        // our last two replies (several messages each) - a customer back
        // after hours may be reminded of the numbers once
        $previous = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')->where('sender_type', 'bot')->where('created_at', '>=', now()->subHours(6))
            ->latest('id')->limit(6)->pluck('text')->filter()->implode("\n");

        if ($previous === '' || preg_match('/\d|[٠-٩]|(?<!\p{L})(?:كام|بكام|قد\s+ايه|قد\s+إيه|تاني|تانى|اعيد|عيد|يعني)(?!\p{L})/u', $this->customerTextSinceLastReply($conversation))) {
            return $args;
        }

        $minValue = (float) (config('agent.guard.number_min_value') ?? 1000);
        // the model rewords the same offer: the same prices are the same sentence
        $saidNumbers = array_filter($this->extractNumbers($previous), fn ($n) => $n >= $minValue);
        $messages = [];

        foreach ((array) ($args['messages'] ?? []) as $message) {
            $kept = [];

            foreach (preg_split('/(?<=[.!؟?\n])/u', (string) $message) as $sentence) {
                $prices = array_filter($this->extractNumbers($sentence), fn ($n) => $n >= $minValue);

                if ($prices !== [] && array_diff($prices, $saidNumbers) === []) {
                    continue;
                }

                $kept[] = $sentence;
            }

            $text = trim(implode('', $kept));

            if ($text !== '') {
                $messages[] = $text;
            }
        }

        // only when what is left still says something
        return mb_strlen(implode(' ', $messages)) >= 20 ? ['messages' => $messages] + $args : $args;
    }

    /** What the customer wrote since our last reply. */
    private function customerTextSinceLastReply(WhatsappConversation $conversation): string
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

    /** Failures found in the 2026-10-02 review of the last fifteen live conversations. */
    private function conversationFailure(string $replyText, WhatsappConversation $conversation, array $outcomes): ?string
    {
        $calls = array_column($outcomes, 'name');
        $okCalls = array_column(array_filter($outcomes, fn ($o) => $o['ok']), 'name');

        // 748: "ممكن حد يكلمني صوت عشان انا ضعيف في القرايه" got "أنا هنا
        // مخصص للرد كتابة بس". A request for a person or a call is a handoff.
        if (! in_array('handoff_to_human', $okCalls, true) && $conversation->status !== 'awaiting_agent') {
            $customer = \App\Support\ArabicTextNormalizer::normalize($this->customerTextSinceLastReply($conversation));

            if ($customer !== '' && preg_match('/(?:حد|موظف|زميل|بني ?ادم|انسان|مسيول|مسئول|مدير|مندوب)\s+(?:من المعرض\s+)?(?:يكلمني|يكلمنا|يتصل|يرن|يكلمني صوت|اكلمه)|يكلمني صوت|كلمني (?:صوت|فون|تليفون)|كلموني|اتصلوا? بيا|اتصل بيا|رنوا? عليا|عايز اكلم (?:حد|موظف|بني ?ادم|انسان|مدير|مسيول|مسئول|المعرض|خدمه العملا)|محتاج اكلم (?:حد|موظف|بني ?ادم|انسان)|مكالمه (?:صوت|تليفون)|رقم (?:المعرض|الفرع|حد اكلمه|خدمه العملا)/u', $customer)) {
                return 'HUMAN_REQUEST_IGNORED';
            }
        }

        // 713, 758: "القسط كام؟" got "محتاج أفتحلك طلب الأول، ابعتلي صورة
        // البطاقة وبعدها أقولك" - numbers never wait for an application.
        $quoted = (bool) array_filter($outcomes, fn ($o) => $o['ok'] && in_array($o['name'], ['get_installment_offer', 'calculate_installment'], true) && isset($o['data']['offers']));
        if (! $quoted && preg_match('/(?:عشان|علشان|وبعدها|بعدها|بعد كده)\s+(?:\S+\s+){0,3}?(?:احسبلك|أحسبلك|نحسبلك|اقولك|أقولك|هقولك|نقولك|اديك|أديك)\s+(?:\S+\s+){0,2}?(?:القسط|الاقساط|الأقساط|تفاصيل|كل التفاصيل|المصاريف|الأنظمة|الانظمه)/u', $replyText)
            && preg_match('/(?:افتحلك|أفتحلك|نفتح|فتح|نكمل بيانات)\s+(?:\S+\s+)?(?:طلب|الطلب|ملف)|صور[ةه]\s+(?:وش|البطاق)|البطاق[ةه]\s+(?:وش|الشخصي)/u', $replyText)) {
            return 'QUOTE_GATED';
        }

        // 2026-10-03 simulator: a shop owner's application opened and he was
        // asked only for "وش وضهر البطاقة" - the shop photo and the tax card
        // came up one by one later. The owner: the whole list in one message.
        if ($missing = $this->documentsLeftOutOfList($replyText, $outcomes)) {
            return 'DOCUMENT_LIST_INCOMPLETE';
        }

        // 748, 752, 735: our own summary went out and then the stored one -
        // the customer got his data twice. "(سيتم إرسال ملخص...)" too.
        if (preg_match('/\((?:سيتم|يرجى|ملاحظ)|يرجى\s|سيتم\s+إرسال/u', $replyText)) {
            return 'META_TEXT';
        }
        $confirmationPending = (bool) array_filter($outcomes, fn ($o) => $o['name'] === 'submit_application' && ! $o['ok'] && ($o['data']['code'] ?? null) === 'CUSTOMER_CONFIRMATION_REQUIRED');
        if ($confirmationPending && preg_match_all('/(?:^|\n)\s*(?:•|-|–|\d+[.)])\s*\S/u', $replyText) >= 3) {
            return 'SUMMARY_DUPLICATED';
        }

        return null;
    }

    /** The documents.list items a reply asking for papers right after opening the application leaves out. */
    private function documentsLeftOutOfList(string $replyText, array $outcomes): array
    {
        $opened = null;

        foreach ($outcomes as $o) {
            if ($o['ok'] && $o['name'] === 'start_application' && ($o['data']['created'] ?? false) === true) {
                $opened = $o['data']['snapshot']['documents'] ?? null;
            }
        }

        $reply = \App\Support\ArabicTextNormalizer::normalize($replyText);

        if (! is_array($opened) || ($opened['list_complete'] ?? false) !== true || ! preg_match('/بطاق|صوره|صور /u', $reply)) {
            return [];
        }

        // normalized like the items ("بطاقة الرقم القومي" → "بطاقه رقم قومي")
        $common = array_map(fn ($w) => \App\Support\ArabicTextNormalizer::normalize($w),
            ['صوره', 'بطاقه', 'الرقم', 'القومي', 'الشخصيه', 'ضهر', 'لمده', 'شهور', 'اخر', 'الخاص', 'بتاع']);
        $missing = [];

        foreach ((array) ($opened['list'] ?? []) as $item) {
            $words = array_filter(preg_split('/[\s\/()،,]+/u', \App\Support\ArabicTextNormalizer::normalize((string) $item)),
                fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, $common, true));

            if ($words === []) {
                continue; // the ID - "البطاقة" covers it
            }

            // "صورة المحل باليافطة" names the business place photo
            $found = str_contains(implode(' ', $words), 'مكان') && preg_match('/محل|ورش|يافط|مطعم|مكان|نشاط/u', $reply);

            foreach ($words as $word) {
                $stem = preg_replace('/^(?:ال|لل)/u', '', $word);
                $found = $found || mb_strpos($reply, mb_substr($stem, 0, max(3, mb_strlen($stem) - 1))) !== false;
            }

            if (! $found) {
                $missing[] = (string) $item;
            }
        }

        return $missing;
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
