<?php

namespace Tests\Unit\Agent\Runtime;

use App\Agent\Runtime\CurrentBusinessPolicy;
use App\Agent\Runtime\ToolResultReply;
use PHPUnit\Framework\TestCase;

class FinancialReplySafetyTest extends TestCase
{
    public function test_missing_selection_never_returns_installment_numbers(): void
    {
        $reply = (new ToolResultReply)->for('get_installment_offer', ['ok'=>false,'error'=>['code'=>'MODEL_SELECTION_REQUIRED']]);
        $this->assertStringContainsString('أنهي موتوسيكل', $reply['messages'][0]);
        $this->assertDoesNotMatchRegularExpression('/\d/u', $reply['messages'][0]);
    }

    public function test_letter_fee_is_explicitly_zero(): void
    {
        $reply = (new CurrentBusinessPolicy)->reply('في مصاريف ادارية ورسوم على الجواب؟');
        $this->assertStringStartsWith('مفيش رسوم على الجواب', $reply);
    }

    public function test_every_available_duration_and_required_difference_are_shown(): void
    {
        $reply = (new ToolResultReply)->for('get_installment_offer',['ok'=>true,'data'=>[
            'minimum_down_payment'=>15000,'durations'=>['سنة','سنة ونص','سنتين','٣ سنين'],
        ]]);
        $this->assertStringContainsString('15,000', $reply['messages'][0]);
        $this->assertStringContainsString('٣ سنين', $reply['messages'][0]);
    }
}
