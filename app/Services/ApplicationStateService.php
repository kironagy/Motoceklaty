<?php

namespace App\Services;

use App\Support\AddressParser;

/**
 * Deterministic field-completeness logic for the installment application
 * flow, replacing the binary complete/incomplete address guessing that
 * used to live entirely inside an LLM prompt (AiIntentClassifier::extractApplicationData's
 * line-order heuristic). Address completeness is now computed from actual
 * structured components (see AddressParser), so a partial address only
 * ever gets asked about for the specific piece that's missing.
 */
class ApplicationStateService
{
    public const REQUIRED_FIELDS = [
        'full_name',
        'national_id',
        'phone',
        'job_type',
        'income_proof',
        'work_address',
        'home_address',
        'installment_months',
    ];

    private const ADDRESS_FIELDS = ['work_address', 'home_address'];

    /**
     * Sentinel value for "no fixed workplace" (delivery/gig workers,
     * customers who work from home, etc.) - same idea as income_proof's
     * "لا يوجد" but for work_address. Set by AiIntentClassifier's
     * extraction prompt when the customer explicitly denies having a
     * workplace; treated as a satisfied field, not an incomplete address.
     */
    public const NO_WORKPLACE = 'لا يوجد';

    /**
     * Fields where a changed value is unambiguously a conflict worth
     * pausing for, not a legitimate in-place correction/addition. Scoped
     * deliberately narrow: unlike a name or an address (which customers
     * legitimately extend across messages), a phone number or national ID
     * that changes value is either a typo-fix or two different real
     * numbers - either way, silently picking one is the wrong call.
     */
    private const CONFLICT_FIELDS = ['phone', 'national_id'];

    private const MISSING_COMPONENT_LABELS = [
        'area_or_governorate' => 'المنطقة',
        'street' => 'اسم الشارع',
        'building' => 'رقم العمارة',
        'floor' => 'الدور',
        'apartment' => 'رقم الشقة',
        'landmark' => 'علامة مميزة قريبة من العنوان',
        'ownership' => 'السكن ده ملكك ولا إيجار',
    ];

    public function __construct(private readonly AddressParser $addressParser)
    {
    }

    /**
     * Re-derives address components/status for every address field that
     * has a value, merging any newly-mentioned components on top of
     * whatever was already known (so a customer can fill in an address
     * across several messages without earlier components being lost).
     * Also tracks which components were newly added *this specific turn*
     * (`{$field}_newly_received_components`) so questionForMissing() can
     * say "حضرتك بعت اسم الشارع" instead of only listing what's still
     * missing. Call this right after merging freshly-extracted values in,
     * before missingFields()/questionForMissing().
     */
    public function refreshAddressComponents(array $application): array
    {
        foreach (self::ADDRESS_FIELDS as $field) {
            $text = (string) ($application[$field] ?? '');

            if (trim($text) === '') {
                continue;
            }

            /*
             * "لا يوجد" هنا معناها العميل صرّح إنه معندوش مكان شغل ثابت
             * (دليفري، سواق تطبيقات، شغال متنقل...) - مش عنوان ناقص محتاج
             * نفصّله لمكوّناته. لو سبناها تعدي على addressParser، هتترجع
             * "incomplete" لأنها مفيهاش شارع/عمارة، فالبوت هيفضل يسأل عن
             * عنوان شغل مش موجود أصلاً من غير ما ينتهي أبدًا.
             */
            if (trim($text) === self::NO_WORKPLACE) {
                $application["{$field}_status"] = 'complete';
                $application["{$field}_missing_components"] = [];
                $application["{$field}_newly_received_components"] = [];

                continue;
            }

            $componentsKey = "{$field}_components";
            $known = $application[$componentsKey] ?? [];
            $extracted = $this->addressParser->parse($text);

            // Newly extracted, non-empty components win; previously known
            // components are kept when this turn didn't mention them.
            $merged = $known;
            $newlyReceived = [];

            foreach ($extracted as $component => $value) {
                if (! filled($value)) {
                    continue;
                }

                /*
                 * حماية إضافية فوق حماية AddressParser: مكوّن اتمسك
                 * بثقة قبل كده (زي اسم شارع كامل) ممنوع يتبدّل بنص
                 * أطول ومبهم جاي من رسالة إجابة قصيرة. الاستثناء
                 * الوحيد هو ownership - دي إجابة صريحة والعميل ليه
                 * حق يغيّرها.
                 */
                if (
                    $component !== 'ownership'
                    && isset($known[$component])
                    && filled($known[$component])
                    && mb_strlen((string) $value) > mb_strlen((string) $known[$component]) * 2
                ) {
                    continue;
                }

                if (! isset($known[$component]) || $known[$component] !== $value) {
                    // city/area/governorate all map to the single
                    // "المنطقة" label used everywhere else (status(),
                    // MISSING_COMPONENT_LABELS) - report under that same
                    // grouped name instead of leaking the raw parser key.
                    $reportedComponent = in_array($component, ['city', 'area', 'governorate'], true)
                        ? 'area_or_governorate'
                        : $component;

                    if (! in_array($reportedComponent, $newlyReceived, true)) {
                        $newlyReceived[] = $reportedComponent;
                    }
                }

                $merged[$component] = $value;
            }

            /*
             * "ملك ولا إيجار" مطلوب لعنوان السكن بس - مش ليه علاقة
             * بمكان الشغل.
             */
            $status = $this->addressParser->status($merged, $field === 'home_address');

            $application[$componentsKey] = $merged;
            $application["{$field}_status"] = $status['status'] === 'complete' ? 'complete' : 'incomplete';
            $application["{$field}_missing_components"] = $status['missing'];
            $application["{$field}_newly_received_components"] = $newlyReceived;
        }

        return $application;
    }

    /**
     * تربط رد العميل المجرد بالمكوّن اللي إحنا سألنا عنه في الدور اللي فات.
     *
     * المشكلة اللي بتحلها:
     * البوت بيسأل "لسه محتاج علامة مميزة قريبة من العنوان"، والعميل يرد
     * "سوبر ماركت الاخوه". الكود القديم كان بيرمي الرد على AddressParser
     * كأنه عنوان جديد كامل - فمكانش بيلاقي كلمة مفتاحية للاندمارك،
     * والرد يضيع، والبوت يسأل نفس السؤال تاني. ده كان أوضح سبب إن
     * البوت يبان إنه مش فاهم.
     *
     * القاعدة هنا: لو إحنا سألنا عن مكوّن واحد بالظبط، والرسالة الجاية
     * قصيرة ومفيهاش أي كلمة عنوان مفتاحية تانية، فهي إجابة عليه - حتى لو
     * العميل ما استخدمش الكلمة المفتاحية.
     *
     * @param  string  $field  work_address أو home_address
     * @param  string|null  $askedComponent  المكوّن اللي سألنا عنه الدور اللي فات
     * @return array<string, mixed> الـ application بعد التعديل
     */
    public function bindAnswerToAskedComponent(
        array $application,
        string $field,
        ?string $askedComponent,
        string $message
    ): array {
        if ($askedComponent === null || ! in_array($field, self::ADDRESS_FIELDS, true)) {
            return $application;
        }

        $answer = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);

        if ($answer === '') {
            return $application;
        }

        // رد طويل قوي = العميل بعت عنوان كامل، سيب الـ parser يشتغل عادي.
        if (mb_strlen($answer) > 60) {
            return $application;
        }

        $componentsKey = "{$field}_components";
        $components = $application[$componentsKey] ?? [];

        // المكوّن اتملى خلاص من الـ parser في نفس الدور - مفيش داعي.
        if (filled($components[$askedComponent] ?? null)) {
            return $application;
        }

        $value = match ($askedComponent) {
            'ownership' => $this->readOwnershipAnswer($answer),
            'building', 'floor', 'apartment' => $this->readShortValueAnswer($answer),
            default => $this->cleanFreeTextAnswer($answer),
        };

        if (! filled($value)) {
            return $application;
        }

        if ($askedComponent === 'area_or_governorate') {
            $components['area'] = $value;
        } else {
            $components[$askedComponent] = $value;
        }

        $application[$componentsKey] = $components;

        $status = $this->addressParser->status($components, $field === 'home_address');

        $application["{$field}_status"] = $status['status'] === 'complete' ? 'complete' : 'incomplete';
        $application["{$field}_missing_components"] = $status['missing'];
        $application["{$field}_newly_received_components"] = [$askedComponent];

        return $application;
    }

    /**
     * قراءة حتمية لرد العميل على سؤال عنوان طلب أكتر من مكوّن.
     *
     * bindAnswerToAskedComponent() بتشتغل لما نكون سألنا عن مكوّن **واحد**
     * بالظبط. لكن السؤال الشائع بيطلب أكتر من مكوّن ("لسه محتاج رقم
     * العمارة والدور ورقم الشقة وعلامة مميزة")، والرد بيبقى جزء من عنوان
     * ("فيلا ١١٥" / "عماره ١٢ إيجار"). ساعتها إحنا معتمدين بالكامل على إن
     * استخراج الـ LLM يرجّع العنوان المدمّج - ولما بيرجع فاضي (وده بيحصل)،
     * الرد بيضيع خالص والبوت بيعيد نفس السؤال حرفيًا. ده كان أوضح سبب إن
     * العميل يحس إن البوت مش بيقرا.
     *
     * القاعدة: بنقرا الرد بالـ parser، وبنملا بيه المكوّنات **الفاضية بس**
     * (مش بنستبدل حاجة اتعرفت قبل كده)، وبنضيف نص الرد على نص العنوان
     * المحفوظ عشان السجل النهائي يبقى كامل.
     */
    public function absorbAddressAnswer(array $application, string $field, string $message): array
    {
        if (! in_array($field, self::ADDRESS_FIELDS, true)) {
            return $application;
        }

        $answer = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);

        // رد طويل قوي = عنوان كامل جديد، الـ parser العادي بيتعامل معاه.
        if ($answer === '' || mb_strlen($answer) > 140) {
            return $application;
        }

        $parsed = array_filter($this->addressParser->parse($answer), fn ($value) => filled($value));

        if (empty($parsed)) {
            return $application;
        }

        $componentsKey = "{$field}_components";
        $known = $application[$componentsKey] ?? [];

        $newlyReceived = [];

        foreach ($parsed as $component => $value) {
            // ownership إجابة صريحة والعميل ليه حق يغيّرها؛ باقي المكوّنات
            // اللي اتعرفت قبل كده ممنوع رد قصير يمسحها.
            if ($component !== 'ownership' && filled($known[$component] ?? null)) {
                continue;
            }

            if (($known[$component] ?? null) === $value) {
                continue;
            }

            $known[$component] = $value;

            $reported = in_array($component, ['city', 'area', 'governorate'], true)
                ? 'area_or_governorate'
                : $component;

            if (! in_array($reported, $newlyReceived, true)) {
                $newlyReceived[] = $reported;
            }
        }

        if (empty($newlyReceived)) {
            return $application;
        }

        $existing = trim((string) ($application[$field] ?? ''));

        if ($existing === '') {
            $application[$field] = $answer;
        } elseif (mb_stripos($existing, $answer) === false) {
            $application[$field] = $existing . ' - ' . $answer;
        }

        $application[$componentsKey] = $known;

        $status = $this->addressParser->status($known, $field === 'home_address');

        $application["{$field}_status"] = $status['status'] === 'complete' ? 'complete' : 'incomplete';
        $application["{$field}_missing_components"] = $status['missing'];
        $application["{$field}_newly_received_components"] = $newlyReceived;

        return $application;
    }

    /**
     * الاستخراج بالـ LLM أحيانًا بيهلوس في نص عنوان طويل: العميل بيبعت
     * "٦ اكتوبر ١٥ أ مربع ٣ فيلا ١١٥" (عنوان ناقص وحقيقي)، والـ LLM
     * بيرجّع home_address = نفس النص + "رقم العماره ٢ والدور التاني شقه
     * ١٥ امام سوبر مركت بيم، إيجار" - تفاصيل مختلقة العميل ماكتبهاش
     * خالص، ومعاها home_address_status = "complete" كذب. ده مش تصحيح
     * إملائي ولا تلخيص - ده اختراع بيانات طلب تمويل حقيقي.
     *
     * الحل: منسيبش نص العنوان المخزّن يعتمد على إعادة صياغة الـ LLM
     * خالص. بعد كل استخراج، بنعيد بناء المكوّنات من **رسالة العميل
     * الخام نفسها** بالـ parser الحتمي، ونراكم النص المعروض من كلام
     * العميل الفعلي بس - مش من نص الموديل. لو رسالة الدور ده مفيهاش أي
     * مكوّن عنوان حقيقي (parse رجع فاضي)، منلمسش الحقل خالص بدل ما نقبل
     * نص الموديل كمصدر وحيد.
     */
    public function groundAddressInRawMessage(array $application, string $field, string $message): array
    {
        if (! in_array($field, self::ADDRESS_FIELDS, true)) {
            return $application;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);

        if ($text === '') {
            return $application;
        }

        $parsed = array_filter($this->addressParser->parse($text), fn ($value) => filled($value));

        if (empty($parsed)) {
            return $application;
        }

        $componentsKey = "{$field}_components";
        $known = $application[$componentsKey] ?? [];

        foreach ($parsed as $component => $value) {
            // ownership إجابة صريحة والعميل ليه حق يغيّرها؛ باقي المكوّنات
            // اللي اتعرفت قبل كده من رسالة خام سابقة ممنوع تتبدّل.
            if ($component !== 'ownership' && filled($known[$component] ?? null)) {
                continue;
            }

            $known[$component] = $value;
        }

        $application[$componentsKey] = $known;

        /*
         * "{field}_raw" هو تراكم كلام العميل الخام بس عبر الأدوار - أبدًا
         * نص الموديل. application[$field] العادي بيتبنى منه، عشان أي
         * كود تاني بيقرا application[$field] (رسالة المستندات، ملخص
         * الطلب) يشوف كلام العميل الحقيقي.
         */
        $existingRaw = trim((string) ($application["{$field}_raw"] ?? ''));

        if ($existingRaw === '') {
            $application[$field] = $text;
        } elseif (mb_stripos($existingRaw, $text) === false) {
            $application[$field] = $existingRaw . ' - ' . $text;
        } else {
            $application[$field] = $existingRaw;
        }

        $application["{$field}_raw"] = $application[$field];

        return $application;
    }

    private function readOwnershipAnswer(string $answer): ?string
    {
        $folded = str_replace(['ة', 'ى', 'أ', 'إ', 'آ'], ['ه', 'ي', 'ا', 'ا', 'ا'], mb_strtolower($answer));

        foreach (['ملك', 'تمليك', 'مالك', 'بتاعي', 'بتاعنا'] as $word) {
            if (str_contains($folded, $word)) {
                return 'ملك';
            }
        }

        foreach (['ايجار', 'مستاجر', 'مؤجر', 'بالايجار'] as $word) {
            if (str_contains($folded, $word)) {
                return 'إيجار';
            }
        }

        return null;
    }

    /** رقم عمارة/دور/شقة: بناخد أول رقم أو أول كلمة ترتيبية. */
    private function readShortValueAnswer(string $answer): ?string
    {
        $latin = str_replace(
            ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
            ['0','1','2','3','4','5','6','7','8','9'],
            $answer
        );

        if (preg_match('/\d{1,4}/u', $latin, $m)) {
            return $m[0];
        }

        $ordinals = ['الاول', 'الأول', 'التاني', 'الثاني', 'التالت', 'الثالث',
                     'الرابع', 'الخامس', 'السادس', 'السابع', 'التامن', 'الثامن',
                     'ارضي', 'أرضي', 'الارضي'];

        foreach ($ordinals as $ordinal) {
            if (str_contains($answer, $ordinal)) {
                return $ordinal;
            }
        }

        return null;
    }

    /** علامة مميزة / شارع / منطقة: بنشيل كلمات الحشو بس. */
    private function cleanFreeTextAnswer(string $answer): ?string
    {
        $fillers = [
            'والله', 'يعني', 'انا', 'أنا', 'ساكن', 'ساكنه', 'ساكنة',
            'العلامه المميزه', 'العلامة المميزة', 'علامه مميزه', 'علامة مميزة',
            'قدام', 'جنب', 'جمب', 'بجوار', 'امام', 'أمام', 'قرب', 'في', 'هي',
        ];

        $cleaned = $answer;

        foreach ($fillers as $filler) {
            $cleaned = preg_replace('/(?:^|\s)' . preg_quote($filler, '/') . '(?:\s|$)/u', ' ', $cleaned) ?? $cleaned;
        }

        $cleaned = trim(preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned);

        return mb_strlen($cleaned) >= 2 ? $cleaned : null;
    }

    /**
     * Reported bug: a customer already mid-way through one address field
     * (e.g. work_address = "٦ أكتوبر", still incomplete) sends a further
     * address-shaped line with no explicit field marker ("عمارة 4 الدور
     * 2"). The extraction LLM only sees each field's raw text - it has no
     * concept of "this field already has SOME value but is still
     * incomplete", so it tends to route new address text to whichever
     * field is still literally null, which is wrong when that null field
     * was never the one being filled in.
     *
     * This is a deterministic safety net, not a prompt-engineering fix:
     * when exactly one address field is known-but-incomplete and the
     * extraction left that field's own text untouched while putting new
     * text into the other (previously empty) address field, redirect it
     * back to the field actually in progress.
     */
    public function reconcileAddressAssignment(array $application, array $extracted): array
    {
        $incomplete = array_values(array_filter(self::ADDRESS_FIELDS, function ($field) use ($application) {
            return filled($application[$field] ?? null) && ($application["{$field}_status"] ?? null) === 'incomplete';
        }));

        if (count($incomplete) !== 1) {
            return $extracted;
        }

        $target = $incomplete[0];
        $other = $target === 'work_address' ? 'home_address' : 'work_address';

        $targetTouched = array_key_exists($target, $extracted)
            && filled($extracted[$target])
            && $extracted[$target] !== $application[$target];

        $otherGivenNewText = array_key_exists($other, $extracted)
            && filled($extracted[$other])
            && empty($application[$other] ?? null);

        if (! $targetTouched && $otherGivenNewText) {
            $extracted[$target] = $extracted[$other];
            $extracted[$other] = null;
            unset($extracted["{$other}_status"]);
        }

        return $extracted;
    }

    /**
     * Same required-field list and freelance exemption as before, but
     * address completeness now comes from refreshAddressComponents()'s
     * deterministic status instead of an LLM-assigned string. $isFreelance
     * is computed by the caller (ApplicationHandler::categorizeIncome) so
     * the "who counts as freelance" keyword list keeps a single owner
     * instead of drifting between two copies.
     */
    public function missingFields(array $application, bool $isFreelance, bool $requiresVehicle = false): array
    {
        $fields = self::REQUIRED_FIELDS;

        /*
         * سواقين التطبيقات والدليفري: المستندات المطلوبة منهم بتتغير
         * بالكامل حسب المركبة (العجلة مش محتاجة رخصة أصلاً، الموتوسيكل
         * والعربية لازم رخصة سارية)، فمينفعش نعدّي لمرحلة المستندات وإحنا
         * مش عارفين هو شغال على إيه. لباقي الفئات الحقل ده مالوش لازمة
         * ومش بيتسأل.
         */
        if ($requiresVehicle) {
            $fields[] = 'work_vehicle';
        }

        return array_values(array_filter($fields, function ($key) use ($application, $isFreelance) {
            if ($key === 'income_proof' && $isFreelance) {
                return false;
            }

            if (empty($application[$key])) {
                return true;
            }

            if (in_array($key, self::ADDRESS_FIELDS, true) && ($application["{$key}_status"] ?? null) === 'incomplete') {
                return true;
            }

            return false;
        }));
    }

    /**
     * Whether the category's requirements note can go out yet.
     *
     * For a courier the requirements are not one list - they branch on
     * what they ride. Someone on a bicycle is never asked for a driving
     * licence, someone on a motorcycle always is. Sending the note
     * before the vehicle is known means the first thing a bicycle
     * courier hears is a licence requirement that will never apply to
     * them, which is what happened in conversation 254: he answered
     * "مش معايا رخصه" and read himself as rejected over a document we
     * were never going to ask him for.
     *
     * work_vehicle is already a required field for this category for
     * exactly that reason (see missingFields()); the note waits on the
     * same answer.
     *
     * @param  array<string, mixed>  $application
     */
    public function shouldSendCategoryNote(string $category, array $application): bool
    {
        if (! in_array($category, ['delivery', 'taxi_owner'], true)) {
            return true;
        }

        return ! empty($application['work_vehicle']);
    }

    /**
     * Which missing fields this turn's question is allowed to cover.
     *
     * A field the verifier rejected (a two-part "full name", a national
     * ID whose checksum does not resolve, an address that reads as
     * fiction) is not a field to step over - it IS the open question,
     * and its rejection message already asks it in the customer's own
     * terms. Asking for the NEXT field in the same breath is what broke
     * conversation 254: the turn that rejected "احمد سيد" closed by
     * asking for the national ID, so the corrected full name that came
     * back was read against the wrong question and dropped by
     * extraction. Nothing landed, the issue never cleared, and the two
     * messages alternated until the customer gave up.
     *
     * So: while any verification issue is open, that issue is the whole
     * turn.
     *
     * @param  array<int, string>  $missing
     * @param  array<string, string>  $verificationIssues  field => rejection message
     * @return array<int, string>
     */
    public function fieldsToAsk(array $missing, array $verificationIssues): array
    {
        if (! empty($verificationIssues)) {
            return [];
        }

        return array_values($missing);
    }

    private const FIELD_LABELS = [
        'full_name' => 'الاسم بالكامل',
        'national_id' => 'الرقم القومي',
        'phone' => 'رقم الموبايل',
        'job_type' => 'طبيعة شغلك',
        'income_proof' => 'إثبات الدخل',
        'work_address' => 'عنوان الشغل',
        'home_address' => 'عنوان السكن',
        'installment_months' => 'مدة التقسيط',
        'work_vehicle' => 'نوع المركبة',
    ];

    private const FIELD_LABELS_DETAILED = [
        'full_name' => 'الاسم بالكامل',
        'national_id' => 'الرقم القومي',
        'phone' => 'رقم الموبايل',
        'job_type' => 'طبيعة شغلك',
        'income_proof' => 'إثبات دخل (مفردات مرتب أو غيرها لو متاح)',
        'work_address' => 'عنوان الشغل بالتفصيل',
        'home_address' => 'عنوان السكن بالتفصيل',
        'installment_months' => 'مدة التقسيط اللي تحبها',
        'work_vehicle' => 'بتشتغل على إيه دلوقتي: عجلة ولا موتوسيكل ولا عربية',
    ];

    /**
     * Opening line used only when nothing new was received this turn
     * (no acknowledgment to prepend) AND this isn't the very first ask
     * (streak > 0) - e.g. the customer sent something irrelevant, or a
     * message that failed to extract any field. The very first ask
     * (streak === 0, nothing received yet) always uses index 0's plain
     * opener - there is nothing to vary yet, varying it would be noise.
     */
    private const NO_PROGRESS_OPENERS = [
        'تمام يا فندم، ناقصني البيانات دي عشان أكمل طلب التقديم:',
        'لسه محتاج منك البيانات دي يا فندم عشان أقدر أكمل طلب التقديم:',
        'عشان نكمل الطلب يا فندم، لسه ناقصني البيانات دي:',
    ];

    /**
     * Builds the missing-info question. Three things make this progress-
     * aware instead of a static template repeated every turn:
     *
     * 1. $newlyFilled - fields that were missing last turn and got
     *    completed this turn - produces an opening acknowledgment
     *    ("تمام يا فندم، استلمت..."). Without this the bot silently
     *    absorbed the customer's data and re-asked as if nothing had
     *    happened, which reads as not having listened at all.
     * 2. When exactly one address field is the only thing missing AND
     *    it's partial (some components already known), asks only for the
     *    specific missing component(s) instead of "عنوان السكن بالتفصيل"
     *    again - this is what makes "محتاج رقم العمارة بس" possible
     *    instead of re-asking for the whole address.
     * 3. $noProgressStreak - how many turns in a row produced nothing new
     *    - rotates the opening line so a stuck conversation doesn't read
     *    the exact same sentence over and over (see Section 5/6 of
     *    AI_MEMORY_CONVERSATION_IMPROVEMENT_PLAN.md: vary wording only
     *    when repetition isn't logically necessary, never touch the
     *    actual missing-field list itself).
     *
     * @param  string|null  $askedComponent  بيترجع فيه اسم المكوّن اللي
     *   السؤال ده بيطلبه، لما يبقى مكوّن واحد بالظبط. الراوتر بيحفظه في
     *   context_payload عشان الدور اللي بعده يعرف الرد المجرد إجابة على
     *   إيه (شوف bindAnswerToAskedComponent).
     */
    public function questionForMissing(
        array $missing,
        array $application,
        array $newlyFilled = [],
        int $noProgressStreak = 0,
        array $labelOverrides = [],
        bool $hasAskedBefore = false,
        ?string &$askedComponent = null,
        ?string &$askedField = null
    ): string {
        $askedComponent = null;
        $askedField = null;
        $acknowledgment = '';

        if (! empty($newlyFilled)) {
            /*
             * قرار من صاحب المعرض: ممنوع نعدّد اللي استلمناه. "تمام يا
             * فندم، استلمت اسم الشارع ورقم العمارة" بتخلي الرسالة طويلة
             * وبتحس إنها روبوت بيقرا تقرير - العميل عايز يعرف اللي ناقص
             * وخلاص. بنسيب بس تأكيد قصير جدًا لما الطلب يكتمل بالفعل
             * (empty($missing))، لأن ساعتها الرسالة محتاجة تقفل بحاجة
             * إيجابية.
             */
            if (empty($missing)) {
                $newlyFilledLabels = array_map(
                    fn ($key) => self::FIELD_LABELS[$key] ?? $key,
                    $newlyFilled
                );

                return 'تمام يا فندم، استلمت ' . implode(' و', $newlyFilledLabels) . '.';
            }

            $acknowledgment = 'تمام يا فندم.';
        }

        if (count($missing) === 1 && in_array($missing[0], self::ADDRESS_FIELDS, true)) {
            $field = $missing[0];
            $addressLabel = $field === 'home_address' ? 'عنوان السكن' : 'عنوان الشغل';
            $missingComponents = $application["{$field}_missing_components"] ?? [];

            if (! empty($application[$field]) && ! empty($missingComponents)) {
                $componentLabels = array_map(
                    fn ($component) => self::MISSING_COMPONENT_LABELS[$component] ?? $component,
                    $missingComponents
                );

                /*
                 * استلمت X، بس محتاج Y - مش بس "محتاج Y" - عشان العميل
                 * يعرف إن اللي بعته اتسجل فعلاً، مش بس اتجاهل وطلعله نفس
                 * سؤال العنوان تاني. صيغة جملة طبيعية بدل قوسين تقنيين.
                 */
                $newlyReceivedComponents = $application["{$field}_newly_received_components"] ?? [];

                /*
                 * لو السؤال بيطلب مكوّن واحد بالظبط، نسجّله عشان الدور
                 * اللي بعده يعرف إن رد العميل المجرد ("سوبر ماركت
                 * الاخوه") هو إجابة على السؤال ده - مش عنوان جديد.
                 */
                /*
                 * الحقل بيتسجّل دايمًا، حتى لو السؤال بيطلب أكتر من
                 * مكوّن. الاستخراج بالـ LLM ساعات بيرجع فاضي على رد زي
                 * "فيلا ١١٥"، وساعتها absorbAddressAnswer() بتقرا الرد
                 * قراءة حتمية وتحطه في نفس الحقل - من غير كده الرد كان
                 * بيضيع والبوت يعيد نفس السؤال حرفيًا.
                 */
                $askedField = $field;

                if (count($missingComponents) === 1) {
                    $askedComponent = $missingComponents[0];
                }

                $missingText = implode(' و', $componentLabels);

                /*
                 * قرار من صاحب المعرض: ممنوع نعدّد اللي استلمناه. "استلمت
                 * منك اسم الشارع ورقم العمارة، بس لسه محتاج علامة مميزة"
                 * بتخلي الرسالة طويلة وبتحس إنها روبوت بيقرا تقرير -
                 * العميل عايز يعرف اللي ناقص وخلاص.
                 */
                $line = "لسه محتاج {$missingText} في {$addressLabel}.";

                return $acknowledgment !== ''
                    ? "{$acknowledgment} {$line}"
                    : "تمام يا فندم، {$line}";
            }
        }

        /*
         * لو أكتر من حقل ناقص وواحد منهم عنوان اتبعت بيانات جزئية له
         * (زي "٦ أكتوبر" لما عنوان الشغل والسكن كانوا فاضيين، فاتحطت في
         * الأول بس)، السطر بتاعه في القايمة لازم يوضح المكوّن الناقص
         * بالظبط بدل ما يرجع للتسمية العامة "بالتفصيل" اللي كأنها متجاهلة
         * إن العميل بعت حاجة أصلاً.
         */
        $items = array_map(fn ($key) => $this->missingFieldLine($key, $application, $labelOverrides), $missing);

        if (count($items) === 1) {
            return $acknowledgment !== ''
                ? "{$acknowledgment} ولسه ناقصني {$items[0]} عشان أكمل طلب التقديم."
                : 'تمام يا فندم، ناقصني ' . $items[0] . ' عشان أكمل طلب التقديم.';
        }

        /*
         * A person collecting data asks for the next thing, not for all
         * seven things again. Re-sending the identical bulleted list on
         * every turn (which is what this did) is the single most robotic
         * thing in the flow - it was sent five times verbatim in
         * conversation 252 and reads as if nothing the customer typed was
         * ever read. The full list still goes out once, the first time, so
         * expectations are set; after that we ask for the next item and
         * only say how many are left.
         */
        if ($hasAskedBefore) {
            $next = $items[0];
            $remaining = count($items) - 1;
            $tail = $remaining > 0
                ? ' (وبعدها فاضل ' . $remaining . ' ' . ($remaining === 1 ? 'بيان' : 'بيانات') . ' بس)'
                : '';

            if ($acknowledgment !== '') {
                return "{$acknowledgment}\n\nطيب محتاج منك دلوقتي {$next}{$tail}.";
            }

            /*
             * NO_PROGRESS_OPENERS are written for the full bulleted list
             * ("ناقصني البيانات دي:") and read wrong in front of a single
             * request, so the one-at-a-time path has its own variants.
             */
            $singleOpeners = [
                'لسه مستنى منك',
                'تمام يا فندم، محتاج منك',
                'عشان نكمل الطلب، محتاج منك',
            ];

            $opener = $singleOpeners[$noProgressStreak % count($singleOpeners)];

            return "{$opener} {$next}{$tail}.";
        }

        /*
         * كل بند على سطر لوحده مسبوق بـ"•" بدل "-" وبعده سطر فاضي - أسهل
         * في القراءة على واتساب من فقرة طويلة متلاصقة، خصوصًا لما بند
         * العنوان نفسه جملة طويلة فيها أكتر من مكوّن ناقص.
         */
        $list = implode("\n\n", array_map(fn ($item) => "• {$item}", $items));

        if ($acknowledgment !== '') {
            return "{$acknowledgment}\n\nولسه ناقصني:\n\n{$list}";
        }

        $opener = $noProgressStreak > 0
            ? self::NO_PROGRESS_OPENERS[$noProgressStreak % count(self::NO_PROGRESS_OPENERS)]
            : self::NO_PROGRESS_OPENERS[0];

        return "{$opener}\n\n{$list}";
    }

    /**
     * One missing-field's display line. An address field that already
     * has some components known (e.g. the customer answered a single,
     * ambiguous line like "٦ أكتوبر" that landed in only one of the two
     * address fields) names the specific missing component(s) instead of
     * the generic "بالتفصيل" label, so the customer can see the message
     * actually understood what they sent.
     */
    private function missingFieldLine(string $key, array $application, array $labelOverrides = []): string
    {
        if (isset($labelOverrides[$key])) {
            return $labelOverrides[$key];
        }


        if (in_array($key, self::ADDRESS_FIELDS, true)) {
            $missingComponents = $application["{$key}_missing_components"] ?? [];

            if (! empty($application[$key]) && ! empty($missingComponents)) {
                $addressLabel = $key === 'home_address' ? 'عنوان السكن' : 'عنوان الشغل';
                $componentLabels = array_map(
                    fn ($component) => self::MISSING_COMPONENT_LABELS[$component] ?? $component,
                    $missingComponents
                );

                $newlyReceived = $application["{$key}_newly_received_components"] ?? [];

                if (! empty($newlyReceived)) {
                    $receivedLabels = array_map(
                        fn ($component) => self::MISSING_COMPONENT_LABELS[$component] ?? $component,
                        $newlyReceived
                    );

                    return "*{$addressLabel}*: استلمت منك {$this->componentList($receivedLabels)}، بس لسه محتاج {$this->componentList($componentLabels)}";
                }

                return "*{$addressLabel}*: لسه محتاج {$this->componentList($componentLabels)}";
            }
        }

        $label = self::FIELD_LABELS_DETAILED[$key] ?? $key;

        return "*{$label}*";
    }

    private function componentList(array $labels): string
    {
        return implode(' و', $labels);
    }

    /**
     * Compares already-known, previously-COMPLETE values against freshly
     * extracted ones for the conflict-sensitive fields. Only a genuinely
     * different, non-empty new value on a field that already had a
     * genuinely different, non-empty known value counts - a field being
     * filled in for the first time is not a conflict.
     *
     * Returns [field => ['previous' => ..., 'new' => ...]].
     */
    public function detectConflicts(array $known, array $extracted): array
    {
        $conflicts = [];

        foreach (self::CONFLICT_FIELDS as $field) {
            $previous = $this->normalizeForComparison($known[$field] ?? null);
            $new = $this->normalizeForComparison($extracted[$field] ?? null);

            if ($previous === null || $new === null) {
                continue;
            }

            if ($previous !== $new) {
                $conflicts[$field] = [
                    'previous' => $known[$field],
                    'new' => $extracted[$field],
                ];
            }
        }

        return $conflicts;
    }

    private function normalizeForComparison(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : preg_replace('/\s+/u', '', $value);
    }

    private function containsAnyPhrase(string $haystack, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (mb_stripos($haystack, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The single disambiguation question for all pending conflicts at
     * once (there's rarely more than one field in conflict on the same
     * turn, but the phrasing scales cleanly if there is).
     */
    public function conflictQuestion(array $conflicts): string
    {
        $labels = [
            'phone' => 'رقم الموبايل',
            'national_id' => 'الرقم القومي',
        ];

        $lines = [];

        foreach ($conflicts as $field => $values) {
            $label = $labels[$field] ?? $field;
            $lines[] = "{$label}: حضرتك بعت \"{$values['previous']}\" قبل كده وبعدين بعت \"{$values['new']}\" - أعتمد أنهي واحد؟";
        }

        return implode("\n", $lines);
    }

    /**
     * Attempts to resolve a pending conflict from the customer's reply.
     * Recognises explicit "القديم"/"الجديد" answers, or the customer
     * simply re-sending one of the two values verbatim. Returns
     * [field => resolved_value] for whatever it could resolve - fields
     * not resolved this turn stay pending (caller keeps asking).
     */
    private const CONFLICT_FIRST_WORDS = ['قديم', 'الاول', 'الأول', 'اللي فات', 'اللى فات'];
    private const CONFLICT_SECOND_WORDS = [
        'جديد', 'التاني', 'التانى', 'الثاني', 'الثانى', 'الاخير', 'الأخير',
        'آخر رقم', 'اخر رقم', 'آخر واحد', 'اخر واحد',
    ];

    public function resolveConflicts(array $pendingConflicts, string $message): array
    {
        $normalizedMessage = $this->normalizeForComparison($message) ?? '';
        $resolved = [];

        /*
         * conflictQuestion() بيقول "بعت X قبل كده وبعدين بعت Y" - يعني X
         * هو "الأول/القديم" وY هو "التاني/الأخير/الجديد". العميل غالبًا
         * بيرد بترتيب مش بكلمة "قديم"/"جديد" الحرفية ("التاني"، "الأخير")
         * - المفروض تتفهم زي "جديد" بالظبط، مش تتجاهل وتتسأل نفس السؤال
         * تاني.
         */
        $wantsOld = $this->containsAnyPhrase($message, self::CONFLICT_FIRST_WORDS);
        $wantsNew = $this->containsAnyPhrase($message, self::CONFLICT_SECOND_WORDS);

        foreach ($pendingConflicts as $field => $values) {
            if ($wantsOld && ! $wantsNew) {
                $resolved[$field] = $values['previous'];

                continue;
            }

            if ($wantsNew && ! $wantsOld) {
                $resolved[$field] = $values['new'];

                continue;
            }

            $previousNormalized = $this->normalizeForComparison((string) $values['previous']);
            $newNormalized = $this->normalizeForComparison((string) $values['new']);

            if ($normalizedMessage !== '' && str_contains($normalizedMessage, $previousNormalized ?? "\0")) {
                $resolved[$field] = $values['previous'];
            } elseif ($normalizedMessage !== '' && str_contains($normalizedMessage, $newNormalized ?? "\0")) {
                $resolved[$field] = $values['new'];
            }
        }

        return $resolved;
    }
}
