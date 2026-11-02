<?php

namespace Tests\Unit\Agent\Runtime;

use App\Agent\Runtime\SimpleFaqReply;
use Tests\TestCase;

class SimpleFaqReplyTest extends TestCase
{
    public function test_finance_company_and_sale_ban_are_answered_without_ai(): void
    {
        $faq = new SimpleFaqReply;

        $this->assertSame(['messages' => ['التقسيط عندنا من خلال شركة أمان.']], $faq->for('بتقسطوا مع شركة ايه؟'));
        $this->assertSame(['messages' => ['التقسيط عندنا ومن غير حظر بيع.']], $faq->for('في حظر بيع؟'));
        $this->assertSame(['messages' => ['التقسيط عندنا من خلال شركة أمان ومن غير حظر بيع.']], $faq->for('التقسيط مع مين وفي حظر بيع؟'));
    }

    public function test_shipping_warranty_and_first_payment_are_answered_without_ai(): void
    {
        config(['agent.installments.first_payment_after_days' => 45]);
        $faq = new SimpleFaqReply;

        $this->assertSame(['messages' => ['الشحن متاح. كاش ولا تقسيط؟']], $faq->for('بتشحنوا؟'));
        $this->assertSame(['messages' => ['أول قسط بيكون بعد 45 يوم من الاستلام.']], $faq->for('اول قسط امتى؟'));
        $this->assertSame(['messages' => ['تفاصيل الضمان بيأكدها الزميل في الفرع حسب الموديل. بتسأل على أنهي موديل؟']], $faq->for('فيه ضمان؟'));
    }

    public function test_questions_that_need_context_continue_to_the_agent(): void
    {
        $faq = new SimpleFaqReply;

        $this->assertNull($faq->for('الشحن للقاهرة بكام وبيوصل في كام يوم؟'));
        $this->assertNull($faq->for('شركة أمان قسط هوجن 4 كام؟'));
    }
}
