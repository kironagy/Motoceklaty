<?php

namespace Tests\Unit\Agent\Runtime;

use App\Agent\Runtime\ToolResultReply;
use PHPUnit\Framework\TestCase;

class FinancialReplySafetyTest extends TestCase
{
    /** At the call limit, a catalog lookup is answered from what it found - prices from the tool, never invented. */
    public function test_catalog_lookup_at_the_limit_answers_with_the_tool_prices(): void
    {
        $reply = (new ToolResultReply)->catalogFallback([
            ['name' => 'search_motorcycles', 'ok' => true, 'data' => ['items' => [
                ['id' => 1, 'name' => 'Z250', 'cash_price' => 70000],
                ['id' => 2, 'name' => 'RK200', 'is_offer' => true, 'offer_price' => 52000, 'cash_price' => 55000],
            ]]],
            ['name' => 'get_installment_offer', 'ok' => true, 'data' => ['items' => [['id' => 9, 'name' => 'X', 'cash_price' => 1]]]],
        ]);

        $this->assertStringContainsString('Z250: كاش 70,000 جنيه.', $reply['messages'][0]);
        $this->assertStringContainsString('RK200: كاش 52,000 جنيه.', $reply['messages'][0]);
        $this->assertStringNotContainsString('X:', $reply['messages'][0]);
    }

    public function test_nothing_found_gives_no_invented_reply(): void
    {
        $this->assertNull((new ToolResultReply)->catalogFallback([['name' => 'search_motorcycles', 'ok' => false, 'data' => []]]));
    }
}
