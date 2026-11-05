<?php
namespace Tests\Unit\Agent;

use App\Domain\Installments\FinancingCapPolicy;
use App\Models\Machine;
use App\Models\InstallmentSystem;
use Tests\TestCase;

class LowCashPriceDepositTest extends TestCase
{
    public function test_under_sixty_thousand_uses_public_cash_price_not_internal_price(): void
    {
        $policy=new FinancingCapPolicy;
        $machine=new Machine(['cash_price'=>59000,'installment_price'=>65000]);
        $system=new InstallmentSystem(['pricing_mode'=>'standard']);
        foreach(['employee','self_employed'] as $type) $this->assertSame(0.0,$policy->minimumDownPayment($machine,$system,60000,$type));
        $this->assertSame(5000.0,$policy->minimumDownPayment($machine,$system,60000,'pension'));
    }

    public function test_boundary_does_not_expand_the_requested_exception(): void
    {
        $policy=new FinancingCapPolicy;
        foreach([60000,60001] as $cash){
            $this->assertFalse($policy->noMandatoryDownPayment(new Machine(['cash_price'=>$cash]),'self_employed'));
        }
        $this->assertFalse($policy->noMandatoryDownPayment(new Machine(['cash_price'=>0]),'employee'));
    }
}
