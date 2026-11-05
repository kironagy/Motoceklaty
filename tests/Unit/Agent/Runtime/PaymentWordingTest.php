<?php
namespace Tests\Unit\Agent\Runtime;

use App\Agent\Runtime\PaymentWording;
use PHPUnit\Framework\TestCase;

class PaymentWordingTest extends TestCase
{
    public function test_generic_promise_does_not_invent_a_down_payment(): void
    {
        $args=(new PaymentWording)->apply(['messages'=>['هحسبلك المقدم والمصاريف الإدارية']],[]);
        $this->assertSame(['هحسبلك القسط والمصاريف الإدارية'],$args['messages']);
    }
    public function test_real_required_down_payment_is_preserved(): void
    {
        $args=['messages'=>['هحسبلك المقدم والمصاريف الإدارية']];
        $this->assertSame($args,(new PaymentWording)->apply($args,[['name'=>'get_installment_offer','ok'=>true,'data'=>['offers'=>[['minimum_down_payment'=>15000]]]]]));
    }
}
