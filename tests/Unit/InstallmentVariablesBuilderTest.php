<?php

namespace Tests\Unit;

use App\Services\InstallmentVariablesBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Reported live 25/08/2026: the installment reply said the administrative
 * fee is paid "عند التعاقد" while the fee-explanation reply in the same
 * conversation said "وقت استلام المكنة", and neither told the customer when
 * the first instalment is actually due. Both facts are produced here, so
 * they are pinned here.
 */
class InstallmentVariablesBuilderTest extends TestCase
{
    private function calculation(array $overrides = []): array
    {
        return array_merge([
            'ok' => true,
            'machine_id' => 1,
            'machine_name' => 'دايو ٤ اصلي',
            'months' => 24,
            'deposit' => 0.0,
            'system' => '20',
            'monthly_payment' => 2975,
            'admin_fee' => 3570,
            'freelance_extra_deposit' => 0.0,
        ], $overrides);
    }

    public function test_admin_fee_is_described_as_due_on_pickup(): void
    {
        $built = (new InstallmentVariablesBuilder())->build([$this->calculation()]);

        $this->assertTrue($built['ok']);
        $this->assertStringContainsString('وقت استلام المكنة', $built['variables']['admin_fee_text']);
        $this->assertStringNotContainsString('عند التعاقد', $built['variables']['admin_fee_text']);
        $this->assertStringContainsString('وقت الاستلام', $built['variables']['admin_fee_list']);
    }

    public function test_first_installment_timing_is_carried_inside_admin_fee_text(): void
    {
        $built = (new InstallmentVariablesBuilder())->build([$this->calculation()]);

        // Inside admin_fee_text specifically, so every {admin_fee_text}
        // template already stored in ai_memories picks it up unedited.
        $this->assertStringContainsString('45 يوم', $built['variables']['admin_fee_text']);
        $this->assertSame(
            'وأول قسط شهري بيبقى بعد استلام المكنة بـ 45 يوم.',
            $built['variables']['first_installment_text']
        );
    }

    public function test_thirty_percent_system_says_there_is_no_admin_fee_but_still_dates_the_first_installment(): void
    {
        $built = (new InstallmentVariablesBuilder())->build([
            $this->calculation(['system' => '30', 'admin_fee' => 0]),
        ]);

        $this->assertStringContainsString('بدون مصاريف إدارية', $built['variables']['admin_fee_text']);
        $this->assertStringContainsString('45 يوم', $built['variables']['admin_fee_text']);
    }
}
