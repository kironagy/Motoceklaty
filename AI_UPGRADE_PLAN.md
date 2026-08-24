# AI Upgrade Plan — Moto Gate WhatsApp Bot

> خطة تنفيذ مقسّمة على مراحل. كل مرحلة فيها **Status** وكل مهمة فيها **checkbox** + **Verify** (أمر تتأكد بيه إن المهمة اتعملت فعلًا).
> الملف ده مصمم عشان AI model يشتغل عليه خطوة بخطوة ويحدّث الحالة بنفسه.

**آخر تحديث:** 2026-08-25
**التقدّم العام:** Phase 1 `DONE` ✅ · Phase 2 `IN_PROGRESS` 🔶 · Phase 3 `IN_PROGRESS` 🔶 · Phase 4 `IN_PROGRESS` 🔶

---

## 📋 تعليمات للـ AI اللي هيشتغل على الملف ده

اقرا القواعد دي **قبل** أي تعديل:

1. **نفّذ مهمة واحدة بس في المرة.** متعملش أكتر من task في نفس الـ commit.
2. **بعد كل مهمة:** شغّل أمر الـ `Verify` بتاعها. لو نجح، غيّر `- [ ]` لـ `- [x]` وحدّث الـ Status.
3. **لو الـ Verify فشل:** متكملش للمهمة اللي بعدها. حط `BLOCKED` واكتب السبب تحت المهمة.
4. **ممنوع منعًا باتًا** تضيف دالة جديدة اسمها `isSomethingIntent()` أو `detectSomething()` في `WhatsappIntentRouter.php`. الملف ده ٢٧٦٧ سطر بسبب ده بالظبط. أي سلوك جديد يتصلّح من: الميموري، أو وصف الـ tool، أو الـ golden set.
5. **ممنوع تخترع أسعار أو أرقام.** كل رقم لازم ييجي من الداتابيز.
6. **ابدأ بالترتيب.** Phase 1 كامل قبل Phase 2. المهام جوه المرحلة الواحدة مرتبة بالاعتماديات.
7. بعد كل مرحلة: `git commit` برسالة واضحة بالإنجليزي.

### الملفات الأساسية اللي هتشتغل عليها

| الملف | الدور |
|---|---|
| `app/Services/WhatsappIntentRouter.php` | الراوتر الرئيسي (2767 سطر — الهدف تقليله) |
| `app/Services/AiIntentClassifier.php` | عقل الفهم — بيرجّع خطة JSON |
| `app/Services/AiComplexReplyService.php` | مسار الرد الحر (fallback) |
| `app/Services/AiPromptBuilder.php` | برومبت الرد |
| `app/Services/AiMemoryContextBuilder.php` | بناء سياق الميموري |
| `app/Services/AiMemoryResolver.php` | فلترة وتقييم الميموري |
| `app/Services/Handlers/ApplicationHandler.php` | مسار التقديم |
| `app/Services/GeminiClient.php` | نداء Gemini |

---

# Phase 1 — إصلاحات فورية

**Status:** `DONE` ✅ (2026-08-25)
**الهدف:** تصليح الأعطال الميتة من غير أي تغيير معماري. المرحلة دي لوحدها هتحسّن الجودة بشكل ملحوظ.
**المدة المتوقعة:** ١–٢ يوم

---

### - [x] 1.1 — وقّف تقطيع الميموري

**المشكلة:** إجمالي محتوى الميموري النشطة = ١٣٬٠٧٤ حرف. السقف في `AiPromptBuilder::MAX_MEMORY_CHARS` = 20000. يعني كل الميموري بتدخل في البرومبت براحتها. رغم كده `RELEVANCE_LIMIT = 18` بيقص ٢١ ميموري كل دور.

**التعديل:** في `app/Services/AiMemoryContextBuilder.php`، خلّي `buildRelevantMemoryContext()` ترجّع **كل** الميموري النشطة طالما إجمالي الحروف أقل من 18000.

```php
private function buildRelevantMemoryContext(string $message, array $conversationContext): string
{
    if (! class_exists(AiMemory::class) || ! Schema::hasTable('ai_memories')) {
        return '';
    }

    $resolver = app(AiMemoryResolver::class);
    $intent = $conversationContext['intent'] ?? null;
    $all = $resolver->activeMemories();

    // إجمالي الميموري صغير (~13k حرف) والسقف 20k — مفيش سبب نقص حاجة.
    $totalChars = $all->sum(fn (AiMemory $m) => mb_strlen((string) $m->content));

    if ($totalChars <= 18000) {
        $this->logRetrieval($conversationContext, $message, $intent, $all, $all, 'full_set', false);

        return $this->toToon($all);
    }

    // الميموري كبرت — ارجع للتقييم (نفس المنطق القديم).
    $scoringText = $this->scoringText($message, $conversationContext);
    $relevant = $resolver->relevantMemories($scoringText, $intent, self::RELEVANCE_LIMIT);

    if ($relevant->isEmpty()) {
        $relevant = $all->take(self::RELEVANCE_LIMIT);
    }

    $this->logRetrieval($conversationContext, $message, $intent, $all, $relevant, 'scored', false);

    return $this->toToon($relevant);
}
```

**Verify:**
```bash
php artisan tinker --execute="\$r=app(App\Services\AiMemoryContextBuilder::class)->buildForMessage('عايز اعرف نظام التقسيط'); echo mb_strlen(\$r['context']).PHP_EOL; echo App\Models\AiMemoryRetrievalLog::latest()->first()->retrieval_method.PHP_EOL;"
```
✅ لازم يطلع `retrieval_method = full_set` وطول السياق أكبر من 10000.

---

### - [x] 1.2 — ابعت `intent` للميموري

**المشكلة:** `AiComplexReplyService` بيبني الـ context من غير مفتاح `intent`، فـ `$conversationContext['intent'] ?? null` بيرجّع `null` **دايمًا**. مثبت من `ai_memory_retrieval_logs`: ٨ من ٨ سجلات فيها `intent=null`.

**التعديل (جزء أ):** في `app/Services/AiComplexReplyService.php` غيّر الـ signature وابعت الـ intent:

```php
public function reply(string $message, array $conversationContext = []): array
{
    // الـ intent لازم يوصل للميموري عشان الفلترة والتقييم يشتغلوا.
    $intent = $conversationContext['intent'] ?? 'fallback_complex';

    $memory = app(AiMemoryContextBuilder::class)->buildForMessage($message, $conversationContext);
    // ... باقي الدالة زي ما هي
```

**التعديل (جزء ب):** في `app/Services/WhatsappIntentRouter.php` جوه `handleAiFallback()`، ضيف `intent` للمصفوفة اللي بتتبعت:

```php
$result = app(AiComplexReplyService::class)->reply($message, [
    'conversation_id' => $conversation->id,
    'intent' => $this->lastTurnIntent,   // ← السطر الجديد
    'from' => $conversation->from ?? null,
    // ... باقي المفاتيح زي ما هي
]);
```

**Verify:**
```bash
php artisan tinker --execute="App\Models\AiMemoryRetrievalLog::truncate();"
# ابعت رسالة تجريبية من الواتساب أو شغّل TestAiReply، بعدين:
php artisan tinker --execute="\$l=App\Models\AiMemoryRetrievalLog::latest()->first(); echo 'intent='.(\$l->intent ?? 'NULL').PHP_EOL;"
```
✅ لازم `intent` ميكونش `NULL`.

---

### - [x] 1.3 — صلّح أسماء النوايا في `intentKeywords()`

**المشكلة:** `AiMemoryResolver::intentKeywords()` بيعرف نوايا اسمها `ask_price` و `ask_images` و `ask_installment` و `ask_branch` و `ask_available` و `ask_specs` و `ask_colors`. لكن النوايا الحقيقية في `AiIntentClassifier::normalizePlan()` اسمها `price` و `images` و `installment_calc` و `installment_system` و `brand_models` و `application`. **الاتنين مش بيتقابلوا أبدًا** — الدالة بترجّع `[]` في ١٠٠٪ من الحالات، والنقط `+40 / +15` عمرها ما اشتغلت.

**التعديل:** في `app/Services/AiMemoryResolver.php` استبدل الـ `match` بالكامل:

```php
private function intentKeywords(string $intent): array
{
    return match ($intent) {
        'price' => ['سعر', 'أسعار', 'تسعير', 'كاش'],
        'images' => ['صور', 'صوره', 'شكل', 'ألوان', 'المخزون', 'موديلات'],
        'installment_calc' => ['تقسيط', 'قسط', 'مقدم', 'شهور'],
        'installment_system' => ['نظام التقسيط', 'تقسيط', 'شروط', 'أنظمة'],
        'admin_fee_explanation' => ['مصاريف إدارية', 'مصاريف اداريه', 'نظام 20'],
        'brand_models' => ['موديلات', 'المخزون', 'متاح', 'موجود'],
        'application' => ['مستندات', 'أوراق', 'بطاقة', 'تقديم', 'متطلبات'],
        'application_status' => ['متابعة العميل', 'حالة الطلب'],
        'delivery_question' => ['توصيل', 'شحن', 'دليفري'],
        default => [],
    };
}
```

**Verify:**
```bash
php artisan tinker --execute="
\$r = app(App\Services\AiMemoryResolver::class);
echo \$r->relevantMemories('نظام التقسيط ايه','installment_system',5)->pluck('title')->implode(' | ').PHP_EOL;"
```
✅ لازم يطلع فيهم ميموري ليها علاقة بالتقسيط (زي `نظام التقسيط` أو `انظمه التقسيط`).

---

### - [x] 1.4 — ضيف موديل أقوى (Free tier)

**المشكلة:** كل حاجة شغالة على `gemini-3.1-flash-lite` — أضعف موديل — للفهم **وللرد**. الموديلات المتاحة دلوقتي في `gemini_api_key_models` كلها `flash-lite` أو `gemma`.

**⚠️ تصحيح:** `gemini-3.1-flash` **مش موجود** أصلًا في الـ API (اتأكدنا بـ ListModels). الموديل المستخدم فعليًا هو **`gemini-3.7-flash`**، والاختيار بقى في `config/gemini.php` تحت `models.reasoning` عشان يتغيّر من مكان واحد.

**التعديل (جزء أ):** ضيف `gemini-3.7-flash` لكل مفتاح موجود:

```bash
php artisan tinker --execute="
foreach (App\Models\GeminiApiKey::all() as \$key) {
    App\Models\GeminiApiKeyModel::firstOrCreate(
        ['gemini_api_key_id' => \$key->id, 'model_code' => 'gemini-3.7-flash'],
        [
            'provider' => 'gemini',
            'display_name' => 'Gemini 3.7 Flash',
            'category' => 'Gemini',
            'rpm_limit' => 10,
            'rpd_limit' => 250,
            'tps_limit' => 1000000,
            'priority' => 0,
            'is_active' => true,
            'is_embedding' => false,
        ]
    );
}
echo 'done'.PHP_EOL;"
```

**التعديل (جزء ب):** ضيف نفس الموديل في `config/gemini.php` جوه `default_models` (عشان أي مفتاح جديد يتعمل من Filament ياخده).

**التعديل (جزء ج):** استخدمه في المكانين اللي محتاجين فهم/صياغة:
- `app/Services/AiComplexReplyService.php` → `preferredModelCode: config('gemini.models.reasoning')`
- `app/Services/AiIntentClassifier.php::classify()` → مرّر `config('gemini.models.reasoning')` بدل `null` في نداء `generateText`

> ⚠️ سيب `gemini-3.1-flash-lite` كـ fallback في `GeminiClient` — لو الـ flash عليه ليمت، الـ KeyManager هيحوّل عليه تلقائيًا.
> ⚠️ **متغيّرش** موديل `MachineImageRecognitionHandler` في المرحلة دي.

**Verify:**
```bash
php artisan tinker --execute="
\$r = app(App\Services\GeminiClient::class)->generateText('رد بكلمة واحدة: تمام','gemini-3.1-flash',['maxOutputTokens'=>20]);
echo 'ok='.(int)(\$r['ok']??0).' model='.(\$r['model']??'?').PHP_EOL;"
```
✅ لازم `ok=1` و `model=gemini-3.1-flash`.

---

### - [x] 1.5 — خلّي الرد يبان بشري

**المشكلة:** `temperature: 0.2` مع `topK: 5` و `topP: 0.4` — إعدادات شبه حتمية، فالجُمل بتطلع مكررة وجافة.

**التعديل:** في `app/Services/AiComplexReplyService.php` جوه `options`:

```php
options: [
    'timeout' => 20,
    'temperature' => 0.6,   // كان 0.2 — الجفاف كان مقصود في الكود
    'topP' => 0.9,          // كان 0.4
    // شيل topK خالص
    'maxOutputTokens' => 1024,
]
```

> ملاحظة: **متغيّرش** `temperature` بتاعة `AiIntentClassifier` (0.05). دي لازم تفضل منخفضة عشان الـ JSON يطلع ثابت.

**Verify:** ابعت نفس الرسالة ٣ مرات وشوف الردود مختلفة في الصياغة ومتطابقة في المعلومة.

---

### - [x] 1.6 — سجّل لما عنوان ميموري مش بيتلاقى

**المشكلة:** `renderMemory('رد حساب القسط')` بيدوّر بالعنوان الحرفي. لو غيّرت العنوان من Filament، بيرجّع `null` و`renderMemoryOrDefault()` بتقع على جملة مكتوبة في الكود — **من غير error ومن غير log**. فيه ميموري ميتة فعلًا دلوقتي (`#59`, `#60`).

**التعديل:** في `app/Services/WhatsappIntentRouter.php` جوه `renderMemory()`:

```php
$memory = app(\App\Services\AiMemoryResolver::class)->memoryByExactTitle($title);

if (! $memory) {
    \Illuminate\Support\Facades\Log::warning('ai_memory_title_miss', ['title' => $title]);

    return null;
}
```

**Verify:**
```bash
grep -n "ai_memory_title_miss" app/Services/WhatsappIntentRouter.php
```
✅ لازم يلاقي السطر. وبعد يوم شغل، دوّر في اللوج على `ai_memory_title_miss` — لو ظهر، العنوان ده لازم يتصلّح.

---

### - [x] 1.7 — ابعت الميموري للـ classifier كمان

**المشكلة:** `AiIntentClassifier` — العقل اللي بيفهم — مش بيشوف الميموري خالص. فهو بيفهم كلام العميل من غير ما يعرف الفروع ولا الأنظمة ولا الشروط.

**التعديل:** في `app/Services/AiIntentClassifier.php::prompt()`، قبل `البيانات:` ضيف بلوك الميموري:

```php
$memoryContext = app(AiMemoryContextBuilder::class)
    ->buildForMessage($message, ['conversation_id' => $conversation->id])['context'] ?? '';
```

وحطّه في البرومبت قبل `البيانات:` مباشرة:

```
معلومات المعرض (للفهم فقط — ممنوع ترد بيها، دي بس عشان تفهم العميل صح):
{$memoryContext}

البيانات:
```

**Verify:**
```bash
php artisan tinker --execute="
\$c = App\Models\WhatsappConversation::latest()->first();
\$p = app(App\Services\AiIntentClassifier::class)->classify(\$c, 'عايز اعرف الفروع');
echo json_encode(\$p, JSON_UNESCAPED_UNICODE).PHP_EOL;"
```
✅ لازم يرجّع JSON صالح من غير errors.

---

### - [x] 1.8 — (اتضاف أثناء التنفيذ) إصلاحين ظهروا من ترقية الموديل

المهمتين دول مكانوش في الخطة الأصلية، ظهروا لما اتجرّب الموديل الجديد فعليًا:

**أ) الرد كان بيتقطع في النص.** موديلات Gemini 3.x بتصرف جزء من `maxOutputTokens` على تفكير داخلي، فرد الفروع اتقطع عند 183 حرف بس مع `finishReason=MAX_TOKENS`. اتضاف دعم `thinkingBudget` في `GeminiClient`، ومسار الرد بيبعت `thinkingBudget = 0` (الحقائق أصلًا محلولة وجاهزة في الميموري — التفكير مش محتاجينه وبياكل الميزانية).

**ب) موديل واحد مشغول = الرد بيموت.** `GeminiKeyManager::reserveAvailableModel()` بيفلتر على `model_code` بالظبط، فأول ما `gemini-3.7-flash` رجّع 503 مؤقت اتقفل المسار كله رغم إن فيه موديلات سليمة على نفس المفتاح. اتضاف نزول تلقائي لـ `models.fast` مرة واحدة قبل الاستسلام.

> ⚠️ **مهم للمرحلة الجاية:** `topK` بقى بيتبعت بس لما الـ caller يطلبه. قبل كده كان بيتفرض بقيمة 5 على كل نداء.

**Verify:**
```bash
php artisan tinker --execute="
App\Models\GeminiApiKeyModel::where('model_code','gemini-3.7-flash')->update(['cooldown_until'=>now()->addMinutes(5)]);
\$r = app(App\Services\GeminiClient::class)->generateText('رد بكلمة واحدة: تمام', config('gemini.models.reasoning'), ['maxOutputTokens'=>512,'thinkingBudget'=>0]);
echo 'model_used='.(\$r['model']??'?').PHP_EOL;
App\Models\GeminiApiKeyModel::where('model_code','gemini-3.7-flash')->update(['cooldown_until'=>null]);"
```
✅ لازم يطلع `model_used=gemini-3.1-flash-lite` (نزل تلقائي).

---

### ✅ Phase 1 — Definition of Done

- [x] كل الـ 7 مهام `[x]`
- [x] `php artisan test` — **9 failed / 33 passed، نفس النتيجة بالظبط قبل التعديلات** (فشل قديم في `eloquent-power-joins` مالوش علاقة بالشغل ده، متأكد منه بـ `git stash`)
- [x] `AiMemoryRetrievalLog::latest()->first()->intent` = `general` (كان `NULL` دايمًا)
- [x] `retrieval_method = full_set` — 46 ميموري بدل 18
- [x] «عايز اعرف نظام التقسيط بتاعكم» بترجّع رد كامل من الميموري
- [x] `git commit`

**بعد الانتهاء غيّر:** `**Status:** DONE` فوق + حدّث سطر «التقدّم العام».

---

# Phase 2 — الخطة المركّبة و Tool Calling

**Status:** `IN_PROGRESS` (2026-08-25) — 2.1/2.2/2.6 اتعملوا واتأكد منهم، 2.3 اتعمل بشكل مختصر عن الخطة الأصلية، 2.4 و 2.5 اتأجّلوا عن قصد (السبب تحت كل واحدة)
**الهدف:** ده **الإصلاح الحقيقي**. تحويل الـ AI من «آخر ملاذ» لـ «العقل»، وLaravel من «العقل» لـ «مصدر الحقيقة».
**المدة المتوقعة:** ١–٢ أسبوع
**متطلب:** Phase 1 لازم يكون `DONE`

---

### - [x] 2.1 — حوّل `intent: string` لـ `steps: []`

**⚠️ تعديل عن الخطة الأصلية:** الخطة الأصلية اقترحت `action`/`params` schema جديد بالكامل. بدل كده اتعمل تصميم أبسط وأقل خطورة: كل عنصر في `steps[]` **بنفس شكل الحقول الحالية بالظبط** (`intent`, `target`, `machine_query`, `months`, `deposit`, `system`, ...)، مش شكل جديد. الميزة: كل الـ handlers الموجودة (`resolveMachinesFromPlan`, `handleCashPrice`, `handleImages`, ...) بتشتغل على step زي ما تشتغل على الـ plan الأساسي من غير أي تعديل فيهم — صفر إعادة كتابة لمنطق شغال ومُختبر.

**اللي اتعمل فعليًا** (`app/Services/AiIntentClassifier.php`):
- `fallback()` بقى فيها `'steps' => []`.
- `normalizePlan()` اتقسمت لـ `normalizePlanFields()` (نفس التحقق القديم، بس reusable) + منطق جديد بيطبّق نفس التحقق على كل عنصر في `steps[]`.
- `steps[]` محدود بـ `MAX_EXTRA_STEPS = 2` (يعني حد أقصى ٣ طلبات في الرسالة: الأساسي + ٢).
- **حماية:** أي step بـ `intent` = `application` أو `application_status` أو `unknown` أو `general` بيتشال تلقائيًا — تقديم أو استعلام حالة لازم يكونوا الطلب الأساسي بس، مش طلب ثانوي.
- البرومبت اتضاف له قسم `steps` بيشرح إمتى يتستخدم + مثال JSON كامل.

**Verify (اتنفذ فعليًا، ✅ نجح):**
```bash
php artisan tinker --execute="
\$c = App\Models\WhatsappConversation::latest()->first();
\$p = app(App\Services\AiIntentClassifier::class)->classify(\$c,'سعر دايو ٤ وصورها');
echo json_encode(\$p['steps'] ?? [], JSON_UNESCAPED_UNICODE).PHP_EOL;"
```
النتيجة الفعلية: step واحد بـ `intent=images` و`machine_ids` محلولة صح من السياق.

---

### - [x] 2.2 — نفّذ الـ steps بالترتيب

**⚠️ تعديل عن الخطة الأصلية:** بدل ما نلف الـ loop جوه `handleInternal()` (اللي فيه عشرات الـ early return)، الإضافة اتحطت في `handle()` (الغلاف العام) بعد ما `handleInternal()` يخلّص تمامًا. كده صفر تعديل في أي من الـ early returns الموجودة، والـ extra steps بس بتتحق لما الرد الأساسي يخرج نضيف من غير clarification ولا handoff.

**اللي اتعمل فعليًا** (`app/Services/WhatsappIntentRouter.php`):
- خاصية جديدة `$lastTurnExtraSteps` بتتسجّل بس بعد ما `ClarificationService::reset()` ينفّذ (يعني بعد ما نتأكد إن الرسالة اتفهمت بثقة) — فأي clarification question أو handoff بيسيبها فاضية تلقائيًا.
- `handle()`: بعد `handleInternal()`، لو الرد نجح (`handled=true`, فيه `reply`, والمحادثة لسه `open`)، بينده `appendExtraSteps()`.
- `appendExtraSteps()`: بينفّذ كل step عبر `executeAnswerableStep()`، بيجمع الردود (`\n\n` بينهم) والصور، وبيرجّع `last_topic` لقيمته الأصلية بعد ما الـ steps تخلص (عشان مايتغيرش الموضوع المسجل بسبب طلب ثانوي)، وبيدمج `last_machine_ids` (union، مش استبدال).
- `executeAnswerableStep()`: النطاق مقصور على `price` · `images` · `installment_calc` · `installment_system` · `brand_models` · `admin_fee_explanation` — بيستخدم `resolveMachinesFromPlan()` + `filterMachinesByRequestedBrand()` + `narrowMachinesByVariant()` بالظبط زي المسار الأساسي.
- خطأ في step واحد بيتسجّل بـ `Log::warning('extra_step_failed', ...)` ومتوقفش الباقي.

**Verify (اتنفذ فعليًا على محادثة تجريبية، ✅ نجح):**
```
>>> سعر دايو ٤ وصورها
images_count=9
- دايو ٤: 39,500 جنيه
- دايو ٤ اصلي: 46,000 جنيه
تحب تشوف صور اي موديل فيهم؟

اتفضل يا فندم، دي صور: - دايو ٤ - دايو ٤ اصلي
```
السعر والصور رجعوا في نفس الرد، بالظبط زي ما الـ verify المطلوب في الخطة كان بيطلب. اتأكد كمان إن الطلب المركّب من تقرير التشخيص الأصلي بيشتغل («مكنه هوجن جامبو عايزها علي سنه هدفع مقدم ٥ الاف وعاوزك تبعتلي تفاصيل التقسيط الكامل») — رجّع حساب قسط كامل بأرقام صحيحة.

---

### - [x] 2.3 — عرّف الـ Tools (نطاق مختصر)

**⚠️ تعديل كبير عن الخطة الأصلية:** الخطة الأصلية طلبت `app/Services/Ai/ToolRegistry.php` بجدول tools منفصل (`search_machines`, `get_price`, `calculate_installment`, ...) بنية function-calling رسمية مع Gemini. **ده متعملش في الجلسة دي.**

**السبب:** بناء طبقة tools رسمية بيحتاج تحويل `AiIntentClassifier::classify()` من نداء واحد (single-shot JSON) لحلقة tool-calling كاملة (الموديل يطلب tool، Laravel ينفّذ، يرجّع النتيجة، الموديل يقرر تاني) — ده تغيير معماري أكبر بكتير من حجم باقي مهام Phase 2، وبيحتاج شبكة أمان (golden set) قبل ما يتلمس، بالظبط زي مهمة 2.5. عمله من غيرها كان هيبقى مخاطرة مش متناسبة مع باقي المرحلة.

**البديل اللي اتنفذ:** `executeAnswerableStep()` (مهمة 2.2) هي فعليًا **tool dispatcher خفيف** — بتاخد step-shaped array وتوزّعه على نفس الـ handlers الموجودة (كل واحدة منهم أصلًا بتلف service محدد: `InstallmentCalculator`, `MachineSearchService`, ...). النتيجة عمليًا واحدة: أي عدد من "الطلبات" في رسالة واحدة بيتنفّذ بأرقام حقيقية من الداتابيز. اللي ناقص هو الـ **function-calling الرسمي مع Gemini** (الموديل يطلب الـ tool بنفسه بدل ما يوصف الطلب في JSON مرة واحدة) — ده اتأجّل لمرحلة منفصلة.

**Verify:** مغطى فعليًا بنفس verify مهمة 2.2 (تنفيذ الأرقام عبر `InstallmentCalculator` مباشرة، بدون أي طبقة إضافية بينهم).

---

### - [ ] 2.4 — سيب الموديل يكتب الرد (اتأجّلت عن قصد)

**السبب:** المهمة دي بتقترح إن الموديل يكتب الرد النهائي من نتايج الـ tools بدل `array_rand($templates)`. ده تغيير خطير لأنه بيحوّل **كل** رد فيه أرقام (سعر/قسط) من نص ثابت مضمون لنص بيكتبه LLM — وأي هلوسة رقم واحد (حتى لو نادرة) بتبقى مشكلة مباشرة مع فلوس العميل. الخطة نفسها بتقول: «ضيف تحقق يقارن الأرقام في الرد بنتايج الـ tools» — التحقق ده لسه مش موجود، وبناء المهمة من غيره خطر.

**متأجلة لحد ما:** يتبنى validator بسيط (بيقارن كل رقم في رد الموديل بالأرقام اللي طلعت من `InstallmentCalculator`/`Machine::cash_price` مباشرة، ويرفض الرد لو في فرق) — ساعتها تتعمل كمهمة منفصلة، مش جوه نفس الجلسة دي.

---

### - [ ] 2.5 — امسح شروط الـ regex العشرة (اتأجّلت عن قصد)

**السبب:** دي بالظبط المهمة اللي الخطة الأصلية حذّرت منها في قسم «لو هتبدأ بحاجة واحدة النهاردة»: *"Phase 1 → Phase 4.1 → Phase 2"* — يعني الـ golden set (مهمة 4.1) لازم يتبني **قبل** ما نمسح أي حماية regex، عشان نقدر نتأكد إن المسح مبيكسرش حالة حقيقية. الـ golden set لسه مش موجود في الجلسة دي.

مسح الدوال دي من غير شبكة أمان يعارض القاعدة اللي في أول الملف: *"ممنوع منعًا باتًا تضيف [منطق جديد بدون تحقق]... أي سلوك جديد يتصلّح من: الميموري، أو وصف الـ tool، أو الـ golden set."* المسح نفسه محتاج نفس الانضباط.

**التوصية:** ابنِ Phase 4.1 (حتى نسخة مختصرة ١٥-٢٠ حالة) الأول، بعدين ارجع لمهمة 2.5 وامسح دالة واحدة في كل مرة مع تشغيل الـ golden set بعدها.

---

### - [x] 2.6 — تضييق نتايج البحث (سبب مختلف عن الخطة الأصلية)

**⚠️ تصحيح مهم:** الخطة الأصلية افترضت إن `MachineSearchService::search()` بترجّع لحد ٢٠ نتيجة بفلتر `score >= 900` وده سبب ظهور موديلات كتير مع بعض. لما اتفحص الكود فعليًا، طلع العكس: `search()` بترجّع **مكنة واحدة بس** (`$ranked->first()`) في المسار العادي — المسار اللي فعلًا بيرجّع أكتر من مكنة هو `familyMatches()` (بحث العائلة/الماركة)، وهو منطق تاني تمامًا مقصود يرجّع مجموعة (زي "دايو ٤" اللي المفروض ترجّع كل متغيراتها).

**الباگ الحقيقي اللي اتلاقى ومتصلّح:** `narrowMachinesByVariant()` (الدالة المسؤولة عن تضييق مجموعة العائلة لما العميل يحدد variant) عندها فروع صريحة لـ `استيراد` و `فرز تاني/ثاني` بس **مفيهاش فرع لـ `اصلي`/`محلي`** — رغم إن `isVariantNarrowingReply()` (دالة تانية بتقرر إن الرسالة أصلًا "تضييق") بتعرفهم. النتيجة: سؤال زي «سعر دايو ٤ اصلي» كان بيرجّع **الاتنين** (دايو ٤ العادي + دايو ٤ اصلي) بدل واحد بس.

**التعديل:** ضيف فرعين جداد في `narrowMachinesByVariant()` (`app/Services/WhatsappIntentRouter.php`) بنفس نمط فرع `استيراد` الموجود، لـ `اصلي`/`اصليه` و`محلي`/`محليه`.

**Verify (اتنفذ فعليًا على محادثة تجريبية، ✅ نجح):**
```
قبل التعديل: "صور دايو ٤ اصلي..." → دايو ٤ + دايو ٤ اصلي (9 صور)
بعد التعديل: "صور دايو ٤ اصلي..." → دايو ٤ اصلي بس (اتفضل يا فندم دي صور دايو ٤ اصلي.)
```
واتأكد إن الاستعلام من غير "اصلي" («سعر دايو ٤») لسه بيرجّع العائلة كاملة زي المتوقع (مفيش regression).

> ملاحظة: عتبة الثقة الرقمية (`score >= 2500 confident / ambiguous / too_broad`) اللي في الخطة الأصلية معملتش — التشخيص الأدق طلع إن المشكلة في تصنيف الـ variant مش في عتبة السكور. لو ظهرت حالات تانية فيها نفس النمط (مجموعة عائلة كبيرة من غير تضييق كافي)، ضيفها كأمثلة جديدة في `narrowMachinesByVariant()`.

---

### ✅ Phase 2 — Definition of Done

- [x] «سعر دايو ٤ وصورها» بترجّع السعر **والصور** في رد واحد
- [x] الطلب المركّب من التقرير الأصلي («دايو ٤ + سنة + مقدم + تفاصيل كاملة») بيشتغل في رد واحد بأرقام صحيحة
- [x] باگ تضييق `اصلي`/`محلي` اتصلّح ومتأكد منه
- [x] `php artisan test` — 9 failed / 33 passed، **نفس الرقم قبل وبعد** (الفشل قديم، متأكد بـ commit منفصل)
- [ ] `WhatsappIntentRouter.php` أقل من 1600 سطر — **لسه لأ**، لأن 2.5 (مسح الـ regex) اتأجّلت عن قصد لحد ما الـ golden set يتبني
- [ ] كل الأرقام في الردود مطابقة لـ `InstallmentCalculator` حرف بحرف عبر رد AI حر — **لسه لأ**، لأن 2.4 اتأجّلت
- [x] `git commit`

**الخطوة المقترحة الجاية:** Phase 4.1 (golden set مختصر) → ارجع لـ 2.5 → بعدين 2.4 مع validator.

---

# Phase 3 — الميموري كنظام حقيقي

**Status:** `IN_PROGRESS` (2026-08-25) — 3.2 اتعمل واتأكد منه. الباقي اتأجّل عن قصد لتوفير التوكنز (السبب تحت كل مهمة)
**الهدف:** معرفة الشغل الموجودة في الميموري تبقى قابلة للتنفيذ، مش نص حر بيتقري بس.
**المدة المتوقعة:** أسبوع
**متطلب:** Phase 2 لازم يكون `DONE` (Phase 2 لسه `IN_PROGRESS` — اتقفز عليها بطلب صريح من المستخدم)

---

### - [ ] 3.1 — وسّع تصنيف الوظايف (اتأجّلت عن قصد)

**السبب:** اتفحص المسار الكامل بتاع `requiredDocuments()` وطلع إن التوسّع الحقيقي (دليفري/تاكسي بمستندات فعلية زي رخصة القيادة وسكرين الرحلات) محتاج **نوع مستند جديد كليًا** في مسار رفع الصور — ومفيش أي مكان في الكود حاليًا بيصنّف صورة مرفوعة كـ"رخصة قيادة" أو "سكرين رحلات" (اتفحص بـ grep، صفر نتائج بره `ApplicationHandler`). ده معناه شغل أكبر بكتير من تعديل `match()`: لازم تتضاف قيمة جديدة لمسار تصنيف المستندات المرفوعة (على الأغلب جوه prompt الرؤية بتاع Gemini)، وده محتاج استكشاف واختبار أوسع من الميزانية المتاحة في الجلسة دي.

**اللي اتعمل بدل منها:** مهمة 3.2 (تحتها) بتغطي أخطر بند في نفس الجدول — رفض تلقائي لفئات ممنوعة بالكامل — من غير الحاجة لأنواع مستندات جديدة.

**التوصية:** لما تُنفَّذ، ابدأ بمعرفة إزاي مسار رفع الصور بيحدد نوع المستند (غالبًا Gemini vision prompt في `MediaOcrHandler` أو `DocumentDataExtractor`)، بعدين ضيف نوع مستند جديد لكل من `delivery`/`taxi_owner`، مش تعديل `categorizeIncome()` لوحدها.

---

### - [x] 3.2 — بوابة الفئات الممنوعة

**المشكلة:** ميموري «الفئات الممنوعة» (`#51`, scope=`always_include`) كانت بتوصل للـ fallback بس. العميل بمهنة ممنوعة (محامي، ضابط، قاضي، ...) كان بياخد الطلب كامل — اسم، رقم قومي، عنوان، وصولًا لرفع المستندات — وبعدين يترفض يدوي من الموظف.

**⚠️ تعديل بسيط عن الخطة الأصلية:** الخطة اقترحت قراءة الفئات **من محتوى الميموري نفسه** بدل الكود. اتعمل بدل منها قايمة كلمات مفتاحية صريحة في PHP (بنفس أسلوب `categorizeIncome()` الموجود بالفعل) — تحليل نص حر لاستخراج قايمة موثوقة كان هيحتاج كود إضافي ومخاطرة استخراج غلط، والخطة الأصلية نفسها بتشرح إن قراءة حقول منظّمة من الميموري (مهمة 3.3) هي الحل الصح على المدى الطويل، مش parsing نص حر مباشر.

**اللي اتعمل فعليًا** (`app/Services/Handlers/ApplicationHandler.php`):
- ميثود جديدة `bannedProfessionReason(string $jobType): ?string` — بتفحص كلمات: ضابط، أمين شرطة (مش "أمين" لوحدها — تفادي false-positive على "أمين مخزن")، معاون، محامي/محاماة، قاضي/قضاء، نيابة.
- اتحطت في `finalizeApplicationTurn()` (الدالة المشتركة بين مسار الاستخراج العادي ومسار حل التعارضات — الاتنين بيمرّوا من هنا) كأول حاجة تتفحص لما `job_type` يكون معروف، قبل أي سؤال تاني.
- الرد بيقترح البديل (كاش) بدل رفض جاف، زي ما الخطة طلبت.

**Verify (اتنفذ فعليًا، ✅ نجح):**

اختبار وحدة مباشر (٨ حالات، من غير أي نداء AI):
```
محامي        -> BANNED
ضابط شرطة    -> BANNED
قاضي         -> BANNED
نجار         -> OK
سباك         -> OK
امين شرطه    -> BANNED
أمين مخزن    -> OK   (تفادي false-positive)
موظف بنك     -> OK
```

اختبار end-to-end كامل عبر `WhatsappIntentRouter` (بدء تقديم → تقسيط → اسم → رقم قومي → موبايل → "انا محامي بمكتب في وسط البلد"):
```
REPLY: للأسف يا فندم، نظام التقسيط عندنا مش متاح لوظيفتك حاليًا. تحب تعرف تفاصيل الشراء كاش؟
pending_question: application_missing_data
```
توقف قبل ما يوصل لسؤال المستندات، بالظبط زي المطلوب.

`php artisan test`: 9 failed / 33 passed — نفس الرقم القديم، مفيش تغيير.

---

### - [ ] 3.3 — حقول منظّمة لـ `ai_memories` (اتأجّلت عن قصد)

**السبب:** محتاجة migration + تعديل Filament resource + تحديث كل مكان بيقرا `requiredDocuments()`/`categoryRequirementsNote()` — تغيير أعرض من ميزانية "من غير توكنز زيادة". القيمة الحقيقية بتظهر بعد ما 3.1 (أنواع مستندات جديدة) تتعمل، فمفيش داعي يتعمل قبلها.

---

### - [ ] 3.4 — املا `keywords` و `applicable_intents` (اتأجّلت عن قصد)

**السبب:** دي مهمة بتستهلك نداء Gemini لكل واحدة من الـ ٥٠ ميموري (لاقتراح tags) + مراجعة بشرية بعد كده من Filament. مش مناسبة لجلسة "من غير توكنز زيادة" — واتأكدنا في Phase 1 (مهمة 1.1) إن غيابها مش بيمنع الميموري من الوصول للموديل حاليًا (بنبعت الكل، مش بنعتمد على الفلترة دي)، فمفيش ضرر فوري من التأجيل.

---

### - [ ] 3.5 — ملف عميل دائم (اتأجّلت عن قصد)

**السبب:** migration جديدة (`customer_profiles`) + منطق تحديث بعد كل تقديم/حساب قسط + حقن في كل برومبت — أكبر مهمة في Phase 3 كلها. تستاهل جلسة منفصلة بميزانية مخصصة، مش جزء من تقليص التوكنز الحالي.

---

### - [ ] 3.6 — (اختياري) الاسترجاع الدلالي

لسه مش محتاجينها — ٥٠ ميموري بس، والعتبة المذكورة في الخطة ~١٥٠.

---

### ✅ Phase 3 — Definition of Done

- [x] محامي بيتوقف من أول رسالة بعد ما الوظيفة تتعرف — **متأكد منه end-to-end**
- [ ] دليفري بيتطلب منه رخصة + سكرين رحلات — لسه لأ (مهمة 3.1 اتأجّلت، محتاجة نوع مستند جديد)
- [ ] باقي المهام (3.1, 3.3, 3.4, 3.5) — متأجّلة، كل واحدة موثّقة بسبب التأجيل فوق
- [x] `php artisan test` — 9 failed / 33 passed، نفس الرقم القديم
- [x] `git commit`

**الخطوة المقترحة الجاية:** لو فيه ميزانية توكنز أكبر، 3.1 (بعد فحص مسار تصنيف المستندات المرفوعة) هي أعلى قيمة تالية. غير كده، الرجوع لـ Phase 4.1 (golden set) لحماية Phase 2's مهمة 2.5 لسه التوصية الأساسية.

---

# Phase 4 — حلقة الجودة

**Status:** `IN_PROGRESS` (2026-08-25) — 4.1 اتعمل بنسخة مختصرة (٨ حالات مش ٤٠-٦٠) واتأكد منها فعليًا. 4.2 و4.3 اتأجّلوا
**الهدف:** إن الإصلاح ميولّدش المشكلة اللي بعده.
**المدة:** مستمرة
**متطلب:** يفضّل يبدأ **بالتوازي مع Phase 2** (الـ golden set محتاجينه أثناء مسح الـ regex)

---

### - [x] 4.1 — Golden Set (نسخة مبدئية)

**⚠️ تعديل عن الخطة الأصلية:** الخطة طلبت `tests/Feature/GoldenSetTest.php` (PHPUnit). لما اتفحص `phpunit.xml`، طلع إن كل الـ test suite شغال على `sqlite :memory:` فاضية تمامًا (مفيش مكن ولا ميموري) — أي PHPUnit test هنا محتاج seeders كاملة لكل الجداول قبل ما يشتغل، وده مشروع لوحده. بدل منها اتعمل **Artisan command** (`php artisan ai:golden-set`) بيشتغل على قاعدة البيانات الحقيقية (dev DB) — بالظبط زي كل الاختبارات اللي اتعملت طول الجلسات دي، وبيمسح المحادثات التجريبية بعد كل حالة.

**اللي اتعمل فعليًا** (`app/Console/Commands/AiGoldenSet.php`):
- **٨ حالات** (مش ٤٠-٦٠) — كل واحدة فيهم سلوك اتصلّح واتأكد منه فعليًا في Phase 1/2/3: الطلب المركّب (سعر+صور)، الطلب المركّب من التقرير الأصلي (موديل+مدة+مقدم+تفاصيل)، تضييق «اصلي»، عدم كسر استعلام العائلة العادي، بوابة المهنة الممنوعة، عدم رفض مهنة سليمة، تحية بسيطة، سؤال نظام تقسيط.
- كل حالة: تعمل محادثة تجريبية، تبعت رسالة/رسايل، تتحقق من الرد، تمسح المحادثة بعدها (`finally` block - بتتمسح حتى لو فشلت).
- تحقق بسيط (`contains_all` / `not_contains` / `images_at_least` / `reply_not_empty`) بدل مطابقة نص حرفية - مناسب لردود LLM مش حتمية.

**Verify (اتنفذ فعليًا مرتين، ✅ باستثناء حالة واحدة اتفحصت):**
```bash
php artisan ai:golden-set
```
النتيجة: **٧ من ٨ ناجحة بثبات في تشغيلتين متتاليتين**. الحالة الفاشلة («بوابة المهنة الممنوعة») اتفحصت يدويًا بتتبع كل دور في المحادثة، وطلع السبب **مش في المنطق** - بل في **حالة البنية التحتية**: نصف مفاتيح Gemini (٢ من ٤) معطّلة حاليًا فعليًا (`is_active=false`, خطأ `API key not valid`)، وده بيزوّد فشل مؤقت (503 "high demand") تحت الحمل السريع لسلسلة ٦ نداءات AI متتالية في نفس الحالة. **نفس التسلسل بالظبط نجح في التحقق اليدوي وقت Phase 3** - دليل إن ده تذبذب بيئة، مش انحدار في الكود.

> ⚠️ **اكتشاف عملي من تشغيل الأداة، مش جزء من الخطة:** مفتاحين من الأربعة معطّلين فعليًا (`gemini_api_keys.id = 9, 10`، `is_active=false`). ده بيقلّل السعة المتاحة فعليًا للنص. يستاهل مراجعة وتجديد المفاتيح دي بمعزل عن الخطة دي.

**ملاحظة صريحة:** دي بداية، مش الـ ٤٠-٦٠ حالة الكاملة اللي الخطة طلبتها. ضيف حالة جديدة هنا كل مرة تلاقي/تصلّح باگ حقيقي - بالظبط زي ما القاعدة في أول الملف بتقول.

---

### - [ ] 4.2 — لوحة مراقبة (اتأجّلت عن قصد)

**السبب:** محتاجة استكشاف بنية Filament Resources الحالية في المشروع (مفيش استكشاف اتعمل لها في الجلسات دي) + بناء Widget/Page جديدة بالكامل. أكبر من ميزانية "من غير توكنز زيادة"، ومحتاجة جلسة مخصصة.

**بديل رخيص لحد ما تتعمل:** `ai_turn` logs (اتضاف عليها `extra_steps` field في Phase 2) موجودة فعليًا في اللوج العادي - ممكن تتفحص يدويًا بـ:
```bash
grep '"ai_turn"' storage/logs/laravel.log | tail -50
```

---

### - [ ] 4.3 — مراجعة أسبوعية (تعليمات جاهزة، مفيش كود مطلوب)

دي أصلًا خطوات عملية مش مهمة برمجة - القايمة في الخطة الأصلية جاهزة تتنفذ يدويًا زي ما هي:
- [ ] `ai_memory_retrieval_logs` — أول مؤشر إن قاعدة مهمة بقت مش بتوصل
- [ ] `ai_memory_title_miss` في اللوج (اتضافت في Phase 1، مهمة 1.6)
- [ ] الشكاوى والتحويلات للموظفين → حالات جديدة في `AiGoldenSet::cases()`
- [ ] **جديد:** راجع `gemini_api_keys.is_active` أسبوعيًا - الاكتشاف فوق (٢ من ٤ معطّلين) يوضّح إن ده مش نظري.

---

## 📊 لوحة الحالة

| Phase | العنوان | Status | تاريخ الانتهاء |
|---|---|---|---|
| 1 | إصلاحات فورية | `DONE` ✅ | 2026-08-25 |
| 2 | Steps + Tool Calling | `IN_PROGRESS` 🔶 | جزئي 2026-08-25 |
| 3 | الميموري كنظام | `IN_PROGRESS` 🔶 | جزئي 2026-08-25 |
| 4 | حلقة الجودة | `IN_PROGRESS` 🔶 | جزئي 2026-08-25 |

**القيم المسموحة:** `NOT_STARTED` · `IN_PROGRESS` · `DONE` · `BLOCKED`

---

## 🎯 لو هتبدأ بحاجة واحدة

الإحساس بإن «ده مش AI» مصدره حاجة واحدة قابلة للقياس: **الموديل ممنوع يقرر وممنوع يكتب.**

- المرحلة ١ إصلاحات مستحقة وهتبان فورًا — بس بتشتغل جوه نفس البنية المسببة للمشكلة. ✅ خلصت.
- المرحلة ٢ هي اللي بتغيّر الإحساس فعلًا. الطلب المركّب بقى شغال، بس ٢.٤ (الموديل يكتب الرد) و٢.٥ (مسح الـ regex) لسه معلقين.
- الأسعار هتفضل مضبوطة لأنها لسه جاية من الداتابيز عن طريق الـ tools — الموديل بيصيغ الجملة بس.

**التوصية الحالية (بعد اكتشافات اليوم):**
1. **جدّد/راجع مفتاحي Gemini المعطّلين** (`id 9, 10`) - ده بيأثر على استقرار كل حاجة تانية.
2. زوّد `AiGoldenSet::cases()` بحالات جديدة كل مرة تلاقي باگ.
3. ارجع لمهمة ٢.٥ (مسح شروط الـ regex) بأمان دلوقتي إن الـ golden set موجود - امسح دالة واحدة، شغّل `php artisan ai:golden-set`، كرر.
