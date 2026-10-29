<?php

namespace App\Domain\Settings;

use App\Models\AgentSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard overrides for config('agent.*'). Every definition maps one
 * settings key to the config path it overrides, so the rest of the code
 * keeps reading config('agent.x') and never knows where the value came from.
 *
 * The worker is a long-running process, so apply() is called on every loop
 * and restores the .env value when an override is removed.
 */
class AgentSettings
{
    private const CACHE_KEY = 'agent_settings.overrides';

    /** @var array<string, mixed>|null config values as loaded from .env, before any override */
    private static ?array $defaults = null;

    /**
     * key => [group, label, type (bool|int|float|string|text|csv|select), help, options?]
     * The key is the path under config('agent.').
     */
    public static function definitions(): array
    {
        return [
            'eligibility.no_work_hint' => ['instructions', 'تفسير اشتراط العمل الحالي', 'text', null],
            'eligibility.other_applicant' => ['instructions', 'تقديم شخص بديل مستوف للشروط', 'text', null],
            'eligibility.other_applicant_refused' => ['instructions', 'الشخص البديل غير مستوف للشروط', 'text', null],
            'installments.cap_explanation' => ['instructions', 'تفسير المقدم بسبب حد التمويل', 'text', null],
            'social.replies' => ['instructions', 'ردود التحيات والشكر المعتمدة JSON', 'text', null],
            'cash.sales_handoff' => ['run', 'مسار شراء الكاش عبر الموظف', 'bool', null],
            'cash.purchase_handoff_message' => ['instructions', 'رسالة تحويل مشتري الكاش', 'text', null],
            'cash.purchase_handoff_topic' => ['instructions', 'عنوان تحويل مشتري الكاش', 'string', null],
            'cash.purchase_review_instructions' => ['instructions', 'التحقق من تأكيد شراء الكاش', 'text', null],
            'safety.repair_instructions' => ['instructions', 'توجيه تصحيح الرد المرفوض', 'text', null],
            'safety.max_evidence_repairs' => ['run', 'محاولات تصحيح مراجعة المصدر', 'int', null],
            'cash.inventory_enabled' => ['run', 'ربط الكاش بالمخزون الفعلي', 'bool', 'قراءة القطع غير المباعة من كل المعارض.'],
            'cash.tool_description' => ['instructions', 'تعليمات أداة مخزون الكاش', 'text', null],
            'cash.unavailable_message' => ['instructions', 'رسالة نفاد مخزون الكاش والتحويل', 'text', '{motorcycle} اسم الموديل.'],
            'cash.handoff_topic' => ['handoff', 'عنوان تحويل الكاش غير المتاح', 'string', null],
            'safety.banned_reply_pattern' => ['instructions', 'عبارات الرد الممنوعة — regex', 'text', null],
            'safety.banned_reply_repair' => ['instructions', 'توجيه إعادة صياغة العبارة الممنوعة', 'text', null],
            'eligibility.age_stop_instructions' => ['instructions', 'إيقاف أسئلة العمل عند رفض السن', 'text', null],
            'safety.reply_guard_mode' => ['run', 'حماية نص الرد', 'select', 'الملاحظة تسجل الاعتراض دون حجب الرد؛ الأفعال تظل متحققة.', ['advisory'=>'ملاحظة فقط', 'enforce'=>'حجب وتصحيح']],
            'safety.enabled' => ['run', 'مراجعة مصدر الرد قبل الإرسال', 'bool', 'غير المثبت يتوقف بدون تحويل تلقائي.'],
            'safety.review_instructions' => ['instructions', 'تعليمات التحقق من مصدر الرد', 'text', 'سياسة مراجعة الرد وطلب التحويل.'],
            'safety.no_zero_upfront_hint' => ['instructions', 'توجيه عدم وجود خطة بلا دفع عند الاستلام', 'text', 'توجيه أداة الحساب من الميموري.'],
            'safety.unknown_reply' => ['instructions', 'رد عدم توفر معلومة مؤكدة', 'text', 'بدون وعد أو تحويل لزميل.'],
            'safety.claim_rules' => ['instructions', 'قواعد الادعاءات JSON', 'text', 'pattern وevidence_key لكل ادعاء.'],
            'handoff.tool_description' => ['instructions', 'تعليمات أداة التحويل', 'text', 'متى يسمح بطلب موظف.'],
            'handoff.customer_only' => ['handoff', 'التحويل بطلب العميل فقط', 'bool', 'يمنع التحويل التلقائي بسبب الأخطاء أو نقص المعلومات.'],
            'keys.pool' => ['run', 'توزيع مفاتيح Gemini', 'select', 'يشمل الردود ومراجعتها؛ المحاكي مجاني فقط.', ['balanced' => 'توزيع على الكل', 'paid' => 'المدفوع أولًا', 'free_first' => 'المجاني أولًا']],
            'enabled' => ['run', 'البوت شغال', 'bool', 'لو اتقفل البوت مش هيرد على أي رسالة واتساب جديدة.'],
            'model' => ['run', 'الموديل الأساسي', 'string', 'الموديل اللي بيرد على العملاء: gpt-5-nano أو gpt-5-mini (محتاج مفتاح GPT) أو gemini-3.1-flash-lite.'],
            'fallback_models' => ['run', 'موديلات احتياطية', 'csv', 'بتتجرب بالترتيب لو الموديل الأساسي واقع. ممكن تخلط GPT و Gemini.'],
            'document_model' => ['run', 'موديل قراءة المستندات', 'string', 'الموديل اللي بيقرا صور البطاقة والمفردات والسكرينات واليافطة: gpt-5-mini (أدق) - فاضي = نفس موديل الرد.'],
            'voice_model' => ['run', 'موديل تفريغ الصوت', 'string', 'gpt-4o-mini-transcribe (الافتراضي) أو gpt-4o-transcribe - ولو مفيش مفتاح GPT شغال بيرجع لـ Gemini.'],
            'openai_reasoning_effort' => ['run', 'تفكير موديل GPT', 'string', 'minimal (الأسرع، ثانية أو اتنين) أو low أو medium (أذكى بس أبطأ وأغلى).'],
            'provider_budget_seconds' => ['run', 'أقصى وقت لنداء الموديل (ثانية)', 'int', null],

            'runtime.max_model_calls' => ['limits', 'أقصى عدد نداءات للموديل في الرد الواحد', 'int', 'كل نداء أداة بيحتاج نداء موديل بعده. 6 رقم كويس.'],
            'runtime.max_tool_calls' => ['limits', 'أقصى عدد أدوات في الرد الواحد', 'int', null],
            'runtime.wall_clock_seconds' => ['limits', 'أقصى وقت للرد (ثانية)', 'int', null],
            'reply.max_chars' => ['limits', 'أقصى طول للرسالة (حرف)', 'int', 'الرسايل الأطول بتترفض ويتطلب من البوت يختصر.'],
            'guard.number_min_value' => ['limits', 'أقل رقم يتفحص في الرد', 'int', 'أي رقم أكبر من ده في رد البوت لازم يكون جاي من نتيجة أداة (حماية من اختراع الأسعار).'],

            'turns.debounce_seconds' => ['timing', 'استنى كام ثانية بعد آخر رسالة', 'int', 'عشان لو العميل بعت كذا رسالة ورا بعض يترد عليهم برد واحد.'],
            'turns.media_debounce_seconds' => ['timing', 'الانتظار بعد صورة (ثانية)', 'int', null],
            'turns.max_wait_seconds' => ['timing', 'أقصى انتظار قبل الرد (ثانية)', 'int', null],
            'session_gap_hours' => ['timing', 'بعد كام ساعة سكوت تبدأ محادثة جديدة', 'int', null],

            'applications.mode' => ['form_links', 'طريقة التقديم', 'select', 'form_links = البوت يبعت رابط الفورم ومبيستلمش طلبات في الشات. bot = الطريقة القديمة (البوت يجمع الطلب في الشات).', ['form_links' => 'رابط الفورم (Dynamic Links)', 'bot' => 'البوت يجمع الطلب (القديم)']],
            'form_links.expires_after_days' => ['form_links', 'الرابط ينتهي بعد (يوم)', 'int', null],
            'form_links.followup_after_minutes' => ['form_links', 'أول متابعة بعد سكوت (دقيقة)', 'int', 'من آخر حركة في الفورم وآخر رسالة منه على الواتس. مفيش متابعة بالليل.'],
            'form_links.second_followup_after_hours' => ['form_links', 'المتابعة التانية والأخيرة بعد (ساعة)', 'int', null],
            'form_links.received_message' => ['form_links', 'رسالة استلام الطلب', 'text', 'بتتبعت على الواتس أول ما العميل يبعت الفورم.'],
            'form_links.followup_not_opened' => ['form_links', 'متابعة: لسه مقدمش', 'text', 'لو مفتحش الرابط أو مكتبش حاجة.'],
            'form_links.followup_warned' => ['form_links', 'متابعة: صورة محتاجة تتعدّل', 'text', '{label} = اسم المستند، {problem} = المشكلة، {url} = الرابط.'],
            'form_links.followup_partial' => ['form_links', 'متابعة: ملا جزء', 'text', '{missing} = اللي فاضل، {url} = الرابط.'],
            'form_links.fix_message' => ['form_links', 'رسالة تعديل البيانات', 'text', 'لما الموظف يطلب تصحيح بيانات: {reason} = سبب الموظف، {url} = الرابط.'],
            'applications.nudge_after_minutes' => ['handoff', 'فكّر العميل اللي سكت في نص التقديم بعد (دقيقة)', 'int', 'رسالة قصيرة بالحاجة الوحيدة الناقصة، وتانية بعد يوم، ومفيش بالليل. فاضي = مفيش تذكير.'],
            'handoff.max_failed_turns' => ['handoff', 'حوّل لموظف بعد كام رد فاشل', 'int', null],
            'handoff.waiting_message' => ['handoff', 'رسالة الانتظار وقت التحويل لموظف', 'text', 'بتتبعت للعميل لو كتب والموظف لسه ما ردش.'],
            'handoff.waiting_ack_interval_minutes' => ['handoff', 'متكررش رسالة الانتظار قبل (دقيقة)', 'int', null],
            'handoff.staff_pause_minutes' => ['handoff', 'البوت يستنى بعد رسالة الموظف (دقيقة)', 'int', 'لما موظف يكتب للعميل، البوت يسكت المدة دي من آخر رسالة للموظف وبعدها يكمل ورا الموظف على أي رسالة جديدة من العميل.'],
            'handoff.return_to_agent_after_minutes' => ['handoff', 'رجّع المحادثة للبوت لو الموظف ما ردش خلال (دقيقة)', 'int', 'فاضي = المحادثة تفضل مع الموظف لحد ما يقفلها.'],
            'fallback.message' => ['handoff', 'رسالة لو البوت وقع', 'text', 'الرسالة الوحيدة اللي السيستم يبعتها من نفسه لما الموديل مش متاح.'],

            'context.pinned_memory_tokens' => ['memory', 'أقصى حجم للمعرفة المثبتة (توكن)', 'int', 'المعرفة المثبتة بتتبعت مع كل رسالة، فلو زادت الرد بيبطأ ويغلى.'],
            'context.recent_messages_tokens' => ['memory', 'حجم آخر الرسايل في الذاكرة (توكن)', 'int', null],
            'summary.trigger_messages' => ['memory', 'اعمل ملخص للمحادثة كل كام رسالة', 'int', null],

            'recognition.match_threshold' => ['documents', 'نسبة التطابق عشان يقول "هي دي المكنة"', 'float', 'من 0 لـ 1.'],
            'recognition.similar_threshold' => ['documents', 'نسبة التشابه عشان يقول "شبه"', 'float', 'من 0 لـ 1.'],
            'documents.name_match_threshold' => ['documents', 'نسبة تطابق الاسم في المستندات', 'float', 'من 0 لـ 1. 1 = لازم الاسم يطابق بالظبط.'],
            'images.resend_window_minutes' => ['documents', 'متبعتش نفس الصور تاني خلال (دقيقة)', 'int', null],

            'installments.first_payment_after_days' => ['sales', 'أول قسط بعد كام يوم من الاستلام', 'int', null],
            'installments.price_difference_explanation' => ['sales', 'شرح فرق السعر بين الكاش والتقسيط', 'text', 'البوت بيشرح للعميل بكلامه من الكلام ده لما يسأل ليه التقسيط أغلى من الكاش - مش بينقله نسخ.'],

            'teaching.coach_model' => ['teaching', 'موديل المدرّب في وضع التعليم', 'string', 'فاضي = نفس موديل البوت. الأقوى أدق في فهم التصحيح بس أبطأ.'],
            'teaching.lessons_tokens' => ['teaching', 'أقصى حجم للدروس في كل رد (توكن)', 'int', 'الدروس بتتبعت مع كل رسالة. لو اتملت، الأمثلة بتتشال الأول.'],

            'instructions.approved_version' => ['instructions', 'نسخة التعليمات المعتمدة', 'string', null],
        ];
    }

    public static function groups(): array
    {
        return [
            'run' => 'التشغيل والموديل',
            'limits' => 'حدود الرد',
            'timing' => 'التوقيت',
            'handoff' => 'التحويل لموظف ورسائل النظام',
            'memory' => 'الذاكرة والسياق',
            'documents' => 'الصور والمستندات',
            'sales' => 'البيع والتقسيط',
            'form_links' => 'روابط التقديم',
            'teaching' => 'وضع التعليم',
        ];
    }

    /** Overlays the stored overrides on config('agent.*'). Safe before migrations. */
    public static function apply(): void
    {
        if (self::$defaults === null) {
            self::$defaults = [];

            foreach (array_keys(self::definitions()) as $key) {
                self::$defaults[$key] = config("agent.{$key}");
            }
        }

        $overrides = self::overrides();

        foreach (self::$defaults as $key => $default) {
            config(["agent.{$key}" => array_key_exists($key, $overrides) ? $overrides[$key] : $default]);
        }
    }

    /** @return array<string, mixed> */
    public static function overrides(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => AgentSetting::query()->pluck('value', 'key')->all());
        } catch (\Throwable) {
            return [];
        }
    }

    /** The value from .env / config, ignoring any dashboard override. */
    public static function envDefault(string $key): mixed
    {
        self::apply();

        return self::$defaults[$key] ?? null;
    }

    /**
     * Stores the given values. A null/empty value removes the override so
     * the .env value applies again.
     *
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values, ?int $staffId = null): void
    {
        $definitions = self::definitions();

        foreach ($values as $key => $value) {
            if (! isset($definitions[$key])) {
                continue;
            }

            $value = self::normalize($definitions[$key][2], $value);

            if ($value === null) {
                AgentSetting::where('key', $key)->delete();

                continue;
            }

            AgentSetting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $staffId]);
        }

        Cache::forget(self::CACHE_KEY);
        self::apply();
    }

    private static function normalize(string $type, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            'float' => (float) $value,
            // stored the same way .env holds it (comma-separated), because
            // GeminiProvider splits the string itself.
            'csv' => implode(',', array_filter(array_map('trim', is_array($value) ? $value : explode(',', (string) $value)))) ?: null,
            default => trim((string) $value) === '' ? null : (string) $value,
        };
    }
}
