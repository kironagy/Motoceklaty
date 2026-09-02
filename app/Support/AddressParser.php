<?php

namespace App\Support;

/**
 * Deterministic best-effort address component extraction, so the LLM
 * extraction prompt only has to fill whatever this parser couldn't
 * confidently resolve. Component list matches the business's own address
 * rule (ai_memories "قواعد العناوين"): governorate, city, area, street,
 * building, floor, apartment, landmark.
 */
class AddressParser
{
    private const GOVERNORATES = [
        'القاهرة', 'الجيزة', 'الاسكندرية', 'الإسكندرية', 'القليوبية', 'الشرقية',
        'الدقهلية', 'الغربية', 'المنوفية', 'البحيرة', 'كفر الشيخ', 'دمياط',
        'بورسعيد', 'الاسماعيلية', 'الإسماعيلية', 'السويس', 'شمال سيناء', 'جنوب سيناء',
        'الفيوم', 'بني سويف', 'المنيا', 'اسيوط', 'أسيوط', 'سوهاج', 'قنا',
        'الاقصر', 'الأقصر', 'اسوان', 'أسوان', 'البحر الاحمر', 'البحر الأحمر',
        'الوادي الجديد', 'مطروح',
    ];

    private const CITY_HINTS = [
        '6 أكتوبر', '٦ أكتوبر', 'اكتوبر', 'أكتوبر', 'الشيخ زايد', 'زايد',
        'مدينة نصر', 'نصر سيتي', 'المعادي', 'مصر الجديدة', 'حلوان', 'العبور',
        'بدر', 'الشروق', 'التجمع', 'العاشر من رمضان', 'الرحاب',
    ];

    /**
     * الكلمات اللي بتبدأ مكوّن عنوان جديد. أي قيمة بنلتقطها (اسم شارع،
     * علامة مميزة، رقم مربع) لازم تقف عندها.
     *
     * الباج اللي بتصلحه: التقاط الشارع كان `([^\n,،]+)` - ياخد كل اللي
     * بعد كلمة "شارع" لحد آخر السطر. فرسالة زي "١٢ شارع محمد سيد شقه ٢
     * الدور التاني امام سوبر ماركت المحبه" كان اسم الشارع فيها بيطلع
     * "محمد سيد شقه ٢ الدور التاني امام سوبر ماركت المحبه" - نص العنوان
     * كله محشور في مكوّن واحد.
     *
     * (?![ء-ي]) بعد الكلمة معناها لازم تكون كلمة كاملة، عشان "عمارات
     * العبور" ما تتقطعش عند "عمار" و"دوران" ما تتقطعش عند "دور".
     */
    private const COMPONENT_BOUNDARY = 'شارع|عماره|عمار|عقار|برج|الدور|دور|شقه|فيلا|فيله|فله|مربع|مجاوره|بلوك|قطعه|العلامه|علامه|قدام|بجوار|جنب|جمب|امام|مقابل|خلف|ورا|وراء|فوق|تحت|عند|قرب|بالقرب|ناحيه|ملك|ملكي|ملكه|مالك|تمليك|ايجار|بالايجار|مستاجر|مؤجر';

    private const OWNERSHIP_OWNER_WORDS = ['ملك', 'مالك', 'ملكي', 'تمليك', 'ملكنا', 'بتاعنا', 'ملكه'];
    private const OWNERSHIP_RENTER_WORDS = ['ايجار', 'إيجار', 'مستاجر', 'مستأجر', 'مؤجر', 'بالايجار', 'مؤجره'];

    public function parse(string $text): array
    {
        $components = [
            'governorate' => null,
            'city' => null,
            'area' => null,
            'street' => null,
            'building' => null,
            'floor' => null,
            'apartment' => null,
            'landmark' => null,
            'ownership' => null,
            /*
             * فيلا / بيت مستقل. الفيلا مالهاش "دور" ولا "رقم شقة"، فلو
             * فضلنا نطلبهم منه هيفضل يرد "فيلا ١١٥" وإحنا نعيد نفس
             * السؤال للأبد - وده اللي حصل فعلاً في محادثة حقيقية.
             */
            'residence_type' => null,
        ];

        $text = trim($text);

        if ($text === '') {
            return $components;
        }

        /*
         * كل المطابقات تحت بتشتغل على النص المطبّع، مش الخام. من غير
         * كده "القاهره" مكانتش بتطابق "القاهرة" و"العلامه المميزه"
         * مكانتش بتطابق "علامة مميزة" - وده كان بيخلي البوت يسأل على
         * نفس المكوّن للأبد رغم إن العميل باعته.
         */
        $text = $this->fold($text);

        foreach (self::GOVERNORATES as $governorate) {
            if (mb_stripos($text, $this->fold($governorate)) !== false) {
                $components['governorate'] = $governorate;
                break;
            }
        }

        foreach (self::CITY_HINTS as $city) {
            if (mb_stripos($text, $this->fold($city)) !== false) {
                $components['city'] = $city;
                break;
            }
        }

        $remaining = $text;

        $untilBoundary = $this->untilBoundary();

        if (preg_match('/^(.*?)\s*شارع\s*' . $untilBoundary . '/u', $text, $m)) {
            $components['street'] = trim($m[2]);
            $leadingPhrase = trim($m[1]);
            $remaining = str_replace($m[0], ' ', $remaining);
        } elseif (preg_match('/^(.*?)\s*ش[\.\s]\s*' . $untilBoundary . '/u', $text, $m)) {
            $components['street'] = trim($m[2]);
            $leadingPhrase = trim($m[1]);
            $remaining = str_replace($m[0], ' ', $remaining);
        }

        /*
         * اللي قبل كلمة "شارع" بيبقى حاجة من اتنين:
         *
         *  1) رقم لوحده = رقم العمارة. ده العرف المصري الأشهر خالص:
         *     "١٢ ش محمد ابو النجا". الكود القديم كان بيحطه في area،
         *     فرقم العمارة كان بيضيع والبوت يفضل يسأل "محتاج رقم
         *     العمارة" رغم إن العميل بعته في أول كلمة في العنوان.
         *
         *  2) كلام = اسم حي/منطقة ("المهندسين شارع جامعة الدول").
         */
        if (isset($leadingPhrase) && $leadingPhrase !== '') {
            /*
             * "اكتوبر ١٢ شارع محمد سيد" - اسم المدينة بيسبق رقم العمارة
             * كتير جدًا. من غير ما نشيله، الشرط "رقم لوحده" تحت مكانش
             * بيتحقق، فرقم العمارة كان بيضيع والبوت يسأل "محتاج رقم
             * العمارة" رغم إن العميل كتبه - وده حصل في محادثة حقيقية
             * والعميل رد "ماهو ١٢ ده رقم العمارة".
             */
            foreach ([$components['city'], $components['governorate']] as $place) {
                if ($place !== null) {
                    $leadingPhrase = str_replace($this->fold($place), ' ', $leadingPhrase);
                }
            }

            $leadingPhrase = trim(preg_replace('/\s+/u', ' ', $leadingPhrase) ?? $leadingPhrase);
        }

        if (isset($leadingPhrase) && $leadingPhrase !== '') {
            $leadingDigits = $this->arabicDigitsToEnglish($leadingPhrase);

            if (preg_match('/^\d{1,4}$/u', trim($leadingDigits))) {
                if ($components['building'] === null) {
                    $components['building'] = trim($leadingDigits);
                }
            } elseif (
                $components['area'] === null
                && $components['governorate'] === null
                && $components['city'] === null
            ) {
                $components['area'] = $leadingPhrase;
            }
        }

        /*
         * تقسيمات المدن الجديدة: "مربع ٣"، "مجاورة ٤"، "بلوك ١٢". العميل
         * في ٦ أكتوبر/الشيخ زايد بيوصف بيته كده بدل اسم شارع، والـ parser
         * القديم مكانش شايف الصيغة دي خالص - فكان بيرمي "١٥ أ - مربع ٣"
         * في street كنص خام، وساعات يضيع.
         */
        if (
            $components['area'] === null
            && preg_match('/(?:مربع|مجاوره|بلوك|قطعه|كمبوند|كومباوند)(?![ء-ي])\s*:?\s*' . $untilBoundary . '/u', $text, $m)
        ) {
            $components['area'] = trim($m[0]);
            $remaining = str_replace($m[0], ' ', $remaining);
        }

        /*
         * "فيلا ١١٥" - رقم الفيلا هو رقم العمارة، والفيلا مالهاش دور ولا
         * رقم شقة (شوف status()). من غير ده كان البوت يفضل يسأل "محتاج
         * رقم العمارة والدور ورقم الشقة" والعميل يرد "فيلا ١١٥" ويتكرر
         * نفس السؤال حرفيًا - وده حصل في محادثة حقيقية.
         */
        if (preg_match('/(?:فيلا|فيله|فله|فيلات)(?![ء-ي])\s*:?\s*(?:رقم\s*)?([\d٠-٩]+)?/u', $text, $m)) {
            $components['residence_type'] = 'villa';

            if (isset($m[1]) && $m[1] !== '' && $components['building'] === null) {
                $components['building'] = $this->arabicDigitsToEnglish($m[1]);
            }

            $remaining = str_replace($m[0], ' ', $remaining);
        }

        if (preg_match('/(?:عماره|عمارة|عمار)\s*:?\s*([\d٠-٩]+)/u', $text, $m) || preg_match('/عقار\s*:?\s*([\d٠-٩]+)/u', $text, $m)) {
            $components['building'] = $this->arabicDigitsToEnglish($m[1]);
            $remaining = str_replace($m[0], ' ', $remaining);
        }

        /*
         * "الدول التالت" - العميل غالبًا بيغلط في كتابة "الدور". وبيكتب
         * الرقم بالحروف كمان ("التالت"). الاتنين لازم يتمسكوا وإلا البوت
         * هيفضل يسأل على الدور رغم إن العميل جاوب.
         */
        $floorOrdinals = '[\d٠-٩]+|الاول|الاولاني|اول|الثاني|التاني|تاني|الثالث|التالت|تالت|الرابع|رابع|الخامس|خامس|السادس|سادس|السابع|سابع|الثامن|ثامن|التاسع|تاسع|العاشر|عاشر|الارضي|ارضي|الاخير|الاخيره';

        /*
         * "الدول التالت" غلطة إملائية شائعة في "الدور" - بس "الدول" كمان
         * كلمة حقيقية في أسماء شوارع ("شارع جامعة الدول العربية"). فبنقبلها
         * كـ"دور" بس لما يبقى بعدها رقم أو ترتيب فعلي، مش أي كلمة.
         */
        if (
            preg_match('/(?:الدور|دور)(?![ء-ي])\s*:?\s*(\S+)/u', $text, $m)
            || preg_match('/(?:الدول|دول)(?![ء-ي])\s*:?\s*(' . $floorOrdinals . ')(?![ء-ي])/u', $text, $m)
        ) {
            $components['floor'] = $this->arabicDigitsToEnglish($this->trimPunctuation($m[1]));
            $remaining = str_replace($m[0], ' ', $remaining);
        }

        if (preg_match('/شقه(?![ء-ي])\s*:?\s*(\S+)/u', $text, $m)) {
            $components['apartment'] = $this->arabicDigitsToEnglish($this->trimPunctuation($m[1]));
            $remaining = str_replace($m[0], ' ', $remaining);
        }

        /*
         * "قدام" كانت ناقصة - وهي أكتر كلمة بيستخدمها المصري في العلامة
         * المميزة. العميل كتب "قدام سوبر ماركت الاخوه" تلات مرات والبوت
         * فضل يسأل على العلامة المميزة تاني وتاني (لوب حقيقي في محادثة
         * حقيقية). كل الصيغ هنا مطبّعة لأن $text نفسه بقى مطبّع.
         */
        $landmarkKeywords = implode('|', [
            /*
             * "والعلامه المميزه قدام كذا" - "ال" التعريف بتيجي على الكلمتين
             * معًا، فمينفعش نحط "علامه" لوحدها الأول لإنها بتطابق جوه
             * "العلامه" وتسيب "المميزه" بره الالتقاط. الصيغ الأطول
             * والمعرّفة بـ"ال" لازم تيجي قبل الصيغ القصيرة.
             */
            'العلامه المميزه', 'علامه مميزه', 'العلامه', 'علامه', 'ملاحظه مميزه',
            'قدام', 'قدامها', 'قدامي',
            'بجوار', 'جنب', 'جمب', 'جانب',
            'قرب', 'بالقرب من', 'جوه', 'ناحيه',
            'امام', 'خلف', 'ورا', 'وراء',
            'فوق', 'تحت', 'عند',
            'مقابل', 'في مواجهه',
        ]);

        if (preg_match('/(?:' . $landmarkKeywords . ')\s*:?\s*' . $untilBoundary . '/u', $text, $m)) {
            $components['landmark'] = trim($m[1]);
            $remaining = str_replace($m[0], ' ', $remaining);
        }

        /*
         * لازم تطابق كلمة كاملة. "شارع الملك فيصل" كان بيتقري إن السكن
         * "ملك" من غير ما العميل يقول كده أصلاً - وبعدين السؤال ده
         * مبيتسألش، فبيتسجل في الطلب جواب العميل مقالوش.
         */
        foreach (self::OWNERSHIP_OWNER_WORDS as $word) {
            if ($this->containsWord($text, $this->fold($word))) {
                $components['ownership'] = 'ملك';
                $remaining = str_replace($word, ' ', $remaining);
                break;
            }
        }

        if ($components['ownership'] === null) {
            foreach (self::OWNERSHIP_RENTER_WORDS as $word) {
                if ($this->containsWord($text, $this->fold($word))) {
                    $components['ownership'] = 'إيجار';
                    $remaining = str_replace($word, ' ', $remaining);
                    break;
                }
            }
        }

        /*
         * لو لسه معندناش شارع بعد استخراج كل حاجة تانية، والباقي من
         * النص فيه كلام حقيقي (مش بس مسافات/فاصلة)، اعتبره اسم الشارع
         * أو المنطقة الفرعية - العميل غالبًا كتب حي/منطقة زي "الحصري"
         * من غير ما يستخدم كلمة "شارع" صراحة، ومحتاجينها عشان
         * الاكتمال مش نسيبها ضايعة ونفضل نسأل عليها تاني.
         */
        if ($components['street'] === null) {
            /*
             * city/governorate بيتسجّلوا بنصهم الأصلي من الثوابت (زي
             * "٦ أكتوبر" بالهمزة)، لكن $remaining دلوقتي جاي من $text
             * المطبّع (fold() شالت الهمزة). str_replace كان بيدور على
             * النص الأصلي فمش بيلاقيه في remaining المطبّع، فاسم المدينة
             * كان بيفضل جوه الـ leftover ويتحط غلط كـ"شارع". لازم نطبّع
             * نص المقارنة بنفس الطريقة.
             */
            if ($components['city']) {
                $remaining = str_replace($this->fold($components['city']), ' ', $remaining);
            }
            if ($components['governorate']) {
                $remaining = str_replace($this->fold($components['governorate']), ' ', $remaining);
            }

            $remaining = trim(preg_replace('/[\s,،\-_]+/u', ' ', $remaining));

            // Strip filler words ("ساكن في", "أنا", ...) that aren't part
            // of a place name, so a bare "ساكن في 6 أكتوبر" doesn't leave
            // "ساكن في" behind and get mistaken for a street name.
            $fillerWords = ['ساكن', 'ساكنه', 'ساكنة', 'في', 'من', 'انا', 'أنا', 'ده', 'دي', 'منطقة', 'منطقه'];
            $words = array_filter(
                preg_split('/\s+/u', $remaining, -1, PREG_SPLIT_NO_EMPTY) ?: [],
                fn ($word) => ! in_array($word, $fillerWords, true)
            );
            $remaining = implode(' ', $words);

            /*
             * الـ fallback ده كان بياخد **أي** نص باقي ويحطه في street.
             * لما العميل رد على سؤال "محتاج علامة مميزة" بكلمتين
             * ("سوبر ماركت الاخوه")، الـ fallback حطهم في street،
             * وrefreshAddressComponents دمجت القيمة الجديدة فوق القديمة،
             * فاسم الشارع الصح اتمسح والعلامة المميزة فضلت فاضية -
             * فالبوت فضل يسأل نفس السؤال للأبد.
             *
             * الشرطين تحت بيخلّوه محافظ:
             *  - لازم يكون فيه مكوّن عنوان تاني اتمسك في نفس الرسالة
             *    (يعني الرسالة دي فعلًا عنوان، مش إجابة على سؤال واحد)،
             *  - أو الرسالة فيها كلمة بتدل على سكن/عنوان صراحة.
             */
            $otherComponentFound = collect($components)
                ->except(['street'])
                ->filter(fn ($value) => filled($value))
                ->isNotEmpty();

            $looksLikeAddressText = (bool) preg_match(
                '/(شارع|\bش\b|حي|منطقه|مدينه|مركز|قريه|محافظه|عنوان|ساكن|سكني|سكن)/u',
                $text
            );

            if (mb_strlen($remaining) >= 2 && ($otherComponentFound || $looksLikeAddressText)) {
                $components['street'] = $remaining;
            }
        }

        return $components;
    }

    /**
     * نمط بياخد قيمة مكوّن كلمة كلمة، وبيقف عند أول كلمة بتبدأ مكوّن
     * تاني (شوف COMPONENT_BOUNDARY). أول كلمة بتتاخد من غير فحص - لأنها
     * دايمًا القيمة نفسها اللي بعد الكلمة المفتاحية على طول.
     */
    /**
     * مطابقة كلمة كاملة في نص عربي - preg بـ\b مبيشتغلش صح مع الحروف
     * العربية، فبنستخدم lookaround على مدى الحروف العربية نفسه.
     */
    private function containsWord(string $haystack, string $word): bool
    {
        return (bool) preg_match('/(?<![ء-ي])' . preg_quote($word, '/') . '(?![ء-ي])/u', $haystack);
    }

    private function untilBoundary(): string
    {
        $boundary = '(?:' . self::COMPONENT_BOUNDARY . ')(?![ء-ي])';

        return '([^\s\n,،\-]+(?:\s+(?!' . $boundary . ')[^\s\n,،\-]+)*)';
    }

    /**
     * PHP's trim() takes its character mask as a byte string, not a
     * character list - passing a multi-byte character like the Arabic
     * comma "،" makes it strip either of that character's two individual
     * UTF-8 bytes from the ends, which can slice through the FIRST byte
     * of an adjacent multi-byte Arabic letter and leave an invalid,
     * unencodable UTF-8 string behind (this is exactly what crashed
     * saving the conversation earlier - trim($m[1], " .،") turned "التاني"
     * into a mangled, un-JSON-encodable string). Regex trimming is
     * character-aware and safe here.
     */
    private function trimPunctuation(string $value): string
    {
        return trim(preg_replace('/^[\s.،]+|[\s.،]+$/u', '', $value) ?? '');
    }

    /**
     * COMPLETE/PARTIAL/MISSING status for one address, given its known
     * components. Matches the business's own address rule (ai_memories
     * "قواعد العناوين"): an address is only COMPLETE once area, street,
     * building, floor, apartment AND a landmark are all known.
     * $requireOwnership additionally requires "ملك ولا إيجار" - only
     * meaningful for the home address, not the work address.
     */
    public function status(array $components, bool $requireOwnership = false, bool $isWorkplace = false): array
    {
        $hasAreaOrGovernorate = filled($components['area'] ?? null)
            || filled($components['governorate'] ?? null)
            || filled($components['city'] ?? null);

        /*
         * فيلا/بيت مستقل: مفيش دور ولا رقم شقة نسألهم أصلاً، والشارع
         * ساعات بيتعوّض بالمربع/المجاورة في المدن الجديدة. طلبهم من
         * صاحب فيلا معناه سؤال مالوش إجابة - والطلب بيقف عنده للأبد.
         */
        $isVilla = ($components['residence_type'] ?? null) === 'villa';

        /*
         * عنوان الشغل مش عنوان سكن.
         *
         * الشخص ممكن يكون شغال في شركة أو مصنع أو مول أو محل - مفيش
         * "دور" ولا "رقم شقة" ولا "ملك ولا إيجار" في أي منهم، وطلبها منه
         * سؤال مالوش إجابة بيوقف الطلب. اللي بيهمنا في مكان الشغل إننا
         * نعرف نوصله: المنطقة، ومعاها اسم الشارع أو علامة مميزة.
         */
        if ($isWorkplace) {
            $requiredMet = [
                'area_or_governorate' => $hasAreaOrGovernorate,
                'workplace_location' => filled($components['street'] ?? null)
                    || filled($components['landmark'] ?? null)
                    || filled($components['building'] ?? null),
            ];

            $missing = array_keys(array_filter($requiredMet, fn ($met) => ! $met));

            if (empty($missing)) {
                return ['status' => 'complete', 'missing' => []];
            }

            $anyPresent = collect($components)->filter(fn ($value) => filled($value))->isNotEmpty();

            return ['status' => $anyPresent ? 'partial' : 'missing', 'missing' => $missing];
        }

        $requiredMet = $isVilla
            ? [
                'area_or_governorate' => $hasAreaOrGovernorate,
                'building' => filled($components['building'] ?? null),
                'landmark' => filled($components['landmark'] ?? null),
            ]
            : [
                'area_or_governorate' => $hasAreaOrGovernorate,
                'street' => filled($components['street'] ?? null),
                'building' => filled($components['building'] ?? null),
                'floor' => filled($components['floor'] ?? null),
                'apartment' => filled($components['apartment'] ?? null),
                'landmark' => filled($components['landmark'] ?? null),
            ];

        if ($requireOwnership) {
            $requiredMet['ownership'] = filled($components['ownership'] ?? null);
        }

        $missing = array_keys(array_filter($requiredMet, fn ($met) => ! $met));

        $anyComponentPresent = collect($components)->filter(fn ($value) => filled($value))->isNotEmpty();

        if (empty($missing)) {
            return ['status' => 'complete', 'missing' => []];
        }

        if ($anyComponentPresent) {
            return ['status' => 'partial', 'missing' => $missing];
        }

        return ['status' => 'missing', 'missing' => $missing];
    }

    /**
     * تطبيع عربي - نفس اللي بيعمله MachineSearchService::normalizeSearchText
     * وApplicationHandler::normalizeJobText. الملف ده كان الوحيد اللي
     * مبيعملش تطبيع خالص، فقايمة المحافظات ("القاهرة" بالتاء المربوطة)
     * كانت مستحيل تتطابق مع كتابة عميل عادي ("القاهره" بالهاء)، وكلمة
     * "العلامه المميزه" مكانتش بتطابق "علامة مميزة".
     */
    private function fold(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace('ؤ', 'و', $text);
        $text = str_replace('ئ', 'ي', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * الأرقام الهندية (١٢) بتيجي كتير من الكيبورد العربي. بنخزّن الأرقام
     * دايمًا لاتيني عشان أي مقارنة أو عرض بعد كده يبقى متسق.
     */
    private function arabicDigitsToEnglish(string $text): string
    {
        return str_replace(
            ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            $text
        );
    }
}
