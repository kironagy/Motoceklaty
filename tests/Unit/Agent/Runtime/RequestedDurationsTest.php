<?php

namespace Tests\Unit\Agent\Runtime;

use App\Agent\Runtime\RequestedDurations;
use PHPUnit\Framework\TestCase;

class RequestedDurationsTest extends TestCase
{
    public function test_year_and_year_and_half_are_both_requested(): void
    {
        $this->assertSame([12,18],(new RequestedDurations)->months('علي السنه بكام والسنه ونص بكام قصد'));
        $this->assertSame([18],(new RequestedDurations)->months('على سنة ونص'));
        $this->assertSame([24],(new RequestedDurations)->months('طب والسنتين معلش'));
        $this->assertSame([12,18,24,36],(new RequestedDurations)->months('سنة وسنة ونص وسنتين و٣ سنين'));
    }
}
