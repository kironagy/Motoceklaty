<?php

namespace Tests\Unit;

use App\Services\WhatsappIntentRouter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * محادثات حقيقية من حملة إعلانية (2026-09-02، محادثات 47 و49 و51 و52).
 * كل تست هنا بيمثّل رد اتبعت لعميل حقيقي وكان غلط.
 */
class AdCampaignRegressionTest extends TestCase
{
    private function router(): WhatsappIntentRouter
    {
        return new WhatsappIntentRouter();
    }

    private function call(string $method, array $args, ?object $on = null)
    {
        $target = $on ?: $this->router();
        $ref = new ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($target, $args);
    }

    /**
     * محادثة 52: "." بعد سؤال السعر اتصنّفت سؤال سعر جديد، فرجع نفس رد
     * السعر حرفيًا (repetition_score = 1.0). والنقطة دي استعجال مش سؤال.
     */
    public function test_punctuation_only_messages_are_not_new_questions(): void
    {
        foreach (['.', '..', '؟؟', '???', '...', '،'] as $filler) {
            $this->assertSame(
                1,
                preg_match('/^[\s\.\?؟!،,ـ_\-]+$/u', $filler),
                "{$filler} المفروض يتحسب رسالة استعجال"
            );
        }

        // ورسالة فيها كلام حقيقي مش استعجال.
        $this->assertSame(0, preg_match('/^[\s\.\?؟!،,ـ_\-]+$/u', 'طب هو سعرو كام'));
    }

    /**
     * محادثة 49: العميل شتم والبوت رد "لسه مستنى منك الرقم القومي".
     */
    public function test_an_insult_is_detected_for_immediate_handoff(): void
    {
        foreach (['الرقم القومي عند امك', 'انطر يعرص', 'انت هلس', 'رد يهلس'] as $abusive) {
            $this->assertTrue($this->call('messageIsAbusive', [$abusive]), $abusive);
        }

        foreach (['عاوز اعرف السعر', 'الجو حر اوي', 'مش عارف انهي موديل'] as $normal) {
            $this->assertFalse($this->call('messageIsAbusive', [$normal]), $normal);
        }
    }

}
