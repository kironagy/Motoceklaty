<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\Handlers\ApplicationHandler;
use ReflectionMethod;

class ApplicationHandlerTest extends TestCase
{
    private function callMissingFields(ApplicationHandler $handler, array $data): array
    {
        $reflection = new ReflectionMethod(ApplicationHandler::class, 'missingFields');
        $reflection->setAccessible(true);
        return $reflection->invoke($handler, $data);
    }

    /**
     * Case 1: home_address = "6 أكتوبر" and home_address_status = "incomplete"
     * Expected: home_address is considered missing.
     */
    public function test_home_address_incomplete_is_considered_missing(): void
    {
        $handler = new ApplicationHandler();
        $data = [
            'full_name' => 'محمد احمد',
            'national_id' => '29001011234567',
            'phone' => '01012345678',
            'job_type' => 'موظف',
            'income_proof' => 'مفردات مرتب',
            'work_address' => 'القاهرة شارع القصر العيني',
            'work_address_status' => 'complete',
            'home_address' => '6 أكتوبر',
            'home_address_status' => 'incomplete',
            'installment_months' => 12,
        ];

        $missing = $this->callMissingFields($handler, $data);

        $this->assertContains('home_address', $missing);
        $this->assertNotContains('work_address', $missing);
    }

    /**
     * Case 2: home_address = "عنوان تفصيلي فعلي..." and home_address_status = "complete"
     * Expected: home_address is considered complete.
     */
    public function test_home_address_complete_is_accepted(): void
    {
        $handler = new ApplicationHandler();
        $data = [
            'full_name' => 'محمد احمد',
            'national_id' => '29001011234567',
            'phone' => '01012345678',
            'job_type' => 'موظف',
            'income_proof' => 'مفردات مرتب',
            'work_address' => 'القاهرة شارع القصر العيني',
            'work_address_status' => 'complete',
            'home_address' => '6 أكتوبر الحي المتميز ش 15 عمارة 4',
            'home_address_status' => 'complete',
            'installment_months' => 12,
        ];

        $missing = $this->callMissingFields($handler, $data);

        $this->assertEmpty($missing);
    }

    /**
     * Case 3: work_address = "منطقة فقط" and work_address_status = "incomplete"
     * Expected: work_address is considered missing.
     */
    public function test_work_address_incomplete_is_considered_missing(): void
    {
        $handler = new ApplicationHandler();
        $data = [
            'full_name' => 'محمد احمد',
            'national_id' => '29001011234567',
            'phone' => '01012345678',
            'job_type' => 'موظف',
            'income_proof' => 'مفردات مرتب',
            'work_address' => 'الجيزة',
            'work_address_status' => 'incomplete',
            'home_address' => 'المهندسين شارع جامعة الدول',
            'home_address_status' => 'complete',
            'installment_months' => 12,
        ];

        $missing = $this->callMissingFields($handler, $data);

        $this->assertContains('work_address', $missing);
        $this->assertNotContains('home_address', $missing);
    }

    /**
     * Case 4: work_address = "عنوان تفصيلي للشغل" and work_address_status = "complete"
     * Expected: work_address is considered complete.
     */
    public function test_work_address_complete_is_accepted(): void
    {
        $handler = new ApplicationHandler();
        $data = [
            'full_name' => 'محمد احمد',
            'national_id' => '29001011234567',
            'phone' => '01012345678',
            'job_type' => 'موظف',
            'income_proof' => 'مفردات مرتب',
            'work_address' => 'مصر الجديدة شارع الميرغني مبنى 10',
            'work_address_status' => 'complete',
            'home_address' => 'المهندسين شارع جامعة الدول',
            'home_address_status' => 'complete',
            'installment_months' => 12,
        ];

        $missing = $this->callMissingFields($handler, $data);

        $this->assertEmpty($missing);
    }

    /**
     * Case 5: Ensure other fields are still validated normally
     * Expected: Missing phone/national_id behaves normally.
     */
    public function test_other_fields_validated_normally(): void
    {
        $handler = new ApplicationHandler();
        $data = [
            'full_name' => 'محمد احمد',
            'national_id' => '', // missing
            'phone' => '', // missing
            'job_type' => 'موظف',
            'income_proof' => 'مفردات مرتب',
            'work_address' => 'مصر الجديدة شارع الميرغني',
            'work_address_status' => 'complete',
            'home_address' => 'المهندسين شارع جامعة الدول',
            'home_address_status' => 'complete',
            'installment_months' => '', // missing
        ];

        $missing = $this->callMissingFields($handler, $data);

        $this->assertContains('national_id', $missing);
        $this->assertContains('phone', $missing);
        $this->assertContains('installment_months', $missing);
        $this->assertNotContains('home_address', $missing);
        $this->assertNotContains('work_address', $missing);
    }

    /**
     * Case 6: Freelance / craftsman (like سباك) does not require income_proof
     */
    public function test_freelance_craftsman_does_not_require_income_proof(): void
    {
        $handler = new ApplicationHandler();
        $data = [
            'full_name' => 'احمد سيد حسين علي',
            'national_id' => '29011260101839',
            'phone' => '01200268302',
            'job_type' => 'سباك',
            'income_proof' => null,
            'work_address' => '6 أكتوبر الحي الأول شارع 10',
            'work_address_status' => 'complete',
            'home_address' => '6 أكتوبر الحي المتميز عمارة 5',
            'home_address_status' => 'complete',
            'installment_months' => 12,
        ];

        $missing = $this->callMissingFields($handler, $data);

        $this->assertNotContains('income_proof', $missing);
        $this->assertEmpty($missing);
    }
}

