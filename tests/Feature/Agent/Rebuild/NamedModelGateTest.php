<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\ToolContext;
use App\Models\AiTrace;
use App\Models\Brand;
use App\Models\Machine;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live 2026-10-13 (conversation 639): with the 10-05 rebuild the gate on
 * "the model he named" was gone, and "هوجان ٤" was priced, pictured and
 * put on his application as motorcycle 4 (L250). Photos are queued by the
 * tool before any reply check, so the tool call itself is refused.
 */
class NamedModelGateTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private function says($conversation, string $text): void
    {
        WhatsappMessage::create(['whatsapp_conversation_id' => $conversation->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text', 'text' => $text]);
    }

    private function ctx($conversation): ToolContext
    {
        $trace = AiTrace::create(['conversation_id' => $conversation->id, 'turn_id' => 1, 'status' => 'running']);

        return new ToolContext($conversation->customer_id, $conversation->id, null, 1, $trace->id, new TurnResultBuilder());
    }

    public function test_a_tool_on_another_brand_than_the_one_he_named_is_refused(): void
    {
        // live: "دايو 4 استيراد" was priced and opened on "هوجن ٤ استيراد"
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'هوجان', 'image' => 'b.jpg']);
        Brand::create(['name' => 'دايو', 'image' => 'd.jpg']);
        $machine = Machine::create(['name' => 'هوجن 4', 'brand_id' => $brand->id, 'cash_price' => 60000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $this->says($conversation, 'بكام دايو 4؟');

        $result = app(\App\Agent\Tools\ToolRegistry::class)->execute('get_motorcycle_details', ['motorcycle_ids' => [$machine->id]], $this->ctx($conversation));

        $this->assertSame('NOT_THE_MODEL_HE_NAMED', $result['error']['code'] ?? null);
    }

    public function test_hogan_4_is_never_motorcycle_4(): void
    {
        // conversation 639 (2026-10-13): "هوجان ٤" was quoted 68,000 and pictured as L250, id 4
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'هوجان', 'image' => 'b.jpg']);
        $l250 = Machine::forceCreate(['id' => 4, 'name' => 'L250', 'brand_id' => $brand->id, 'cash_price' => 68000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $hogan4 = Machine::create(['name' => 'هوجن ٤ استيراد فرز تاني', 'brand_id' => $brand->id, 'cash_price' => 40000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $this->says($conversation, 'كنت محتاج مكنه هوجان ٤');

        $registry = app(\App\Agent\Tools\ToolRegistry::class);
        $refused = $registry->execute('get_installment_offer', ['motorcycle_id' => $l250->id], $this->ctx($conversation));
        $allowed = $registry->execute('get_motorcycle_details', ['motorcycle_ids' => [$hogan4->id]], $this->ctx($conversation));

        $this->assertSame('NOT_THE_MODEL_HE_NAMED', $refused['error']['code'] ?? null);
        $this->assertTrue($allowed['ok'], json_encode($allowed));
    }

    public function test_a_number_he_wrote_is_not_a_catalog_id(): void
    {
        // simulator 2026-10-08: "ايه الفرق بين ال43 وال50" looked up motorcycles 43 and 50
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'TVS', 'image' => 'b.jpg']);
        $apache = Machine::forceCreate(['id' => 43, 'name' => 'اباتشي 200', 'brand_id' => $brand->id, 'cash_price' => 98000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $this->says($conversation, 'ايه الفرق بين ال43 وال50');

        $result = app(\App\Agent\Tools\ToolRegistry::class)->execute('get_motorcycle_details', ['motorcycle_ids' => [$apache->id]], $this->ctx($conversation));

        $this->assertSame('NOT_THE_MODEL_HE_NAMED', $result['error']['code'] ?? null);
    }

    public function test_engine_size_and_durations_are_not_model_numbers(): void
    {
        // 10-01..10-03: "هوجن 150 استيراد" refused the 150cc Hogan 4 import; "على 3 سنين" is no id
        $conversation = $this->conversation();
        $brand = Brand::create(['name' => 'هوجان', 'image' => 'b.jpg']);
        $daewooBrand = Brand::create(['name' => 'دايو', 'image' => 'd.jpg']);
        $hogan = Machine::create(['name' => 'هوجن ٤ استيراد', 'brand_id' => $brand->id, 'cc' => 150, 'cash_price' => 52000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $daewoo = Machine::forceCreate(['id' => 3, 'name' => 'دايو ٤', 'brand_id' => $daewooBrand->id, 'cash_price' => 39000, 'is_active' => true, 'availability' => 'in_stock', 'type' => 'normal']);
        $this->says($conversation, 'هوجن 150 استيراد');

        $registry = app(\App\Agent\Tools\ToolRegistry::class);
        $this->assertTrue($registry->execute('get_motorcycle_details', ['motorcycle_ids' => [$hogan->id]], $this->ctx($conversation))['ok']);

        $other = $this->conversation('2012');
        $this->says($other, 'دايو 4 على 3 سنين');
        $this->assertTrue($registry->execute('get_motorcycle_details', ['motorcycle_ids' => [$daewoo->id]], $this->ctx($other))['ok']);
    }
}
