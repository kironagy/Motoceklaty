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
            'enabled' => ['run', 'البوت شغال', 'bool', 'لو اتقفل البوت مش هيرد على أي رسالة واتساب جديدة.'],
            'model' => ['run', 'الموديل الأساسي', 'string', 'اسم موديل Gemini اللي بيرد على العملاء، مثال: gemini-3.1-flash-lite'],
            'fallback_models' => ['run', 'موديلات احتياطية', 'csv', 'بتتجرب بالترتيب لو الموديل الأساسي واقع.'],
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

            'applications.nudge_after_minutes' => ['handoff', 'فكّر العميل اللي سكت في نص التقديم بعد (دقيقة)', 'int', 'رسالة قصيرة بالحاجة الوحيدة الناقصة، وتانية بعد يوم، ومفيش بالليل. فاضي = مفيش تذكير.'],
            'handoff.max_failed_turns' => ['handoff', 'حوّل لموظف بعد كام رد فاشل', 'int', null],
            'handoff.waiting_message' => ['handoff', 'رسالة الانتظار وقت التحويل لموظف', 'text', 'بتتبعت للعميل لو كتب والموظف لسه ما ردش.'],
            'handoff.waiting_ack_interval_minutes' => ['handoff', 'متكررش رسالة الانتظار قبل (دقيقة)', 'int', null],
            'handoff.return_to_agent_after_minutes' => ['handoff', 'رجّع المحادثة للبوت لو الموظف ما ردش خلال (دقيقة)', 'int', 'فاضي = المحادثة تفضل مع الموظف لحد ما يقفلها.'],
            'fallback.message' => ['handoff', 'رسالة لو البوت وقع', 'text', 'الرسالة الوحيدة اللي السيستم يبعتها من نفسه لما الموديل مش متاح.'],

            'context.pinned_memory_tokens' => ['memory', 'أقصى حجم للمعرفة المثبتة (توكن)', 'int', 'المعرفة المثبتة بتتبعت مع كل رسالة، فلو زادت الرد بيبطأ ويغلى.'],
            'context.recent_messages_tokens' => ['memory', 'حجم آخر الرسايل في الذاكرة (توكن)', 'int', null],
            'summary.trigger_messages' => ['memory', 'اعمل ملخص للمحادثة كل كام رسالة', 'int', null],

            'recognition.match_threshold' => ['documents', 'نسبة التطابق عشان يقول "هي دي المكنة"', 'float', 'من 0 لـ 1.'],
            'recognition.similar_threshold' => ['documents', 'نسبة التشابه عشان يقول "شبه"', 'float', 'من 0 لـ 1.'],
            'documents.name_match_threshold' => ['documents', 'نسبة تطابق الاسم في المستندات', 'float', 'من 0 لـ 1. 1 = لازم الاسم يطابق بالظبط.'],
            'images.resend_window_minutes' => ['documents', 'متبعتش نفس الصور تاني خلال (دقيقة)', 'int', null],

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
