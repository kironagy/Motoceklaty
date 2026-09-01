<?php

namespace Tests\Unit;

use App\Services\WhatsappIntentRouter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * محادثة حقيقية (01/09): العميل خلص حسبة القسط على 24 شهر، وبعدها قال
 * "انا شغال طلبات علي عجله" - تعريف بشغله ومركبته. الكلاسيفاير رجّعها
 * installment_calc، يعني الرسالة كانت رايحة لمسار حساب القسط وممكن ترجع
 * نفس الأرقام اللي اتبعتت قبلها بثواني بدل ما ترد على اللي قاله.
 *
 * الحارس ده حتمي عشان ميعتمدش على تصنيف الموديل، وضيق بقصد: لو الرسالة
 * فيها طلب حسبة كمان، تفضل حسبة.
 */
class JobStatementIntentTest extends TestCase
{
    private function statesOwnJobOnly(string $message): bool
    {
        $router = new WhatsappIntentRouter();

        $normalize = new ReflectionMethod($router, 'normalizeText');
        $normalize->setAccessible(true);

        $method = new ReflectionMethod($router, 'statesOwnJobOnly');
        $method->setAccessible(true);

        return $method->invoke($router, $normalize->invoke($router, $message));
    }

    public function test_a_plain_job_statement_is_not_a_calculation_request(): void
    {
        $this->assertTrue($this->statesOwnJobOnly('انا شغال طلبات علي عجله'));
        $this->assertTrue($this->statesOwnJobOnly('أنا بشتغل أوبر على عربية'));
        $this->assertTrue($this->statesOwnJobOnly('انا موظف'));
    }

    public function test_a_job_statement_that_also_asks_for_a_calculation_stays_a_calculation(): void
    {
        $this->assertFalse($this->statesOwnJobOnly('انا شغال طلبات وعايز اقسط على 12 شهر'));
        $this->assertFalse($this->statesOwnJobOnly('انا شغال دليفري القسط كام'));
        $this->assertFalse($this->statesOwnJobOnly('انا موظف والمقدم هيكون كام'));
    }

    public function test_messages_with_no_job_statement_are_untouched(): void
    {
        $this->assertFalse($this->statesOwnJobOnly('تمام عاوزها علي سنتين'));
        $this->assertFalse($this->statesOwnJobOnly('عاوز دايونج'));
        $this->assertFalse($this->statesOwnJobOnly('مكانكم فين'));
    }
}
