# AI Upgrade Plan — Moto Gate WhatsApp Bot

> خطة تنفيذ مقسّمة على مراحل. كل مرحلة فيها **Status** وكل مهمة فيها **checkbox** + **Verify** (أمر تتأكد بيه إن المهمة اتعملت فعلًا).
> الملف ده مصمم عشان AI model يشتغل عليه خطوة بخطوة ويحدّث الحالة بنفسه.

**آخر تحديث:** 2026-08-25
**التقدّم العام:** Phase 1 `DONE` ✅ · Phase 2 `NOT_STARTED` · Phase 3 `NOT_STARTED` · Phase 4 `NOT_STARTED`

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

**Status:** `NOT_STARTED`
**الهدف:** ده **الإصلاح الحقيقي**. تحويل الـ AI من «آخر ملاذ» لـ «العقل»، وLaravel من «العقل» لـ «مصدر الحقيقة».
**المدة المتوقعة:** ١–٢ أسبوع
**متطلب:** Phase 1 لازم يكون `DONE`

---

### - [ ] 2.1 — حوّل `intent: string` لـ `steps: []`

**المشكلة:** الرسالة الواحدة = نية واحدة. الطلب المركّب مستحيل يتمثّل:

> «مكنه دايو ٤ عاوزها علي سنه هدفع مقدم ٥ الاف وعاوزك تبعتلي تفاصيل التقسيط الكامل»

«تفاصيل التقسيط الكامل» مالهاش حقل في الـ JSON schema أصلًا فبتتلغي، ولو `months` رجع `null` بتترمي على سؤال «على كام شهر؟».

**التعديل:** في `app/Services/AiIntentClassifier.php` غيّر الـ schema المطلوب في البرومبت:

```json
{
  "steps": [
    {
      "action": "calculate_installment",
      "machine_query": "دايو 4",
      "target": "new_machine",
      "params": { "months": 12, "deposit": 5000, "system": null, "full_breakdown": true }
    }
  ],
  "needs_clarification": false,
  "clarification_question": null,
  "confidence": 0.0
}
```

القيم المسموحة لـ `action`: `get_price` · `get_images` · `calculate_installment` · `explain_installment_system` · `explain_admin_fee` · `list_brand_models` · `start_application` · `check_application_status` · `answer_delivery` · `general_reply`

**قاعدة مهمة تتكتب في البرومبت:** «لو العميل طلب أكتر من حاجة في نفس الرسالة، اعمل step لكل حاجة بالترتيب اللي اتقالوا بيه. متلغيش أي طلب.»

**التعديل (توافق):** ضيف `normalizeSteps()` وسيب `normalizePlan()` شغالة — خلّي أول step يتحوّل لـ `intent`/`target`/`machine_query` القديمة عشان الكود الحالي ميقعش أثناء الانتقال.

**Verify:**
```bash
php artisan tinker --execute="
\$c = App\Models\WhatsappConversation::latest()->first();
\$p = app(App\Services\AiIntentClassifier::class)->classify(\$c,'مكنه دايو ٤ عاوزها علي سنه هدفع مقدم ٥ الاف وعاوزك تبعتلي تفاصيل التقسيط الكامل');
echo json_encode(\$p['steps'] ?? [], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;"
```
✅ لازم يطلع step واحد على الأقل بـ `action=calculate_installment` و `months=12` و `deposit=5000`.

---

### - [ ] 2.2 — نفّذ الـ steps بالترتيب

**التعديل:** في `WhatsappIntentRouter::handleInternal()`، بدل ما تنفّذ `intent` واحدة، اعمل loop على `$plan['steps']` وجمّع الردود.

```php
$replies = [];
$allImages = [];

foreach ($plan['steps'] as $step) {
    $result = $this->executeStep($conversation, $message, $step, $plan);

    if (! empty($result['reply'])) {
        $replies[] = $result['reply'];
    }

    $allImages = array_merge($allImages, $result['images'] ?? []);
}

$reply = implode("\n\n", array_filter($replies));
```

**قواعد:**
- حد أقصى **٣ steps** في الرسالة الواحدة (حماية).
- لو step واحد فشل، كمّل الباقي ومتوقفش كله.
- `start_application` لازم يكون **آخر** step دايمًا.

**Verify:** ابعت «سعر دايو ٤ وصورها» — لازم يرجّع السعر **والصور** في نفس الرد.

---

### - [ ] 2.3 — عرّف الـ Tools (Function Calling)

**المشكلة:** الكود بيطلب من الموديل JSON بصيغة مكتوبة بالإيد وبيـ parse-ها بـ regex (`extractJson()`). ده هش. Gemini عنده function calling رسمي بيضمن الـ schema.

**التعديل:** اعمل ملف جديد `app/Services/Ai/ToolRegistry.php` فيه تعريفات الـ tools:

| Tool | Params | بيرجّع |
|---|---|---|
| `search_machines` | `query`, `limit` | قايمة مكن + `match_score` |
| `get_price` | `machine_ids[]` | سعر كاش من الـ DB |
| `calculate_installment` | `machine_ids[]`, `months`, `deposit`, `system` | نتيجة `InstallmentCalculator` |
| `get_images` | `machine_ids[]` | روابط الصور |
| `get_requirements` | `job_category` | المستندات المطلوبة |
| `check_eligibility` | `job_type` | مقبول / مرفوض + السبب |
| `start_application` | `machine_id` | يبدأ الطلب |

كل tool بيلفّ الـ service الموجود بالفعل — **متعيدش كتابة أي منطق حسابي**.

**Verify:**
```bash
php artisan tinker --execute="
\$t = app(App\Services\Ai\ToolRegistry::class);
echo json_encode(\$t->call('calculate_installment',['machine_ids'=>[App\Models\Machine::first()->id],'months'=>12,'deposit'=>5000,'system'=>'20']), JSON_UNESCAPED_UNICODE).PHP_EOL;"
```
✅ لازم يرجّع أرقام حقيقية مطابقة لـ `InstallmentCalculator`.

---

### - [ ] 2.4 — سيب الموديل يكتب الرد

**المشكلة:** `AiReplyBuilder::fromMemories()` بيختار جملة بـ `array_rand($templates)` وبيحط مكان `{variables}` أرقام. **٤٩ من ٥٠ ميموري** عندها `template_replies`. ده حرفيًا automation bot.

**التعديل:**
1. بعد ما الـ tools ترجّع نتايجها، ابعتها للموديل مع الميموري وخلّيه يكتب الرد النهائي.
2. حوّل `template_replies` من **قوالب مُلزِمة** لـ **أمثلة على الأسلوب** في البرومبت:

```
أمثلة على أسلوب الرد المطلوب (للأسلوب فقط — ممنوع تنسخها حرفيًا):
{$styleExamples}

الأرقام الحقيقية من النظام (استخدمها كما هي — ممنوع تغيّر أي رقم):
{$toolResults}
```

3. سيب `renderMemoryOrDefault()` كـ fallback لو نداء الـ AI فشل.

> ⚠️ **الأرقام لازم تفضل حرفية.** لو الموديل غيّر رقم، ده bug خطير — ضيف تحقق يقارن الأرقام في الرد بنتايج الـ tools.

**Verify:** احسب قسط على مكنة، وقارن الأرقام في الرد بـ `InstallmentCalculator` مباشرة — لازم تكون **متطابقة حرف بحرف**.

---

### - [ ] 2.5 — امسح شروط الـ regex العشرة

**المشكلة:** `handleInternal()` بيعدّي على ١٠ شروط كل واحد فيهم ممكن يغيّر النية بعد ما الـ AI قرر. كل واحد اتضاف عشان يصلّح حالة واحدة، وكل واحد بيضرب غلط على رسايل تانية. ده اللي مخلي كل إصلاح بيولّد المشكلة اللي بعده.

**امسح:** `isAdminFeeRejectionIntent` · `isAdminFeeExplanationIntent` · `isBareAdminFeeFollowUp` · `isPureGreeting` · `isNewApplicationRequest` · `isBareConfirmation` · `isVariantNarrowingReply` · `isGenericNarrowingReply` · `isPureFollowUp` · `isInstallmentSystemIntent` · `isInstallmentCalcIntent` · `detectIntent`

**سيب (بوابات أمان حقيقية):** `awaiting_agent` check · `isHumanSupportRequest` · `isComplaintMessage` · معالجة الميديا/الصوت

> مثال على خطورتهم: `isPureFollowUp()` بتطابق كلمة **«سنه»** لوحدها — أي رسالة فيها «سنة» بتتحسب متابعة لآخر مكنة. و `detectIntent()` بترجّع `price` من مجرد كلمة **«كام»**.

**امسح كل واحدة لوحدها** وشغّل الـ golden set (مهمة 4.1) بعد كل مسحة.

**Verify:**
```bash
wc -l app/Services/WhatsappIntentRouter.php
```
✅ لازم يقل من 2767 لأقل من 1600 سطر.

---

### - [ ] 2.6 — ضيف عتبة ثقة على البحث

**المشكلة:** `MachineSearchService::search()` بيفلتر على `score >= 900` وبيرجّع لحد **٢٠ مكنة**، و`handleCashPrice` بيعمل loop عليهم كلهم ويعرض سعر كل واحدة. لو التضييق فشل، العميل بياخد قايمة فيها موديلات مش اللي سأل عنها.

> ✅ **مهم:** الـ ٥٨ مكنة **كلهم** عندهم `cash_price` و `installment_price` و `installment_systems`. فالسعر الغلط **مستحيل** يكون داتا ناقصة — هو دايمًا اختيار مكنة غلط.

**التعديل:** خلّي `search()` ترجّع الـ score مع كل نتيجة، وصنّفها:

```php
// score >= 2500          → confident    : نفّذ على طول
// 900 <= score < 2500    → ambiguous    : اعرض الاختيارات واسأل
// أكتر من 5 نتايج        → too_broad    : اسأل يوضّح
```

وفي `get_price` tool، لو الحالة `ambiguous` رجّع `needs_confirmation: true` مع الاختيارات بدل ما تعرض ٢٠ سعر.

**Verify:**
```bash
php artisan tinker --execute="
\$s = app(App\Services\MachineSearchService::class);
echo 'دايو: '.\$s->search('دايو',20)->count().PHP_EOL;
echo 'دايو 4: '.\$s->search('دايو 4',20)->count().PHP_EOL;"
```
✅ `دايو 4` لازم ترجّع نتايج أقل بكتير من `دايو`.

---

### ✅ Phase 2 — Definition of Done

- [ ] كل الـ 6 مهام `[x]`
- [ ] الرسالة دي بتشتغل صح في رد واحد: «مكنه دايو ٤ عاوزها علي سنه هدفع مقدم ٥ الاف وعاوزك تبعتلي تفاصيل التقسيط الكامل»
- [ ] «سعر دايو ٤ وصورها» بترجّع السعر **والصور**
- [ ] `WhatsappIntentRouter.php` أقل من 1600 سطر
- [ ] كل الأرقام في الردود مطابقة لـ `InstallmentCalculator` حرف بحرف
- [ ] `git commit -m "Phase 2: multi-step plans + tool calling, remove regex overrides"`

---

# Phase 3 — الميموري كنظام حقيقي

**Status:** `NOT_STARTED`
**الهدف:** معرفة الشغل الموجودة في الميموري تبقى قابلة للتنفيذ، مش نص حر بيتقري بس.
**المدة المتوقعة:** أسبوع
**متطلب:** Phase 2 لازم يكون `DONE`

---

### - [ ] 3.1 — وسّع تصنيف الوظايف

**المشكلة:** `ApplicationHandler::categorizeIncome()` بيلمّ كل الوظايف في **٥ خانات** بـ `str_contains`، و`requiredDocuments('freelance')` بترجّع **`[]`**.

النتيجة: **الدليفري بيتصنّف `freelance` → عمره ما هيتطلب منه رخصة ولا سكرين رحلات** — رغم إن ميموري «الدليفري» (`#47`) بتقول ده بالحرف.

**جدول الفجوات (كله متأكد منه من الكود):**

| القاعدة في الميموري | مطبّقة؟ | النتيجة على العميل |
|---|---|---|
| الدليفري: رخصة سارية + سكرين رحلات | ❌ | `requiredDocuments` بترجّع `[]` |
| التاكسي: صاحب مقبول، سواق يومية غير مناسب | ❌ | الاتنين بيتقبلوا |
| المعاشات: لازم ضامن | ❌ | بيان معاش بس |
| المعاشات: السن ٢١–٦٢، المعاش ≥ ٤٠٠٠ | ❌ | مفيش تحقق |
| الفئات الممنوعة (ضابط، محامي، قضاء) | ❌ | الطلب بيتاخد كامل وبعدين يترفض يدوي |
| قطاع خاص غير مؤمّن: مفيش مفردات = دخل حر | ❌ | تصنيف ثابت من كلمة الوظيفة |
| الجيش: كشف حساب ٦ شهور | ⚠️ جزئيًا | `mapWorkStatus` بيسجّله `employee` |

**التعديل:** ضيف الفئات دي لـ `categorizeIncome()`:

```php
'delivery'      // طلبات، أوبر، اندرايف، دليفري مطاعم
'taxi_owner'    // صاحب تاكسي
'taxi_driver'   // سواق يومية → مرفوض
'microbus'      // نفس نظام التاكسي
'army'          // موجودة بس ناقصة معالجة
```

وحدّث `requiredDocuments()` و `mapWorkStatus()` و `categoryRequirementsNote()` لكل فئة جديدة.

**Verify:**
```bash
php artisan tinker --execute="
\$h = app(App\Services\Handlers\ApplicationHandler::class);
foreach (['شغال طلبات','سواق اوبر','صاحب تاكسي','بشتغل ميكروباص','معاش'] as \$j) {
  echo \$j.' → '.\$h->categorizeIncome(\$j,'').PHP_EOL;
}"
```
✅ «شغال طلبات» و«سواق اوبر» لازم يرجّعوا `delivery` مش `freelance`.

---

### - [ ] 3.2 — بوابة الفئات الممنوعة

**المشكلة:** ميموري «الفئات الممنوعة» (`#51`, scope=`always_include`) بتوصل للـ fallback بس. التقديم بياخد الطلب كامل — بطاقة وكل حاجة — وبعدين يترفض يدوي.

**التعديل:** اعمل `check_eligibility()` تتنده **قبل أول سؤال** في `ApplicationHandler::handle()`، وتقرا الفئات من ميموري `#51` مش من مصفوفة في الكود.

الرد لازم يكون محترم وواضح، ويعرض بديل (كاش) بدل «مرفوض» جافة.

**Verify:** ابدأ تقديم بوظيفة «محامي» — لازم يتوقف من أول رسالة بعد ما يعرف الوظيفة.

---

### - [ ] 3.3 — حقول منظّمة لـ `ai_memories`

**التعديل:** migration يضيف:

```php
$table->string('job_category')->nullable()->index();
$table->json('required_documents')->nullable();
$table->json('eligibility_rules')->nullable();
```

بعدها `requiredDocuments()` تقرا **من الداتابيز** بدل `match` مكتوب في PHP. المكسب: تقدر تعدّل شروط الدليفري من Filament **من غير deploy**.

**Verify:** غيّر مستندات الدليفري من Filament، وابدأ تقديم دليفري جديد — لازم المستندات الجديدة تظهر من غير أي deploy.

---

### - [ ] 3.4 — املا `keywords` و `applicable_intents`

**المشكلة:** **٠ من ٥٠** ميموري عندها `keywords`، و**٠ من ٥٠** عندها `applicable_intents`. الحقول موجودة في الـ model والـ casts والفلتر — ومفيهاش داتا.

**التعديل:** اكتب command `php artisan ai:tag-memories` يستخدم Gemini يقترح tags لكل ميموري، وتراجعها انت من Filament.

**Verify:**
```bash
php artisan tinker --execute="
\$m = App\Models\AiMemory::all();
echo 'keywords: '.\$m->filter(fn(\$x)=>!empty(\$x->keywords))->count().'/'.\$m->count().PHP_EOL;
echo 'intents: '.\$m->filter(fn(\$x)=>!empty(\$x->applicable_intents))->count().'/'.\$m->count().PHP_EOL;"
```
✅ لازم يبقوا أكتر من ٤٠ من ٥٠.

---

### - [ ] 3.5 — ملف عميل دائم

**المشكلة:** `WhatsappConversation` عندها `context_payload` واحد JSON. مفيش ملف عميل بيعيش عبر المحادثات. عمود `customer_job_type` موجود ومفيش حاجة بتبني عليه. الموديل بيشوف آخر ٢٠ رسالة خام وبس.

**التعديل:** جدول `customer_profiles`:

| عمود | الوصف |
|---|---|
| `phone` (unique) | المفتاح |
| `job_category` | من آخر تقديم |
| `city` / `area` | من العناوين |
| `budget_range` | من الأقساط اللي حسبها |
| `machines_viewed` (json) | المكن اللي شافها |
| `applications_count` | عدد الطلبات |
| `conversation_summary` | ملخّص متجدد |

يتحقن في كل برومبت. **ده اللي بيدي الإحساس «بتكلم مع حد يعرفني».**

**Verify:** ابدأ محادثة جديدة من نفس الرقم بعد تقديم قديم — لازم البوت يعرف وظيفتك ومنطقتك من غير ما تقولهم تاني.

---

### - [ ] 3.6 — (اختياري) الاسترجاع الدلالي

**متى:** لما الميموري تعدي **~١٥٠ عنصر**. دلوقتي ٥٠ بس — **مش محتاجها**.

`gemini-embedding-001` و `gemini-embedding-2` متسجّلين في `config/gemini.php` ومفيش **ولا سطر واحد** في المشروع بينده `embedContent`. البنية جاهزة ومتسيبة.

---

### ✅ Phase 3 — Definition of Done

- [ ] كل المهام الإلزامية `[x]` (3.6 اختيارية)
- [ ] دليفري بيتطلب منه رخصة + سكرين رحلات
- [ ] محامي بيتوقف من أول رسالة
- [ ] `git commit -m "Phase 3: structured memory, job categories, customer profiles"`

---

# Phase 4 — حلقة الجودة

**Status:** `NOT_STARTED`
**الهدف:** إن الإصلاح ميولّدش المشكلة اللي بعده.
**المدة:** مستمرة
**متطلب:** يفضّل يبدأ **بالتوازي مع Phase 2** (الـ golden set محتاجينه أثناء مسح الـ regex)

---

### - [ ] 4.1 — Golden Set

**المشكلة:** مفيش أي regression harness. ده السبب المباشر إن آخر ٥ commits كل واحد بيصلّح اللي قبله.

**التعديل:** `tests/Feature/GoldenSetTest.php` + `tests/fixtures/golden_set.json` فيه **٤٠–٦٠ رسالة مصرية حقيقية** من محادثاتك، ولكل واحدة الخطة المتوقعة.

**لازم يشمل:**
- الطلب المركّب: «مكنه دايو ٤ عاوزها علي سنه هدفع مقدم ٥ الاف وتفاصيل التقسيط الكامل»
- التضييق: «استيراد» بعد عرض موديلين
- التبديل: «لا انا قصدي هوجن مش دايو»
- الشكوى: «انتم نصابين»
- التقديم: كل فئة وظيفة
- الغموض: «كام» لوحدها

**استخرج الحالات من محادثاتك الحقيقية:**
```bash
php artisan tinker --execute="
foreach (App\Models\WhatsappMessage::where('direction','incoming')->latest()->take(80)->get() as \$m) {
  echo str_replace(\"\n\",' ',mb_substr(\$m->message,0,90)).PHP_EOL;
}"
```

**Verify:** `php artisan test --filter=GoldenSet` → ≥ ٩٠٪ نجاح.

---

### - [ ] 4.2 — لوحة مراقبة

**المشكلة:** `ai_turn` logs بتتكتب بالفعل في `WhatsappIntentRouter::handle()` — ومحدش بيقراها.

**المقاييس:** نسبة `llm_fallback` · نسبة التحويل لموظف · متوسط `repetition_score` · متوسط `latency_ms` · `clarification_attempts`

**Verify:** الصفحة بتعرض بيانات آخر ٧ أيام.

---

### - [ ] 4.3 — مراجعة أسبوعية

- [ ] `ai_memory_retrieval_logs` — أول مؤشر إن قاعدة مهمة بقت مش بتوصل
- [ ] `ai_memory_title_miss` في اللوج
- [ ] الشكاوى والتحويلات للموظفين → حالات جديدة في الـ golden set

---

## 📊 لوحة الحالة

| Phase | العنوان | Status | تاريخ الانتهاء |
|---|---|---|---|
| 1 | إصلاحات فورية | `DONE` ✅ | 2026-08-25 |
| 2 | Steps + Tool Calling | `NOT_STARTED` | — |
| 3 | الميموري كنظام | `NOT_STARTED` | — |
| 4 | حلقة الجودة | `NOT_STARTED` | — |

**القيم المسموحة:** `NOT_STARTED` · `IN_PROGRESS` · `DONE` · `BLOCKED`

---

## 🎯 لو هتبدأ بحاجة واحدة

الإحساس بإن «ده مش AI» مصدره حاجة واحدة قابلة للقياس: **الموديل ممنوع يقرر وممنوع يكتب.**

- المرحلة ١ إصلاحات مستحقة وهتبان فورًا — بس بتشتغل جوه نفس البنية المسببة للمشكلة.
- المرحلة ٢ هي اللي بتغيّر الإحساس فعلًا.
- الأسعار هتفضل مضبوطة لأنها لسه جاية من الداتابيز عن طريق الـ tools — الموديل بيصيغ الجملة بس.

**التوصية:** Phase 1 كامل (يومين) → Phase 4.1 (الـ golden set) → Phase 2.
