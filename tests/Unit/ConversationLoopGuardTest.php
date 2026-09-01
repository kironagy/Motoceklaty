<?php

namespace Tests\Unit;

use App\Services\ConversationLoopGuard;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * أكتر مشكلة اتكررت في اختبار البوت الحقيقي: نفس الرسالة بالحرف ٤ مرات
 * ورا بعض والعميل بيرد بحاجة مختلفة كل مرة، وفي الآخر المحادثة بتموت.
 * سببها إن مانع التكرار كان مربوط بمسار الرد الحر بس، وكل الردود
 * الحتمية بره تغطيته.
 *
 * الاختبارات دي بتغطي المنطق اللي مش محتاج نداء Gemini: التصعيد،
 * وباب الخروج، والتأكد إن إعادة الصياغة مبتضيّعش أرقام.
 */
class ConversationLoopGuardTest extends TestCase
{
    private function call(string $method, array $args)
    {
        $guard = new ConversationLoopGuard(
            new \App\Support\RepetitionGuard(new \App\Services\AiMemoryParser()),
            new \App\Services\GeminiClient()
        );

        $reflection = new ReflectionMethod($guard, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($guard, ...$args);
    }

    public function test_the_escape_hatch_is_added_on_the_second_repeat(): void
    {
        $reply = $this->call('withEscapeHatch', ['تحب التقسيط على كام شهر؟', true]);

        $this->assertStringContainsString('عدّيها', $reply);
        $this->assertStringContainsString('عايز أكلم حد', $reply);
        $this->assertStringContainsString('تحب التقسيط على كام شهر؟', $reply);
    }

    public function test_the_escape_hatch_is_not_added_on_the_first_repeat(): void
    {
        $reply = $this->call('withEscapeHatch', ['تحب التقسيط على كام شهر؟', false]);

        $this->assertSame('تحب التقسيط على كام شهر؟', $reply);
    }

    public function test_the_escape_hatch_is_never_added_twice(): void
    {
        $once = $this->call('withEscapeHatch', ['سؤال؟', true]);
        $twice = $this->call('withEscapeHatch', [$once, true]);

        $this->assertSame($once, $twice);
    }

    /**
     * إعادة الصياغة اللي بتضيّع رقم بتترفض. حصلت فعلًا: رسالة فيها
     * النظامين والنِسب والمدد اتحوّلت لسطرين مفيهمش ولا رقم، يعني العميل
     * سأل تاني وخد إجابة أقل من الأولى.
     */
    public function test_a_rewrite_that_drops_a_number_is_rejected(): void
    {
        $original = 'نظام 20% وفيه 7% مصاريف إدارية، والمدد 12 أو 24 شهر.';

        $this->assertFalse($this->call('keepsSameNumbers', [$original, 'عندنا نظامين تقسيط، تحب أشرحلك؟']));
    }

    public function test_a_rewrite_that_invents_a_number_is_rejected(): void
    {
        $original = 'نظام 20% وفيه 7% مصاريف إدارية.';

        $this->assertFalse($this->call('keepsSameNumbers', [$original, 'نظام 20% ومصاريف 7% ومدة 36 شهر.']));
    }

    public function test_a_faithful_rewrite_is_accepted(): void
    {
        $original = 'نظام 20% وفيه 7% مصاريف إدارية، والمدد 12 أو 24 شهر.';
        $rewrite = 'عندنا نظام الـ 20% ومعاه 7% مصاريف إدارية، وتقدر تقسط على 12 أو 24 شهر.';

        $this->assertTrue($this->call('keepsSameNumbers', [$original, $rewrite]));
    }

    /** الأرقام العربية والفواصل لازم تتقارن صح، مش تتحسب أرقام مختلفة. */
    public function test_arabic_digits_and_separators_compare_equal(): void
    {
        $this->assertTrue($this->call('keepsSameNumbers', [
            'السعر 39,500 جنيه على 12 شهر',
            'السعر ٣٩٥٠٠ جنيه على ١٢ شهر',
        ]));
    }

    public function test_the_fallback_rewrite_never_returns_the_original_untouched(): void
    {
        $original = 'تحب تقدم على أنهي موديل؟';

        $this->assertNotSame($original, $this->call('fallbackRewrite', [$original, false]));
        $this->assertNotSame($original, $this->call('fallbackRewrite', [$original, true]));
    }
}
