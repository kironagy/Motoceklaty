# تقرير: أسباب استهلاك التوكنز الزيادة في نظام الواتساب/AI

## Context
النظام Laravel + Node + OCR بيستقبل رسايل WhatsApp ويرد باستخدام Gemini (عبر `GeminiClient`)، ومعاه memory مخزنة في `ai_memories`. المطلوب: تحديد أسباب استهلاك التوكنز الزيادة (بدون تنفيذ أي حل، مجرد تشخيص) وإخراج ملف `.md` يوثق كل مشكلة.

هذا الملف نتيجة قراءة مباشرة للكود (بدون تشغيل AI agents، وبأقل استهلاك ممكن) في:
- `app/Services/WhatsappIntentRouter.php`
- `app/Services/AiIntentClassifier.php`
- `app/Services/AiComplexReplyService.php`
- `app/Services/AiPromptBuilder.php`
- `app/Services/AiMemoryContextBuilder.php`
- `app/Services/GeminiClient.php`
- `app/Services/Handlers/ApplicationHandler.php` (namespace `Whatsapp\Handlers`)
- `app/Services/Handlers/MediaOcrHandler.php`

## المشاكل المكتشفة (مرتبة حسب الأثر)

### 1. رسالة واحدة ممكن تعمل استدعاءين لـ Gemini بدل واحد
في [WhatsappIntentRouter.php:37](app/Services/WhatsappIntentRouter.php:37) بيتم نداء `AiIntentClassifier::classify()` **دايمًا** في أول كل رسالة، حتى لو الرسالة هيتم حلها بعدين بـ heuristics محلية (زي `isPureFollowUp`, `isInstallmentSystemIntent`, `isApplicationIntent` في السطور 44-79) من غير أي احتياج لنتيجة الـ AI classify أصلاً. يعني كل رسالة بتدفع تكلفة AI call كامل حتى لو النتيجة هترمى وتتحل بمنطق كود عادي.

وبعدين لو الرسالة وقعت في fallback (`handleAiFallback`, سطر 228)، بيتم نداء **AI تاني بالكامل** عبر `AiComplexReplyService::reply()` ([WhatsappIntentRouter.php:254](app/Services/WhatsappIntentRouter.php:254)) وبيبني prompt جديد فيه تاريخ محادثة تاني + الميموري الكاملة.

كمان في تدفق التقديم (`application`)، `classify()` بيتنادى مرة في أول الراوتر (سطر 37، mode عادي)، وبعدين تاني مرة جوه [ApplicationHandler.php:32](app/Services/Handlers/ApplicationHandler.php:32) بـ `mode: application_data_extraction` — يعني **رسالة تقديم واحدة = استدعاءين AI منفصلين**، كل واحد فيهم بيبعت آخر 20 رسالة من جديد.

**النتيجة:** بدل استدعاء AI واحد لكل رسالة، فيه سيناريوهات بتاخد 2 استدعاء كامل (كل واحد فيه نفس تاريخ المحادثة تقريبًا).

### 2. الميموري الكاملة بتتبعت في كل رد fallback من غير فلترة
`AiMemoryContextBuilder::generateFullMemoryContext()` ([AiMemoryContextBuilder.php:37](app/Services/AiMemoryContextBuilder.php:37)) بيجيب **كل** الصفوف من `ai_memories` اللي `is_active = true` ويحولها كلها لـ text ويحطها في الـ prompt بالكامل — من غير أي بحث/ترجيح حسب الرسالة الحالية، ومن غير أي حد أقصى لعدد الأحرف. كل ما حد يزود memories جديدة من Filament (`AiMemoryResource`)، حجم الـ prompt (وبالتالي التوكنز) بيكبر تلقائيًا لكل رسالة fallback واحدة، حتى لو الميموري دي مالهاش علاقة بالرسالة.

الكاش هنا (`Cache::remember('ai_full_memory_context', 5 دقايق)`) بيقلل عدد queries لقاعدة البيانات، لكن مش بيقلل عدد التوكنز المبعوتة لـ Gemini — النص الكامل لسه بيتبعت مع كل استدعاء لـ `AiComplexReplyService`.

### 3. الـ OCR text بيتكرر بعثه في كل رسالة لاحقة لغاية 20 رسالة
`MediaOcrHandler::saveOcrResults()` ([MediaOcrHandler.php:98](app/Services/Handlers/MediaOcrHandler.php:98)) بيحفظ نتيجة الـ OCR الكاملة (`text`, `lines`, `pages`, `document`, `display_text`) جوه `payload` الرسالة نفسها في قاعدة البيانات.

لكن `AiIntentClassifier::classify()` ([AiIntentClassifier.php:21](app/Services/AiIntentClassifier.php:21)) بياخد آخر 20 رسالة ويحط `payload` الخاص بيها **خام كامل** جوه الـ prompt:
```php
->map(fn ($m) => [
    'direction' => $m->direction,
    'message' => $m->message,
    'payload' => $m->payload ?? null,   // <-- ده اللي بيكرر نص الـ OCR
])
```
يعني لو عميل رفع بطاقة أو مستند وطلع منها نص OCR كبير، النص ده هيتبعت **تاني في كل رسالة classify جاية** لغاية ما يخرج بره نطاق آخر 20 رسالة (يعني ممكن يتكرر عشرات المرات في محادثة تقديم واحدة). ده على الأرجح أكبر مصدر منفرد لاستهلاك التوكنز في تدفقات التقديم (applications).

### 4. `classify()` بيبعت آخر 20 رسالة + JSON كامل حتى لرسايل بسيطة جدًا
مفيش أي "fast path" أو shortcut قبل نداء Gemini للرسائل الواضحة (تحية بسيطة، "تمام"، "أيوه"، رقم واحد) — كل رسالة بتاخد نفس المعاملة: بناء `$recent` (20 رسالة) + `$lastMachines` + `context_payload` + `known_context` وتتبعت كاملة كـ JSON prompt ضخم نسبيًا ([AiIntentClassifier.php:61-182](app/Services/AiIntentClassifier.php:61)).

### 5. تقدير التوكنز غير دقيق (تأثير غير مباشر)
في [GeminiClient.php:24](app/Services/GeminiClient.php:24):
```php
$estimatedTokens = mb_strlen($prompt);
```
ده بيستخدم عدد الأحرف كـ "تقدير توكنز" للحجز في `GeminiKeyManager`. النص العربي بالذات بيتحول لتوكنز أكتر من عدد الأحرف بكتير (multi-byte)، فالتقدير ده غالبًا أقل من الحقيقي. ده مش بيسبب استهلاك زيادة مباشرة، لكنه بيخلي نظام حجز الكوتا (`reserveAvailableModel`) بيتأسس على رقم غلط، وده ممكن يأدي لفشل حجوزات/failover إضافي (نداءات مكررة) في حالات معينة.

### 6. لا يوجد أي cap على طول الرسالة أو الـ payload الداخل في الـ prompt
مفيش أي `mb_substr` أو truncation على `$message`, `$m->payload`, أو `$memoryContext` قبل حقنها في الـ prompt templates (`AiPromptBuilder`, `AiIntentClassifier`). لو أي رسالة أو payload كبر بشكل غير متوقع (رسالة طويلة، أو OCR document ضخم زي ما في المشكلة #3)، مفيش حد أقصى بيوقف الحجم من الانفجار.

## ملخص الأولويات
| # | المشكلة | الأثر |
|---|---------|-------|
| 1 | استدعاءين AI لنفس الرسالة (classify + fallback/application extraction) | مضاعفة التوكنز 2x في مسارات كتير |
| 2 | الميموري الكاملة بتتبعت كل مرة من غير فلترة | نمو تلقائي مع الوقت كل ما تتضاف memory |
| 3 | تكرار نص الـ OCR في كل classify لغاية 20 رسالة | الأكبر أثرًا في محادثات التقديم |
| 4 | classify() بيتنادى حتى لو هيتحل بـ heuristics محلية | استدعاءات AI ضايعة بالكامل |
| 5 | تقدير التوكنز = طول النص مش التوكنز الحقيقية | تأثير غير مباشر على الحجز/الـ failover |
| 6 | مفيش حد أقصى لحجم أي حقل بيتحقن في الـ prompt | خطر انفجار الحجم في حالات نادرة |

هذا الملف تشخيصي فقط — لا يقترح تنفيذ تغييرات في الكود بدون موافقتك.
