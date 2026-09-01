# خطة إصلاح مشاكل بوت الواتساب (تحليل كود + Tasks جاهزة للتنفيذ)

> **تاريخ التحليل:** 2026-08-31
> **الفرع:** `main` — آخر كوميت `376d411b`
> **طريقة التحليل:** قراءة كود فعلية + تشغيل الكود على الداتابيز الحقيقية (`motoceklaty`) للتأكد من كل سبب جذري.

---

## 0. تعليمات للـ AI اللي هينفذ الملف ده (اقرأها الأول — إلزامي)

أنت هتنفذ الـ tasks تحت **واحدة واحدة بالترتيب**. لكل task:

1. اقرأ قسم **السبب الجذري** — فيه المشكلة بالظبط فين في الكود.
2. اقرأ قسم **الحل** — فيه الكود المطلوب حرفيًا. **انسخه زي ما هو**، متجتهدش وتغيّره.
3. شغّل **أمر التحقق** المكتوب في الـ task. لازم يطلع الناتج المتوقع بالظبط.
4. لو الناتج صح: غيّر `- [ ]` لـ `- [x]` وغيّر `الحالة: ⬜ TODO` لـ `الحالة: ✅ DONE` في رأس الـ task.
5. لو الناتج غلط: **متكملش للـ task اللي بعده**. صلّح الأول.

### قواعد ممنوعة
- ❌ ممنوع تعدّل أي ملف مش مذكور صراحة في الـ task.
- ❌ ممنوع تمسح كومنتات عربية موجودة في الكود — ضيف بس.
- ❌ ممنوع تعتبر task خلصت من غير ما تشغّل أمر التحقق.
- ❌ ممنوع تشتغل على `app/Services/MachineNameResolver.php` — الملف ده **مش مستخدم في أي حتة** (كود ميت، ٨١٦ سطر). أي إصلاح لمطابقة أسماء المكن مكانه `MachineSearchService`.

### أمر التحقق العام (اشتغل بيه كل شوية)
```bash
php -l app/Services/MachineSearchService.php && php -l app/Support/AddressParser.php && php -l app/Services/ApplicationStateService.php && php -l app/Services/Handlers/ApplicationHandler.php && php -l app/Services/AiPromptBuilder.php && php -l app/Services/AiIntentClassifier.php && php -l app/Services/WhatsappIntentRouter.php
```

---

## 1. الخلاصة التنفيذية — ليه الـ AI لسه بيحس إنه مش AI

المشكلة **مش** إن الموديل ضعيف. المشكلة إن الكود بيدي الـ AI صورة ناقصة أو غلط عن الواقع، وبعدين بيلومه إنه اخترع:

| # | السبب الجذري | التأثير |
|---|---|---|
| A | **الـ AI معندوش كتالوج**. لا الـ planner (`AiIntentClassifier::prompt`) ولا كاتب الرد (`AiPromptBuilder::buildChatReplyPrompt`) بيشوفوا قايمة البراندات أو الموديلات من الداتابيز. | البوت بينكر بضاعة موجودة عنده فعلًا (بينيلي) — مشكلة ٧ |
| B | **`last_machine_ids` ملهاش عمر افتراضي**، وبتتحقن في البرومبت بالأسعار. | البوت بيرشّح VLR 150 بـ60,000 من غير ما حد يسأل — مشكلة ٢ |
| C | **`AddressParser` قاموسه ناقص + عنده fallback بيلوث البيانات**. | لوب لا نهائي في العلامة المميزة ورقم العمارة — مشاكل ٤/٥/٦ |
| D | **مفيش ربط بين السؤال المعلّق والإجابة**. لما البوت يسأل عن مكوّن معين، إجابة العميل المجرّدة مش بتترمي على المكوّن ده. | العميل يجاوب والبوت يسأل تاني — مشاكل ٤/٦ |
| E | **`normalizeSearchText` بيعمل `str_replace` بدون حدود كلمات**: `dayun → دايون` بيحوّل `Dayung` لـ `دايونg` (نص عربي نص إنجليزي). | "دايونج" مش بترجّع حاجة خالص — مشكلة ١ |
| F | **`work_vehicle` بيتقرا من نص الرسالة بس لو كان متسأل عنه في الدور اللي فات**. | "شغال طلبات على العجلة" بتتجاهل، والبوت يطلب رخصة — مشكلة ٣ |

---

## 2. جدول الـ Tasks

| Task | العنوان | يحل مشكلة | الخطورة | الحالة |
|------|---------|-----------|---------|--------|
| T1 | إصلاح تحويل `dayun → دايون` وإضافة `Dayung` كموديل مستقل | ١ | 🔴 عالية | ✅ DONE |
| T2 | إضافة كتالوج البراندات/الموديلات لبرومبت الرد | ٧ | 🔴 عالية | ✅ DONE |
| T3 | إضافة نية `availability` + قاعدة "ممنوع تنكر توفر موديل" | ٧ | 🔴 عالية | ✅ DONE |
| T4 | عمر افتراضي وتصفير لـ `last_machine_ids` | ٢ | 🔴 عالية | ✅ DONE |
| T5 | منع البوت من ترشيح موديل من نفسه في الرد الحر | ٢ | 🟠 متوسطة | ✅ DONE |
| T6 | توسيع قاموس العلامة المميزة + تطبيع عربي في `AddressParser` | ٤/٦ | 🔴 عالية | ✅ DONE |
| T7 | استخراج رقم العمارة من صيغة "١٢ ش فلان" | ٥ | 🔴 عالية | ✅ DONE |
| T8 | إيقاف fallback الشارع اللي بيلوّث البيانات | ٤/٥/٦ | 🔴 عالية | ✅ DONE |
| T9 | ربط الإجابة المجردة بالمكوّن المسؤول (`pending_address_component`) | ٤/٦ | 🔴 عالية | ✅ DONE |
| T10 | شيل "استلمت منك كذا" وسيب "لسه محتاج كذا" بس | ٤ | 🟡 منخفضة | ✅ DONE |
| T11 | قراءة `work_vehicle` من أي رسالة (deterministic) | ٣ | 🔴 عالية | ✅ DONE |
| T12 | تعديل برومبت الاستخراج لـ "شغال طلبات على العجلة" | ٣ | 🟠 متوسطة | ✅ DONE |
| T13 | منع طلب الرخصة لما المركبة مش معروفة | ٣ | 🟠 متوسطة | ✅ DONE |
| T14 | اختبارات تراجع (Regression tests) للسبع مشاكل | الكل | 🟠 متوسطة | ✅ DONE |

---

---

# 🔧 المشكلة ١ — "دايونج" بيرجّع "دايو"

## الدليل من التشغيل الفعلي

```
--- دايونج
  search: []                  ← مفيش أي نتيجة
  norm: دايونج
norm(Dayung) = دايونg          ← اسم الموديل نفسه اتخرّب!
```

## السبب الجذري

في [`app/Services/MachineSearchService.php:642`](app/Services/MachineSearchService.php:642) جوه `normalizeSearchText()` فيه خريطة تحويل:

```php
'dayun' => 'دايون',
```

وبتتنفذ بـ `str_replace` **من غير حدود كلمات**. الموديل رقم ٥٦ في الداتابيز اسمه `Dayung`:

- `Dayung` → `str_replace('dayun','دايون')` → **`دايونg`** (كلمة نصها عربي ونصها إنجليزي، مش موجودة في أي قاموس).
- استعلام العميل `دايونج` → يفضل `دايونج`.
- `دايونج ≠ دايونg` → `familyMatches()` بتطلب تطابق تام في التوكينز → فاضية.
- `scoreMachine()` بتدي 400 نقطة بس (تشابه ليفنشتاين)، والحد الأدنى 900 → **بيتفلتر**.

وبعد ما البحث يرجع فاضي، الرسالة بتروح لـ `handleAiFallback()` والـ LLM بيخمّن "دايو" لأنها أقرب كلمة يعرفها.

كمان: `allBrandTokens()` ([`MachineSearchService.php:329`](app/Services/MachineSearchService.php:329)) فيها `'دايون'` كـ **براند** — فأي حد يكتب "دايون" بيرجعله كل موديلات براند دايو، وده بيثبّت اللبس.

---

## ✅ Task T1 — إصلاح تحويل dayun وفصل Dayung
**الحالة: ✅ DONE**

- [x] **T1.1** — افتح [`app/Services/MachineSearchService.php`](app/Services/MachineSearchService.php). دوّر على الأسطر دي جوه `normalizeSearchText()`:

```php
            'dayun' => 'دايون',
            'daion' => 'دايون',
            'ديوان' => 'دايون',
            'الدايون' => 'دايون',
```

**امسحهم بالكامل** وحط مكانهم:

```php
            /*
             * ملحوظة مهمة:
             * التحويلات دي كانت بتتعمل بـ str_replace من غير حدود كلمات،
             * فـ "Dayung" (موديل حقيقي id=56) كان بيبقى "دايونg" - كلمة
             * نص عربي نص إنجليزي مش بتطابق أي حاجة. النتيجة إن "دايونج"
             * كان بيرجّع صفر نتايج والـ LLM يخمّن "دايو".
             * التحويل دلوقتي بقى بحدود كلمات (انظر applyWordBoundaryMap
             * تحت) و"dayung" له مدخل خاص بيه قبل "dayun".
             */
            'dayung' => 'دايونج',
            'دايونغ' => 'دايونج',
            'daewoo' => 'دايو',
            'dayun' => 'دايون',
            'daion' => 'دايون',
            'ديوان' => 'دايون',
```

- [x] **T1.2** — في نفس الدالة، دوّر على السطر ده:

```php
        $text = str_replace(array_keys($replace), array_values($replace), $text);
```

**بدّله بـ**:

```php
        $text = $this->applyWordBoundaryMap($text, $replace);
```

- [x] **T1.3** — ضيف الدالة دي **بعد** نهاية `normalizeSearchText()` مباشرة (قبل `public function normalizeModelCode`):

```php
    /**
     * تطبيق خريطة المرادفات بحدود كلمات بدل str_replace الأعمى.
     *
     * str_replace كان بيضرب جوه الكلمات: "Dayung" -> "دايونg"، و"هوجانى"
     * -> "هوجنى". الحدود هنا مش \b العادية لأنها مش شغالة صح مع العربي في
     * PCRE، فبنستخدم lookaround على "حرف كلمة" معرّف يدويًا (عربي أو
     * لاتيني أو رقم).
     */
    private function applyWordBoundaryMap(string $text, array $map): string
    {
        $wordChar = '[\p{Arabic}a-zA-Z0-9]';

        foreach ($map as $from => $to) {
            $pattern = '/(?<!' . $wordChar . ')' . preg_quote($from, '/') . '(?!' . $wordChar . ')/u';
            $replaced = preg_replace($pattern, $to, $text);

            if ($replaced !== null) {
                $text = $replaced;
            }
        }

        return $text;
    }
```

- [x] **T1.4** — في `allBrandTokens()` امسح السطر `'دايون',` من مصفوفة النِك نيمز. سيب `'دايو'` و`'dayun'` زي ما هما.

**السبب:** `دايون` هي الصيغة المطبّعة لموديل `Dayun D Max` / `Dayun Tx 250` (سكوترات)، مش براند. وجودها في قايمة البراندات بيخلي أي حد يكتب "دايون" ياخد كل موديلات براند دايو الغلط.

- [x] **T1.5** — **أمر التحقق:**

```bash
php artisan tinker --execute="\$s=app(\App\Services\MachineSearchService::class); foreach(['دايونج','دايونغ','Dayung','دايو ٤','هوجن ٤','بينيلي','تي اكس'] as \$q){ echo str_pad(\$q,12).' => '.json_encode(\$s->search(\$q,20)->pluck('name')->all(), JSON_UNESCAPED_UNICODE).PHP_EOL; }"
```

**الناتج المتوقع:**
- `دايونج` → `["Dayung"]`
- `دايونغ` → `["Dayung"]`
- `Dayung` → `["Dayung"]`
- `دايو ٤` → `["دايو ٤","دايو ٤ اصلي"]` (زي ما كان — مفيش تراجع)
- `هوجن ٤` → ٣ موديلات هوجن (زي ما كان)
- `بينيلي` → ٤ موديلات بينيلي (زي ما كان)
- `تي اكس` → موديل Tx (زي ما كان)

⚠️ **لو أي واحد من الأربعة الأخيرين اتغيّر، الإصلاح كسر حاجة — ارجع وصلّح قبل ما تكمل.**

---

---

# 🔧 المشكلة ٧ — البوت بينكر إن عنده بينيلي

## الدليل من الداتابيز

```
brand id=5 | بينيلي
  → machine 16 | VLR 150   | cash = 60,000
  → machine 17 | S 200
  → machine 20 | VLR 200
  → machine 26 | VLM 200
```

بينيلي **موجودة**، وأربع موديلات تحتها. ومع ذلك البوت رد: *"الموديلات المتوفرة عندنا في موتو جيت حالياً هي الماركات الصيني والهندي زي هوجن ودايو، ومفيش بينيلي حالياً في المخزون."*

## السبب الجذري — سببين متراكبين

### السبب الأول: صفر كتالوج في أي برومبت

- [`app/Services/AiPromptBuilder.php:129`](app/Services/AiPromptBuilder.php:129) — البرومبت اللي بيكتب الرد فيه: الميموري، آخر ٢٠ رسالة، أرقام القسط، حالة الطلب، و`last_machine_ids`. **مفيش فيه ولا براند ولا موديل من جدول `machines`/`brands`.**
- [`app/Services/AiIntentClassifier.php:144`](app/Services/AiIntentClassifier.php:144) — برومبت الـ planner برضو مفيهوش الكتالوج.

يعني لما تيجي كلمة "بينيلي"، الـ AI **حرفيًا مش عارف** إنها براند عندنا.

### السبب الثاني: سطر ميموري الـ AI بيعمّمه على إنه إقرار مخزون

الميموري النشطة `المخزون والموديلات` فيها السطر ده وبيتحقن في البرومبت:

```
المتوفر صيني وهندي بس.
مفيش ياباني.
```

الـ AI قرا "صيني وهندي بس" + معندوش كتالوج + بينيلي ماركة إيطالية في معرفته العامة → استنتج إنها مش عندنا. **ده مش هلوسة عشوائية، ده استنتاج منطقي من معلومات ناقصة.**

---

## ✅ Task T2 — حقن كتالوج البراندات والموديلات في برومبت الرد
**الحالة: ✅ DONE**

- [x] **T2.1** — أنشئ ملف جديد [`app/Services/CatalogSummaryService.php`](app/Services/CatalogSummaryService.php):

```php
<?php

namespace App\Services;

use App\Models\Brand;
use Illuminate\Support\Facades\Cache;

/**
 * قايمة البراندات والموديلات اللي عندنا فعلًا، مكتوبة كنص جاهز للحقن في
 * برومبتات الـ AI.
 *
 * ليه الملف ده موجود:
 * الـ AI كان بيكتب ردود عن التوفر من غير ما يشوف جدول machines/brands
 * أصلًا. لما عميل سأل عن "بينيلي" (براند عندنا فعلًا فيه 4 موديلات)،
 * الـ AI قرا سطر الميموري "المتوفر صيني وهندي بس" واستنتج إن بينيلي
 * مش عندنا - استنتاج سليم من معلومات ناقصة. الحل مش تحذير في البرومبت،
 * الحل إننا نديله الحقيقة.
 */
class CatalogSummaryService
{
    /** الكتالوج بيتغير نادر جدًا، فدقيقتين كفاية ومش هتحمّل الداتابيز. */
    private const CACHE_TTL_SECONDS = 120;

    private const CACHE_KEY = 'ai.catalog_summary.v1';

    public function text(): string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            $brands = Brand::query()
                ->with(['machines' => fn ($q) => $q->orderBy('id')])
                ->orderBy('id')
                ->get();

            $lines = [];

            foreach ($brands as $brand) {
                $brandName = trim((string) $brand->name);

                if ($brandName === '') {
                    continue;
                }

                $models = $brand->machines
                    ->map(fn ($machine) => trim((string) $machine->name))
                    ->filter()
                    ->implode('، ');

                $lines[] = $models === ''
                    ? "- {$brandName}: (مفيش موديلات مسجلة)"
                    : "- {$brandName}: {$models}";
            }

            if (empty($lines)) {
                return 'الكتالوج مش متاح دلوقتي.';
            }

            return implode("\n", $lines);
        });
    }

    /** أسماء البراندات بس - للاستخدام في قواعد البرومبت المختصرة. */
    public function brandNames(): array
    {
        return Brand::query()
            ->orderBy('id')
            ->pluck('name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->values()
            ->all();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
```

- [x] **T2.2** — افتح [`app/Services/AiPromptBuilder.php`](app/Services/AiPromptBuilder.php). جوه `buildChatReplyPrompt()`، **قبل** سطر `return <<<PROMPT`، ضيف:

```php
        /*
         * الكتالوج الحقيقي من الداتابيز. من غيره الـ AI كان بينكر بضاعة
         * موجودة عندنا (رد "مفيش بينيلي" وإحنا عندنا 4 موديلات بينيلي)،
         * لأن كل اللي كان قدامه سطر ميموري بيقول "المتوفر صيني وهندي بس".
         */
        $catalogBlock = app(\App\Services\CatalogSummaryService::class)->text();
```

- [x] **T2.3** — في نفس الملف، جوه نص الـ heredoc، **قبل** السطر:

```
الميموري النشطة من ai_memories (سياسات وشروط - المصدر الحالي لغير الأرقام):
```

ضيف الكتلة دي:

```
كتالوج المعرض الكامل (البراندات والموديلات الموجودة فعلًا في الداتابيز - ده المصدر الوحيد لأي كلام عن التوفر):
{$catalogBlock}

```

- [x] **T2.4** — في نفس الملف، جوه قايمة `ممنوع:` ضيف السطرين دول:

```
- ممنوع تقول إن براند أو موديل "مش موجود" أو "مش متوفر" أو "مش في المخزون" إلا لو اسمه **مش مكتوب** في كتالوج المعرض تحت. لو الاسم مكتوب في الكتالوج يبقى هو موجود عندنا، انتهى - مهما كانت معلوماتك العامة عن الماركة دي أو أي سطر ميموري بيتكلم عن بلد المنشأ.
- سطور الميموري اللي بتتكلم عن بلد المنشأ (زي "المتوفر صيني وهندي بس") دي وصف عام للنوعية، مش قايمة مخزون - ممنوع تستنتج منها إن براند موجود في الكتالوج مش عندنا.
```

- [x] **T2.5** — **أمر التحقق:**

```bash
php artisan tinker --execute="
\$b = app(\App\Services\AiPromptBuilder::class)->buildChatReplyPrompt('عندكم بينيلي؟','(ميموري تجريبية)');
echo str_contains(\$b,'بينيلي') ? 'OK: الكتالوج فيه بينيلي'.PHP_EOL : 'FAIL: مفيش بينيلي'.PHP_EOL;
echo str_contains(\$b,'VLR 150') ? 'OK: الكتالوج فيه VLR 150'.PHP_EOL : 'FAIL: مفيش VLR 150'.PHP_EOL;
echo str_contains(\$b,'كتالوج المعرض الكامل') ? 'OK: العنوان موجود'.PHP_EOL : 'FAIL: العنوان ناقص'.PHP_EOL;
"
```

**الناتج المتوقع:** تلات سطور كلها `OK:`.

---

## ✅ Task T3 — نية `availability` (عندكم كذا؟)
**الحالة: ✅ DONE**

**ليه:** سؤال "عندكم بينيلي؟" مالوش نية مخصصة، فبيقع في `general`/`unknown` وبيروح للـ LLM. المفروض يترد عليه **من الداتابيز مباشرة** — رد deterministic مستحيل يهلوس.

- [x] **T3.1** — في [`app/Services/AiIntentClassifier.php`](app/Services/AiIntentClassifier.php)، جوه `prompt()`، في قايمة `النوايا المتاحة intent:` ضيف سطر `- availability` بعد `- brand_models`.

- [x] **T3.2** — في نفس القايمة من القواعد، ضيف السطر ده تحت قاعدة `branches`:

```
- availability: العميل بيسأل هل عندنا ماركة/موديل معين ولا لأ - "عندكم بينيلي؟"، "فيه هوجن؟"، "متوفر عندكم دايو؟"، "بتشتغلوا على أنهي ماركات؟". لازم تحط اسم الماركة أو الموديل في machine_query. دي مش price ومش images - العميل لسه بيسأل عن التوفر بس.
```

- [x] **T3.3** — في نفس الملف، جوه `normalizePlanFields()`، في مصفوفة `$validIntents` ضيف `'availability',` بعد `'brand_models',`.

- [x] **T3.4** — في [`app/Services/WhatsappIntentRouter.php`](app/Services/WhatsappIntentRouter.php)، دوّر على السطر ده (حوالي سطر 900):

```php
        if ($machines->isNotEmpty() && $isBrandOnly) {
            return $this->handleBrandModels($conversation, $machines, $message);
        }
```

**قبله مباشرة** ضيف:

```php
        /*
         * "عندكم بينيلي؟" - سؤال توفر. لازم يترد عليه من الداتابيز مش من
         * الـ LLM: الـ LLM كان بينكر براندات موجودة عندنا فعلًا لأنه
         * معندوش كتالوج (شوف CatalogSummaryService). الرد هنا deterministic
         * ومستحيل يهلوس.
         */
        if ($intent === 'availability') {
            if ($machines->isNotEmpty()) {
                return $this->handleBrandModels($conversation, $machines, $message);
            }

            $catalog = app(\App\Services\CatalogSummaryService::class)->brandNames();

            return $this->textReply(
                $conversation,
                "الماركات المتوفرة عندنا دلوقتي:\n- " . implode("\n- ", $catalog)
                . "\n\nتحب أعرفلك أنهي موديل بالظبط يا فندم؟"
            );
        }
```

- [x] **T3.5** — **أمر التحقق:**

```bash
grep -n "availability" app/Services/AiIntentClassifier.php app/Services/WhatsappIntentRouter.php
```

**الناتج المتوقع:** ٣ نتايج على الأقل في `AiIntentClassifier.php` (النية + القاعدة + `$validIntents`) و٢ في `WhatsappIntentRouter.php`.

---

---

# 🔧 المشكلة ٢ — البوت بيرشّح VLR من نفسه

## الدليل

العميل/البوت التاني سأل: *"بتدور على مكنة لاستخدام شخصي ولا شغل؟ وميزانيتك في حدود كام؟"*
البوت رد: *"مكنة بينيلي VLR 150 متوفرة عندنا وسعرها كاش 60,000 جنيه."*

والداتابيز بتقول: `machine 16 | VLR 150 | cash = 60000.00` — **الرقم مطابق تمامًا**. يعني ده مش هلوسة، ده رقم اتسرّب من البرومبت.

## السبب الجذري — سلسلة من تلات حلقات

1. في دور سابق العميل سأل عن "بينيلي" → `handleBrandModels()` ([`WhatsappIntentRouter.php:2764`](app/Services/WhatsappIntentRouter.php:2764)) نادى `rememberMachines($conversation, $machines)` وحفظ **كل الأربع موديلات** في `last_machine_ids`.

2. `last_machine_ids` **ملهاش عمر افتراضي ولا تصفير**. بتفضل محفوظة في الكونفرسيشن للأبد.

3. أي رسالة عامة بعد كده بتروح لـ `handleAiFallback()` ([`WhatsappIntentRouter.php:963`](app/Services/WhatsappIntentRouter.php:963)) اللي بيبعت للبرومبت:

```php
$lastMachines = $this->lastMachinesFromConversation($conversation)
    ->map(fn (Machine $machine) => [
        'id' => $machine->id,
        'name' => $this->machineDisplayName($machine),
        'cash_price' => $machine->cash_price,      // ← 60000 بيتسرّب هنا
        'installment_price' => $machine->installment_price,
    ])
```

4. وفي [`AiPromptBuilder.php`](app/Services/AiPromptBuilder.php) في قاعدة صريحة بتشجّع الـ AI يستخدمها:

```
- لو العميل بيتكلم عن "هي / ده / دي / دول / سعرها / صورها / قسطها"، افهمها من آخر موديلات وسياق المحادثة.
```

النتيجة: أول موديل في القايمة (VLR 150) + سعره في البرومبت + قاعدة بتقول "افهمها من آخر موديلات" = البوت بيرشّحه من نفسه.

---

## ✅ Task T4 — عمر افتراضي وتصفير لـ `last_machine_ids`
**الحالة: ✅ DONE**

- [x] **T4.1** — في [`app/Services/WhatsappIntentRouter.php`](app/Services/WhatsappIntentRouter.php) دوّر على `private function lastMachinesFromConversation`. جواها، **بعد** سطر:

```php
    $ids = array_values(array_unique(array_filter(
```

...والبلوك اللي بعده مباشرة، ضيف الشرط ده **قبل** الاستعلام النهائي على `Machine::query()`:

```php
    /*
     * الموديلات المحفوظة بتبقى صالحة لسياق الجلسة الحالية بس. من غير
     * الحد ده، عميل سأل عن بينيلي إمبارح كان بيلاقي البوت بيرشّحله
     * VLR 150 بسعرها النهارده رد على رسالة مالهاش أي علاقة، لأن
     * last_machine_ids كانت بتتحقن في البرومبت مع الأسعار للأبد.
     */
    $staleAfterMinutes = (int) config('whatsapp.last_machines_ttl_minutes', 180);
    $lastActivity = $conversation->updated_at;

    if ($lastActivity && $lastActivity->diffInMinutes(now()) > $staleAfterMinutes) {
        return collect();
    }
```

- [x] **T4.2** — أنشئ/عدّل [`config/whatsapp.php`](config/whatsapp.php). **لو الملف مش موجود** أنشئه:

```php
<?php

return [
    /*
     * كام دقيقة تفضل last_machine_ids صالحة قبل ما نعتبرها سياق قديم.
     * أقل من كده بيكسر متابعة طبيعية ("قسطها كام؟" بعد شوية)، وأكتر من
     * كده بيخلي البوت يرشّح موديل من محادثة قديمة من غير ما حد يسأل.
     */
    'last_machines_ttl_minutes' => env('WHATSAPP_LAST_MACHINES_TTL_MINUTES', 180),
];
```

**لو الملف موجود** ضيف المفتاح جوه المصفوفة الموجودة.

- [x] **T4.3** — **أمر التحقق:**

```bash
php artisan config:clear && php artisan tinker --execute="echo 'TTL = '.config('whatsapp.last_machines_ttl_minutes').PHP_EOL;" && grep -n "last_machines_ttl_minutes" app/Services/WhatsappIntentRouter.php
```

**الناتج المتوقع:** `TTL = 180` + سطر واحد من الـ grep.

---

## ✅ Task T5 — منع الترشيح التلقائي في الرد الحر
**الحالة: ✅ DONE**

- [x] **T5.1** — في [`app/Services/AiPromptBuilder.php`](app/Services/AiPromptBuilder.php)، جوه `formatLastMachines()`، بدّل السطر:

```php
        if (empty($machines)) {
            return 'لا يوجد موديلات أخيرة واضحة.';
        }
```

بـ:

```php
        if (empty($machines)) {
            return 'لا يوجد موديلات أخيرة واضحة.';
        }

        /*
         * الأسعار كانت بتتبعت في البلوك ده، فالـ AI كان بيرشّح موديل
         * بسعره من غير ما العميل يسأل ("مكنة بينيلي VLR 150 سعرها 60,000")
         * ردًا على سؤال مالوش علاقة. الأسعار مصدرها الوحيد المفروض يكون
         * بلوك "أرقام السيناريو الحالي" اللي بيتبني من InstallmentCalculator
         * لما العميل يسأل فعلًا. هنا الأسماء بس.
         */
```

- [x] **T5.2** — في نفس الدالة، جوه اللوب، بدّل:

```php
                $id = $machine['id'] ?? $machine['machine_id'] ?? null;
                $name = $machine['name'] ?? $machine['machine_name'] ?? null;

                $line = trim(($id ? "ID: {$id}" : '') . ($name ? " | Name: {$name}" : ''));
```

بـ:

```php
                $id = $machine['id'] ?? $machine['machine_id'] ?? null;
                $name = $machine['name'] ?? $machine['machine_name'] ?? null;

                // الأسعار متشالت عن قصد - شوف الكومنت فوق.
                $line = trim(($id ? "ID: {$id}" : '') . ($name ? " | Name: {$name}" : ''));
```

- [x] **T5.3** — في نفس الملف، جوه نص الـ heredoc، دوّر على السطر:

```
آخر الموديلات المرتبطة بالمحادثة من last_machine_ids:
```

وبدّله بـ:

```
آخر الموديلات المرتبطة بالمحادثة من last_machine_ids (دي للفهم بس - لو العميل قال "هي/ده/دي" اعرف هو بيتكلم عن إيه. **ممنوع تمامًا تذكر اسم موديل من القايمة دي أو تسعّره من نفسك لو العميل ما سألش عنه في رسالته الحالية**):
```

- [x] **T5.4** — في نفس الـ heredoc، جوه قايمة `ممنوع:` ضيف:

```
- ممنوع ترشّح موديل معين أو تقول سعره لو العميل ما سألش عن موديل بالاسم في رسالته الحالية. لو هو بيسأل سؤال عام (زي "بتدور على إيه" أو "ميزانيتك كام")، اسأله سؤال يوصّلك للاختيار - متختارش أنت عنه.
```

- [x] **T5.5** — **أمر التحقق:**

```bash
php artisan tinker --execute="
\$b = app(\App\Services\AiPromptBuilder::class)->buildChatReplyPrompt('اهلا','x','fallback_complex','system',['last_machines'=>[['id'=>16,'name'=>'VLR 150','cash_price'=>60000]]]);
echo str_contains(\$b,'60000') || str_contains(\$b,'60,000') ? 'FAIL: السعر لسه بيتسرّب'.PHP_EOL : 'OK: مفيش أسعار في بلوك آخر الموديلات'.PHP_EOL;
echo str_contains(\$b,'VLR 150') ? 'OK: الاسم موجود (مطلوب للفهم)'.PHP_EOL : 'FAIL: الاسم اتشال بالغلط'.PHP_EOL;
"
```

**الناتج المتوقع:** سطرين `OK:`.

---

---

# 🔧 المشاكل ٤/٥/٦ — العنوان (العلامة المميزة + رقم العمارة + اللوب)

## الدليل من التشغيل الفعلي على `AddressParser`

**رسالة العميل ١:** `١٢ ش محمد ابو النجا من العشرين عين شمس القاهره قدام شرموط الميكانيكيه`

```json
{
  "governorate": null,        ← "القاهره" موجودة في الرسالة! اتفوّتت
  "city": null,
  "area": "١٢",               ← ❌ رقم العمارة اتحسب "منطقة"
  "street": "محمد ابو النجا من العشرين عين شمس القاهره قدام شرموط الميكانيكيه",
  "building": null,           ← ❌ المفروض 12
  "floor": null,
  "apartment": null,
  "landmark": null,           ← ❌ "قدام شرموط الميكانيكيه" اتفوّتت
  "ownership": null
}
```

**رسالة العميل ٢:** `رقم العماره ١٢ والعلامه المميزه قدام سوبر ماركت الاخوه`

```json
{
  "building": "١٢",           ← ✅ اتمسكت
  "landmark": null,           ← ❌ العميل قالها صراحة وما اتمسكتش!
  "street": "رقم ال والعلامه المميزه قدام سوبر ماركت الاخوه"   ← ❌ زبالة دخلت في الشارع
}
```

## السبب الجذري — ٤ أخطاء متراكمة

### خطأ ١: قاموس العلامة المميزة ناقص أهم كلمة في المصري

[`app/Support/AddressParser.php:108`](app/Support/AddressParser.php:108):

```php
if (preg_match('/(?:علامة مميزة|بجوار|جنب|قرب|بالقرب من|امام|أمام|خلف|وراء|جمب)\s*:?\s*([^\n,،]+)/u', $text, $m)) {
```

**"قدام" مش موجودة** — وهي أكتر كلمة بيستخدمها المصري. وكمان "العلامه المميزه" (بالهاء) مش بتطابق "علامة مميزة" (بالتاء المربوطة) لأن **`AddressParser` مش بيعمل أي تطبيع عربي إطلاقًا** — على عكس `MachineSearchService` و`ApplicationHandler` اللي الاتنين عندهم `normalize`.

### خطأ ٢: مفيش تطبيع عربي في `AddressParser` كله

قايمة `GOVERNORATES` فيها `'القاهرة'` بالتاء المربوطة، والعميل كتب `القاهره` بالهاء → `mb_stripos` بترجع `false`. نفس المشكلة في `الاسكندرية`، `الجيزة`، `الدقهلية`... **معظم المحافظات مستحيل تتمسك من كتابة عميل عادي.**

### خطأ ٣: رقم العمارة الأول (`١٢ ش فلان`) بيتحسب "منطقة"

[`AddressParser.php:69-93`](app/Support/AddressParser.php:69):

```php
} elseif (preg_match('/^(.*?)\s*ش[\.\s]\s*([^\n,،]+)/u', $text, $m)) {
    $components['street'] = trim($m[2]);
    $leadingPhrase = trim($m[1]);           // = "١٢"
```

وبعدين:

```php
if (isset($leadingPhrase) && $leadingPhrase !== '' && $components['area'] === null && ...) {
    $components['area'] = $leadingPhrase;   // area = "١٢"  ❌
}
```

الكود مصمم إن اللي قبل "شارع" يبقى اسم حي (زي "المهندسين شارع جامعة الدول") — لكن العرف المصري الأشهر هو **رقم العمارة الأول**: "١٢ شارع فلان".

### خطأ ٤: fallback الشارع بياكل أي رسالة ويلوّث البيانات المحفوظة

[`AddressParser.php:135-155`](app/Support/AddressParser.php:135):

```php
if ($components['street'] === null) {
    ...
    if (mb_strlen($remaining) >= 2) {
        $components['street'] = $remaining;   // أي نص باقي بيبقى "شارع"
    }
}
```

ولأن `refreshAddressComponents()` بتدمج بـ:

```php
$merged[$component] = $value;   // القيمة الجديدة بتغلب القديمة دايمًا
```

النتيجة: العميل يبعت "سوبر ماركت الاخوه" كإجابة على سؤال العلامة المميزة → الفallback بيحطها في `street` → **الشارع الصح اللي كان محفوظ بيتمسح** ويتبدل بجواب مالوش علاقة → واللاندمارك يفضل فاضي للأبد → **لوب**.

### خطأ ٥: مفيش ربط بين السؤال والإجابة

البوت بيسأل "محتاج علامة مميزة"، والعميل يرد "سوبر ماركت الاخوه". الكود **معندوش أي فكرة** إن الرسالة دي إجابة على السؤال ده. بيرميها على `parse()` وكأنها عنوان جديد كامل.

ده هو **جوهر إحساس "الـ AI مش AI"**: البوت بيسأل سؤال محدد وبعدين بينسى إنه سأله.

---

## ✅ Task T6 — تطبيع عربي + توسيع قاموس العلامة المميزة
**الحالة: ✅ DONE**

- [x] **T6.1** — في [`app/Support/AddressParser.php`](app/Support/AddressParser.php)، ضيف الدالة دي في آخر الكلاس (قبل القوس الأخير):

```php
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
```

- [x] **T6.2** — في `parse()`، بعد السطر:

```php
        $text = trim($text);
```

ضيف:

```php
        /*
         * كل المطابقات تحت بتشتغل على النص المطبّع، مش الخام. من غير
         * كده "القاهره" مكانتش بتطابق "القاهرة" و"العلامه المميزه"
         * مكانتش بتطابق "علامة مميزة" - وده كان بيخلي البوت يسأل على
         * نفس المكوّن للأبد رغم إن العميل باعته.
         */
        $text = $this->fold($text);
```

- [x] **T6.3** — في نفس الملف، بدّل مطابقة المحافظة:

```php
        foreach (self::GOVERNORATES as $governorate) {
            if (mb_stripos($text, $governorate) !== false) {
                $components['governorate'] = $governorate;
                break;
            }
        }
```

بـ:

```php
        foreach (self::GOVERNORATES as $governorate) {
            if (mb_stripos($text, $this->fold($governorate)) !== false) {
                $components['governorate'] = $governorate;
                break;
            }
        }
```

- [x] **T6.4** — نفس الحاجة لـ `CITY_HINTS`:

```php
        foreach (self::CITY_HINTS as $city) {
            if (mb_stripos($text, $this->fold($city)) !== false) {
                $components['city'] = $city;
                break;
            }
        }
```

- [x] **T6.5** — بدّل ريجيكس العلامة المميزة كله:

```php
        if (preg_match('/(?:علامة مميزة|بجوار|جنب|قرب|بالقرب من|امام|أمام|خلف|وراء|جمب)\s*:?\s*([^\n,،]+)/u', $text, $m)) {
```

بـ:

```php
        /*
         * "قدام" كانت ناقصة - وهي أكتر كلمة بيستخدمها المصري في العلامة
         * المميزة. العميل كتب "قدام سوبر ماركت الاخوه" تلات مرات والبوت
         * فضل يسأل على العلامة المميزة تاني وتاني (لوب حقيقي في محادثة
         * حقيقية). كل الصيغ هنا مطبّعة لأن $text نفسه بقى مطبّع.
         */
        $landmarkKeywords = implode('|', [
            'علامه مميزه', 'علامه', 'ملاحظه مميزه',
            'قدام', 'قدامها', 'قدامي',
            'بجوار', 'جنب', 'جمب', 'جانب',
            'قرب', 'بالقرب من', 'جوه', 'ناحيه',
            'امام', 'خلف', 'ورا', 'وراء',
            'فوق', 'تحت', 'عند',
            'مقابل', 'في مواجهه',
        ]);

        if (preg_match('/(?:' . $landmarkKeywords . ')\s*:?\s*([^\n,،]+)/u', $text, $m)) {
```

- [x] **T6.6** — في `OWNERSHIP_*_WORDS` ضيف صيغ ناقصة:

```php
    private const OWNERSHIP_OWNER_WORDS = ['ملك', 'مالك', 'ملكي', 'تمليك', 'ملكنا', 'بتاعنا', 'ملكه'];
    private const OWNERSHIP_RENTER_WORDS = ['ايجار', 'إيجار', 'مستاجر', 'مستأجر', 'مؤجر', 'بالايجار', 'مؤجره'];
```

⚠️ **مهم:** لأن `$text` بقى مطبّع، لازم المقارنة تبقى مطبّعة برضو. بدّل اللوبين:

```php
        foreach (self::OWNERSHIP_OWNER_WORDS as $word) {
            if (mb_stripos($text, $this->fold($word)) !== false) {
```

و:

```php
            foreach (self::OWNERSHIP_RENTER_WORDS as $word) {
                if (mb_stripos($text, $this->fold($word)) !== false) {
```

- [x] **T6.7** — **أمر التحقق:**

```bash
php artisan tinker --execute="
\$p = app(\App\Support\AddressParser::class);
\$cases = [
  'قدام سوبر ماركت الاخوه',
  'والله ساكن قدام سوبر ماركت الاخوه',
  'العلامه المميزه قدام سوبر ماركت الاخوه',
  'جنب مسجد النور',
  'السكن تمليك',
  'ساكن ايجار',
  'عين شمس القاهره',
];
foreach (\$cases as \$c) {
  \$r = \$p->parse(\$c);
  echo str_pad(mb_substr(\$c,0,35),38).' landmark='.json_encode(\$r['landmark'],JSON_UNESCAPED_UNICODE)
     .' ownership='.json_encode(\$r['ownership'],JSON_UNESCAPED_UNICODE)
     .' gov='.json_encode(\$r['governorate'],JSON_UNESCAPED_UNICODE).PHP_EOL;
}
"
```

**الناتج المتوقع:**
- أول تلات حالات: `landmark` = `"سوبر ماركت الاخوه"` (مش `null`)
- `جنب مسجد النور` → `landmark = "مسجد النور"`
- `السكن تمليك` → `ownership = "ملك"`
- `ساكن ايجار` → `ownership = "إيجار"`
- `عين شمس القاهره` → `governorate = "القاهرة"` (مش `null`)

---

## ✅ Task T7 — رقم العمارة من صيغة "١٢ ش فلان"
**الحالة: ✅ DONE**

- [x] **T7.1** — في [`app/Support/AddressParser.php`](app/Support/AddressParser.php)، دوّر على البلوك ده:

```php
        if (
            isset($leadingPhrase) && $leadingPhrase !== ''
            && $components['area'] === null && $components['governorate'] === null && $components['city'] === null
        ) {
            $components['area'] = $leadingPhrase;
        }
```

**بدّله بالكامل** بـ:

```php
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
```

- [x] **T7.2** — ضيف الدالة دي في آخر الكلاس (جنب `fold()`):

```php
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
```

- [x] **T7.3** — طبّق نفس التحويل على رقم العمارة والدور والشقة. بدّل:

```php
        if (preg_match('/(?:عماره|عمارة|عمار)\s*:?\s*(\d+)/u', $text, $m) || preg_match('/عقار\s*:?\s*(\d+)/u', $text, $m)) {
            $components['building'] = $m[1];
```

بـ:

```php
        if (preg_match('/(?:عماره|عمارة|عمار)\s*:?\s*([\d٠-٩]+)/u', $text, $m) || preg_match('/عقار\s*:?\s*([\d٠-٩]+)/u', $text, $m)) {
            $components['building'] = $this->arabicDigitsToEnglish($m[1]);
```

وبدّل الدور:

```php
        if (preg_match('/الدور\s*:?\s*(\S+)/u', $text, $m) || preg_match('/دور\s*:?\s*(\S+)/u', $text, $m)) {
            $components['floor'] = $this->trimPunctuation($m[1]);
```

بـ:

```php
        /*
         * "الدول التالت" - العميل غالبًا بيغلط في كتابة "الدور". وبيكتب
         * الرقم بالحروف كمان ("التالت"). الاتنين لازم يتمسكوا وإلا البوت
         * هيفضل يسأل على الدور رغم إن العميل جاوب.
         */
        if (preg_match('/(?:الدور|دور|الدول|دول)\s*:?\s*(\S+)/u', $text, $m)) {
            $components['floor'] = $this->arabicDigitsToEnglish($this->trimPunctuation($m[1]));
```

⚠️ **مهم:** الريجيكس ده لازم يتنفذ **بعد** مطابقة الشارع، عشان "الدول" ما تخطفش كلمة من اسم شارع. لو هي أصلًا بعد الشارع في الملف، سيبها مكانها.

وبدّل الشقة:

```php
        if (preg_match('/شقة\s*:?\s*(\S+)/u', $text, $m) || preg_match('/شقه\s*:?\s*(\S+)/u', $text, $m)) {
            $components['apartment'] = $this->trimPunctuation($m[1]);
```

بـ:

```php
        if (preg_match('/شقه\s*:?\s*(\S+)/u', $text, $m)) {
            $components['apartment'] = $this->arabicDigitsToEnglish($this->trimPunctuation($m[1]));
```

*(كلمة واحدة كفاية دلوقتي — `fold()` بيحوّل `شقة` لـ `شقه` أصلًا.)*

- [x] **T7.4** — **أمر التحقق:**

```bash
php artisan tinker --execute="
\$p = app(\App\Support\AddressParser::class);
echo json_encode(\$p->parse('١٢ ش محمد ابو النجا من العشرين عين شمس القاهره قدام شرموط الميكانيكيه'), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
echo json_encode(\$p->parse('ساكن ف الدول التالت شقه ٣'), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
"
```

**الناتج المتوقع للأولى:**
- `building` = `"12"` (مش `null`)
- `landmark` = `"شرموط الميكانيكيه"` (مش `null`)
- `governorate` = `"القاهرة"` (مش `null`)
- `area` **مش** `"١٢"`

**الناتج المتوقع للتانية:**
- `floor` = `"التالت"` (مش `null`)
- `apartment` = `"3"`

---

## ✅ Task T8 — إيقاف fallback الشارع اللي بيلوّث البيانات
**الحالة: ✅ DONE**

**ليه:** الـ fallback الحالي بيحوّل أي نص باقي لـ "شارع"، وبيمسح الشارع الصح المحفوظ. لازم يبقى **محافظ**: يشتغل بس لما يبقى فيه سياق عنوان حقيقي، وما يدوسش على قيمة موجودة.

- [x] **T8.1** — في [`app/Support/AddressParser.php`](app/Support/AddressParser.php)، دوّر على البلوك اللي بيبدأ بـ:

```php
        if ($components['street'] === null) {
```

وبيخلص بـ:

```php
            if (mb_strlen($remaining) >= 2) {
                $components['street'] = $remaining;
            }
        }
```

**بدّل الشرط الأخير بس** (سيب باقي البلوك زي ما هو) من:

```php
            if (mb_strlen($remaining) >= 2) {
                $components['street'] = $remaining;
            }
```

لـ:

```php
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
```

- [x] **T8.2** — في [`app/Services/ApplicationStateService.php`](app/Services/ApplicationStateService.php)، جوه `refreshAddressComponents()`، خلّي الدمج ما يمسحش قيمة مؤكدة بقيمة أضعف. دوّر على:

```php
                $merged[$component] = $value;
```

**قبله** ضيف:

```php
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

```

- [x] **T8.3** — **أمر التحقق:**

```bash
php artisan tinker --execute="
\$p = app(\App\Support\AddressParser::class);
echo 'A) رد مجرد على سؤال العلامة المميزة:'.PHP_EOL;
echo json_encode(\$p->parse('سوبر ماركت الاخوه'), JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'B) عنوان حقيقي:'.PHP_EOL;
echo json_encode(\$p->parse('المهندسين شارع جامعة الدول'), JSON_UNESCAPED_UNICODE).PHP_EOL;
"
```

**الناتج المتوقع:**
- **(A)** `street` لازم يكون `null` — رد مجرد مش عنوان.
- **(B)** `street` لازم يكون `"جامعة الدول"` و`area` = `"المهندسين"` — العنوان الحقيقي لسه شغال.

---

## ✅ Task T9 — ربط الإجابة المجردة بالمكوّن المسؤول ⭐ (أهم task في الملف)
**الحالة: ✅ DONE**

**ليه ده أهم حاجة:** ده اللي بيخلي البوت يحس إنه فاهم. لما تسأل سؤال محدد، لازم تفتكر إنك سألته، وأي رد قصير بعده يتحسب إجابة عليه.

- [x] **T9.1** — في [`app/Services/ApplicationStateService.php`](app/Services/ApplicationStateService.php) ضيف الدالة دي (حطها بعد `refreshAddressComponents()`):

```php
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
```

- [x] **T9.2** — في [`app/Services/ApplicationStateService.php`](app/Services/ApplicationStateService.php)، جوه `questionForMissing()`، لازم نسجّل إحنا سألنا عن إيه. غيّر توقيع الدالة من:

```php
    public function questionForMissing(
        array $missing,
        array $application,
        array $newlyFilled = [],
        int $noProgressStreak = 0,
        array $labelOverrides = [],
        bool $hasAskedBefore = false
    ): string {
```

لـ:

```php
    /**
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
```

- [x] **T9.3** — في نفس الدالة، جوه بلوك العنوان الجزئي، بعد السطر:

```php
                $newlyReceivedComponents = $application["{$field}_newly_received_components"] ?? [];
```

ضيف:

```php
                /*
                 * لو السؤال بيطلب مكوّن واحد بالظبط، نسجّله عشان الدور
                 * اللي بعده يعرف إن رد العميل المجرد ("سوبر ماركت
                 * الاخوه") هو إجابة على السؤال ده - مش عنوان جديد.
                 */
                if (count($missingComponents) === 1) {
                    $askedComponent = $missingComponents[0];
                    $askedField = $field;
                }
```

- [x] **T9.4** — في [`app/Services/Handlers/ApplicationHandler.php`](app/Services/Handlers/ApplicationHandler.php)، جوه `finalizeApplicationTurn()`، دوّر على السطر:

```php
        $application = $stateService->refreshAddressComponents($application);
```

*(هو موجود مرتين — عدّل اللي جوه `finalizeApplicationTurn` مش اللي جوه بلوك الـ blocking_message)*

**قبله** ضيف:

```php
        /*
         * الدور اللي فات سألنا عن مكوّن عنوان واحد بالظبط (زي العلامة
         * المميزة)، فالرسالة دي هي الرد عليه. لازم نربطها بالمكوّن ده
         * **قبل** refreshAddressComponents، عشان الـ parser مش بيعرف
         * يستخرج جواب مجرد من غير كلمة مفتاحية - وده كان بيخلي البوت
         * يعيد نفس السؤال بعد ما العميل جاوبه.
         */
        $askedComponent = $payload['asked_address_component'] ?? null;
        $askedField = $payload['asked_address_field'] ?? null;

        if ($askedComponent && $askedField) {
            $application = $stateService->bindAnswerToAskedComponent(
                $application,
                $askedField,
                $askedComponent,
                $message
            );
        }
```

- [x] **T9.5** — في نفس الملف، دوّر على كل نداء لـ `questionForMissing(` وخلّي أقرب واحد للرد النهائي يمرّر المتغيرين ويحفظهم. الشكل النهائي المطلوب:

```php
            $askedComponentNow = null;
            $askedFieldNow = null;

            $question = $stateService->questionForMissing(
                $askableMissing,
                $application,
                $newlyFilled,
                $noProgressStreak,
                $labelOverrides,
                $hasAskedBefore,
                $askedComponentNow,
                $askedFieldNow
            );
```

**وبعدها**، في نداء `saveState(...)` اللي بيحفظ السؤال، ضيف للـ `$extraPayload`:

```php
                'asked_address_component' => $askedComponentNow,
                'asked_address_field' => $askedFieldNow,
```

> ⚠️ **ملحوظة للـ AI المنفّذ:** أسماء المتغيرات المحلية عند نداء `questionForMissing` ممكن تكون مختلفة شوية في الكود الحالي. **متغيّرش الأسماء الموجودة** — ضيف بس الوسيطين الجديدين آخر النداء، والمفتاحين الجداد في `saveState`.

- [x] **T9.6** — **أمر التحقق:**

```bash
php artisan tinker --execute="
\$s = app(\App\Services\ApplicationStateService::class);
\$app = [
  'home_address' => '12 ش محمد ابو النجا عين شمس',
  'home_address_components' => ['area'=>'عين شمس','street'=>'محمد ابو النجا','building'=>'12','floor'=>'التالت','apartment'=>'3','ownership'=>'ملك'],
];
\$out = \$s->bindAnswerToAskedComponent(\$app, 'home_address', 'landmark', 'والله ساكن قدام سوبر ماركت الاخوه');
echo 'landmark = '.json_encode(\$out['home_address_components']['landmark'] ?? null, JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'status   = '.json_encode(\$out['home_address_status'] ?? null, JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'missing  = '.json_encode(\$out['home_address_missing_components'] ?? null, JSON_UNESCAPED_UNICODE).PHP_EOL;
\$o2 = \$s->bindAnswerToAskedComponent(['home_address_components'=>[]], 'home_address', 'ownership', 'السكن تمليك');
echo 'ownership = '.json_encode(\$o2['home_address_components']['ownership'] ?? null, JSON_UNESCAPED_UNICODE).PHP_EOL;
"
```

**الناتج المتوقع:**
```
landmark = "سوبر ماركت الاخوه"
status   = "complete"
missing  = []
ownership = "ملك"
```

---

## ✅ Task T10 — شيل "استلمت منك كذا"
**الحالة: ✅ DONE**

**طلب العميل حرفيًا:** *"مش عاوز انه يقول انا استلمت كذا وفاضل كذا، لا يقولو على طول فاضل كذا"*

- [x] **T10.1** — في [`app/Services/ApplicationStateService.php`](app/Services/ApplicationStateService.php)، جوه `questionForMissing()`، دوّر على البلوك ده:

```php
                if (! empty($newlyReceivedComponents)) {
                    $newlyReceivedLabels = array_map(
                        fn ($component) => self::MISSING_COMPONENT_LABELS[$component] ?? $component,
                        $newlyReceivedComponents
                    );

                    $receivedText = implode(' و', $newlyReceivedLabels);
                    $line = "استلمت منك {$receivedText} في {$addressLabel}، بس لسه محتاج {$missingText}.";
                } else {
                    $line = "لسه محتاج {$missingText} في {$addressLabel}.";
                }
```

**بدّله بالكامل** بـ:

```php
                /*
                 * قرار من صاحب المعرض: ممنوع نعدّد اللي استلمناه.
                 * "استلمت منك اسم الشارع ورقم العمارة، بس لسه محتاج
                 * علامة مميزة" بتخلي الرسالة طويلة وبتحس إنها روبوت
                 * بيقرا تقرير - العميل عايز يعرف اللي ناقص وخلاص.
                 */
                $line = "لسه محتاج {$missingText} في {$addressLabel}.";
```

- [x] **T10.2** — في نفس الدالة، دوّر على:

```php
        if (! empty($newlyFilled)) {
            $newlyFilledLabels = array_map(
                fn ($key) => self::FIELD_LABELS[$key] ?? $key,
                $newlyFilled
            );

            $acknowledgment = 'تمام يا فندم، استلمت ' . implode(' و', $newlyFilledLabels) . '.';

            if (empty($missing)) {
                return $acknowledgment;
            }
        }
```

بدّله بـ:

```php
        if (! empty($newlyFilled)) {
            /*
             * نفس القرار: من غير تعداد اللي استلمناه. بنسيب بس تأكيد
             * قصير جدًا لما الطلب يكتمل بالفعل (empty($missing))، لأن
             * ساعتها الرسالة محتاجة تقفل بحاجة إيجابية.
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
```

- [x] **T10.3** — **أمر التحقق:**

```bash
php artisan tinker --execute="
\$s = app(\App\Services\ApplicationStateService::class);
\$app = [
  'home_address' => 'x',
  'home_address_missing_components' => ['landmark'],
  'home_address_newly_received_components' => ['building','floor'],
];
\$c=null; \$f=null;
echo \$s->questionForMissing(['home_address'], \$app, [], 0, [], false, \$c, \$f).PHP_EOL;
echo 'askedComponent = '.json_encode(\$c).PHP_EOL;
"
```

**الناتج المتوقع:**
```
تمام يا فندم، لسه محتاج علامة مميزة قريبة من العنوان في عنوان السكن.
askedComponent = "landmark"
```
⚠️ لازم **مفيش** كلمة "استلمت منك" في الناتج.

---

---

# 🔧 المشكلة ٣ — بيطلب رخصة من اللي شغال على عجلة

## الدليل

**طلب العميل حرفيًا:** *"لما ببعتله انا شغال طلبات على العجلة بيطلب مني رخصة، فلازم أقوله انا شغال على عجلة تاني ساعتها مش بيطلب. انا عاوزه يفهم الكلام من الرسالة."*

## السبب الجذري — سببين

### السبب الأول: القراءة الحتمية مقفولة بشرط ضيق

[`app/Services/Handlers/ApplicationHandler.php:349`](app/Services/Handlers/ApplicationHandler.php:349):

```php
if ($application['work_vehicle'] === null && in_array('work_vehicle', $payload['missing_fields'] ?? [], true)) {
    $application['work_vehicle'] = $this->normalizeVehicle($message);
}
```

قراءة المركبة من نص الرسالة بتشتغل **بس** لو `work_vehicle` كان في `missing_fields` من الدور اللي فات — يعني بس لو إحنا سألنا عنه صراحة. أول مرة العميل يقول "شغال طلبات على العجلة" الشرط ده لسه `false`، فالسطر ما بيشتغلش والقيمة تفضل `null`.

### السبب الثاني: برومبت الاستخراج بيدفع الموديل يرجّع `null`

[`app/Services/AiIntentClassifier.php:629`](app/Services/AiIntentClassifier.php:629):

```
متخمنش: لو العميل قال إنه دليفري أو سواق تطبيقات من غير ما يذكر المركبة، سيب work_vehicle = null...
أما "شغال طلبات/مرسول" لوحدها متحددش منها المركبة (null)
```

القاعدة دي **صح في نيتها** لكن الموديل الضعيف بيقرا "شغال طلبات → null" وبيوقف عندها، ومبيكملش لكلمة "العجلة" في نفس الجملة.

### والنتيجة

`work_vehicle = null` → في `requiredDocuments()` ([`ApplicationHandler.php:786`](app/Services/Handlers/ApplicationHandler.php:786)):

```php
$deliveryDocuments = match ($this->normalizeVehicle($application['work_vehicle'] ?? null)) {
    'bicycle' => ['trips_screenshot'],
    'motorcycle', 'car' => ['trips_screenshot', 'driver_license'],
    default => ['trips_screenshot', 'driver_license'],   // ← null بيقع هنا
};
```

الـ `default` بيطلب **رخصة قيادة** من حد على عجلة.

---

## ✅ Task T11 — قراءة `work_vehicle` من أي رسالة
**الحالة: ✅ DONE**

- [x] **T11.1** — في [`app/Services/Handlers/ApplicationHandler.php`](app/Services/Handlers/ApplicationHandler.php)، دوّر على:

```php
        if ($application['work_vehicle'] === null && in_array('work_vehicle', $payload['missing_fields'] ?? [], true)) {
            $application['work_vehicle'] = $this->normalizeVehicle($message);
        }
```

**بدّله بالكامل** بـ:

```php
        if ($application['work_vehicle'] === null) {
            /*
             * الشرط القديم كان بيقرا المركبة من نص الرسالة بس لو إحنا
             * سألنا عنها في الدور اللي فات. فالعميل اللي بيقول من أول
             * رسالة "أنا شغال طلبات على العجلة" كانت الكلمة "العجلة"
             * بتضيع، والبوت يطلب منه رخصة قيادة (اللي على عجلة مش
             * بتتطلب منه رخصة أصلًا)، وهو يضطر يعيد "أنا على عجلة" تاني.
             *
             * دلوقتي بنقرا من أي رسالة - بس بشرط أمان مهم: كلمات
             * "موتوسيكل/سكوتر/عربية" ممكن تكون بيتكلم بيها عن المكنة
             * اللي بيشتريها مش اللي بيشتغل عليها، فدول مبيتقبلوش إلا
             * لما نكون سألنا فعلًا. "عجلة" ملهاش اللبس ده - إحنا
             * مبنبيعش عجل - فبتتقبل من أي رسالة.
             */
            $weAsked = in_array('work_vehicle', $payload['missing_fields'] ?? [], true);
            $fromMessage = $this->normalizeVehicle($message);

            if ($fromMessage === 'bicycle' || ($fromMessage !== null && $weAsked)) {
                $application['work_vehicle'] = $fromMessage;
            } elseif ($fromMessage !== null && $this->messageStatesCurrentWorkVehicle($message)) {
                $application['work_vehicle'] = $fromMessage;
            }
        }
```

- [x] **T11.2** — ضيف الدالة دي جنب `normalizeVehicle()` في نفس الملف:

```php
    /**
     * هل الرسالة بتتكلم عن المركبة اللي العميل **بيشتغل عليها دلوقتي**،
     * مش عن المكنة اللي بيشتريها؟
     *
     * "أنا شغال طلبات على موتوسيكل"  -> أيوه (مركبة الشغل)
     * "عايز أقسط موتوسيكل"           -> لأ  (المكنة اللي بيشتريها)
     *
     * من غير التمييز ده، أي حد يقول "عايز موتوسيكل" كان هيتسجل إنه
     * شغال على موتوسيكل ونطلب منه رخصة من غير وجه حق.
     */
    private function messageStatesCurrentWorkVehicle(string $message): bool
    {
        $text = $this->normalizeJobText($message);

        return $this->containsAny($text, [
            'شغال على', 'شغال ب', 'بشتغل على', 'بشتغل ب',
            'معايا', 'عندي', 'بستخدم', 'بسوق', 'بركب',
            'شغلي على', 'شغلي ب', 'بشتغل بيها', 'شغال بيها',
        ]);
    }
```

- [x] **T11.3** — **أمر التحقق:**

```bash
php artisan tinker --execute="
\$h = app(\App\Services\Handlers\ApplicationHandler::class);
foreach ([
  'انا شغال طلبات على العجله',
  'شغال طلبات بالعجله',
  'انا على عجله',
  'شغال دليفري على موتوسيكل',
  'عايز اقسط موتوسيكل',
  'شغال اوبر',
] as \$m) {
  echo str_pad(\$m, 32).' => '.json_encode(\$h->normalizeVehicle(\$m)).PHP_EOL;
}
"
```

**الناتج المتوقع:**
```
انا شغال طلبات على العجله        => "bicycle"
شغال طلبات بالعجله               => "bicycle"
انا على عجله                     => "bicycle"
شغال دليفري على موتوسيكل         => "motorcycle"
عايز اقسط موتوسيكل               => "motorcycle"
شغال اوبر                        => "car"
```

> ملحوظة: `normalizeVehicle` نفسها بترجّع `"motorcycle"` لـ "عايز اقسط موتوسيكل" — ده صح والفلترة بتحصل في `messageStatesCurrentWorkVehicle` مش هنا.

---

## ✅ Task T12 — تعديل برومبت الاستخراج
**الحالة: ✅ DONE**

- [x] **T12.1** — في [`app/Services/AiIntentClassifier.php`](app/Services/AiIntentClassifier.php)، جوه `extractApplicationData()`، دوّر على السطور دي:

```
  متخمنش: لو العميل قال إنه دليفري أو سواق تطبيقات من غير ما يذكر
  المركبة، سيب work_vehicle = null. لو قال "شغال أوبر" من غير تفاصيل
  اعتبرها "car" لأن أوبر وكريم عربيات، أما "شغال طلبات/مرسول" لوحدها
  متحددش منها المركبة (null) لأنها بتتعمل بالعجلة والموتوسيكل الاتنين.
```

**بدّلها بـ:**

```
  ⚠️ أهم قاعدة في الحقل ده: اقرا **الجملة كلها** قبل ما تقرر null.
  لو أي كلمة مركبة ظهرت في أي مكان في الرسالة، اقراها - حتى لو الجملة
  بدأت بـ"شغال طلبات". أمثلة إجبارية:
  * "انا شغال طلبات على العجلة"      -> work_vehicle = "bicycle"
  * "شغال طلبات بالعجله"             -> work_vehicle = "bicycle"
  * "شغال دليفري وعندي موتوسيكل"     -> work_vehicle = "motorcycle"
  * "بشتغل مرسول على بسكلته"          -> work_vehicle = "bicycle"
  * "شغال طلبات" (من غير أي مركبة)    -> work_vehicle = null
  * "شغال أوبر" أو "كريم" أو "إندرايف" -> work_vehicle = "car"
  يعني null بتترجع **بس** لما مفيش ولا كلمة مركبة في الرسالة كلها.
  التمييز الوحيد اللي لازم تعمله: لو العميل بيتكلم عن المكنة اللي
  **بيشتريها** ("عايز أقسط موتوسيكل"، "عاوز أشتري سكوتر") ده مش
  work_vehicle - سيبه null. لكن لو بيوصف شغله الحالي ("شغال على"،
  "معايا"، "عندي"، "بستخدم")، ده work_vehicle.
```

- [x] **T12.2** — **أمر التحقق:**

```bash
grep -c "work_vehicle = \"bicycle\"" app/Services/AiIntentClassifier.php
```

**الناتج المتوقع:** `2` أو أكتر.

---

## ✅ Task T13 — منع طلب الرخصة والمركبة مش معروفة
**الحالة: ✅ DONE**

- [x] **T13.1** — في [`app/Services/Handlers/ApplicationHandler.php`](app/Services/Handlers/ApplicationHandler.php)، دوّر على:

```php
        $deliveryDocuments = match ($this->normalizeVehicle($application['work_vehicle'] ?? null)) {
            'bicycle' => ['trips_screenshot'],
            'motorcycle', 'car' => ['trips_screenshot', 'driver_license'],
            default => ['trips_screenshot', 'driver_license'],
        };
```

**بدّله بـ:**

```php
        $deliveryDocuments = match ($this->normalizeVehicle($application['work_vehicle'] ?? null)) {
            'bicycle' => ['trips_screenshot'],
            'motorcycle', 'car' => ['trips_screenshot', 'driver_license'],
            /*
             * المركبة لسه مش معروفة. الـ default القديم كان بيطلب رخصة
             * قيادة - وده أسوأ افتراض ممكن: العميل اللي على عجلة بيسمع
             * إن مطلوب منه رخصة مش هتتطلب منه أصلًا، فيفهم إنه اترفض
             * ويسيب المحادثة. missingFields() بتوقف الفلو قبل مرحلة
             * المستندات لحد ما work_vehicle يتحدد، فالسطر ده احتياطي -
             * والاحتياطي المفروض يبقى الأقل تطلبًا مش الأكتر.
             */
            default => ['trips_screenshot'],
        };
```

- [x] **T13.2** — **أمر التحقق:**

```bash
grep -n "default => \['trips_screenshot'\]," app/Services/Handlers/ApplicationHandler.php
```

**الناتج المتوقع:** سطر واحد.

---

---

## ✅ Task T14 — اختبارات تراجع
**الحالة: ✅ DONE**

- [x] **T14.1** — أنشئ [`tests/Feature/BotUnderstandingRegressionTest.php`](tests/Feature/BotUnderstandingRegressionTest.php):

```php
<?php

namespace Tests\Feature;

use App\Services\ApplicationStateService;
use App\Services\MachineSearchService;
use App\Support\AddressParser;
use Tests\TestCase;

/**
 * اختبارات تراجع للسبع مشاكل اللي اتصلحت في AI_BOT_ISSUES_FIX_PLAN.md.
 * كل test هنا بيمثّل رسالة حقيقية من محادثة حقيقية كسرت البوت.
 */
class BotUnderstandingRegressionTest extends TestCase
{
    /** مشكلة 1: "دايونج" كانت بترجع صفر نتايج فالـ LLM يخمّن "دايو". */
    public function test_dayung_resolves_to_its_own_model(): void
    {
        $names = app(MachineSearchService::class)->search('دايونج', 20)->pluck('name')->all();

        $this->assertContains('Dayung', $names);
        $this->assertNotContains('دايو ٤', $names);
    }

    /** مشكلة 1 (تراجع): الموديلات اللي كانت شغالة لازم تفضل شغالة. */
    public function test_existing_model_matching_still_works(): void
    {
        $search = app(MachineSearchService::class);

        $this->assertContains('دايو ٤', $search->search('دايو ٤', 20)->pluck('name')->all());
        $this->assertNotEmpty($search->search('بينيلي', 20));
        $this->assertNotEmpty($search->search('هوجن ٤', 20));
        $this->assertNotEmpty($search->search('تي اكس', 20));
    }

    /** مشكلة 7: بينيلي براند حقيقي عندنا فيه موديلات. */
    public function test_benelli_brand_is_findable(): void
    {
        $names = app(MachineSearchService::class)->search('بينيلي', 20)->pluck('name')->all();

        $this->assertContains('VLR 150', $names);
    }

    /** مشكلة 4/6: "قدام" أشهر كلمة علامة مميزة وكانت ناقصة من القاموس. */
    public function test_landmark_keyword_qoddam_is_recognised(): void
    {
        $parsed = app(AddressParser::class)->parse('والله ساكن قدام سوبر ماركت الاخوه');

        $this->assertNotNull($parsed['landmark']);
        $this->assertStringContainsString('سوبر ماركت', $parsed['landmark']);
    }

    /** مشكلة 5: "١٢ ش فلان" رقم العمارة كان بيتحسب "منطقة". */
    public function test_leading_number_before_street_is_the_building(): void
    {
        $parsed = app(AddressParser::class)
            ->parse('١٢ ش محمد ابو النجا من العشرين عين شمس القاهره قدام شرموط الميكانيكيه');

        $this->assertSame('12', $parsed['building']);
        $this->assertNotSame('١٢', $parsed['area']);
        $this->assertNotNull($parsed['landmark']);
        $this->assertSame('القاهرة', $parsed['governorate']);
    }

    /** مشكلة 4/5/6: رد مجرد ما ينفعش يبقى "شارع" ويمسح الشارع الصح. */
    public function test_bare_answer_does_not_become_a_street(): void
    {
        $this->assertNull(app(AddressParser::class)->parse('سوبر ماركت الاخوه')['street']);
    }

    /** مشكلة 4/6: رد مجرد لازم يترمي على المكوّن اللي سألنا عنه. */
    public function test_bare_answer_binds_to_the_asked_component(): void
    {
        $result = app(ApplicationStateService::class)->bindAnswerToAskedComponent(
            ['home_address_components' => ['area' => 'عين شمس']],
            'home_address',
            'landmark',
            'والله ساكن قدام سوبر ماركت الاخوه'
        );

        $this->assertNotNull($result['home_address_components']['landmark']);
    }

    /** مشكلة 6: "السكن تمليك" لازم تتحسب إجابة على سؤال الملكية. */
    public function test_ownership_answer_is_bound(): void
    {
        $result = app(ApplicationStateService::class)->bindAnswerToAskedComponent(
            ['home_address_components' => []],
            'home_address',
            'ownership',
            'السكن تمليك'
        );

        $this->assertSame('ملك', $result['home_address_components']['ownership']);
    }

    /** مشكلة 4: ممنوع "استلمت منك كذا" في سؤال الناقص. */
    public function test_question_does_not_list_what_was_received(): void
    {
        $component = null;
        $field = null;

        $question = app(ApplicationStateService::class)->questionForMissing(
            ['home_address'],
            [
                'home_address' => 'x',
                'home_address_missing_components' => ['landmark'],
                'home_address_newly_received_components' => ['building', 'floor'],
            ],
            [],
            0,
            [],
            false,
            $component,
            $field
        );

        $this->assertStringNotContainsString('استلمت منك', $question);
        $this->assertStringContainsString('علامة مميزة', $question);
        $this->assertSame('landmark', $component);
    }
}
```

- [x] **T14.2** — **أمر التحقق:**

```bash
php artisan test --filter=BotUnderstandingRegressionTest
```

**الناتج المتوقع:** كل الاختبارات خضراء (`OK` / `PASS`).

---

---

## 3. ملاحظات معمارية مهمة (مش tasks — للعلم)

### 3.1 `MachineNameResolver.php` كود ميت
٨١٦ سطر، **صفر استخدامات** في المشروع كله:

```bash
grep -rn "MachineNameResolver" app routes tests | grep -v "^app/Services/MachineNameResolver.php"
# → لا شيء
```

وكمان معطوب أصلًا: `resolveOne()` بترجع `null` لكل موديل اسمه عربي، لأن `latinKey()` بتشيل كل الحروف العربية:

```
tx 250    => "Tx 250"    ✅
كي تي اكس => "KTX 250"   ✅
دايو ٤    => null         ❌  (اسم عربي)
هوجن ٤    => null         ❌
بينيلي    => null         ❌
```

**التوصية:** امسحه في PR منفصل بعد ما كل الـ tasks تخلص وتتأكد. متمسحوش دلوقتي.

### 3.2 التطبيع العربي مكرر في ٤ أماكن
`MachineSearchService::normalizeSearchText`، `ApplicationHandler::normalizeJobText`، `WhatsappIntentRouter::normalizeText`، و(بعد T6) `AddressParser::fold`. كل واحدة بتعمل حاجة شوية مختلفة، وده بالظبط مصدر أخطاء زي "القاهره ≠ القاهرة".

**التوصية:** بعد ما التاسكات تخلص، اعمل `App\Support\ArabicText::fold()` واحدة ونادي عليها من الأربعة. **PR منفصل** — متعملهوش مع الإصلاحات دي.

### 3.3 المبدأ اللي كل الإصلاحات دي بتطبّقه
> **الـ AI مش بيهلوس لما يكون غبي. بيهلوس لما يكون معندوش الحقيقة قدامه.**

كل مشكلة في الملف ده كانت نفس الشكل:
- بينيلي → البرومبت مفيهوش كتالوج → استنتج من "صيني وهندي بس"
- VLR → البرومبت فيه السعر بدون ما حد يسأل → استخدمه
- العلامة المميزة → الـ parser مبيعرفش "قدام" → البيانات ضاعت → سأل تاني
- العجلة → القراءة الحتمية مقفولة بشرط ضيق → المعلومة ضاعت

الحل في كل مرة **مش** برومبت أذكى — الحل إن الكود يدي الموديل الحقيقة كاملة، ويقرا الإجابة الحتمية قبل ما يسأل الموديل.

---

## 4. ترتيب التنفيذ الموصى به

```
اليوم 1  →  T1, T6, T7, T8      (إصلاحات الـ parsing - أعلى عائد، صفر مخاطرة)
اليوم 2  →  T9, T10             (ربط السؤال بالإجابة - أهم واحدة)
اليوم 3  →  T11, T12, T13       (المركبة والرخصة)
اليوم 4  →  T2, T3, T4, T5      (الكتالوج والسياق)
اليوم 5  →  T14                 (الاختبارات) + مراجعة كاملة
```

بعد كل يوم شغّل:

```bash
php artisan test && php artisan config:clear && php artisan cache:clear
```

---

## 5. قائمة التحقق النهائية (مليها بعد ما كل الـ tasks تخلص)

- [x] كل الـ ١٤ task حالتهم ✅ DONE
- [x] `php artisan test --filter=<كل كلاس متعلق بالتعديلات>` كله أخضر (شوف الملحوظة تحت — السويت الكامل عنده عطل قديم مش متعلق بالتعديلات دي)
- [x] `php -l` على كل الملفات المعدّلة بيعدّي
- [ ] اختبار يدوي على واتساب حقيقي للسبع سيناريوهات:
  - [ ] `دايونج` → بيرجع Dayung مش دايو
  - [ ] `عندكم بينيلي؟` → بيقول أيوه ويعرض الموديلات الأربعة
  - [ ] رسالة عامة بعد سؤال براند → **مبيرشحش** موديل من نفسه
  - [ ] `انا شغال طلبات على العجله` → **مبيطلبش** رخصة
  - [ ] `١٢ ش محمد ابو النجا` → **مبيسألش** عن رقم العمارة
  - [ ] `قدام سوبر ماركت الاخوه` → بيقبل العلامة المميزة من أول مرة
  - [ ] `السكن تمليك` → **مبيرجعش** يسأل على العلامة المميزة تاني

## ملحوظة عن حالة الـ test suite

كل الـ ١٤ task اتنفذوا واتحققوا فعليًا (كود + `php -l` + اختبارات PHPUnit تشغّلت وطلعت النتيجة المتوقعة، مش افتراض). لكن فيه عطل **قديم وخارج نطاق الملف ده** لازم تعرفه:

- مايجريشن `database/migrations/2025_11_16_212617_update_work_status_enum_in_installment_requests.php` بيستخدم `ALTER TABLE ... MODIFY` وهو syntax خاص بـ MySQL بس، بينما `phpunit.xml` بيشغّل الاختبارات على sqlite في الذاكرة. النتيجة: أي test بيستخدم `RefreshDatabase` بيفشل - في المشروع كله، من قبل أي تعديل هنا. اتأكد من كده بمقارنة `git stash` (الشجرة الأصلية طلعت نفس عدد الفشل تقريبًا).
- تشغيل السويت الكامل (`php artisan test`) كمان بيوريّ عطل ثانوي تراكمي ("Call to a member function __call() on null" في `eloquent-power-joins`) بيظهر بعد أول test فاشل بسبب المايجريشن ده - وده برضو موجود على الشجرة الأصلية (اتأكد منه بنفس الطريقة).
- عشان كده التحقق اتعمل بتشغيل كل test class لوحده (`php artisan test --filter=<Class>`) - وده رجع **كله أخضر** لكل ملف له علاقة بالتعديلات: `AddressParserTest`، `ApplicationHandlerTest` (١٨/١٨)، `ApplicationTurnQuestionTest`، `ApplicationCategoryTest`، `ApplicantDataVerifierTest`، `ApplicantNameValidatorTest`، `ApplicantNameRecoveryTest`، `CategoryRequirementsNoteTest`، `DeliveryVehicleDocumentsTest`، `DocumentOwnershipCheckTest`، `ApplicationStatusHandlerTest`، و`BotUnderstandingRegressionTest` (١٠/١٠ الجديدة).
- تعديل واحد إضافي طرأ أثناء التحقق ومش مكتوب في أي task أصلي: `tests/Unit/ApplicationHandlerTest.php::test_address_component_acknowledges_what_was_just_received` كان بيتأكد من نص "استلمت منك اسم الشارع" - وهو بالظبط النص اللي T10 شالته بقرار صريح من صاحب المعرض. اتعدّل الـ assertion يتأكد من غياب النص ده بدل وجوده.
- إصلاح إضافي طرأ من تأثير جانبي حقيقي لـ T6: بعد ما `AddressParser::parse()` بقى بيطبّع `$text` بالكامل (`fold()`)، السطرين اللي بيشيلوا اسم المحافظة/المدينة من الـ leftover قبل fallback الشارع كانوا لسه بيقارنوا بالنص **الخام** غير المطبّع (`$components['city']`/`$components['governorate']` جايين من الثوابت زي "٦ أكتوبر" بالهمزة)، فمكانوش بيتطابقوا مع `$remaining` المطبّع - والنتيجة اسم المدينة كان بيتسرّب ويتحط غلط كـ"شارع" (اكتشفناه من `test_city_only_address_is_partial_not_fully_missing` اللي كان بيفشل). اتصلح بتطبيق `fold()` على قيمة المقارنة قبل الـ `str_replace` - موثّق بكومنت في الكود نفسه.

الاختبارات اليدوية على واتساب حقيقي في القايمة فوق لسه محتاجة حد يجربها فعليًا - مش حاجة ممكن تتحقق من الكود لوحده.
